<?php

function obj_has($src, $pattern)
{
	if (!is_object($src) && !is_array($src))
	{
		throw new \InvalidArgumentException('Source variable must be an object or an array');
	}

	$keys = explode('.', $pattern);

	foreach ($keys as $key)
	{
		if (is_object($src) && !($src instanceOf ArrayAccess) && property_exists($src, $key))
		{
			$src = $src->$key;
		}
		elseif (is_array($src) && array_key_exists($key, $src))
		{
			$src = $src[$key];
		}
		else
		{
			// Not found
			return false;
		}
	}

	return true;
}

function obj_get($src, $pattern, $default = null)
{
	if (!is_object($src) && !is_array($src))
	{
		throw new \InvalidArgumentException('Source variable must be an object or an array');
	}

	$keys = explode('.', $pattern);

	foreach ($keys as $key)
	{
		if (is_object($src) && !($src instanceOf ArrayAccess) && property_exists($src, $key))
		{
			$src = $src->$key;
		}
		elseif (is_array($src) && array_key_exists($key, $src))
		{
			$src = $src[$key];
		}
		else
		{
			// Not found
			return $default;
		}
	}

	return $src;
}

