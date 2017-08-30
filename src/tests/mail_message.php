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
	Test::equals('Mary Smith <mary@example.net>', implode('', $msg->getTo()));
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

	/* See RFC 5322 § Appendix A.1.3 */
	$raw = 'From: Pete <pete@silly.example>
To: A Group:Ed Jones <c@a.test>,joe@where.test,John <jdoe@one.test>;
Cc: Undisclosed recipients:;
Date: Thu, 13 Feb 1969 23:32:54 -0330
Message-ID: <testabcd.1234@silly.example>';

	$msg = new Mail_Message;
	$msg->parse($raw);

	Test::equals('Pete <pete@silly.example>', $msg->getHeader('From'));
	Test::equals('A Group:Ed Jones <c@a.test>,joe@where.test,John <jdoe@one.test>;', $msg->getHeader('To'));
	Test::equals('Ed Jones <c@a.test>##joe@where.test##John <jdoe@one.test>', implode('##', $msg->getTo()));
	Test::equals('Undisclosed recipients:;', $msg->getHeader('cc'));
	Test::equals(0, count($msg->getCc()));
	Test::equals('<testabcd.1234@silly.example>', $msg->getHeader('Message-id'));
	Test::equals('1969-02-13 23:32:54', $msg->getDate()->format('Y-m-d H:i:s'));

	/* See RFC 5322 § Appendix A.2 */
	$raw = 'From: Mary Smith <mary@example.net>
To: John Doe <jdoe@machine.example>
Reply-To: "Mary Smith: Personal Account" <smith@home.example>
Subject: Re: Saying
 Hello
Date: Fri, 21 Nov 1997 10:01:10 -0600
Message-ID: <3456@example.net>
In-Reply-To: <1234@local.machine.example>
References: <1234@local.machine.example>
 <abcd@local.machine.example>';

	$msg = new Mail_Message;
	$msg->parse($raw);

	Test::equals('Mary Smith <mary@example.net>', $msg->getHeader('From'));
	Test::equals('John Doe <jdoe@machine.example>', $msg->getHeader('To'));
	Test::equals('"Mary Smith: Personal Account" <smith@home.example>', $msg->getHeader('Reply-To'));
	Test::equals('Re: Saying Hello', $msg->getHeader('Subject'));
	Test::equals('3456@example.net', $msg->getMessageId());
	Test::equals('1234@local.machine.example', $msg->getInReplyTo());
	Test::equals('1234@local.machine.example##abcd@local.machine.example', implode('##', $msg->getReferences()));

	/* See RFC 2047 § 8 */
	$raw = 'From: =?US-ASCII?Q?Keith_Moore?= <moore@cs.utk.edu>
Date     :  27 Aug 76 09:32 PDT
To: =?ISO-8859-1?Q?Keld_J=F8rn_Simonsen?= <keld@dkuug.dk>
CC: =?ISO-8859-1?Q?Andr=E9?= Pirard <PIRARD@vm1.ulg.ac.be>
Subject: =?ISO-8859-1?B?SWYgeW91IGNhbiByZWFkIHRoaXMgeW8=?=
 =?ISO-8859-2?B?dSB1bmRlcnN0YW5kIHRoZSBleGFtcGxlLg==?=';

	$msg = new Mail_Message;
	$msg->parse($raw);

	Test::equals('Keith Moore <moore@cs.utk.edu>', $msg->getHeader('from'));
	Test::equals('Keld Jørn Simonsen <keld@dkuug.dk>', $msg->getHeader('to'));
	Test::equals('André Pirard <PIRARD@vm1.ulg.ac.be>', $msg->getHeader('cc'));
	Test::equals('If you can read this you understand the example.', $msg->getHeader('subject'));
}

