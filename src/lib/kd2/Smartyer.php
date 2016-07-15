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

/**
 * Smartyer: a lightweight Smarty template engine
 *
 * Smartyer is not really smarter, in fact it is dumber, it is merely replacing
 * some Smarty tags to PHP code. This may lead to hard to debug bugs as the
 * compiled PHP code may contain invalid syntax.
 *
 * Differences:
 * - UNSAFE! this is directly executing PHP code from the template,
 * you MUST NOT allow end users to edit templates. Consider Smartyer templates
 * as the same as PHP files.
 * - Auto-escaping of single variables: {$name} will be escaped, 
 * but {$name|rot13} won't be. Use {$name|raw} to disable auto-escaping, and
 * |escape modifier to escape after or before other modifiers.
 * - {foreach}...{foreachelse}...{/foreach} must be written {foreachelse}...{/if}
 *
 * @author  bohwaz  http://bohwaz.net/
 * @license BSD
 * @version 0.1
 */

namespace KD2;

class Smartyer
{
	protected $delimiter_start = '{';
	protected $delimiter_end = '}';

	protected $template_path = null;
	protected $compiled_template_path = null;

	protected $source = null;
	protected $compiled = null;

	protected $variables = [];
	protected $functions = [];

	protected $blocks = [];

	protected $modifiers = [
		'nl2br' => 'nl2br',
		'count' => 'count',
		'escape' => [__CLASS__, 'escape'],
		'truncate' => [__CLASS__, 'truncate'],
		'replace' => [__CLASS__, 'replace'],
		'regex_replace' => [__CLASS__, 'replaceRegExp'],
		'date_format' => [__CLASS__, 'dateFormat'],
	];

	static protected $cache_path = null;
	static protected $templates_path = null;

	static protected $cache_check_changes = true;

	static public function setCachePath($path)
	{
		if (!is_dir($path))
		{
			throw new \RuntimeException($path . ' is not a directory.');
		}

		if (!is_writable($path))
		{
			throw new \RuntimeException($path . ' is not writeable by ' . __CLASS__);
		}

		self::$cache_path = $path;
	}

	static public function setTemplatesPath($path)
	{
		if (!is_dir($path))
		{
			throw new \RuntimeException($path . ' is not a directory.');
		}

		if (!is_writable($path))
		{
			throw new \RuntimeException($path . ' is not writeable by ' . __CLASS__);
		}

		self::$templates_path = $path;
	}

	static public function fromString($str)
	{
		$obj = self::__construct();
		$obj->source = $str;
		$obj->template_path = null;
		$obj->compiled_template_path = self::$cache_path . DIRECTORY_SEPARATOR . sha1($str);
		return $obj;
	}

	public function __construct($template = null, Smartyer $parent = null)
	{
		if (is_null(self::$cache_path))
		{
			throw new \LogicException('Cache path not set: call ' . __CLASS__ . '::setCachePath() first');
		}

		$this->delimiter_start = preg_quote($this->delimiter_start, '#');
		$this->delimiter_end = preg_quote($this->delimiter_end, '#');

		$this->template_path = self::$templates_path . DIRECTORY_SEPARATOR . $template;
		$this->compiled_template_path = self::$cache_path . DIRECTORY_SEPARATOR . sha1($template);

		if ($parent instanceof Smartyer)
		{
			foreach ($parent->modifiers as $key=>$value)
			{
				$this->register_modifier($key, $value);
			}

			foreach ($parent->blocks as $key=>$value)
			{
				$this->register_block($key, $value);
			}

			foreach ($parent->functions as $key=>$value)
			{
				$this->register_function($key, $value);
			}

			foreach ($parent->variables as $key=>$value)
			{
				$this->assign($key, $value);
			}
		}
	}

