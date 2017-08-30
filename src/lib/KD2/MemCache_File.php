<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/
  
  Copyright (c) 2001-2016 BohwaZ <http://bohwaz.net/>
  All rights reserved.
  
  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:
  1. Redistributions of source code must retain the above copyright notice,
  this list of conditions and the following disclaimer.
  2. Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.
  
  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF
  THE POSSIBILITY OF SUCH DAMAGE.
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