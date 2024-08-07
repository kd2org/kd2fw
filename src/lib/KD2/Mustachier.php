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
 * Mustachier, a lightweight implementation of Mustache templates
 *
 * @author  bohwaz  http://bohwaz.net/
 * @deprecated
 */
class Mustachier
{
	/**
	 * Start delimiter, usually is {{
	 * @var string
	 */
	protected $delimiter_start = '{{';

	/**
	 * End delimiter, usually }}
	 * @var string
	 */
	protected $delimiter_end = '}}';

	/**
	 * Internal loop stack to check closing tags match opening tags
	 * @var array
	 */
	protected $_loop_stack = [];

	/**
	 * Internal variable stack for running a template
	 * @var array
	 */
	protected $_variables = [];

	/**
	 * Current object template path
	 * @var string
	 */
	protected $templates_dir = null;

	/**
	 * Templates compile dir
	 * @var string
	 */
	protected $compile_dir = null;

	protected $_cache_closures = [];

	protected $_cache_templates = [];

	/**
	 * Partials
	 * @var array
	 */
	protected $_partials = [];

	/**
	 * Mustachier constructor
	 * @param string $templates_dir Path to directory where templates are stored (must exist)
	 * @param string $compile_dir   Path to directory where compiled templates should be stored,
	 * if set to NULL then templates will be re-compiled every time they are called
	 */
	public function __construct($templates_dir = null, $compile_dir = null)
	{
		if (!is_null($templates_dir))
		{
			$this->templates_dir = realpath(rtrim($templates_dir, DIRECTORY_SEPARATOR));

			if (!$this->templates_dir || !is_readable($this->templates_dir) || !is_dir($this->templates_dir))
			{
				throw new \InvalidArgumentException('Cannot read directory: ' . $templates_dir);
			}
		}

		if (!is_null($compile_dir))
		{
			$this->compile_dir = realpath(rtrim($compile_dir, DIRECTORY_SEPARATOR));

			if (!$this->compile_dir || !is_writeable($this->compile_dir) || !is_dir($this->compile_dir))
			{
				throw new \InvalidArgumentException('Cannot write directory: ' . $compile_dir);
			}
		}
	}

	/**
	 * Set partials to be used before including templates
	 * @param Array $partials [name => template]
	 */
	public function setPartials(Array $partials)
	{
		$this->_partials = $partials;
	}

	/**
	 * Assign a variable to be used in templates
	 * @param  string|array $key
	 * @param  mixed|null $value
	 * @return boolean
	 */
	public function assign($key, $value = null)
	{
		if (!isset($this->_variables[0]))
		{
			$this->_variables[0] = [];
		}

		if (is_array($key))
		{
			$this->_variables[0] = array_merge($this->_variables[0], $key);
			return true;
		}

		$this->_variables[0][$key] = $value;
		return true;
	}

	/**
	 * Returns a template output
	 * @param  string $template  Template file name
	 * @param  Array  $variables Variables to assign to this template
	 * @return string
	 * @throws MustachierException
	 */
	public function fetch($template, Array $variables = [])
	{
		return $this->display($template, $variables, true);
	}

	/**
	 * Displays or returns a template output
	 * @param  string  $template  Template file name
	 * @param  Array   $variables Variables to assign to this template
	 * @param  boolean $return    TRUE to get the output returned, FALSE to get it printed
	 * @return string
	 * @throws MustachierException
	 * @throws \InvalidArgumentException
	 */
	public function display($template, Array $variables = [], $return = false)
	{
		assert(is_string($template));

		$template = ($this->templates_dir ? ($this->templates_dir . DIRECTORY_SEPARATOR) : '') . $template;

		if (!is_readable($template))
		{
			throw new \InvalidArgumentException('Cannot open file: ' . $template);
		}

		// If compile_dir is set, we will store the compiled template and include it instead of using eval()
		if ($this->compile_dir)
		{
			$compiled_template = $this->compile_dir . DIRECTORY_SEPARATOR . sha1($template);

			// Compile template
			if (filemtime($template) > @filemtime($compiled_template))
			{
				$str = file_get_contents($template);
				$closure_name = sha1($str);

				// Compile
				$code = $this->compile($str, $template);

				// Create closure
				eval('$this->_cache_closures[$closure_name] = function () { ' . $code . '};');
				$this->_cache_templates[$template] = $closure_name;

				// Store closure
				$code = sprintf('<?php $closure_name = %s; $this->_cache_closures[$closure_name] = function () { %s };',
					var_export($closure_name, true), $code);

				file_put_contents($compiled_template, $code);
			}
			// Load from cache
			elseif (!array_key_exists($template, $this->_cache_templates))
			{
				include $compiled_template;
				$this->_cache_templates[$template] = $closure_name;
			}
			else
			{
				$closure_name = $this->_cache_templates[$template];
			}

			return $this->run($closure_name, $variables, $return);
		}
		// No compile_dir: fully dynamic on-the-fly compiling and execution
		else
		{
			return $this->render(file_get_contents($template), $variables, $return);
		}
	}