	protected function compile($mode = 'display')
	{
		if (is_null($this->source) && !is_null($this->template_path))
		{
			$this->source = file_get_contents($this->template_path);
		}

		$this->source = str_replace("\r", "", $this->source);
		
		$this->compiled = $this->source;

		$this->parseComments();
		$this->parseVariables();
		$this->parseBlocks();

		// Force new lines (this is to avoid PHP eating new lines after its closing tag)
		$this->compiled = preg_replace("/\?>\n/", "$0\n", $this->compiled);

		$this->compiled = '<?php /* Compiled from ' . $this->template_path . ' - ' . gmdate('Y-m-d H:i:s') . ' UTC */ '
			. 'if (!isset($_i)) { $_i = []; } if (!isset($_blocks)) { $_blocks = []; }  ?>'
			. $this->compiled;

		// Write to temporary file
		file_put_contents($this->compiled_template_path . '.tmp', $this->compiled);

		// We can catch most errors in first run
		try {
			extract($this->variables);

			ob_start();
			include $this->compiled_template_path . '.tmp';
			$out = ob_get_clean();

			if ($mode == 'display')
			{
				echo $out;
				$out = true;
			}

			// Atomic update if everything worked
			@unlink($this->compiled_template_path);
			rename($this->compiled_template_path . '.tmp', $this->compiled_template_path);

			return $out;
		}
		catch (\Exception $e)
		{
			// Finding the original template line number
			$source = explode("\n", $this->compiled);
			$source = array_slice($source, $e->getLine());
			$source = implode("\n", $source);
			
			if (preg_match('!//#(\d+)\?>!', $source, $match))
			{
				$this->parseError($match[1], $e->getMessage());
			}
			else
			{
				throw new Smartyer_Exception($e->getMessage(), $this->compiled_template_path, $e->getLine());
			}
		}


		$this->source = null;
		$this->compiled = null;
	}

	public function display()
	{
		$time = @filemtime($this->compiled_template_path);

		if (!$time || (self::$cache_check_changes && filemtime($this->template_path) > $time))
		{
			return $this->compile('display');
		}

		extract($this->variables);

		include $this->compiled_template_path;
		return true;
	}

	public function assign($name, $value = null)
	{
		if (is_array($name))
		{
			foreach ($name as $k=>$v)
			{
				$this->assign($k, $v);
			}

			return true;
		}

		$this->variables[$name] = $value;
	}

	public function register_modifier($name, Callable $callback = null)
	{
		if (is_array($name))
		{
			foreach ($name as $k=>$v)
			{
				$this->register_modifier($k, $v);
			}

			return true;
		}

		$this->modifiers[$name] = $callback;
	}

	public function register_function($name, Callable $callback)
	{
		if (is_array($name))
		{
			foreach ($name as $k=>$v)
			{
				$this->register_function($k, $v);
			}

			return true;
		}

		$this->functions[$name] = $callback;
	}

	public function register_block($name, Callable $callback)
	{
		if (is_array($name))
		{
			foreach ($name as $k=>$v)
			{
				$this->register_block($k, $v);
			}

			return true;
		}

		$this->blocks[$name] = $callback;
	}

