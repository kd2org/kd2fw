<?php

namespace KD2;

class Helpers
{
	static public function obj_has($src, $pattern)
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

	static public function obj_get($src, $pattern, $default = null)
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

	/**
	 * Tries to match a shorthand name to a complete name from a list
	 * of possible names (useful for CLI scripts, like fossil!)
	 *
	 * @param  string $name    Name to match
	 * @param  array  $matches List of possible matches
	 * @return mixed FALSE if no match is found, a string if only one possible match exists, and an array if multiple matches are possible
	 */
	static public function shorthandMatch($name, Array $matches)
	{
		$name = strtolower($name);

		$possibles = [];

		foreach ($matches as $match)
		{
			if ($match == $name)
			{
				return $match;
			}

			if (strpos($match, $name) === 0)
			{
				$possibles[] = $match;
			}
		}

		$count = count($possibles);

		if ($count == 0)
		{
			return false;
		}
		elseif ($count == 1)
		{
			return $possibles[0];
		}
		else
		{
			return $possibles;
		}
	}
}