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
	public $allow_html = true;

	public $inline_tags = array(
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

	protected $_code_highlight_callback = null;

	public function __construct()
	{
		// Default callback
		$this->setCodeHighLightCallback(function ($language, $line)
		{
			if ($language == 'php')
			{
				if (strpos($line, '<?php') === false)
					$line = "<?php\n" . $line;
				
				$line = highlight_string($line, true);
			}
			else
			{
				$line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
			}
		});
	}

	public function setCodeHighLightCallback($callback)
	{
		if ((is_bool($callback) && $callback === false) || is_callable($callback))
			$this->_code_highlight_callback = $callback;
		else
			throw new UnexpectedValue('$callback is not a valid callback or FALSE');

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
				return '<abbr title="' . htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8') . '">' . $matches[1] . '</abbr>';
			}, $text);

		return $text;
	}

	protected function _titleToIdentifier($text)
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
			if ($this->_code && $this->_code_highlight_callback)
			{
				return call_user_func($this->_code_highlight_callback, $this->_code, $line);
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

			$id = $this->_titleToIdentifier($id);
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

		// If we're at the end of the processing, we must close opened tags
		if (is_null($next))
		{
			$line .= $this->_closeStack();
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
			$line = $this->_renderLine($line, 
				($i > 0) ? $text[$i - 1] : null, 
				($i + 1 < $max) ? $text[$i + 1] : null
			);
		}

		return implode("\n", $text);
	}
}



?>