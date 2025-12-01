<?php

use KD2\Test;
use KD2\Mail\DKIM;

require __DIR__ . '/_assert.php';

test_body_canonicalization();
test_headers_canonicalization();

test_sign();
test_valid();

function test_body_canonicalization()
{
	$dkim = new DKIM;

	$expected = 'uoq1oCgLlTqpdDX/iUbLy7J1Wic=';
	$result = $dkim->canonicalizeBody("\r\n\r\n", 'simple');
	$result = base64_encode(sha1($result, true));
	Test::strictlyEquals($expected, $result);

	$expected = '2jmj7l5rSw0yVb/vlWAYkK/YBwk=';
	$result = $dkim->canonicalizeBody("\r\n\r\n", 'relaxed');
	$result = base64_encode(sha1($result, true));
	Test::strictlyEquals($expected, $result);
}

function test_headers_canonicalization()
{
	$dkim = new DKIM;
	$replace = function (string $source): string {
		$source = preg_replace('/\r|\n/', '', $source);
		$source = preg_replace('/[ ]*<SP>[ ]*/', ' ', $source);
		$source = preg_replace('/[ ]*<HTAB>[ ]*/', "\t", $source);
		$source = preg_replace('/[ ]*<CRLF>[ ]*/', "\r\n", $source);
		return $source;
	};

	$source = "A: <SP> X <CRLF>
B <SP> : <SP> Y <HTAB><CRLF>
                <HTAB> Z <SP><SP><CRLF>";
	$source = $replace($source);

	$target = "A: <SP> X <CRLF>
B <SP> : <SP> Y <HTAB><CRLF>
       <HTAB> Z <SP><SP><CRLF>";

	$result = $dkim->canonicalizeHeaders($source, 'simple');
	Test::strictlyEquals($replace($target), $result);

	$target = "a:X <CRLF>
b:Y <SP> Z <CRLF>";

	$result = $dkim->canonicalizeHeaders($source, 'relaxed');
	Test::strictlyEquals($replace($target), $result);
}

function test_sign()
{
	$pkey = '
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQDEMHFTrXbQMJ8X
rz2Bqhu0gK3v/SnQKWZNVofXducDo0/PcjS28rX9XQR+cDKs1cQBaT+4mIIu21xv
ylmcs9CqSlHXJcq3QXZ9coAq1zxkcOMjk7IZmnrDcaAP1rwQGz5ut9wlIZSiuh1Y
RPlzZNRybr5iOCPtaWwK4WWqGepj+LthOZPKaq9tlNB9HcI+An1SXOA7GZZtk10t
PD6r0DnUHZyWthBEF8zOIzCxzjrAQbdeeAGTYE0yxvKVFwT4vr77EeLYzYed+Nuf
pJoZXgTU3DNW2E1SsoG1VOQK5WTTLs/XmAK7E03EfXX81r2eUVWVOruc9K9DiniB
KFpRRUjlAgMBAAECggEAH1Pg9MyxOUNaVCzedHMWY3dczwKtB5lkxZq4rFZHQ1Rz
rRt+gWw2VVPiINKHtQOZfeQxkaeAujy7APrd3mD0RA0EDofxW9vvMM702mJuOVex
XX/7I42AZu8g8QaIF0ZSbNmdJKy9EFMJ1ouFDcEfD3rRmdt/GS0JXJ9rXYMv43CP
q7T79307t5e2Shm+2Tf7pp6THMoafhIku0cK3euIS3CaOwkKbu7OTWC4TccrA+6p
dZSmGITuXfqCrfnthIg5RvWgkA0WfEuHiw1xnyQebP0iFedUucBTH3CYhnBYQbLu
SY14Kww0435qCkepId76QgpRG+13XiFilIJ0cyptzQKBgQDx0ZJ4u6say4ldvj77
XzJLRuEVuofDh0Z5SvILVHt1q2eALwK3lgmFzAfROwca+uFHfocVpP/0bC1XYN5e
GCkoJ+r4x0gOfvp3+QvLCxjKNliUpxaKl6X0MMhbbclID5NVpevJV7q8GwVqyGwW
cA56BWS1/QL9U94mcC2vQF45OwKBgQDPsdXWSC5wh9W7QcrqHydbyaAAh+10FCbn
nczmbpXOiCRfGURlxJq19zI0Roa4K321g3hg25aoAxMFoN5sDuXtvE5u1ZKF4ZFp
rcPF3jU9uSoiXgR6nPvACeBeEpT262c5zAM4OcZoOteUyg+eNuTL1QB8NoVqthgu
y0KdCKlkXwKBgQCeiEtjVX1uWqOazn+R4q4hRb2ItjoNSOE94ZFfNiqeURnQooFA
hF+H1OQHGpCk8nbAnUXIPd0Di+wJzdraizJoPgtAv70Qq9Re1OoTWhoNb6WRBx2z
DIpi1Zx0vDvqPzPTQayb2iT07y4U/IJID3yeKG0HFnFgTRxlzMNWkndzQwKBgQCj
tEAgDfTMrcRBPLJ7puKW7m02/eyjud6QYUjHuBJMH/LLIldO/5ASLS1dFtnJAD6t
e1q+vVYaR5IOtaWa9oM0x1/q7Fv5Oroq2dOdem/snq4fOIu/OU0VKLO4cl0H4rQS
UkOXZbKFJRwXIsz8n7fnEZS4UyEF41FcUMnIjLM3cQKBgBYuCvFMNAAC0EoO79IC
0r/cSVZJPlpdUdNfV2J6R7u5Oi15OcJ+2CJQRUp+BGSEojU6yqrzi1D2OUnTKblj
viSCdBumQPgK72mPxVNHsXonmsH/3dc24tsE6etxFk+c7SAfX3G/mzd8rZbWFYCM
/zIAUdR+m2oFOBVJ9ULFzA55
-----END PRIVATE KEY-----';

	$message = 'From: A <a@example.com>
To: b@example.com
Cc: =?UTF-8?Q?Fran=C3=A7ois?= <f@email.fr>, Bruno
 <b@email.com>
Content-Type: text/plain
Subject: Coucou

Hello!



';

	$dkim = new DKIM;
	var_dump($dkim->sign($message, 'test', 'example.com', $pkey));
}

function test_valid()
{
	$dkim = new DKIM;
	Test::strictlyEquals(null, $dkim->verify(file_get_contents('data/mails/dkim/01.eml')));
}
