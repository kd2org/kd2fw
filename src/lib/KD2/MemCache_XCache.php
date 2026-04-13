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
 * Cache data to XCache user cache
 * WARNING: The cache is shared by all users of the same server by default.
 * Use the following php.ini config to have a separate cache for every vhost:
 * xcache.var_namespace_mode = 1
 * xcache.var_namespace = "DOCUMENT_ROOT"
 *
 * See https://xcache.lighttpd.net/ticket/287#comment:13 for details
 * @deprecated
 */

class MemCache_XCache extends MemCache
{
	public function checkSetup()
	{
		return function_exists('xcache_set');
	}

	public function exists($key)
	{
		return xcache_isset($this->prefix . $key);
	}

	public function get($key)
	{
		return xcache_get($this->prefix . $key);
	}

	public function set($key, $value, $ttl = 0)
	{
		return xcache_set($this->prefix . $key, $value, $ttl);
	}

	public function inc($key, $step = 1)
	{
		return xcache_inc($this->prefix . $key, (int) $step);
	}

	public function dec($key, $step = 1)
	{
		return xcache_dec($this->prefix . $key, (int) $step);
	}

	public function delete($key)
	{
		return xcache_unset($this->prefix . $key);
	}
}