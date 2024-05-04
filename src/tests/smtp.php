<?php

require __DIR__ . '/_assert.php';

use KD2\SMTP;
use KD2\SMTP_Exception;
use KD2\Test;

const SMTP_SERVER = 'smtp.mailtrap.io';
const SMTP_USERNAME = '7d565d93aadbec';
const SMTP_PASSWORD = 'f103803c179a2e';

test_connect_disconnect('smtp.gmail.com', 465, SMTP::TLS);
test_connect_disconnect('smtp.gmail.com', 465, SMTP::SSL);
test_connect_disconnect('smtp.gmail.com', 465, 'tlsv1.2'); // Manual protocol

test_invalid_rcpt('gmail-smtp-in.l.google.com', 25, 'bohwazinvalid@gmail.com');

test_smtp(SMTP_SERVER, 2525, SMTP_USERNAME, SMTP_PASSWORD);
test_smtp(SMTP_SERVER, 465, SMTP_USERNAME, SMTP_PASSWORD, SMTP::STARTTLS);

function test_smtp($server, $port, $username = null, $password = null, $secure = SMTP::NONE)
{
	$smtp = new SMTP($server, $port, $username, $password, $secure, 'local.host');

	Test::isInstanceOf(\KD2\SMTP::class, $smtp);

	$msg = (object) $smtp->buildMessage('"Mr. Sémètépè ! test@email.co" <kd2@mailtrap.io>', 'Test test éàç…&$', 'Coucou ! éhéhéé', [
		'From' => '"Expéditeur mail" <autre@fakeserver.email>',
	]);

	Test::assert(strpos($msg->message, 'To: <kd2@mailtrap.io>') !== false);
	Test::assert(count($msg->to) === 1);

	$return = $smtp->send('"Mr. Sémètépè ! test@email.co" <kd2@mailtrap.io>', 'Test test éàç…&$', 'Coucou ! éhéhéé', [
		'From' => '"Expéditeur mail" <autre@fakeserver.email>',
	]);

	Test::strictlyEquals('250 2.0.0 Ok: queued', $return);

	$return = $smtp->send('"Mr. Sémètépè ! test@email.co" <kd2@mailtrap.io>', 'Test test éàç…&$ bis', 'Coucou ! éhéhéé BIS', [
		'From' => '"Expéditeur mail" <autre@fakeserver.email>',
	]);

	Test::strictlyEquals('250 2.0.0 Ok: queued', $return);
	Test::assert($smtp->count() === 2);

	$smtp->disconnect();
	Test::assert($smtp->isConnected() === false);
}

function test_invalid_rcpt($server, $port, $rcpt)
{
	$smtp = new SMTP($server, $port);

	try {
		$smtp->send('"Coucou" <' . $rcpt . '>', 'Test coucou !', 'Salut ça va ?', ['From' => 'bohwaz@kd2.org']);
		Test::assert(false, 'Missing exception');
	}
	catch (SMTP_Exception $e) {
		Test::strictlyEquals($rcpt, $e->getRecipient());
		Test::strictlyEquals(550, $e->getCode());
	}
}

function test_connect($server, $port, $secure)
{
	$smtp = new SMTP($server, $port, null, null, $secure, 'local.host');
	$smtp->connect();
	Test::assert($smtp->isConnected() === true);
	return $smtp;
}

function test_connect_disconnect($server, $port, $secure)
{
	$smtp = test_connect($server, $port, $secure);
	$smtp->disconnect();
	Test::assert($smtp->isConnected() === false);
}
