<?php

use KD2\Test;
use KD2\Mail_Message;

require __DIR__ . '/_assert.php';

test_headers();
test_headers_multiline();
test_length();

/**
 * Check that maximum line length of 998 is enforced
 */
function test_length()
{
	$msg = new Mail_Message;
	$msg->setHeader('X-Test', str_repeat('aAbB', 1000));
	$msg->setBody(str_repeat('cCdD', 1000));

	Test::assert(!preg_match("/[^\r\n]{999,}/", $msg->outputHeaders()));
	Test::assert(!preg_match("/[^\r\n]{999,}/", $msg->outputBody()));
}

/**
 * Check that we do keep line breaks in headers when outputting
 * but not when using getHeader()
 */
function test_headers_multiline()
{
	$headers = <<<EOF
Received: from mail76-20.mail.maxony.net ([62.50.76.20])
	by mail.xx.org with esmtps (TLS1.2:ECDHE_RSA_AES_256_GCM_SHA384:256)
	(Exim 4.84_2)
	(envelope-from <2814597.aurelie=tobeflash.com@mpdkim2.ch>)
	id 1hGK29-0005Xo-L5
	for xx@xx.net; Tue, 16 Apr 2019 11:05:43 +0200
Received: by mail76-17.mail.maxony.net id hmmdea24meou for <xx@xx.net>; Tue, 16 Apr 2019 09:46:50 +0200 (envelope-from <2814597.aurelie=tobeflash.com@mpdkim2.ch>)
X-Spam-Threshold: 5
X-Spam-Score: 15.4
X-Spam-Score-Int: 154
X-Spam-Bar: +++++++++++++++
X-Spam-Report: score                  = 15.4
 bayes-score            = 0.9506
 bayes-token-summary    = Tokens: new, 150; hammy, 6; neutral, 56; spammy, 20.
 bayes-token-spam-count = 20
 bayes-token-ham-count  = 6
 bayes-token-spam       = idcamp, cliquez-ici, désirez, cliquezici, visualisez, correctement, cliquez, 48h, usb, I*:capacits, UD:jpg, donnes, EXPRESS, données, H*R:D*com
 bayes-token-ham        = H*Ad:U*aurelie, l’e-mail, H*ct:multipart, 2018, H*ct:alternative, uns
 bayes-auto-learned     = no autolearn_force=no 5.245
 last-external-host     = mail76-20.mail.maxony.net [62.50.76.20] HELO=mail76-20.mail.maxony.net
 possible-languages     = 
 relayed-countries      = _RELAYCOUNTRY_
 ---- ---------------------- --------------------------------------------------
  4.0 USER_IN_BLACKLIST      From: address is in the user's black-list
  5.0 BAYES_95               BODY: Bayes spam probability is 95 to 99%
                             [score: 0.9506]
  2.0 FR_HOWTOUNSUBSCRIBE    BODY: French: how to unsubscribe
 -0.0 SPF_PASS               SPF: sender matches SPF record
  0.0 HEADER_FROM_DIFFERENT_DOMAINS From and EnvelopeFrom 2nd level
                             mail domains are different
  1.1 URI_HEX                URI: URI hostname has long hexadecimal sequence
  0.1 HTML_MESSAGE           BODY: HTML included in message
  0.4 MIME_HTML_MOSTLY       BODY: Multipart message mostly text/html MIME
  0.8 MPART_ALT_DIFF         BODY: HTML and text parts are different
  1.0 HTML_IMAGE_RATIO_02    BODY: HTML has a low ratio of text to image
                             area
  0.0 HTML_FONT_LOW_CONTRAST BODY: HTML font color similar or
                             identical to background
  1.0 FROM_EXCESS_BASE64     From: base64 encoded unnecessarily
Content-Type: text/plain
EOF;

	$msg = new Mail_Message;
	$msg->parse(trim($headers));
	Test::assert(preg_match("/[^\n\r]{998,}/", $msg->getHeader('X-Spam-Report')));
	$expected = substr_count(trim($headers), "\n");
	var_dump($headers, $msg->outputHeaders());
	Test::equals($expected, substr_count($msg->outputHeaders(), "\n"));
}

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

