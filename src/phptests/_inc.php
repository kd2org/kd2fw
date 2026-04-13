<?php

namespace KD2;

define('KD2FW_ROOT', __DIR__ . '/../lib/KD2');

spl_autoload_register(function ($class)
{
	$class = explode('\\', $class);
	$class = array_pop($class);
	$path = KD2FW_ROOT . '/' . $class . '.php';

	require_once $path;
});
