<?php

require __DIR__ . '/_inc.php';

assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_WARNING, 1);

if (!ini_get('zend.assertions') && PHP_MAJOR_VERSION >= 7)
{
	die("Fail: zend.assertions is disabled");
}

assert(0 == 1, 'My test');