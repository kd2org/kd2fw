<?php

namespace KD2;

abstract class MemCache
{
	protected $prefix = null;
	protected $default_ttl = null;

	public function __construct($prefix = null, $default_ttl = 0)
	{
		if (!$this->checkSetup())
		{
			throw new \RuntimeException('Required extension is not installed: ' . get_class($this));
		}

		$this->prefix = (string) $prefix;
		$this->default_ttl = (int) $default_ttl;
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