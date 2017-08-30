<?php

use KD2\ErrorManager as EM;

define('KD2FW_ROOT', __DIR__ . '/../lib/kd2');
define('DATA_DIR', __DIR__ . '/data');

function __autoload($class)
{
	$class = explode('\\', $class);
	$class = array_pop($class);
	$path = KD2FW_ROOT . '/' . $class . '.php';

	require_once $path;
}

if (file_exists(__DIR__ . '/error.log'))
{
	unlink(__DIR__ . '/error.log');
}

EM::setLogFile(__DIR__ . '/error.log');
EM::enable();

// Check error log at shutdown
register_shutdown_function(function () {
	if (file_exists(__DIR__ . '/error.log'))
	{
		echo '[FAIL] Error log is not empty: ' . __DIR__ . '/error.log' . PHP_EOL;
	}
});