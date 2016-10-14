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

use KD2\ErrorManager as EM;

class Test
{
	static public function assert($test, $message = 'Assertion failed')
	{
		if (!$test)
		{
			throw new TestException('[FAIL] ' . $message);
		}

		echo '.';
	}

	static public function equals($expected, $result, $message = '')
	{
		assert(is_string($message));

		self::assert($expected == $result, 
			sprintf("Equals condition failed: %s\n--- %s\n+++ %s", 
				$message, EM::dump($expected), EM::dump($result)
			)
		);
	}

	static public function isObject($object, $message = '')
	{
		self::assert(is_object($object), 
			sprintf("Not an object: %s\n%s",
				$message, EM::dump($object)
			)
		);
	}

	static public function isArray($array, $message = '')
	{
		self::assert(is_array($array),
			sprintf("Not an array: %s\n%s",
				$message, EM::dump($object)
			)
		);
	}

	static public function isInstanceOf($expected, $result, $message = '')
	{
		self::isObject($result, $message);

		$result_name = get_class($result);
		$expected_name = is_object($expected) ? get_class($expected_name) : $expected;

		self::assert($result instanceof $expected,
			sprintf("'%s' is not an instance of '%s': %s\n%s",
				$result_name, $expected_name, $message, EM::dump($result)
			)
		);
	}

	static public function hasKey($key, Array $array, $message = '')
	{
		self::assert(array_key_exists($key, $array),
			sprintf("Array have no key '%s': %s\n%s",
				$key, $message, EM::dump($array)
			)
		);
	}

	static public function hasProperty($property, $class, $message = '')
	{
		$name = is_object($class) ? get_class($class) : $class;

		self::assert(property_exists($class, $property), 
			sprintf("Class '%s' have no property '%s': %s\n%s",
				$name, $property, $message, EM::dump($class)
			)
		);
	}

	static public function runMethods($class)
	{
		$reflection = new \ReflectionClass($class);

		if (is_object($class))
		{
			$filter = \ReflectionMethod::IS_PUBLIC;
		}
		elseif (is_string($class))
		{
			$filter = \ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_STATIC;
		}
		else
		{
			throw new \InvalidArgumentException('Class argument must be an object or a string.');
		}
		
		$args = array_slice(func_get_args(), 1);

		foreach ($reflection->getMethods($filter) as $method)
		{
			// Skip special methods
			if (substr($method->name, 0, 4) != 'test')
			{
				continue;
			}

			call_user_func_array([$class, $method->name], $args);
		}
	}
}

class TestException extends \Exception
{
	public function __construct($message)
	{
		parent::__construct($message);

		// Get original test file/line
		foreach ($this->getTrace() as $trace)
		{
			if ($trace['file'] == __FILE__)
			{
				continue;
			}

			$this->file = $trace['file'];
			$this->line = $trace['line'];
			break;
		}
	}
}