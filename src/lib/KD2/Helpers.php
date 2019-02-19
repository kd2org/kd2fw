<?php
/*
    This file is part of KD2FW -- <http://dev.kd2.org/>

    Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
    All rights reserved.

    KD2FW is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Foobar is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

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
			if (is_object($src) && !($src instanceOf \ArrayAccess) && property_exists($src, $key))
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
			if (is_object($src) && !($src instanceOf \ArrayAccess) && property_exists($src, $key))
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