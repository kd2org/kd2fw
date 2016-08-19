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

class HTML_Document extends \DOMDocument
{
	static public function cssSelectorToXPath($selector)
	{
		$selector = trim($selector);

		// Multiple rules
		if (strpos($selector, ',') !== false)
		{
			$selector = preg_split('/\s*,\s*/', $selector);
			$path = [];

			foreach ($selector as $single)
			{
				$path[] = self::cssSelectorToXPath($single);
			}

			return implode(' | ', $path);
		}

		// http://plasmasturm.org/log/444/
		preg_match_all('/\[\s*([^=\*\|~\]]+)(?:\s*([~*\|]?=)\s*(.*?))?\s*\]|\.\w+|#\w+|\w+|\s*>\s*|\s*\+\s*|\s+/i', strtolower($selector), $tokens, PREG_SET_ORDER);

		$xpath = ['//'];

		foreach ($tokens as $token)
		{
			$t = trim($token[0]);

			// Separator
			if ($t == '+')
			{
				// div + form
				$xpath[] = '/following-sibling::*[1]/self::';
			}
			elseif ($t == '>')
			{
				// div > form
				$xpath[] = '/';
			}
			elseif ($t === '')
			{
				// div form
				$xpath[] = '/descendant-or-self::*/';
			}
			else if ($t[0] == '[')
			{
				// Remove quotes if necessary
				if (isset($token[3]) && ($token[3][0] == '"' || $token[3][0] == '\''))
				{
					$token[3] = substr($token[3], 1, -1);
				}

				// div[name]
				if (!isset($token[2]))
				{
					$xpath[] = '[@' . trim($token[1]) . ']';
				}
				// div[name="blob"]
				elseif ($token[2] == '=')
				{
					$xpath[] = sprintf('[@%s="%s"]', trim($token[1]), trim($token[3]));
				}
				// div[name~="blob"]
				elseif ($token[2] == '~=')
				{
					$xpath[] = sprintf('[contains(concat(" ", @%s, " "), " %s ")]', trim($token[1]), $token[3]);
				}
				// div[name|="blob"]
				elseif ($token[2] == '|=')
				{
					$xpath[] = sprintf('[@%s = %s or starts-with(@%1$s, "%2$s-"))', trim($token[1]), $token[3]);
				}
				elseif ($token[2] == '*=')
				{
					$xpath[] = sprintf('[@%s and contains(@%1$s, "%s")]', trim($token[1]), $token[3]);
				}
			}
			// div.class
			elseif ($t[0] == '.')
			{
				$xpath[] = '[@class and contains(concat(" ", normalize-space(@class), " "), " ' . substr($t, 1) . ' ")]';
			}
			elseif ($t[0] == '#')
			{
				$xpath[] = '[@id="' . substr($t, 1) . '"]';
			}
			// div
			else
			{
				$xpath[] = $t;
			}
		}

		$xpath = implode('', $xpath);
		$xpath = preg_replace('!(^|/)\[!', '$1*[', $xpath);
		return $xpath;
	}
}

class HTML_Node extends \DOMNode
{
	public function querySelector($query)
	{

	}

	public function querySelectorAll($query)
	{

	}


}