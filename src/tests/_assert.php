<?php

use KD2\ErrorManager as EM;

define('KD2FW_ROOT', __DIR__ . '/../lib');
define('DATA_DIR', __DIR__ . '/data');

spl_autoload_register(function ($class)
{
	$class = str_replace('\\', '/', $class);
	$path = KD2FW_ROOT . '/' . $class . '.php';

	if (!file_exists($path)) {
		throw new \RuntimeException('Cannot find file: ' . $path);
	}

	require_once $path;
});

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