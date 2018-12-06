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
 * Cache user data with files, since PHP 5.5 will use OpCache and will be faster than MemCache!
 * (and so it is still a memory cache)
 *
 */
class MemCache_File extends MemCache
{
	public function checkSetup()
	{
		if (empty($this->options['cache_dir']))
		{
			throw new \Exception('options.cache_dir is not set.');
		}

		if (!is_writeable($this->options['cache_dir']))
		{
			throw new \Exception($this->options['cache_dir'] . ' directory does not exists or is not writable');
		}
	}

	protected function getPath($key)
	{
		return $this->options['cache_dir'] . DIRECTORY_SEPARATOR . sha1($this->prefix . $key);
	}

	public function exists($key)
	{
		return file_exists($this->getPath($key));
	}

	public function get($key)
	{
		include file_exists($this->getPath($key));
		return $data;
	}

	public function set($key, $value, $ttl = 0)
	{
		return file_put_contents($this->getPath($key), '<?php $data = ' . var_export($value) . ';');
	}

	public function inc($key, $step = 1)
	{
		return $this->set($key, $this->get($key) + $step);
	}

	public function dec($key, $step = 1)
	{
		return $this->set($key, $this->get($key) - $step);
	}

	public function delete($key)
	{
		unlink($this->getPath($key));
	}
}