<?php

require __DIR__ . '/_assert.php';

use KD2\SMTP;
use KD2\Test;

const SMTP_SERVER = 'smtp.mailtrap.io';
const SMTP_USERNAME = '7d565d93aadbec';
const SMTP_PASSWORD = 'f103803c179a2e';

test_connect('smtp.gmail.com', 465, SMTP::TLS);
test_connect('smtp.gmail.com', 465, SMTP::SSL);
test_connect('smtp.gmail.com', 465, 'tlsv1.2'); // Manual protocol

test_smtp(SMTP_SERVER, 2525, SMTP_USERNAME, SMTP_PASSWORD);
test_smtp(SMTP_SERVER, 465, SMTP_USERNAME, SMTP_PASSWORD, SMTP::STARTTLS);

function test_smtp($server, $port, $username = null, $password = null, $secure = SMTP::NONE)
{
	$smtp = new SMTP($server, $port, $username, $password, $secure, 'local.host');

	Test::isInstanceOf(\KD2\SMTP::class, $smtp);

	$msg = $smtp->buildMessage('"Mr. Sémètépè ! test@email.co" <kd2@mailtrap.io>', 'Test test éàç…&$', 'Coucou ! éhéhéé', [
		'From' => '"Expéditeur mail" <autre@fakeserver.email>',
	]);

	Test::assert(strpos($msg->message, 'To: <kd2@mailtrap.io>') !== false);
	Test::assert(count($msg->recipients) === 1);

	$return = $smtp->send('"Mr. Sémètépè ! test@email.co" <kd2@mailtrap.io>', 'Test test éàç…&$', 'Coucou ! éhéhéé', [
		'From' => '"Expéditeur mail" <autre@fakeserver.email>',
	]);

	Test::assert($return === true);

	Test::assert($smtp->disconnect() === true);
}

function test_connect($server, $port, $secure)
{
	$smtp = new SMTP($server, $port, null, null, $secure, 'local.host');
	Test::assert($smtp->connect() == true);
	Test::assert($smtp->disconnect() == true);
}