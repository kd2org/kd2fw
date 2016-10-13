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
	 * Internal variable stack for running a template
	 * @var array
	 */
	protected $_variables = [];

	/**
	 * List of callbacks (lambdas)
	 * @var array
	 */
	protected $_callbacks = [];

	/**
	 * Current object template path
	 * @var null
	 */
	protected $templates_dir = null;

	/**
	 * Templates compile dir
	 * @var null
	 */
	protected $compile_dir = null;

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

			if (!is_readable($this->templates_dir) || !is_dir($this->templates_dir))
			{
				throw new \InvalidArgumentException('Cannot read directory: ' . $this->templates_dir);
			}
		}

		if (!is_null($compile_dir))
		{
			$this->compile_dir = realpath(rtrim($compile_dir, DIRECTORY_SEPARATOR));

			if (!is_writeable($this->compile_dir) || !is_dir($this->compile_dir))
			{
				throw new \InvalidArgumentException('Cannot write directory: ' . $this->compile_dir);
			}
		}
	}

	public function assign($key, $value = null)
	{
		// Last element of variables
		$pos = max(0, count($this->_variables) - 1);

		if (is_array($key))
		{
			$this->_variables[$pos] = array_merge($this->_variables[$pos], $key);
			return true;
		}

		$this->_variables[$pos][$key] = $value;
		return true;
	}

	public function registerCallback($name, Callable $callback)
	{
		$this->_callbacks[$name] = $callback;
		return true;
	}

	public function fetch($template, Array $variables = [])
	{
		return $this->display($template, $variables, true);
	}

	public function display($template, Array $variables = [], $return = false)
	{
		assert(is_string($template));

		$template = ($this->templates_dir ? ($this->templates_dir . DIRECTORY_SEPARATOR) : '') . $template;
		$template = realpath($template);

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
				$code = $this->compile(file_get_contents($template));
				file_put_contents($compiled_template, $code);
			}

			// Execute template by including it

			if ($return)
			{
				ob_start();
			}

			$this->_append($variables);

			try {
				$eval = @include $compiled_template;
			}
			catch (\Exception $e)
			{
			}

			if (!$eval && ($error = error_get_last()))
			{
				throw new MustachierException($error['line'], $error['message'], $compiled_template);
			}

			$this->_pop();

			if ($return)
			{
				return ob_get_clean();
			}
		}
		// No compile_dir: fully dynamic on-the-fly compiling and execution
		else
		{
			return $this->run(file_get_contents($template), $variables, $return);
		}
	}

	/**
	 * Compiles and runs a template code
	 * @param  string  $str       Mustache template code
	 * @param  Array   $variables Variables to pass to the template for the duration of its execution
	 * @param  boolean $return    Set to TRUE to return as string instead of echoing the template
	 * @return void|string
	 */
	public function run($str, Array $variables = [], $return = false)
	{
		$code = $this->compile($str);

		if ($return)
		{
			ob_start();
		}

		$this->_append($variables);

		$eval = @eval('?>' . $code);

		$this->_pop();

		if (!$eval && ($error = error_get_last()))
		{
			throw new MustachierException($error['line'], $error['message'], $code);
		}

		if ($return)
		{
			return ob_get_clean();
		}
	}

	protected function _empty($key)
	{
		foreach ($this->_variables as $vars)
		{
			if (isset($vars[$key]))
			{
				if (is_array($vars[$key]))
				{
					return count($vars[$key]) > 0 ? false : true;
				}
				
				return empty($vars[$key]);
			}
		}

		return true;
	}

	protected function _get($key)
	{
		foreach ($this->_variables as $vars)
		{
			if (isset($vars[$key]))
			{
				return $vars[$key];
			}
		}

		return '';
	}

	protected function _append($variables)
	{
		if (!is_array($variables))
		{
			return;
		}

		array_unshift($this->_variables, $variables);
	}

	protected function _pop()
	{
		array_shift($this->_variables);
	}

	protected function _loop($key)
	{
		if (isset($this->_callbacks[$key]))
		{

		}

		foreach ($this->_variables as $vars)
		{
			if (isset($vars[$key]) && is_array($vars[$key]))
			{
				return $vars;
			}
		}

		// Return an array with only one item
		// this is for conditional tags that are not loops
		return [0];
	}

	protected function _include($name)
	{
		$this->display($name);
	}

	public function compile($str)
	{
		// Don't allow PHP tags
		$php_replace = [
			'<?='   => '<?=\'<?=\'?>',
			'<?php' => '<?=\'<?php\'?>',
			'?>'    => '<?=\'?>\'?>'
		];

		$str = strtr($str, $php_replace);

		$pattern = sprintf('/(?<!\\\\)%s([#^>&{\/!]?\s*[\w.]+?)\s*\}?(?<!\\\\)%s/', preg_quote($this->delimiter_start, '/'), preg_quote($this->delimiter_end, '/'));
		$str = preg_split($pattern, $str, 0, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_DELIM_CAPTURE);

		$out = '';

		foreach ($str as $i=>$split)
		{
			if ($i % 2 == 0)
			{
				$out .= $split[0];
				continue;
			}

			$tag = trim($split[0]);
			$pos = (int) $split[1];

			// first character of tag
			$a = substr($tag, 0, 1);
			$b = trim(substr($tag, 1));
			$b = var_export($b, true);

			// Comments: ignore
			if ($a == '!')
			{
				continue;
			}

			// positive condition (section)
			if ($a == '#')
			{
				$out .= sprintf('<?php if (!$this->_empty(%s)): foreach ($this->_loop(%1$s) as $loop): $this->_append($loop); ?>', $b);
			}
			// end of condition
			elseif ($a == '/')
			{
				// how do you know if you are closing a loop or a condition?
				// you don't! that's why we treat conditions as one-iteration loop!
				$out .= '<?php $this->_pop(); endforeach; endif; ?>';
			}
			// inverted sections (negative condition)
			elseif ($a == '^')
			{
				$out .= sprintf('<?php if ($this->_empty(%s)): foreach ([0] as $ignore): $this->_append(false); ?>', $b);
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
				$out .= sprintf('<?=$this->escape($this->_get(%s))?>', var_export($tag, true));
			}
		}

		return $out;
	}

	protected function escape($str)
	{
		return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
	}
}

class MustachierException extends \Exception
{
	public function __construct($line, $message, $code)
	{
		parent::__construct('Error in compiled template: '. $message);
		$this->line = $line;
		$this->file = $code;
	}

	public function __toString()
	{
		return parent::__toString() . PHP_EOL . PHP_EOL . $this->compiled;
	}
}