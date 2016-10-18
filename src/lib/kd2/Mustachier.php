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
 * Mustachier, a lightweight implementation of Mustache templates
 * 
 * @author  bohwaz  http://bohwaz.net/
 * @license BSD
 * @version 0.1
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

	/**
	 * Strip comments (or not)
	 * @var boolean
	 */
	protected $strip_comments = true;

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
	 * @throws MustacheException
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
	 * @throws MustacheException
	 * @throws InvalidArgumentException
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
			$compiled_template = $this->compile_dir . DIRECTORY_SEPARATOR . md5($template);

			// Compile template
			if (filemtime($template) > @filemtime($compiled_template))
			{
				$code = $this->compile(file_get_contents($template), $template);
				file_put_contents($compiled_template, $code);
			}

			// Execute template by including it

			if ($return)
			{
				ob_start();
			}

			// Set variables context for current template
			if (count($variables) > 0)
			{
				$previous_variables = $this->_variables;
				$this->assign($variables);
			}

			try {
				$eval = @include $compiled_template;
			}
			catch (\Exception $e)
			{
				throw $e;
			}

			// Reset variables context
			if (isset($previous_variables))
			{
				$this->_variables = $previous_variables;
				unset($previous_variables);
			}

			if (!$eval && ($error = error_get_last()))
			{
				throw new MustachierException($error['line'], 'Error in compiled template: '. $error['message'], $compiled_template);
			}

			if ($return)
			{
				return ob_get_clean();
			}
		}
		// No compile_dir: fully dynamic on-the-fly compiling and execution
		else
		{
			return $this->run(file_get_contents($template), $variables, $return, $template);
		}
	}

	/**
	 * Compiles and runs a template code
	 * @param  string  $str       Mustache template code
	 * @param  Array   $variables Variables to pass to the template for the duration of its execution
	 * @param  boolean $return    Set to TRUE to return as string instead of echoing the template
	 * @param  string|null $template Path to template file
	 * @return void|string
	 */
	public function run($str, Array $variables = [], $return = false, $template = null)
	{
		$code = $this->compile($str, $template);

		if ($return)
		{
			ob_start();
		}

		// Set variables context for current template
		if (count($variables) > 0)
		{
			$previous_variables = $this->_variables;
			$this->assign($variables);
		}

		$eval = @eval('?>' . $code);

		// Reset variables context
		if (isset($previous_variables))
		{
			$this->_variables = $previous_variables;
			unset($previous_variables);
		}

		if (!$eval && ($error = error_get_last()))
		{
			throw new MustachierException($error['line'], 'Error in compiled template: '. $error['message'], $code);
		}

		if ($return)
		{
			return ob_get_clean();
		}
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
	protected function _append(Array $variables, $key = null)
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

		if (is_array($var) || is_object($var))
		{
			return $var;
		}

		// Return an array with only one item
		// this is for conditional tags that are not loops
		return [0];
	}

	/**
	 * Includes a template
	 * @param  string $name
	 * @return void
	 */
	protected function _include($name)
	{
		$this->display($name . '.mustache');
	}

	/**
	 * Compiles a given Mustache code into PHP code
	 * @param  string $code     Mustache template code
	 * @param  string|null $template Path to template file
	 * @return string PHP code
	 */
	public function compile($code, $template = null)
	{
		// Don't allow PHP tags
		$php_replace = [
			"\r"    => '',
			'<?='   => '<?=\'<?=\'?>',
			'<?php' => '<?=\'<?php\'?>',
			'?>'    => '<?=\'?>\'?>'
		];

		$str = strtr($code, $php_replace);

		$pattern = sprintf('/(?<!\\\\)%s([#^>&{\/!]?\s*.+?)\s*\}?(?<!\\\\)%s/sm', preg_quote($this->delimiter_start, '/'), preg_quote($this->delimiter_end, '/'));
		$str = preg_split($pattern, $str, 0, PREG_SPLIT_DELIM_CAPTURE);

		$out = '';
		$line = 1;

		foreach ($str as $i=>$split)
		{
			$line += substr_count($split, "\n");

			if ($i % 2 == 0)
			{
				$out .= $split;
				continue;
			}

			$tag = trim($split);

			// first character of tag
			$a = substr($tag, 0, 1);
			$b = trim(substr($tag, 1));
			$b = var_export($b, true);

			// Comments
			if ($a == '!')
			{
				$out .= sprintf('<?php/* %s */?>', str_replace('*/', '* /', trim(substr($tag, 1))));
			}
			// positive condition (section)
			elseif ($a == '#')
			{
				$out .= sprintf('<?php if (!$this->_empty(%s)): foreach ($this->_loop(%1$s) as $key=>$loop): $this->_append($loop, $key); ?>', $b);
				$this->_loop_stack[] = $b;
			}
			// end of condition
			elseif ($a == '/')
			{
				if (array_pop($this->_loop_stack) != $b)
				{
					throw new MustachierException($line, 'Unexpected closing tag for section: ' . $b, $template ?: $code);
				}

				// how do you know if you are closing a loop or a condition?
				// you don't! that's why we treat conditions as one-iteration loop!
				$out .= '<?php $this->_pop(); endforeach; endif; ?>';
			}
			// inverted sections (negative condition)
			elseif ($a == '^')
			{
				$out .= sprintf('<?php if ($this->_empty(%s)): foreach ([0] as $ignore): $this->_append([false]); ?>', $b);
				$this->_loop_stack[] = $b;
			}
			// include (= partials)
			elseif ($a == '>')
			{
				$out .= sprintf('<?php $this->_include(%s); ?>', $b);
			}
			// No escape {{&variable}}
			// No escape {{{variable}}}
			elseif ($a == '&' || $a == '{')
			{
				$out .= sprintf('<?=$this->_get(%s); ?>', $b);
			}
			// Escaped variable
			else
			{
				$out .= sprintf('<?=$this->_escape($this->_get(%s))?>', var_export($tag, true));
			}
		}

		if (count($this->_loop_stack) > 0)
		{
			throw new MustachierException($line, 'Missing closing tag for section: ' . array_pop($this->_loop_stack), $template ?: $str);
		}

		// Fix PHP eating newline
		$out = preg_replace("/\?>\n/", "$0\n", $out);
		
		// Fix standalone tags whitespaces/new lines
		$out = preg_replace("/^\s*(<\?((?!\?>).)*\?>)\s*$/sm", '$1', $out);
		
		// Remove comments
		if ($this->strip_comments)
		{
			// Remove comment lines
			$out = preg_replace('#^\s*<\?php/\*((?!\*/).)*\*/\?>\s*\n*#sm', '', $out);

			// remove in-line comments
			$out = preg_replace('#<\?php/\*((?!\*/).)*\*/\?>#s', '', $out);
		}

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
	public function __construct($line, $message, $code)
	{
		parent::__construct($message);
		$this->line = $line;
		$this->file = $code;
	}
}