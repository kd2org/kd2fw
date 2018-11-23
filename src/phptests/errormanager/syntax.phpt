--TEST--
ErrorManager: syntax error
--FILE--
<?php

require __DIR__ . '/_inc.php';

function lol (array $test)
{
}

lol('bla');

?>
--EXPECT--