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

class Translate
{
	/**
	 * Register a new template block in Smartyer to call KD2\Intl::gettext()
	 * @param  Smartyer &$tpl Smartyer instance
	 * @return Smartyer
	 */
	static public function registerSmartyerBlock(Smartyer &$tpl)
	{
		return (new Translate)->_registerSmartyerBlock($tpl);
	}

	/**
	 * Trying to get around the static limitation of closures in PHP < 7
	 * @link   https://bugs.php.net/bug.php?id=68792
	 * @param  Smartyer $tpl Smartyer instance
	 */
	protected function _registerSmartyerBlock(Smartyer &$tpl)
	{
		return $tpl->register_compile_function('\KD2\Translate\SmartyerTranslate', function ($pos, $block, $name, $raw_args) {
			$block = trim($block);

			if ($block[0] != '{')
			{
				return false;
			}

			// Extract strings from arguments
			$block = preg_split('#\{((?:[^\{\}]|(?R))*?)\}#i', $block, 0, PREG_SPLIT_DELIM_CAPTURE);
			$raw_args = '';
			$strings = [];

			foreach ($block as $k=>$v)
			{
				if ($k % 2 == 0)
				{
					$raw_args .= $v;
				}
				else
				{
					$strings[] = trim($v);
				}
			}

			$nb_strings = count($strings);

			if ($nb_strings < 1)
			{
				$this->parseError($pos, 'No string found in translation block: ' . $block);
			}

			// Only one plural is allowed
			if ($nb_strings > 2)
			{
				$this->parseError($pos, 'Maximum number of translation strings is 2, found ' . $nb_strings . ' in: ' . $block);
			}

			$args = $this->parseArguments($raw_args);

			if ($nb_strings > 1 && empty($args['count']))
			{
				$this->parseError($pos, 'Multiple strings in translation block, but no \'count\' argument.');
			}

			$code = '';

			if ($nb_strings > 1)
			{
				$code = 'ngettext(' . var_export($strings[0], true) . ', ' . var_export($strings[1], true) . ', (int) ' . $args['count'] . ')';
			}
			else
			{
				$code = 'gettext(' . var_export($strings[0], true) . ')';
			}

			$escape = $this->escape_type;

			if (isset($args['escape']))
			{
				$escape = strtolower($args['escape']);
			}

			unset($args['escape']);

			// Use named arguments: %name, %nb_apples...
			// This will cause weird bugs if you use %s, or %d etc. before or between named arguments
			if (!empty($args))
			{
				foreach ($args as $k=>$v)
				{
					$code = preg_replace('/%' . preg_quote($k, '/') . '(?=[^\w\d_-]|$)/i', '%s', $code);
				}
				
				$code = 'vsprintf(' . $code . ', ' . $this->exportArguments(array_values($args)) .')';
			}

			if ($escape != 'false' && $escape != 'off' && $escape !== '')
			{
				$code = 'self::escape(' . $code . ', ' . var_export($escape, true) . ')';
			}

			return 'echo ' . $code . ';';
		});
	}
}
