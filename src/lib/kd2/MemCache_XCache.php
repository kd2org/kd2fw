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
 * Cache data to XCache user cache
 * WARNING: The cache is shared by all users of the same server by default.
 * Use the following php.ini config to have a separate cache for every vhost:
 * xcache.var_namespace_mode = 1
 * xcache.var_namespace = "DOCUMENT_ROOT"
 *
 * See https://xcache.lighttpd.net/ticket/287#comment:13 for details
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