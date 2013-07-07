<?php

namespace KD2;

/**
 * SkrivLite
 *
 * Lightweight and one-file implementation of Skriv Markup Language.
 *
 * What differs from the main SkrivML renderer:
 * - no smileys and symbols shortcuts
 * - no extensions (yet)
 * - no styled paragraphs (yet)
 * - ability to allow HTML (if enabled, you should only allow secure tags and use HTML tidy to make the code valid)
 * - no integration with GeShi for code highlighting, use your own callback to do that
 */

class SkrivLite
{
	const CALLBACK_CODE_HIGHLIGHT = 'codehl';
	const CALLBACK_URL_ESCAPING = 'urlescape';
	const CALLBACK_URL_SHORTENING = 'urlshort';
	const CALLBACK_TITLE_TO_ID = 'title2id';

	public $allow_html = true;

	protected $inline_tags = array(
			'**'	=>	'strong',
			"''"	=>	'em',
			'__'	=>	'u',
			'--'	=>	's',
			'##'	=>	'tt',
			'^^'	=>	'sup',
			',,'	=>	'sub'
		);

	protected $_inline_match = null;

	protected $_stack = array();

	protected $_verbatim = false;
	protected $_code = false;

	protected $_classes = array();

	protected $_callback = array();

	public function __construct()
	{
		$this->setCallback(self::CALLBACK_CODE_HIGHLIGHT, array(__NAMESPACE__ . '\SkrivLite_Helper', 'highlightCode'));
		$this->setCallback(self::CALLBACK_URL_ESCAPING, array(__NAMESPACE__ . '\SkrivLite_Helper', 'protectUrl'));
		$this->setCallback(self::CALLBACK_TITLE_TO_ID, array(__NAMESPACE__ . '\SkrivLite_Helper', 'titleToIdentifier'));
	}

	public function setCallback($function, $callback)
	{
		$callbacks = array(
			self::CALLBACK_CODE_HIGHLIGHT,
			self::CALLBACK_URL_ESCAPING,
			self::CALLBACK_URL_SHORTENING,
			self::CALLBACK_TITLE_TO_ID
		);

		if (!in_array($function, $callbacks))
		{
			throw new \UnexpectedValue('Invalid callback method "' . $function . '"');
		}

		if ((is_bool($callback) && $callback === false) || is_callable($callback))
		{
			$this->_callback[$function] = $callback;
		}
		else
		{
			throw new \UnexpectedValue('$callback is not a valid callback or FALSE');
		}

		return true;
	}

	protected function _buildInlineMatchFromTags()
	{
		$this->_inline_match = '';

		foreach ($this->inline_tags as $tag=>$html)
		{
			$this->_inline_match .= preg_quote($tag, '/') . '|';
		}

		$this->_inline_match = substr($this->_inline_match, 0, -1);
	}

	protected function _escape($text)
	{
		return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
	}

	protected function _renderInline($text)
	{
		if (!$this->allow_html)
		{
			$text = $this->_escape($text);
		}

		$tags = $this->inline_tags;

		// Simple inline tags
		$text = preg_replace_callback('/(?<![\\\\\S])(' . $this->_inline_match . ')(.*?)\\1/',
			function ($matches) use ($tags) {
				return '<' . $tags[$matches[1]] . '>' . $matches[2] . '</' . $tags[$matches[1]] . '>';
			}, $text);

		// Abbreviations
		$text = preg_replace_callback('/(?<![\\\\\S])\?\?([^|]+)\|(.+)\?\?/U', 
			function ($matches) {
				return '<abbr title="' . htmlspecialchars(trim($matches[2]), ENT_QUOTES, 'UTF-8', false) . '">' . trim($matches[1]) . '</abbr>';
			}, $text);

		// Links
		$callback = $this->_callback[self::CALLBACK_URL_ESCAPING];
		$text = preg_replace_callback('/\[\[(.+?)\]\]/', 
			function ($matches) use ($callback) 
			{
				if (($pos = strpos($matches[1], '|')) !== false)
				{
					$text = trim(substr($matches[1], 0, $pos));
					$url = trim(substr($matches[1], $pos + 1));
				}
				else
				{
					$text = $url = trim($matches[1]);
				}

				return '<a href="' . call_user_func($callback, $url) . '">'
					. htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
			}, $text);

		// Images
		$text = preg_replace_callback('/(?<![\\\\\S])\{\{(.+?)\}\}/', 
			function ($matches) use ($callback)
			{
				if (($pos = strpos($matches[1], '|')) !== false)
				{
					$text = trim(substr($matches[1], 0, $pos));
					$url = trim(substr($matches[1], $pos + 1));
				}
				else
				{
					$text = $url = trim($matches[1]);
				}

				return '<img src="' . call_user_func($callback, $url) . '" '
					. 'alt="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false) . '" />';
			}, $text);

		return $text;
	}

