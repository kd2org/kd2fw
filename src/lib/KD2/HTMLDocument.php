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

/**
 * Extends DOMDocument to provide querySelector and querySelectorAll methods
 */

namespace KD2;

trait HTML_Query_Selector
{
	/**
	 * Returns the first element matching a given CSS selector
	 * @param  string $selector  CSS selector
	 * @param  null $xpath_query Will be populated by XPath query translated from CSS selector
	 * @return DOMNode
	 */
	public function querySelector(string $selector, &$xpath_query = '')
	{
		$result = $this->querySelectorAll($selector, $xpath_query);

		if ($result->length == 0)
		{
			return false;
		}

		return $result->item(0);
	}

	/**
	 * Returns a list of elements matching a given CSS selector
	 * @param  string $selector  CSS selector
	 * @param  null $xpath_query Will be populated by XPath query translated from CSS selector
	 * @return DOMNodeList
	 */
	public function querySelectorAll(string $selector, &$xpath_query = '')
	{
		$xpath_query = self::cssSelectorToXPath($selector);

		$xpath = new \DOMXPath($this instanceOf \DOMDocument ? $this : $this->ownerDocument);

		try {
			return $xpath->query($xpath_query, $this);
		}
		catch (\Exception $e) {
			throw new \Exception(sprintf('%s (for selector: "%s" = "%s")', $e->getMessage(), $selector, $xpath_query), $e->getCode(), $e);
		}
	}

	/**
	 * Converts a CSS selector to a XPath query
	 *
	 * Support is better than Symfony and Zend, but some features are still missing:
	 * - namespaces support
	 * - :first-child and :last-child are unsupported
	 * - :hover, :active, :focus etc. are obviously unsupported
	 * - :nth-of-type = no support
	 * - :nth-child supports only (odd), (even), (x) where x is an integer, no support for (2n+1) or (-n6) etc.
	 *
	 * @link http://plasmasturm.org/log/444/
	 * @link https://github.com/zendframework/zend-dom
	 * @link https://github.com/symfony/css-selector
	 * @link https://github.com/siuying/CSSSelectorConverter
	 * 
	 * @param  string $selector CSS selector
	 * @param  boolean $raw		TRUE if the XPath query should be returned without leading // (internal use)
	 * @return string           XPath query
	 */
	static public function cssSelectorToXPath($selector, $raw = false)
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

		// Split by tokens
		preg_match_all('/\[\s*(.+?)(?:\s*([~\^*\|$]?=)\s*(.*?))?\s*\]|:[a-z-]+(?:\((.*?)\))?|\.[\w-]+|#[\w-]+|\w+|\*|\s*~\s*|\s*>\s*|\s*\+\s*|\s+/i', $selector, $tokens, PREG_SET_ORDER);

		$xpath = [];

