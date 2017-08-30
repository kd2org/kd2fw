<?php

require __DIR__ . '/_assert.php';

use KD2\Security_OTP as OTP;
use KD2\Test;

assert(
	strlen($secret = OTP::getRandomSecret()) == 16 && (bool)$secret === true,
	'Generate random secret'
);

assert(
	($code = OTP::TOTP($secret)),
	'Generate TOTP code'
);

assert(
	OTP::TOTP($secret, $code) === true,
	'TOTP validation code'
);

assert(
	OTP::TOTP($secret, '3' . $code . '2') === false,
	'TOTP validation code failure'
);

assert(
	OTP::TOTP($secret, '3' . $code . '2', time() + 60) === false,
	'TOTP validation time failure'
);

$code = OTP::TOTP($secret, null, time()-30);

assert(
	OTP::TOTP($secret, $code) === true,
	'TOTP validation code with 30 seconds drift'
);

$count = 42;

assert(
	($code = OTP::HOTP($secret, $count)),
	'Generate HOTP code'
);

assert(
	OTP::HOTP($secret, $count, $code) === true,
	'HOTP validation code'
);

assert(
	OTP::TOTP($secret, $count + 1, $code) === false,
	'HOTP validation code failure due to count'
);

assert(
	OTP::getTimeFromNTP() > time() - 3600*24,
	'NTP time is invalid'
);