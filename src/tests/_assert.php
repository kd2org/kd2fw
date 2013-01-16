<?php

define('KD2FW_ROOT', __DIR__ . '/../lib/kd2');
define('DATA_DIR', __DIR__ . '/data');

error_reporting(-1);

assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_WARNING, 0);
assert_options(ASSERT_QUIET_EVAL, 0);
assert_options(ASSERT_CALLBACK, 'assert_fail');

function test($assertion, $desc)
{
	assert($assertion . ' // ' . $desc . "\n");
}

function assert_fail($file, $line, $code, $desc = null)
{
	if (is_null($desc))
	{
		list($code, $desc) = explode('//', $code);
	}

    echo '[FAIL] ' . $file . ':' . $line . ': ' . trim($desc) . "\n";
}