	protected function _closeStack()
	{
		$out = '';

		while ($tag = array_pop($this->_stack))
		{
			$out .= '</' . $tag . '>';
		}

		return $out;
	}

	protected function _checkLastStack($tag)
	{
		$last = count($this->_stack);

		if ($last === 0)
			return false;

		if ($this->_stack[$last - 1] == $tag)
			return true;

		return false;
	}

	protected function _countTagsInStack($search)
	{
		$count = 0;

		foreach ($this->_stack as $tag)
		{
			if ($tag == $search)
				$count++;
		}

		return $count;
	}

	protected function _renderLine($line, $prev = null, $next = null)
	{
		// In a verbatim block: no further processing
		if ($this->_verbatim && strpos($line, ']]]') !== 0)
		{
			if ($this->_code && $this->_callback[self::CALLBACK_CODE_HIGHLIGHT])
			{
				return call_user_func($this->_callback[self::CALLBACK_CODE_HIGHLIGHT], $this->_code, $line);
			}
			else
			{
				return $this->allow_html ? $line : $this->_escape($line);
			}
		}

		// Verbatim/Code
		if (strpos($line, '[[[') === 0)
		{
			$before = $this->_closeStack();
			$before .= '<pre>';
			$this->_stack[] = 'pre';

			// If programming language is given it's a code block
			if (trim(substr($line, 3)) !== '')
			{
				$language = strtolower(trim(substr($line, 3)));
				$before .= '<code class="language-' . $this->_escape($language) . '">';
				$this->_stack[] = 'code';
				$this->_code = $language;
			}

			$line = $before;
			$this->_verbatim = true;
		}
		// Closing verbatim/code block
		elseif (strpos($line, ']]]') === 0)
		{
			$line = $this->_closeStack();
			$this->_verbatim = false;
			$this->_code = false;
		}
		// Horizontal rule
		elseif (strpos($line, '----') === 0)
		{
			$line = '<hr />';
		}
		// Titles
		elseif (preg_match('#^(?<!\\\\)(={1,6})\s*(.*?)(?:\s*(?<!\\\\)\\1(?:\s*(.+))?)?$#', $line, $match))
		{
			$level = strlen($match[1]);
			$line = trim($match[2]);

			// Optional ID
			if (!empty($match[3]))
			{
				$id = $match[3];
			}
			else
			{
				$line = str_replace('\=', '=', $line);
				$id = $line;
			}

			$id = call_user_func($this->_callback[self::CALLBACK_TITLE_TO_ID], $id);
			$line = $this->_closeStack() . '<h' . $level . ' id="' . $id . '">' . $this->_renderInline($line) . '</h' . $level . '>';
		}
		// Quotes
		elseif (preg_match('#^(?<!\\\\)((?:>\s*)+)\s*(.*)$#', $line, $match))
		{
			// Number of opened <blockquotes>
			$nb_bq = $this->_countTagsInStack('blockquote');

			// Number of quotes character
			$nb_q = substr_count($match[1], '>');
			$before = $after = '';
			$line = trim($match[2]) == '' ? '' : $this->_renderInline($match[2]);

			// If we need to get one level down, we have to close some tags
			if ($nb_q < $nb_bq)
			{
				while ($nb_bq > $nb_q)
				{
					if ($this->_checkLastStack('p'))
					{
						array_pop($this->_stack);
						$before .= '</p>';
					}

					array_pop($this->_stack);
					$before .= '</blockquote>';

					$nb_bq--;
				}
			}

			// If we need to get one level up, we need to open some tags
			if ($nb_q > $nb_bq)
			{
				// First close any <p> tag opened
				if ($this->_checkLastStack('p'))
				{
					array_pop($this->_stack);
					$before .= '</p>';
				}

				while ($nb_bq < $nb_q)
				{
					$this->_stack[] = 'blockquote';
					$before .= '<blockquote>';
					$nb_bq++;
				}
			}

			// Empty line: close current paragraph if open
			if (trim($match[2]) == '' && $this->_checkLastStack('p'))
			{
				array_pop($this->_stack);
				$after .= '</p>';
			}
			// We're already in a paragraph: then the previous line needs a line-break
			elseif ($this->_checkLastStack('p'))
			{
				$before .= '<br />';
			}
			// If we are not in a paragraph and the line is not empty, then we need one for content
			elseif ($line != '')
			{
				$before .= '<p>';
				$this->_stack[] = 'p';
			}

			$line = $before . $line . $after;
		}
		// Preformatted text
		elseif (isset($line[0]) && $line[0] == ' ')
		{
			$before = '';

			if (!$this->_checkLastStack('pre'))
			{
				$before .= $this->_closeStack();
				$before .= '<pre>';
				$this->_stack[] = 'pre';
			}

			$line = $before . $this->_renderInline(substr($line, 1));
		}
		// Styled blocks
		elseif (preg_match('/^(?<!\\\\)((?:\{{3}\s*)+)\s*(.*)$/', $line, $match))
		{
			$this->_classes[] = trim($match[2]);
			$line = '<div class="' . implode(' ', $this->_classes) . '">';
		}
		// Closing styled blocks
		elseif (preg_match('/^(?<!\\\\)((?:\}{3}\s*)+)$/', $line, $match))
		{
			$nb_closing = substr_count($line, '}}}');
			$line = '';

			// Just checking we have the right amount of closing curly brackets
			// If not, let's just assume this is a mistake and close all styled blocks now
			if ($nb_closing != count($this->_classes))
			{
				while (count($this->_classes))
				{
					array_pop($this->_classes);
					$line .= '</div>';
				}
			}
			else
			{
				array_pop($this->_classes);
				$line .= '</div>';
			}

		}
		// Paragraphs breaks
		elseif (trim($line) == '')
		{
			$line = $this->_closeStack();
		}
		else
		{
			$line = $this->_renderInline($line);

			// Line has content but no <p> container, open one
			if (!$this->_checkLastStack('p'))
			{
				$paragraph = true;
				$line = '<p>' . $line;
				$this->_stack[] = 'p';
			}
			// Already in a <p>? that means the previous-line needs a line-break
			else
			{
				$line = '<br />' . $line;
			}
		}

		return $line;
	}