		foreach ($tokens as $token)
		{
			$t = trim($token[0]);

			// E + F
			// an F element immediately preceded by an E element
			if ($t == '+')
			{
				// div + form
				$xpath[] = '/following-sibling::*[1]/self::';
			}
			// E > F
			// an F element child of an E element
			elseif ($t == '>')
			{
				// div > form
				$xpath[] = '/';
			}
			// E ~ F
			// an F element preceded by an E element
			elseif ($t == '~')
			{
				// div ~ form
				$xpath[] = '/following-sibling::';
			}
			// E F
			// an F element descendant of an E element
			elseif ($t === '')
			{
				// div form
				$xpath[] = '//';
			}
			// E:empty
			// an E element that has no children (including text nodes)
			elseif ($t == ':empty')
			{
				$xpath[] = '[not(node())]';
			}
			// E:first-of-type
			// an E element, first sibling of its type
			elseif ($t == ':first-of-type')
			{
				$xpath[] = '[position() = 1]';
			}
			// E:last-of-type
			// an E element, last sibling of its type
			elseif ($t == ':last-of-type')
			{
				$xpath[] = '[position() = last()]';
			}
			// E:only-of-type
			// an E element, only sibling of its type
			elseif ($t == ':only-of-type')
			{
				$xpath[] = '[last() = 1]';
			}
			// E:nth-child(n)
			// an E element, the n-th child of its parent
			elseif (substr($t, 0, 10) == ':nth-child')
			{
				$operation = $token[4];
				
				if ($operation == 'odd')
				{
					$xpath[] = '[(position() >= 1) and (((position()-1) mod 2) = 0)]';
				}
				elseif ($operation == 'even')
				{
					$xpath[] = '[(position() mod 2) = 0]';
				}
				elseif (is_numeric($operation))
				{
					$xpath[] = '[position() = ' . (int) $operation . ']';
				}
				else
				{
					throw new \InvalidArgumentException(':nth-child operation \'' . $operation . '\' is not supported.');
				}
			}
			// Negation selector
			// E:not(s)
			// an E element that does not match simple selector s
			elseif (substr($t, 0, 4) == ':not')
			{
				$expr = self::cssSelectorToXPath($token[4], true);

				if ($expr[0] == '[')
				{
					$expr = substr($expr, 1, -1);
				}

				$xpath[] = '[not(' . $expr . ')]';
			}
			// Attribute selectors
			elseif ($t[0] == '[')
			{
				$attr = $token[1];
				$operator = isset($token[2]) ? $token[2] : false;
				$value = isset($token[3]) ? $token[3] : null;

				// Remove quotes if necessary
				if ($value && ($value[0] == '"' || $value[0] == '\''))
				{
					$value = substr($value, 1, -1);
				}

				// E[foo]
				// an E element with a "foo" attribute
				if (!$operator)
				{
					$xpath[] = '[@' . trim($attr) . ']';
				}
				// E[foo="bar"]
				// an E element whose "foo" attribute value is exactly equal to "bar"
				elseif ($operator == '=')
				{
					$xpath[] = sprintf('[@%s="%s"]', trim($attr), trim($value));
				}
				// E[foo~="bar"]
				// an E element whose "foo" attribute value is a list of whitespace-separated values, one of which is exactly equal to "bar"
				elseif ($operator == '~=')
				{
					$xpath[] = sprintf('[contains(concat(" ", @%s, " "), " %s ")]', trim($attr), $value);
				}
				// E[foo^="bar"]
				// an E element whose "foo" attribute value begins exactly with the string "bar"
				elseif ($operator == '^=')
				{
					$xpath[] = sprintf('[@%s="%s" or starts-with(@%1$s, "%2$s")]', trim($attr), $value);
				}
				// E[foo$="bar"]
				// an E element whose "foo" attribute value ends exactly with the string "bar"
				elseif ($operator == '$=')
				{
					// ends-with() only supported in XPath 2.0
					// https://stackoverflow.com/questions/22436789/xpath-ends-with-does-not-work
					// substring(@id, string-length(@id) - string-length('register') +1) = 'register'
					$xpath[] = sprintf('[@%s="%s" or substring(@%1$s, string-length(@%1$s) - %3$d + 1) = "%2$s"]', trim($attr), $value, strlen($value));
				}
				// E[foo*="bar"]
				// an E element whose "foo" attribute value contains the substring "bar"
				elseif ($operator == '*=')
				{
					$xpath[] = sprintf('[@%s and contains(@%1$s, "%s")]', trim($attr), $value);
				}
				// E[foo|="en"]
				// an E element whose "foo" attribute has a hyphen-separated list of values beginning (from the left) with "en"
				elseif ($operator == '|=')
				{
					$xpath[] = sprintf('[@%s="%s" or starts-with(@%1$s, "%2$s-")]', trim($attr), $value);
				}
			}
			// E.warning
			// an E element whose class is "warning"
			elseif ($t[0] == '.')
			{
				$xpath[] = '[@class and contains(concat(" ", normalize-space(@class), " "), " ' . substr($t, 1) . ' ")]';
			}
			// E#myid
			// an E element with ID equal to "myid".
			elseif ($t[0] == '#')
			{
				$xpath[] = '[@id="' . substr($t, 1) . '"]';
			}
			// Other unsupported pseudo selectors
			elseif ($t[0] == ':')
			{
				throw new \InvalidArgumentException('CSS selector ' . $t . ' is not supported.');
			}
			// element itself
			else
			{
				$xpath[] = $t;
			}
		}

		$xpath = implode('', $xpath);

		if (!$raw)
		{
			$xpath = '//' . $xpath;

			// Add wildcard where there is no element
			$xpath = preg_replace('!(^|/)\[!', '$1*[', $xpath);
		}

		// Always return relative queries, see http://php.net/manual/fr/domxpath.query.php#99760
		return '.' . $xpath;
	}
}

/**
 * Extends DOMDocument by adding querySelector/querySelectorAll on Document and Node objects
 */
class HTMLDocument extends \DOMDocument
{
	use HTML_Query_Selector;

	protected $errors = null;

	/**
	 * Constructor, registers HTML_Node and HTML_Element to add querySelector[All] methods
	 */
	public function __construct($version = null, $encoding = null)
	{
		parent::__construct($version, $encoding);
		$this->registerNodeClass('DOMNode', '\KD2\HTMLNode');
		$this->registerNodeClass('DOMElement', '\KD2\HTMLElement');
	}

	/**
	 * Load HTML from a string
	 * @param  string $source  HTML string
	 * @param  integer $options use the options parameter to specify additional Libxml parameters
	 * @return bool Returns TRUE on success or FALSE on failure.
	 */
	public function loadHTML($source, $options = null)
	{
		if (is_null($options))
		{
			$options = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
		}

		// Temporary disable throwing errors / exceptions / warnings
		// you can see them later using ->getErrors()
		libxml_use_internal_errors(true);

		$r = parent::loadHTML($source, $options);

		$this->errors = libxml_get_errors();

		libxml_use_internal_errors(false);
		return $r;
	}

	/**
	 * Load HTML from a file
	 * @param  string $filename The path to the HTML file
	 * @param  integer $options  use the options parameter to specify additional Libxml parameters
	 * @return boolean Returns TRUE on success or FALSE on failure.
	 */
	public function loadHTMLFile($filename, $options = null)
	{
		if (is_null($options))
		{
			$options = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
		}

		// Temporary disable throwing errors / exceptions
		// you can see them later using ->getErrors()
		libxml_use_internal_errors(true);

		$r = parent::loadHTMLFile($filename, $options);

		$this->errors = libxml_get_errors();

		libxml_use_internal_errors(false);
		return $r;
	}

	/**
	 * Returns libxml errors from the last call to loadHTML/loadHTMLFile
	 * @return array
	 */
	public function getErrors()
	{
		return $this->errors;
	}
}

/**
 * Extends DOMNode with querySelector and querySelectorAll
 */
class HTMLNode extends \DOMNode
{
	use HTML_Query_Selector;
}

/**
 * Extends DOMNode with querySelector and querySelectorAll
 */
class HTMLElement extends \DOMElement
{
	use HTML_Query_Selector;
}