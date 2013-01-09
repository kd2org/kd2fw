<?php

namespace KD2;

if (!defined('ALLOW_REGISTER_GLOBALS'))
{
	define('ALLOW_REGISTER_GLOBALS', false);
}

if (ini_get('register_globals'))
{
	if (!ALLOW_REGISTER_GLOBALS)
	{
		throw new Exception('register_globals is deprecated and should not be enabled, but it is, please disable it.');
	}

    foreach ($_SESSION as $key=>$value)
    {
        if (isset($GLOBALS[$key]))
        {
            unset($GLOBALS[$key]);
        }
    }
}