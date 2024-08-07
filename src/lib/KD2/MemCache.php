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

/**
 * @deprecated
 */
abstract class MemCache
{
	protected $prefix = null;
	protected $default_ttl = null;
	protected $options = [];

	public function __construct($prefix = null, $default_ttl = 0, $options = [])
	{
		if (!$this->checkSetup())
		{
			throw new \RuntimeException('Required extension is not installed: ' . get_class($this));
		}

		$this->prefix = (string) $prefix;
		$this->default_ttl = (int) $default_ttl;
		$this->options = $options;
	}

	abstract public function checkSetup();
	abstract public function get($key);
	abstract public function set($key, $value, $ttl = 0);
	abstract public function delete($key);
	abstract public function exists($key);
	abstract public function inc($key, $step = 1);
	abstract public function dec($key, $step = 1);

	final public function __unset($key)
	{
		return $this->delete($key);
	}

	final public function __isset($key)
	{
		return $this->test($key);
	}

	final public function __get($key)
	{
		return $this->get($key);
	}
	
	final public function __set($key, $value)
	{
		return $this->set($key, $value);
	}
}