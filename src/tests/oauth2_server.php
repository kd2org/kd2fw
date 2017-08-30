<?php

use KD2\Test;
use KD2\OAuth2_Server;

require __DIR__ . '/_assert.php';

function check_error($error, $data)
{
	Test::assert(isset($data->error) && isset($data->error_description));
	Test::equals($error, $data->error);
}

$db = new \SQLite3(':memory:');
$db->exec('
	PRAGMA foreign_keys = ON;

	CREATE TABLE users (id INTEGER PRIMARY KEY, username, password);
	INSERT INTO users VALUES (1, \'bohwaz\', \'verySecret\');

	CREATE TABLE clients (id TEXT, secret TEXT);
	INSERT INTO clients VALUES (\'my_app\', \'my_secret\');

	CREATE TABLE refresh_tokens (
		user_id REFERENCES users(id),
		token TEXT PRIMARY KEY,
		expiry TEXT);
	CREATE TABLE access_tokens (
		user_id REFERENCES users(id),
		token TEXT PRIMARY KEY,
		expiry TEXT,
		refresh_token REFERENCES refresh_tokens(token) ON DELETE CASCADE);
');

$o = new OAuth2_Server;

// Check that we can't run before setting callbacks
try {
	$o->handleRequest();
}
catch (\Exception $e)
{
	Test::equals('LogicException', get_class($e));
}

$o->toggleReturnMode(true);

$o->setCallback('store_token', function ($type, $auth_response, $data) use ($db) {
	if ($type == 'refresh_token')
	{
		// Expire old refresh token and linked access token (by cascade)
		$st = $db->prepare('DELETE FROM refresh_tokens WHERE token = ?;');
		$st->bindValue(1, $auth_response->refresh_token);
		$st->execute();
	}

	$user_id = isset($auth_response->user_id) ? $auth_response->user_id : null;

	$st = $db->prepare('INSERT INTO refresh_tokens VALUES (?, ?, ?);');
	$st->bindValue(1, $user_id);
	$st->bindValue(2, $data->refresh_token);
	$st->bindValue(3, $data->refresh_expiry->format(DATE_W3C));
	$st->execute();

	$st = $db->prepare('INSERT INTO access_tokens VALUES (?, ?, ?, ?);');
	$st->bindValue(1, $user_id);
	$st->bindValue(2, $data->access_token);
	$st->bindValue(3, $data->access_expiry->format(DATE_W3C));
	$st->bindValue(4, $data->refresh_token);
	$st->execute();

	return true;
});

$o->setCallback('auth_client', function ($id, $secret) use ($db) {
	$st = $db->prepare('SELECT 1 FROM clients WHERE id = ? AND secret = ?;');
	$st->bindValue(1, $id);
	$st->bindValue(2, $secret);
	$res = $st->execute();

	return $res->fetchArray() ? (object) ['user_id' => null] : false;
});

$o->setCallback('auth_password', function ($username, $password) use ($db) {
	$st = $db->prepare('SELECT id AS user_id FROM users WHERE username = ? AND password = ?;');
	$st->bindValue(1, $username);
	$st->bindValue(2, $password);
	$res = $st->execute();

	$row = $res->fetchArray();

	return $row ? (object) $row : false;
});


$o->setCallback('check_access_token', function ($token) use ($db) {
	// Expire old tokens
	$db->exec('DELETE FROM access_tokens WHERE datetime(expiry) < datetime();');

	$st = $db->prepare('SELECT token AS access_token, user_id FROM access_tokens WHERE token = ?;');
	$st->bindValue(1, $token);
	$res = $st->execute();

	$row = $res->fetchArray(\SQLITE3_ASSOC);

	return $row ? (object) $row : false;
});

$o->setCallback('check_refresh_token', function ($token) use ($db) {
	// Expire old tokens
	$db->exec('DELETE FROM refresh_tokens WHERE datetime(expiry) < datetime();');

	$st = $db->prepare('SELECT token AS refresh_token, user_id FROM refresh_tokens WHERE token = ?;');
	$st->bindValue(1, $token);
	$res = $st->execute();

	$row = $res->fetchArray(\SQLITE3_ASSOC);

	return $row ? (object) $row : false;
});

// No request, nothing to do
Test::equals(false, $o->handleRequest());

// Wrong grant type
$_POST['grant_type'] = 'wrong';
check_error('unsupported_grant_type', $o->handleRequest());

// Unsecure connection
$_POST['grant_type'] = 'client_credentials';
check_error('unsupported_over_http', $o->handleRequest());

// Disable secure default
$o->toggleForceSecureClient(false);

// Missing client credentials
$_POST['grant_type'] = 'client_credentials';
check_error('invalid_client', $o->handleRequest());

// Wrong client credentials
$_POST['client_id'] = 'my_app';
$_POST['client_secret'] = 'lolwat';
check_error('invalid_client', $o->handleRequest());

// Correct client credentials
$_POST['client_secret'] = 'my_secret';

$return = $o->handleRequest();

Test::assert(isset($return->token_type, $return->access_token, $return->expires_in, $return->refresh_token));
Test::equals('bearer', $return->token_type);
Test::equals(3600, $return->expires_in);

$access_token = $return->access_token;
$refresh_token = $return->refresh_token;

// Shouldn't be authorized
Test::assert(!$o->isAuthorized());

$_POST = [];

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token;

// Should be ok now
Test::assert($o->isAuthorized());
Test::assert(property_exists($o->getAuthorizedUser(), 'user_id'));

// Reset authorization
$o->unauthorize();

$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . $access_token;

// Invalid auth
Test::assert(!$o->isAuthorized());

// Renew token
$_POST['grant_type'] = 'refresh_token';

// Missing token
check_error('invalid_token', $o->handleRequest());

$_POST['refresh_token'] = $refresh_token;
unset($_SERVER['HTTP_AUTHORIZATION']);

// this time it's ok
$return = $o->handleRequest();

Test::assert(isset($return->token_type, $return->access_token, $return->expires_in, $return->refresh_token));
Test::equals('bearer', $return->token_type);
Test::equals(3600, $return->expires_in);

$access_token = $return->access_token;
$refresh_token = $return->refresh_token;

$_POST = [];

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $access_token;

// Should be ok now
Test::assert($o->isAuthorized());
Test::hasProperty('user_id', $o->getAuthorizedUser());

////////////////////
// Now check the password grant!
$o->unauthorize();

$_POST = [
	'grant_type' => 'password',
	'username'   => 'bohwaz',
	'password'   => 'wrongSecret',
];

$return = $o->handleRequest();

// Should fail as the password is wrong
check_error('invalid_grant', $return);

$_POST['password'] = 'verySecret';

$return = $o->handleRequest();

Test::assert(isset($return->token_type, $return->access_token, $return->expires_in, $return->refresh_token));
Test::equals('bearer', $return->token_type);
Test::equals(3600, $return->expires_in);

$_POST = [];
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $return->access_token;

// Should be ok now
Test::assert($o->isAuthorized());
Test::hasProperty('user_id', $o->getAuthorizedUser());
Test::equals(1, (int) $o->getAuthorizedUser()->user_id);