	/**
	 * Run a stored closure
	 * @param  string  $closure_name Closure name
	 * @param  Array   $variables Variables to assign to this template
	 * @param  boolean $return    TRUE to get the output returned, FALSE to get it printed
	 * @return string|void
	 */
	protected function run($closure_name, Array $variables = [], $return = false)
	{
		// Set variables context for current template
		if (count($variables) > 0)
		{
			$previous_variables = $this->_variables;
			$this->assign($variables);
		}

		$out = $this->_cache_closures[$closure_name]();

		// Reset variables context
		if (isset($previous_variables))
		{
			$this->_variables = $previous_variables;
			unset($previous_variables);
		}

		if ($return)
		{
			return $out;
		}
		else
		{
			echo $out;
		}
	}

	/**
	 * Compiles and runs a template code
	 * @param  string  $str       Mustache template code
	 * @param  Array   $variables Variables to pass to the template for the duration of its execution
	 * @param  boolean $return    Set to TRUE to return as string instead of echoing the template
	 * @param  string  $template  Template file path
	 * @return void|string
	 */
	public function render($str, Array $variables = [], $return = false, $template = null)
	{
		$closure_name = sha1($str);

		if (!array_key_exists($closure_name, $this->_cache_closures))
		{
			$code = $this->compile($str, $template);

			// Create closure
			eval('$this->_cache_closures[$closure_name] = function () { ' . $code . '};');
		}

		return $this->run($closure_name, $variables, $return);
	}

	/**
	 * Used in templates to return the value of a variable
	 * @param  string $key
	 * @return mixed
	 */
	protected function _get($key)
	{
		$key = explode('.', $key);
		$vars = end($this->_variables);

		if (!$vars)
		{
			return null;
		}

		return $this->_magicVar($vars, $key);
	}

	/**
	 * Used in templates to know if a variable is empty or not
	 * @param  string $key
	 * @return boolean
	 */
	protected function _empty($key)
	{
		$var = $this->_get($key);

		if (is_array($var) || is_object($var))
		{
			return !count((array) $var);
		}

		return empty($var);
	}

	/**
	 * Retrieve a magic variable like $object.key or $array.key.subkey
	 * @param  mixed $var   Variable to look into (object or array)
	 * @param  array $keys  List of keys to look for
	 * @return mixed        NULL if the key doesn't exists, or the value associated to the key
	 */
	protected function _magicVar($var, array $keys)
	{
		while ($key = array_shift($keys))
		{
			if (is_object($var))
			{
				// Test for constants
				if (defined(get_class($var) . '::' . $key))
				{
					return constant(get_class($var) . '::' . $key);
				}

				if (!property_exists($var, $key))
				{
					return null;
				}

				$var = $var->$key;
			}
			elseif (is_array($var))
			{
				if (!array_key_exists($key, $var))
				{
					return null;
				}

				$var = $var[$key];
			}
		}

		return $var;
	}

