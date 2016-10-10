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

	public function fetch($template, Array $variables = [])
	{
		return $this->run(file_get_contents($template), $variables, true);
	}

	public function display($template, Array $variables = [])
	{
		$this->run(file_get_contents($template), $variables);
	}

	public function run($str, Array $variables = [], $return = false)
	{
		$code = $this->compile($str);
		var_dump($code);

		$this->_variables = [$variables];

		if ($return)
		{
			ob_start();
		}

		eval('?>' . $code);

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
		array_unshift($this->_variables, $variables);
	}

	protected function _pop()
	{
		array_shift($this->_variables);
	}

	protected function _loop($key)
	{
		foreach ($this->_variables as $vars)
		{
			if (isset($vars[$key]) && is_array($vars[$key]))
			{
				return $vars[$key];
			}
		}

		// Return an array with only one item, at this point we know
		// it's not empty
		return [0];
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

		$pattern = sprintf('/(?<!\\\\)%s([#^<&{\/!]?\s*\w+?)\s*\}?(?<!\\\\)%s/', preg_quote($this->delimiter_start, '/'), preg_quote($this->delimiter_end, '/'));
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