	public function render($text)
	{
		$this->_buildInlineMatchFromTags();

		$text = str_replace("\r", '', $text);
		$text = preg_replace("/\n{3,}/", "\n\n", $text);
		$text = preg_replace("/^\n+|\n+$/", '', $text); // Remove line breaks at beginning and end of text

		$text = explode("\n", $text);
		$max = count($text);

		foreach ($text as $i => &$line)
		{
			$line = $this->_renderLine(
				$line, 
				($i > 0) ? $text[$i - 1] : null, // Previous line
				($i + 1 < $max) ? $text[$i + 1] : null // Next line
			);
		}

		$line .= $this->_closeStack();

		return implode("\n", $text);
	}
}

/**
 * Some useful default callbacks for SkrivLite class
 */
class SkrivLite_Helper
{
	/**
	 * Allowed schemes in URLs
	 * @var array
	 */
    static public $allowed_url_schemes = array(
        'http'  =>  '://',
        'https' =>  '://',
        'ftp'   =>  '://',
        'mailto'=>  ':',
        'xmpp'  =>  ':',
        'news'  =>  ':',
        'nntp'  =>  '://',
        'tel'   =>  ':',
        'callto'=>  ':',
        'ed2k'  =>  '://',
        'irc'   =>  '://',
        'magnet'=>  ':',
        'mms'   =>  '://',
        'rtsp'  =>  '://',
        'sip'   =>  ':',
        );

