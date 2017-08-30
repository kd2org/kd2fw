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