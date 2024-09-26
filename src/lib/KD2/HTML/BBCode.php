<?php

/**
 * MikroMarkup BBCode
 * Copyleft (C) 2005-2023 BohwaZ <http://bohwaz.net/>
 *
 * Part of KD2 framework, see http://dev.kd2.org/kd2fw/
 *
 * BBCode reference: see http://bohwaz.net/p/BBcode
 * and http://www.bbcode.org/reference.php
 * and http://forums.phpbb-fr.com/faq.php?mode=bbcode
 * and https://fluxbb.org/forums/help.php
 */

namespace KD2\HTML;

class BBCode
{
	/**
	 * Enable a tag parsing by supplying it in the array
	 * @var [type]
	 */
	static public $tags = [
		'code',
		'quote',
		'img',
		'url',
		'email',
		'inline', // i, b, u, and s, del, ins, strong, em
		'h', // headers: [h]title[/h] and [h1-6]title[/h1-6]
		'style',
		'font',
		'colors',
		'size',
		'list',
	];

	static public function cleanArg($str)
	{
		$a = substr($str, 0, 1);

		if ($a == '"' || $a == '\'')
			return substr($str, 1, -1);
		else
			return $str;
	}

	static public function escapeArg($str)
	{
		return htmlspecialchars($str, ENT_QUOTES, 'UTF-8', false);
	}

	static public function escapeCleanArg($str)
	{
		return self::escapeArg(self::cleanArg($str));
	}

	/**
	 * Parse block arguments, this is similar to parsing HTML arguments
	 */
	static public function parseArguments(string $str): array
	{
		$args = [];
		$name = null;
		$state = 0;
		$last_value = '';

		preg_match_all('/(?:"(?:\\\\"|[^\"])*?"|\'(?:\\\\\'|[^\'])*?\'|(?>[^"\'=\s]+))+|[=]/i', $str, $match);

		foreach ($match[0] as $value) {
			if ($state === 0) {
				$name = $value;
			}
			elseif ($state === 1) {
				if ($value !== '=') {
					throw new \InvalidArgumentException('Expecting \'=\' after \'' . $last_value . '\'');
				}
			}
			elseif ($state === 2) {
				if ($value === '=') {
					throw new \InvalidArgumentException('Unexpected \'=\' after \'' . $last_value . '\'');
				}

				$args[$name] = self::getValueFromArgument($value);
				$name = null;
				$state = -1;
			}

			$last_value = $value;
			$state++;
		}

		unset($state, $last_value, $name, $str, $match);

		return $args;
	}

	static public function getValueFromArgument(string $arg): string
	{
		static $replace = [
			'\\"'  => '"',
			'\\\'' => '\'',
			'\\n'  => "\n",
			'\\t'  => "\t",
			'\\\\' => '\\',
		];

		if (strlen($arg) && ($arg[0] === '"' || $arg[0] === "'")){
			return strtr(substr($arg, 1, -1), $replace);
		}

		return $arg;
	}

