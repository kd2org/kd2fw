--TEST--
ErrorManager: assertions
--SKIPIF--
<?php

if (ini_get('zend.assertions') < 1 && PHP_MAJOR_VERSION >= 7)
{
	die("Skip: zend.assertions is disabled");
}

?>
--FILE--
<?php

require __DIR__ . '/_inc.php';

assert_options(ASSERT_ACTIVE, 1);
assert_options(ASSERT_WARNING, 1);

assert(0 == 1, 'My test');

?>
--EXPECTF--
 /!\ PHP error 
Warning: assert(): My test failed
#0 %s/errormanager/assertion.phpt.php(8)
#1 %s/errormanager/assertion.phpt.php(8): assert(bool(false), string(7) "My test")
