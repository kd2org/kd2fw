<?php

use KD2\Test;
use KD2\Mail_Message;

require __DIR__ . '/_assert.php';

test_headers();

function test_headers()
{
	/* See RFC 5322 § Appendix A.1.1 */
	$raw = 'From: John Doe <jdoe@machine.example>
To: Mary Smith <mary@example.net>
Subject: Saying Hello
Date: Fri, 21 Nov 1997 09:55:06 -0600
Message-ID: <1234@local.machine.example>';

	$msg = new Mail_Message;
	$msg->parse($raw);

	Test::equals('John Doe <jdoe@machine.example>', $msg->getHeader('From'));
	Test::equals('Mary Smith <mary@example.net>', $msg->getHeader('To'));
	Test::equals('Saying Hello', $msg->getHeader('subject'));
	Test::equals('Fri, 21 Nov 1997 09:55:06 -0600', $msg->getHeader('Date'));
	Test::equals('<1234@local.machine.example>', $msg->getHeader('Message-id'));
	Test::equals('1997-11-21 09:55:06', $msg->getDate()->format('Y-m-d H:i:s'));

	/* See RFC 5322 § Appendix A.1.2 */
	$raw = 'From: "Joe Q. Public" <john.q.public@example.com>
To: Mary Smith <mary@x.test>, jdoe@example.org, "Who?, not me?" <one@y.test>
Cc: <boss@nil.test>, "Giant; \"Big\" Box" <sysservices@example.net>
Date: Tue, 1 Jul 2003 10:52:37 +0200
Message-ID: <5678.21-Nov-1997@example.com>';

	$msg = new Mail_Message;
	$msg->parse($raw);

	Test::equals('"Joe Q. Public" <john.q.public@example.com>', $msg->getHeader('From'));
	Test::equals('Mary Smith <mary@x.test>, jdoe@example.org, "Who?, not me?" <one@y.test>', $msg->getHeader('To'));
	Test::equals('Mary Smith <mary@x.test>##jdoe@example.org##"Who?, not me?" <one@y.test>', implode('##', $msg->getTo()));
	Test::equals('<boss@nil.test>, "Giant; \"Big\" Box" <sysservices@example.net>', $msg->getHeader('cc'));
	Test::equals('Tue, 1 Jul 2003 10:52:37 +0200', $msg->getHeader('Date'));
	Test::equals('<5678.21-Nov-1997@example.com>', $msg->getHeader('Message-id'));
	Test::equals('2003-07-01 10:52:37', $msg->getDate()->format('Y-m-d H:i:s'));
}