	/**
	 * Simple and dirty code highlighter
	 * @param  string $language Language code in lowercase (not filtered for security)
	 * @param  string $line Code line to highlight (not escaped)
	 * @return string Highlighted code
	 */
	static public function highlightCode($language, $line)
	{
		
		$line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
		$line = preg_replace('![;{}[]$]!', '<b>$1</b>', $line);
		$line = preg_replace('!(public|static|protected|function|private|return)!i', '<i>$1</i>', $line);
		$line = preg_replace('!(false|true|boolean|bool|integer|int)!i', '<u>$1</u>', $line);
		return $line;
	}

	/**
	 * Protects a URL/URI given as an image/link target against XSS attacks
	 * (at least it tries) - copied from garbage2xhtml class by bohwaz
	 * @param  string 	$value 	Original URL
	 * @return string 	Filtered URL
	 */
	static public function protectUrl($value)
	{
        // Decode entities and encoded URIs
        $value = rawurldecode($value);
        $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

        // Convert unicode entities back to ASCII
        // unicode entities don't always have a semicolon ending the entity
        $value = preg_replace_callback('~&#x0*([0-9a-f]+);?~i', 
			function($match) { return chr(hexdec($match[1])); }, 
			$value);
        $value = preg_replace_callback('~&#0*([0-9]+);?~', 
        	function ($match) { return chr($match[1]); },
        	$value);

        // parse_url already have some tricks against XSS
        $url = parse_url($value);
        $value = '';

        if (!empty($url['scheme']))
        {
            $url['scheme'] = strtolower($url['scheme']);

            if (!array_key_exists($url['scheme'], self::$allowed_url_schemes))
                return '';

            $value .= $url['scheme'] . self::$allowed_url_schemes[$url['scheme']];
        }

        if (!empty($url['host']))
        {
            $value .= $url['host'];
        }

        if (!empty($url['path']))
        {
            $value .= $url['path'];
        }

        if (!empty($url['query']))
        {
            // We can't use parse_str and build_http_string to sanitize url here
            // Or else we'll get things like ?param1&param2 transformed in ?param1=&param2=
            $query = explode('&', $url['query']);

            foreach ($query as &$item)
            {
                $item = explode('=', $item);

                if (isset($item[1]))
                    $item = rawurlencode(rawurldecode($item[0])) . '=' . rawurlencode(rawurldecode($item[1]));
                else
                    $item = rawurlencode(rawurldecode($item[0]));
            }

            $value .= '?' . $this->escape(implode('&', $query));
        }

        if (!empty($url['fragment']))
        {
            $value .= '#' . $url['fragment'];
        }
        return $value;
	}

	/**
	 * Transforms a title (used in headings) to a unique identifier (used in id attribute)
	 * Copied from SkrivMarkup project by Amaury Bouchard
	 * @param  string $text original title
	 * @return string unique title identifier
	 */
	static public function titleToIdentifier($text)
	{
        // Don't process empty strings
        if (!trim($text))
            return '-';

		// conversion of accented characters
		// see http://www.weirdog.com/blog/php/supprimer-les-accents-des-caracteres-accentues.html
		$text = htmlentities($text, ENT_NOQUOTES, 'utf-8');
		$text = preg_replace('#&([A-za-z])(?:acute|cedil|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $text);
		$text = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $text);	// for ligatures e.g. '&oelig;'
		$text = preg_replace('#&([lr]s|sb|[lrb]d)(quo);#', ' ', $text);	// for *quote (http://www.degraeve.com/reference/specialcharacters.php)
		$text = str_replace('&nbsp;', ' ', $text);                      // for non breaking space
		$text = preg_replace('#&[^;]+;#', '', $text);                   // strips other characters

		$text = preg_replace("/[^a-zA-Z0-9_-]/", ' ', $text);           // remove any other characters
		$text = str_replace(' ', '-', $text);
		$text = preg_replace('/\s+/', " ", $text);
		$text = preg_replace('/-+/', "-", $text);
		$text = trim($text, '-');
		$text = trim($text);
		$text = empty($text) ? '-' : $text;

        return $text;
	}

}

?>