	/**
	 * Escapes a variable value
	 * @param  string $str
	 * @return string
	 */
	protected function _escape($str)
	{
		return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Append variables to the stack for the current context (loop/condition/include)
	 * @param  array $variables
	 * @return boolean
	 */
	protected function _append($variables, $key = null)
	{
		if (is_string($key))
		{
			$variables = [$key => $variables];
		}

		if (!is_array($variables))
		{
			return;
		}

		array_push($this->_variables, $variables);
	}

	/**
	 * Remove last context variables from variables stack
	 * @return void
	 */
	protected function _pop()
	{
		return array_pop($this->_variables);
	}

	/**
	 * Returns an array from variables for a loop
	 * @param  string $key
	 * @return array
	 */
	protected function _loop($key)
	{
		$var = $this->_get($key);

		if (is_array($var) || is_object($var) || is_iterable($var))
		{
			return $var;
		}

		// Return an array with only one item
		// this is for conditional tags that are not loops
		return $var ? [0] : [];
	}

	protected function _notloop($key)
	{
		$var = $this->_get($key);

		if (is_array($var) || is_object($var) || is_iterable($var))
		{
			return !count($var);
		}

		// Return an array with only one item
		// this is for conditional tags that are not loops
		return $var ? [] : [0];
	}

	/**
	 * Includes a template
	 * @param  string $name
	 * @return void
	 */
	protected function _include($name)
	{
		if (array_key_exists($name, $this->_partials))
		{
			return $this->render($this->_partials[$name], [], true);
		}
		elseif (null !== $this->templates_dir)
		{
			return $this->fetch($name . '.mustache');
		}

		return '';
	}

	/**
	 * Compiles a given Mustache code into PHP code
	 * @param  string $code     Mustache template code
	 * @param  string|null $template Path to template file
	 * @return string PHP code
	 */
	public function compile($code, $template = null)
	{
		$pattern = '/(?|^\s*((?<!\\\\)%s(?!=)(?:[#^>&{\/!]?\s*(?:(?!%2$s).)*?)\s*\}?(?<!\\\\)%2$s)\s*$\r?\n|((?1)))/sm';
		$pattern = sprintf($pattern, preg_quote($this->delimiter_start, '/'), preg_quote($this->delimiter_end, '/'));
		$str = preg_split($pattern, $code, 0, PREG_SPLIT_DELIM_CAPTURE);

		$out = '$o = \'\';' . PHP_EOL;
		$line = 1;

		foreach ($str as $i=>$split)
		{
			$line += substr_count($split, "\n");

			// string, not a tag
			if ($i % 2 == 0)
			{
				if ($split !== '')
				{
					$out .= '$o .= ' . var_export($split, true) . ';' . PHP_EOL;
				}

				continue;
			}

			$tag = substr($split, strlen($this->delimiter_start), -(strlen($this->delimiter_end)));

			// first character of tag
			$a = substr($tag, 0, 1);
			$b = substr($tag, 1);

			if ($a == '{')
			{
				$b = substr($b, 0, -1);
			}

			$b = var_export(trim($b), true);

			// Comments
			if ($a == '!')
			{
				$out .= sprintf('/* %s */', str_replace('*/', '* /', trim(substr($tag, 1))));
			}
			// positive condition (section)
			elseif ($a == '#')
			{
				$out .= sprintf('foreach ($this->_loop(%s) as $loop): $this->_append($loop, %1$s);', $b);
				$this->_loop_stack[] = $b;
			}
			// end of condition
			elseif ($a == '/')
			{
				if (array_pop($this->_loop_stack) != $b)
				{
					throw new MustachierException($line, 'Unexpected closing tag for section: ' . $b, $template, $code);
				}

				// how do you know if you are closing a loop or a condition?
				// you don't! that's why we treat conditions as one-iteration loop!
				$out .= '$this->_pop(); endforeach;';
			}
			// inverted sections (negative condition)
			elseif ($a == '^')
			{
				$out .= sprintf('foreach ($this->_notloop(%s) as $ignore): $this->_append([null]);', $b);
				$this->_loop_stack[] = $b;
			}
			// include (= partials)
			elseif ($a == '>')
			{
				$out .= sprintf('$o .= $this->_include(%s);', $b);
			}
			// No escape {{{variable}}}
			// No escape {{&variable}}
			elseif ($a == '&' || $a == '{')
			{
				$out .= sprintf('$o .= $this->_get(%s);', $b);
			}
			// Escaped variable
			else
			{
				$out .= sprintf('$o .= $this->_escape($this->_get(%s));', var_export(trim($tag), true));
			}

			$out .= PHP_EOL;
		}

		if (count($this->_loop_stack) > 0)
		{
			throw new MustachierException($line, 'Missing closing tag for section: ' . array_pop($this->_loop_stack), $template, $code);
		}

		$out .= 'return $o;' . PHP_EOL;

		return $out;
	}
}

class MustachierException extends \Exception
{
	/**
	 * Creates a new Mustache template exception
	 * @param integer $line   Line number in Mustache template
	 * @param string $message Error message
	 * @param string $code    Either path to Mustache template file or code used for run() method
	 */
	public function __construct($line, $message, $file, $code)
	{
		parent::__construct($message);
		$this->line = $line;
		$this->file = $file;
		$this->code = $code;
	}
}