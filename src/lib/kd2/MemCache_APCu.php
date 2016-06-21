<?php

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