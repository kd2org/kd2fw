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

class Test
{
	static public function assert($test, $message = 'Assertion failed')
	{
		echo $test ? '.' : 'F';

		if (!$test)
		{
			throw new TestException($message);
		}
	}

	static public function assertf($format, array $args, $message = 'Assertion failed')
	{
		array_walk($args, function (&$arg) {
			$arg = var_export($arg, true);
		});

		$expression = vsprintf($format, $args);
		eval('$result = ' . $expression . ';');
		return self::assert($expression, $message);
	}

	static public function equals($expected, $result, $message = '')
	{
		self::assert($expected == $result, 
			sprintf("Equals condition failed: %s\n--- %s\n+++ %s", 
				$message, self::dump($expected), self::dump($result)
			)
		);
	}

	static public function strictlyEquals($expected, $result, $message = '')
	{
		self::assert($expected === $result, 
			sprintf("Strictly equals condition failed: %s\n--- %s\n+++ %s", 
				$message, self::dump($expected), self::dump($result)
			)
		);
	}

	static public function isObject($object, $message = '')
	{
		self::assert(is_object($object), 
			sprintf("Not an object: %s\n%s",
				$message, self::dump($object)
			)
		);
	}

	static public function isArray($array, $message = '')
	{
		self::assert(is_array($array),
			sprintf("Not an array: %s\n%s",
				$message, self::dump($object)
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
				$result_name, $expected_name, $message, self::dump($result)
			)
		);
	}

	static public function hasKey($key, Array $array, $message = '')
	{
		self::assert(array_key_exists($key, $array),
			sprintf("Array have no key '%s': %s\n%s",
				$key, $message, self::dump($array)
			)
		);
	}

	static public function hasProperty($property, $class, $message = '')
	{
		$name = is_string($class) ? $class : get_class($class);

		self::assert(property_exists($class, $property), 
			sprintf("Class '%s' have no property '%s': %s\n%s",
				$name, $property, $message, self::dump($class)
			)
		);
	}

	static public function exception($name, callable $callback, $message = '')
	{
		try
		{
			$callback();
		}
		catch (\Exception $e)
		{
			self::equals($name, get_class($e),
				sprintf("Exception '%s' doesn't match expected '%s'", get_class($e), $name));
		}
	}

	static public function runFile($file)
	{
		$classes = get_declared_classes();

		try {
			require_once $file;
		}
		catch (TestException $e) {
			$failed[] = [
				'class'     => $class,
				'file'      => $e->getFile(),
				'line'      => $e->getLine(),
				'assertion' => $e->getAssertion(),
				'message'   => $e->getMessage(),
				'trace'     => $e->getCallTraceAsString(),
			];
		}
		catch (\Throwable $e) {
			$failed[] = [
				'class'     => $class,
				'file'      => $e->getFile(),
				'line'      => $e->getLine(),
				'assertion' => 'PHP code',
				'message'   => $e->getMessage(),
				'trace'     => $e->getTraceAsString(),
			];
		}
		catch (\Exception $e) {
			$failed[] = [
				'class'     => $class,
				'file'      => $e->getFile(),
				'line'      => $e->getLine(),
				'assertion' => 'PHP code',
				'message'   => $e->getMessage(),
				'trace'     => $e->getTraceAsString(),
			];
		}

		$classes = array_diff(get_declared_classes(), $classes);

		$failed = [];

		foreach ($classes as $class)
		{
			$reflection = new \ReflectionClass($class);
			$class_file = $reflection->getFileName();
			
			if (realpath($class_file) != realpath($file))
			{
				// Skip classes that are not defined in that file
				continue;
			}

			unset($class_file, $reflection);

			try {
				$obj = new $class;
				$result = self::runMethods($obj);

				if ($result !== null)
				{
					$failed[] = $result;
				}

				unset($obj);
			}
			catch (TestException $e) {
				$failed[] = [
					'class'     => $class,
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'assertion' => $e->getAssertion(),
					'message'   => $e->getMessage(),
					'trace'     => $e->getCallTraceAsString(),
				];
			}
			catch (\Throwable $e) {
				$failed[] = [
					'class'     => $class,
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'assertion' => 'PHP code',
					'message'   => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				];
			}
			catch (\Exception $e) {
				$failed[] = [
					'class'     => $class,
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'assertion' => 'PHP code',
					'message'   => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				];
			}
		}

		return $failed;
	}

	static public function runMethods($class)
	{
		$reflection = new \ReflectionClass($class);
		$name = is_string($class) ? $class : get_class($class);

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

			try {
				if (is_object($class) && method_exists($class, 'setUp'))
				{
					$class->setUp();
				}

				call_user_func_array([$class, $method->name], $args);

				if (is_object($class) && method_exists($class, 'tearDown'))
				{
					$class->tearDown();
				}
			}
			catch (TestException $e) {
				return [
					'class'     => $name,
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'assertion' => $e->getAssertion(),
					'message'   => $e->getMessage(),
					'trace'     => $e->getCallTraceAsString(),
				];
			}
			catch (Throwable $t) {
				return [
					'class'     => $name,
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'assertion' => 'PHP code',
					'message'   => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				];
			}
			catch (\Exception $e) {
				return [
					'class'     => $name,
					'file'      => $e->getFile(),
					'line'      => $e->getLine(),
					'assertion' => 'PHP code',
					'message'   => $e->getMessage(),
					'trace'     => $e->getTraceAsString(),
				];
			}
		}
	}

	static public function dump()
	{
		ob_start();
		
		foreach (func_get_args() as $arg)
		{
			var_dump($arg);
		}

		$out = ob_get_contents();
		ob_end_clean();

		return trim($out);
	}
}

class TestException extends \Exception
{
	protected $assertion;
	protected $trace;

	public function getAssertion()
	{
		return $this->assertion;
	}

	public function __construct($message)
	{
		parent::__construct($message);

		// Get original test file/line
		foreach ($this->getTrace() as $k=>$trace)
		{
			if ($trace['file'] == __FILE__)
			{
				continue;
			}

			$this->file = $trace['file'];
			$this->line = $trace['line'];
			$this->assertion = $trace['function'];
			$this->trace = array_slice(parent::getTrace(), $k);
			break;
		}
	}

	public function getCallTrace()
	{
		return $this->trace;
	}

	public function getCallTraceAsString()
	{
		$out = [];

		foreach ($this->trace as $i=>$trace)
		{
			$source = isset($trace['file']) ? sprintf('%s(%d)', $trace['file'], $trace['line']) : '[internal function]';
			$function = isset($trace['class']) ? $trace['class'] . $trace['type'] . $trace['function'] : $trace['function'];
			$args = [];

			foreach ($trace['args'] as $arg)
			{
				if (is_object($arg))
				{
					$args[] = sprintf('Object(%s)', get_class($arg));
				}
				elseif (is_array($arg))
				{
					$args[] = sprintf('Array(%d)', count($arg));
				}
				else
				{
					$args[] = var_export($arg, true);
				}
			}

			$function .= sprintf('(%s)', implode(', ', $args));

			$out[] = sprintf('#%d %s: %s', $i, $source, $function);
		}

		return implode(PHP_EOL, $out);
	}
}