	protected function parseBlocks()
	{
		$pattern = '#' . $this->delimiter_start . '(if|else[ ]?if|\w+(?:[_\w\d]+)*)';
		$pattern.= '(?:\s+(.*?))?\s*' . $this->delimiter_end . '#i';
		
		preg_match_all($pattern, $this->compiled, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

		foreach ($matches as $block)
		{
			$pos = $block[0][1];
			$name = strtolower($block[1][0]);
			$raw_args = !empty($block[2]) ? $block[2][0] : null;

			switch ($name)
			{
				// Note: switch/case is not supported because any white space 
				// between switch and the first case will produce and error
				// see https://secure.php.net/manual/en/control-structures.alternative-syntax.php
				case 'else if':
					$name = 'elseif';
				case 'if':
				case 'elseif':
				{
					$code = $name . ' (' . $raw_args . '):';
					break;
				}
				case 'foreach':
				case 'for':
				case 'while':
				{
					$args = $this->parseArguments($raw_args);
					$args['key'] = isset($args['key']) ? $args['key'] : null;
					$code = '$_i[] = 0; ';

					if (empty($args['from']))
					{
						$code .= $name . ' (' . $raw_args . '):';
					}
					elseif ($name == 'foreach')
					{
						if (empty($args['item']))
						{
							$this->parseError($pos, 'Invalid foreach call: item parameter required.');
						}

						$key = $args['key'] ? '$' . $args['key'] . ' => ' : '';

						$code .= $name . ' (' . $args['from'] . ' as ' . $key . '$' . $args['item'] . '):';
					}

					// Update iteration counter
					$code .= ' $iteration =& $_i[count($_i)-1]; $iteration++;';
					break;
				}
				case 'foreachelse':
				{
					$code = 'endforeach; $_i_count = array_pop($_i); ';
					$code .= 'if ($_i_count == 0):';
					break;
				}
				case 'include':
				{
					$args = $this->parseArguments($raw_args);

					if (empty($args['file']))
					{
						throw new Smartyer_Exception($pos, '{include} function requires file parameter.');
					}
					
					$file = $args['file'];
					unset($args['file']);

					if (count($args) > 0)
					{
						$assign = '$_s->assign(' . var_export($args, true) . ');';
					}
					else
					{
						$assign = '';
					}

					$code = '$_s = new \KD2\Smartyer(' . var_export($file, true) . ', $this); ' . $assign . ' $_s->display(); unset($_s);';
					break;
				}
				default:
				{
					$args = $this->parseArguments($raw_args);
					$raw_args = '';

					foreach ($args as $key=>$value)
					{
						if (substr(trim($value), 0, 1) == '$')
						{
							$value = $this->parseMagicVariables($value);
						}
						else
						{
							$value = var_export($value, true);
						}

						$raw_args .= var_export($key, true) . ' => ' . $value . ', ';
					}

					if (array_key_exists($name, $this->blocks))
					{
						$code = 'ob_start(); $_blocks[] = [' . var_export($name, true) . ', ' . var_export($args, true) . '];';
					}
					elseif (array_key_exists($name, $this->functions))
					{
						$code = 'echo $this->functions[' . var_export($name, true) . ']([' . $raw_args . ']);';
					}
					else
					{
						$this->parseError($pos, 'Unknown function or block: ' . $name);
					}
					break;
				}
			}

			$this->compiled = str_replace($block[0][0], '<?php ' . $code . ' //#' . $pos . '?>', $this->compiled);
			unset($args, $name, $pos, $raw_args, $code, $block);
		}

		// Closing tags
		$pattern = '#' . $this->delimiter_start . '\s*/(if|else[ ]?if|\w+(?:[_\w\d]+)*)';
		$pattern.= '\s*' . $this->delimiter_end . '#i';
		
		preg_match_all($pattern, $this->compiled, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

		foreach ($matches as $block)
		{
			$pos = $block[0][1];
			$name = strtolower($block[1][0]);

			$code = '';

			switch ($name)
			{
				case 'foreach':
				case 'for':
				case 'while':
					$code = ' array_pop($_i);';
				case 'if':
					$code = 'end' . $name . ';' . $code;
					break;
				default:
				{
					if (array_key_exists($name, $this->blocks))
					{
						$code = '$_b = array_pop($_blocks); echo $this->blocks[$_b[0]](ob_get_clean(), $_b[1]);';
					}
					else
					{
						$this->parseError($pos, 'Unknown function or block: ' . $name);
					}
					break;
				}
			}

			$this->compiled = str_replace($block[0][0], '<?php ' . $code . ' //#' . $pos . '?>', $this->compiled);
			unset($name, $pos, $code, $block);
		}
	}

	protected function parseComments()
	{
		$this->compiled = preg_replace('#' . $this->delimiter_start . '\*(.*?)\*' . $this->delimiter_end . '#', '', $this->compiled);
	}

	protected function parseVariables()
	{
		$pattern = '#' . $this->delimiter_start . '(\$.+?)';
		$pattern.= '((\s*\|\s*.+?)*)\s*' . $this->delimiter_end . '#i';

		preg_match_all($pattern, $this->compiled, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

		foreach ($matches as $match)
		{
			$pos = $match[0][1];
			$var = $match[1][0];
			$escape = true;
			$modifiers = empty($match[2][0]) ? [] : preg_split('/\s*\|\s*/', $match[2][0], null, PREG_SPLIT_NO_EMPTY);

			$var = $this->parseMagicVariables($var);

			$code = '$_var = ' . $var . ';';

			foreach ($modifiers as &$m)
			{
				$m = trim($m);

				// No auto-escape for single variables
				if ($m == 'raw')
				{
					$escape = false;
					$modifiers = [];
					break;
				}

				$m = $this->parseMagicVariables($m);

				if (!preg_match('!\(.*\)!', $m) && ($args = explode(':', $m)) && count($args) > 1)
				{
					$m = array_shift($args) . '($_var, ' . implode(', ', $args) . ')';
				}
				// No arguments, let's pass the variable
				elseif (!preg_match('!\(.*\)!', $m))
				{
					$m .= '($_var)';
				}
				else
				{
					// Add variable as first argument
					$m = preg_replace_callback('/\(\s*/', function ($match) {
						return '($_var, ';
					}, $m, 1);
				}
			}

			unset($m, $args);

			// Auto escape of single variables
			if (empty($modifiers) && $escape)
			{
				$code .= '$_var = htmlspecialchars($_var, ENT_QUOTES, \'UTF-8\'); ';
			}

			foreach ($modifiers as $m)
			{
				// Split callback name and arguments
				$m = explode('(', $m, 2);

				$code .= '$_var = $this->modifiers[' . var_export($m[0], true) . ']('. $m[1] . '; ';
			}

			$code .= 'echo $_var; unset($_var);';

			$this->compiled = str_replace($match[0][0], '<?php ' . $code . ' //#' . $pos . '?>', $this->compiled);
			unset($pos, $var, $escape, $modifiers, $code);
		}
	}

	protected function parseMagicVariables($str)
	{
		return preg_replace_callback('!(\$[\w\d_]+)((?:\.[\w\d_]+)+)!', function ($match) {
			$find = explode('.', $match[2]);
			return '$this->_magicVar(' . $match[1] . ', ' . var_export(array_slice($find, 1), true) . ')';
		}, $str);
	}

	protected function parseError($position, $message)
	{
		$line = substr_count(substr($this->source, 0, $position), "\n");
		throw new Smartyer_Exception($message, $this->template_path, $line);
	}

	protected function parseArguments($str)
	{
		$args = [];
		preg_match_all('/(\w[\w\d]*)(?:\s*=\s*(?:([\'"])(.*?)\2|([^>\s\'"]+)))?/i', $str, $_args, PREG_SET_ORDER);

		foreach ($_args as $_a)
		{
			$args[$_a[1]] = isset($_a[4]) ? $_a[4] : (isset($_a[3]) ? $_a[3] : null);
		}

		return $args;
	}

	protected function _magicVar($var, $keys)
	{
		while ($key = array_shift($keys))
		{
			if (is_object($var))
			{
				$var = $var->$key;
			}
			elseif (is_array($var))
			{
				$var = $var[$key];
			}
		}

		return $var;
	}

	static protected function escape($str, $type = 'html')
	{
		switch ($type)
		{
			case 'html':
				return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
			case 'xml':
				return htmlspecialchars($str, ENT_XML1, 'UTF-8');
			case 'htmlall':
				return htmlentities($str, ENT_QUOTES, 'UTF-8');
			case 'url':
				return rawurlencode($str);
			case 'quotes':
				return addslashes($str);
			case 'hex':
				return preg_replace_callback('/./', function ($match) {
					return '%' . ord($match[0]);
				}, $str);
			case 'hexentity':
				return preg_replace_callback('/./', function ($match) {
					return '&#x' . ord($match[0]) . ';';
				}, $str);
			case 'mail':
				return str_replace('.', '[dot]', $str);
			case 'js':
			case 'javascript':
				return strtr($str, [
					"\x08" => '\\b', "\x09" => '\\t', "\x0a" => '\\n', 
					"\x0b" => '\\v', "\x0c" => '\\f', "\x0d" => '\\r', 
					"\x22" => '\\"', "\x27" => '\\\'', "\x5c" => '\\'
				]);
			default:
				return $str;
		}
	}

	static protected function replace($str, $a, $b)
	{
		return str_replace($a, $b, $str);
	}

	static protected function replaceRegExp($str, $a, $b)
	{
		return preg_replace($a, $b, $str);
	}

	/**
	 * UTF-8 aware intelligent substr
	 * @param  string  $str         UTF-8 string
	 * @param  integer $length      Maximum string length
	 * @param  string  $placeholder Placeholder text to append at the string if it has been cut
	 * @param  boolean $strict_cut  If true then will cut in the middle of words
	 * @return string 				String cut to $length or shorter
	 */
	static protected function truncate($str, $length = 80, $placeholder = '…', $strict_cut = false)
	{
		// Don't try to use unicode if the string is not valid UTF-8
		$u = preg_match('//u') ? 'u' : '';

		// Shorter than $length + 1
		if (!preg_match('/^.{' . ((int)$length + 1) . '}/' . $u, $str))
		{
			return $str;
		}

		// Cut at 80 characters
		$str = preg_replace('/^(.{' . (int)$length . '}).*$/' . $u, '$1', $str);

		if (!$strict_cut)
		{
			$str = preg_replace('/([\s.,:;!?]).*?$/' . $u, '$1', $str);
		}

		return trim($str) . $placeholder;
	}

	static protected function dateFormat($date, $format = '%b, %e %Y')
	{
		if (!is_numeric($date))
		{
			$date = strtotime($date);
		}

		return strftime($date, $format);
	}
}

class Smartyer_Exception extends \Exception
{
	public function __construct($message, $file, $line)
	{
		parent::__construct($message);
		$this->file = $file;
		$this->line = $line;
	}
}

