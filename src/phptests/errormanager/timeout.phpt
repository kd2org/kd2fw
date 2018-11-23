--TEST--
ErrorManager: timeout
--INI--
max_execution_time=1
--FILE--
<?php

require __DIR__ . '/_inc.php';

while (true) {}

?>
--EXPECT--