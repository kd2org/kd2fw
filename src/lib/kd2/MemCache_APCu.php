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
 * Cache user data with APCu
 *
 * Note that the cache is shared by the same process 
 * and can result in being shared by multiple vhosts
 * or users.
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