<?php

require __DIR__ . '/_assert.php';
require KD2FW_ROOT . '/ErrorManager.php';

use KD2\ErrorManager as EM;

EM::enable();
EM::setLogFile(__DIR__ . '/error.log');

function lol (array $test)
{
	throw new Exception('test', 0, new RuntimeException());
}

lol('bla');