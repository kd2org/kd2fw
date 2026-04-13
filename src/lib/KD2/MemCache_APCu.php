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
 * Cache user data with APCu
 *
 * Note that the cache is shared by the same process 
 * and can result in being shared by multiple vhosts
 * or users.
 * @deprecated
 */
class MemCache_APCu extends MemCache
{
	public function checkSetup()
	{
		return function_exists('apcu_store');
	}

	public function exists($key)
	{
		return apcu_exists($this->prefix . $key);
	}

	public function get($key)
	{
		return apcu_fetch($this->prefix . $key);
	}

	public function set($key, $value, $ttl = 0)
	{
		return apcu_store($this->prefix . $key, $value, $ttl);
	}

	public function inc($key, $step = 1)
	{
		return apcu_inc($this->prefix . $key, (int) $step);
	}

	public function dec($key, $step = 1)
	{
		return apcu_dec($this->prefix . $key, (int) $step);
	}

	public function delete($key)
	{
		return apcu_delete($this->prefix . $key);
	}
}