	static public function render($str, $tags = null)
	{
		if (is_null($tags))
		{
			$tags = self::$tags;
		}

		if (!is_array($tags))
		{
			throw new \LogicException('$tags MUST be an array.');
		}

		// Use array keys, this is easier
		$tags = array_flip($tags);

		// No codes encoding right now, do it later
		$str = htmlspecialchars($str, ENT_NOQUOTES, 'UTF-8');
		$str = nl2br(trim($str));

		$arg = '(?:\s*=\s*(?P<arg>".*?"|\'.*?\'|.*?))';

		if ($tags['inline'] ?? null)
		{
			// [b] [i] [s] [u] (most BB parsers) [em] [strong] [ins] [del] (FluxBB)
			$str = preg_replace('#(?<!\\\\)\[(/?(?:b|i|u|s|strong|em|ins|del|sub|sup))\]#i', '<\\1>', $str);
		}

		if ($tags['h'] ?? null)
		{
			// [h] (PunBB/FluxBB) [h1-6] (custom)
			$str = preg_replace('#(?<!\\\\)\[(/?(?:h[1-6]?))\]#i', '<\\1>', $str);
		}

		if ($tags['code'] ?? null)
		{
			// [code] with PHP highlighting (PHPBB)
			$str = preg_replace_callback('#(?<!\\\\)\[(?P<tag>code|pre)'.$arg.'](?P<c>.*?)(?<!\\\\)\[/(?P=tag)\]#is', function($match) {
				$lang = self::cleanArg($match['arg']);

				if ($lang == 'php')
				{
					$c = trim($match['c'], "\r\n");
					$c = preg_replace('!^|^\s*<?php\s*!i', "<?php\n", $c);
					return '<pre class="code-' . self::escapeArg($lang) . '">' . highlight_string($c) . '</pre>';
				}

				return '[code]' . $match['c'] . '[/code]';
			}, $str);

			// [code], [pre]
			$str = preg_replace_callback('#(?<!\\\\)\[(?P<tag>code|pre)\](.*?)(?<!\\\\)\[/(?P=tag)\]#is', function ($match) {
				return '<pre class="code">' . trim($match[1], "\r\n") . '</pre>';
			}, $str);
		}

		if ($tags['quote'] ?? null)
		{
			// [quote="Author"], [quote=Author], [quote]
			$str = preg_replace('#(?<!\\\\)\[quote'.$arg.'\](.*?)(?<!\\\\)\[/quote\]#is', '<blockquote><cite>\\1</cite>\\2</blockquote>', $str);
			$str = preg_replace('#(?<!\\\\)\[quote\](.*?)(?<!\\\\)\[/quote\]#is', '<blockquote>\\1</blockquote>', $str);
		}

		if ($tags['img'] ?? null)
		{
			// [img=WIDTHxHEIGHT] as described on http://www.bbcode.org/reference.php
			$str = preg_replace_callback('#(?<!\\\\)\[img=(\d+)x(\d+)\](.*?)(?<!\\\\)\[/img\]#i', function ($match) {
				$w = min((int)$match[1], 1920);
				$h = min((int)$match[2], 1920);
				return '<img loading="lazy" src="' . self::escapeArg($match[3]) . '" alt="" width="' . $w . '" height="' . $h . '" />';
			}, $str);

			// [img=alt text], [img]
			$str = preg_replace_callback('#(?<!\\\\)\[img'.$arg.'?\](.*?)(?<!\\\\)\[/img\]#i', function ($match) {
				return '<img loading="lazy" src="' . self::escapeArg($match[2]) . '" alt="' . self::escapeCleanArg($match['arg']) . '" />';
			}, $str);

			// [img width=XXX alt="sfsd" align="center"]
			$str = preg_replace_callback('#(?<!\\\\)\[img\s+(.+)\](.*?)(?<!\\\\)\[/img\]#i', function ($match) {
				if (trim($match[1]) === '') {
					return $match[0];
				}

				$args = self::parseArguments($match[1]);
				$args = array_intersect_key($args, array_flip(['align', 'width', 'height', 'alt']));
				$args['src'] = $match[2];
				$args = array_map(fn($k, $v) => $k . '="' . self::escapeArg($v) . '"', array_keys($args), $args);
				$args = implode(' ', $args);

				return '<img loading="lazy" ' . $args . ' />';
			}, $str);
		}

		if ($tags['email'] ?? null)
		{
			// [email]address@domain.tld[/email], [email=address@domain.tld]Label text[/email]
			$str = preg_replace_callback('#(?<!\\\\)\[email'.$arg.'?\](.*?)(?<!\\\\)\[/email\]#i', function ($match) {
				$email = empty($match['arg']) ? $match[2] : self::cleanArg($match['arg']);
				return '<a href="mailto:' . str_replace('@', '&#64;', self::escapeArg($email)) . '">' . strtr($match[1], ['@' => '&#64;', '.' => '⋅']) . '</a>';
			}, $str);
		}

		if ($tags['url'] ?? null)
		{
			// [url]http://...[/url], [url=http://...]Label text[/url], [a]http://...[/a]
			$str = preg_replace_callback('#(?<!\\\\)\[(?P<tag>url|a)'.$arg.'?\](.*?)(?<!\\\\)\[/(?P=tag)\]#i', function ($match) {
				return '<a href="' . (!empty($match['arg']) ? self::cleanArg($match['arg']) : $match[2]) . '">' . $match[3] . '</a>';
			}, $str);
		}

		// [list], [list=a], [list=1] [*] item [/list], [ul], [ol]
		if ($tags['list'] ?? null) {
			$str = preg_replace_callback('#(?<!\\\\)\[list(?:=([a1]))\](.*?)\[/list\]#is', function ($match) {
				$tag = ($match[1] ?? 'a') === '1' ? 'ol' : 'ul';
				return '<' . $tag . '>' . $match[2] . '</' . $tag . '>';
			}, $str);

			$str = preg_replace('#(?<!\\\\)\[(/?(?:ol|ul|li))\]#', '<$1>', $str);
			$str = preg_replace('#(?<!\\\\)\[\*\]#', '<li>', $str);
		}

		// FIXME:
		// [size=10] (px) [size=10(px|em|ft|cm|mm|%...)]
		// [align=(center|left|right)], [center]
		// [font=...]

		// Skyblog gradients: text [x=#ABC-#DEC] background-color: [y=#ABC-#DEC]
		// colors/bgcolors: background color: [bg=color], [background=color], [f=color]
		// [color=#AABBCC], [color=red], [color=#ABC], [c=...]
		if ($tags['colors'] ?? null) {
			$str = preg_replace_callback('#(?<!\\\\)\[(x|y|c|color|f|bg|background|bgcolor)=(.+?)\](.*?)(?<!\\\\)\[/\1\]#i', function ($match) {
				if (!ctype_alnum(str_replace(['#', '-'], '', strtolower($match[2])))) {
					return $match[0];
				}

				$name = strtr($match[1], [
					'x'          => 'color',
					'y'          => 'bgcolor',
					'bg'         => 'bgcolor',
					'c'          => 'color',
					'f'          => 'bgcolor',
					'background' => 'bgcolor',
				]);

				$args = explode('-', $match[2]);
				$style = '';

				if ($name == 'color' && count($args) == 1) {
					$style .= 'color: ' . $args[0];
				}
				elseif ($name == 'color') {
					$style .= sprintf('background-size: 100%%; background: linear-gradient(to right, %s); -webkit-background-clip: text; -webkit-text-fill-color: transparent; -moz-text-fill-color: transparent; -moz-background-clip: text;', implode(', ', $args));
				}
				elseif ($name == 'bgcolor' && count($args) == 1) {
					$style .= 'background-color: ' . $args[0];
				}
				else {
					$style .= sprintf('background-size: 100%%; background: linear-gradient(to right, %s); -webkit-background-clip: initial; -webkit-text-fill-color: initial; -moz-text-fill-color: initial; -moz-background-clip: initial;', implode(', ', $args));
				}

				return sprintf('<span style="%s">%s</span>', $style, $match[3]);
			}, $str);
		}

		return $str;
	}

	/**
	 * Clean the generated HTML as best as we can
	 * @param  string $str xHTML string (from BBCode parsing and rendering)
	 * @return string      Possibly valid xHTML code
	 */
	static public function clean($str)
	{
		$str = trim($str);

		// Close and open <p> tags before block tags
		$str = preg_replace('!(?:<br\s*/?>)+<(blockquote|pre)!i', '</p><\\1', $str);
		$str = preg_replace('!</(blockquote|pre)>(?:<br\s*/?>)+!i', '</\\1><p>', $str);

		// Convert double line breaks in paragraph breaks (but keep subsequent line breaks)
		$str = preg_replace('!(<br\s*/?>\s*)(\\1)*\\1!', '</p><p>\\2', $str);

		// Add first <p>
		//$str = preg_replace('!^')
	}
}
