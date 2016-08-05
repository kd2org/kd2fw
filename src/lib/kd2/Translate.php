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
 * Translate: a drop-in (almost) replacement to gettext functions
 * with no dependency on system locales or gettext
 *
 * Copyleft 2006-2016 BohwaZ
 *
 * @author	bohwaz	http://bohwaz.net/
 * @license	BSD
 */

use KD2\MemCache;

class Translate
{
	/**
	 * MemCache object used for caching of translation messages
	 * @var MemCache|null
	 */
	static protected $cache = null;

	/**
	 * Object cache of translation messages
	 * @var array
	 */
	static protected $translations = [];

	/**
	 * List of registered domains
	 * @var array
	 */
	static protected $domains = [];

	/**
	 * Default domain (by default is the first one registered with registerDomain)
	 * @var null|string
	 */
	static protected $default_domain = null;

	/**
	 * Current locale (set with ::setLocale)
	 * @var null
	 */
	static protected $locale = null;

	/**
	 * Set the MemCache object used for caching translation messages
	 *
	 * If no cache is set, messages will be reloaded from .mo or .po file every time
	 * 
	 * @param MemCache $cache_engine A MemCache object like MemCache_APCu (recommended)
	 */
	static public function setCacheEngine(MemCache $cache_engine)
	{
		self::$cache = $cache_engine;
	}

	/**
	 * Sets the locale (eg. en_US, fr_BE, etc.)
	 * @param string $locale Locale
	 */
	static public function setLocale($locale)
	{
		$locale = strtok($locale, '@.-+=%:; ');

		self::$locale = $locale;

		return self::$locale;
	}

	/**
	 * Registers a domain to a directory
	 *
	 * If domain is '*' (wild card) it will be used as a default when no domain is set and no default domain has been set
	 * 
	 * @param  string $domain    Translation domain (equivalent to a category, in practice will be the name of the file .po/.mo)
	 * @param  string $directory Directory where translations will be stored
	 * @return boolean
	 */
	static public function registerDomain($domain, $directory)
	{
		if (!is_null($directory) && !is_readable($directory))
		{
			throw new \InvalidArgumentException('Translations directory \'' . $directory . '\' does not exists or is not readable.');
		}

		self::$domains[$domain] = $directory;
		self::$translations[$domain] = [];

		if (is_null(self::$default_domain))
		{
			self::$default_domain = $domain;
		}

		return true;
	}

	/**
	 * Sets the default domain used for translating text
	 * @param  string $domain Domain
	 * @return void
	 */
	static public function setDefaultDomain($domain)
	{
		if (!array_key_exists($domain, self::$domains))
		{
			throw new \InvalidArgumentException('Unknown domain \'' . $domain . '\', did you call ::registerDomain(domain, directory) before?');
		}

		self::$default_domain = $domain;
	}

	/**
	 * Loads translations for this domain and the current locale, either from cache or from the .po/.mo file
	 * @param  string $domain Domain
	 * @return boolean
	 */
	static protected function _loadTranslations($domain, $locale = null)
	{
		// Fallback to default domain
		if (is_null($domain))
		{
			$domain = self::$default_domain;
		}

		if (is_null($locale))
		{
			$locale = self::$locale;
			$locale_short = strtok($locale, '_');
		}

		// Already loaded
		if (isset(self::$translations[$domain][$locale]))
		{
			return true;
		}

		self::$translations[$domain][$locale] = [];
		
		// If this domain exists
		if (isset(self::$domains[$domain]))
		{
			$dir = self::$domains[$domain];
		}
		// Or if we have a "catch-all" domain
		elseif (isset(self::$domains['*']))
		{
			$dir = self::$domains['*'];
		}
		// Or we fail
		else
		{
			throw new \InvalidArgumentException('Unknown gettext domain: ' . $domain);
		}

		$cache_key = 'gettext_' . $domain . '_' . $locale;

		// Try to fetch from cache
		if (!is_null(self::$cache) && self::$cache->exists($cache_key))
		{
			self::$translations[$domain][$locale] = self::$cache->get($cache_key);
			return true;
		}

		// If not, let's parse and load from .po or .mo file

		if (file_exists($dir . DIRECTORY_SEPARATOR . $locale))
		{
			$dir .= DIRECTORY_SEPARATOR . $locale;
		}
		elseif ($locale_short && file_exists($dir . DIRECTORY_SEPARATOR . $locale_short))
		{
			$dir .= DIRECTORY_SEPARATOR . $locale_short;
		}
		else
		{
			// No directory found, don't fail, just fallback to msgids
			return true;
		}

		// File path is domain_directory/locale/LC_MESSAGES/domain.mo
		// example: myApp/fr_BE/LC_MESSAGES/errors.mo
		$file = implode(DIRECTORY_SEPARATOR, [$dir, $locale, 'LC_MESSAGES', $domain]);

		if (file_exists($file . '.mo'))
		{
			self::$translations[$domain][$locale] = self::parseGettextMOFile($file . '.mo', true);
		}
		elseif (file_exists($file . '.po'))
		{
			self::$translations[$domain][$locale] = self::parseGettextPOFile($file . '.po', true);
		}

		return true;
	}

	/**
	 * Stores translations internally from an external source (eg. could be a PHP file, a INI file, YAML, JSON, etc.)
	 * @param  string $domain       Domain
	 * @param  string $locale       Locale
	 * @param  Array  $translations List of translations, in format array(msgid => array(0 => msgstr, 1 => plural form, 10 => plural form 10...))
	 * @return void
	 */
	static public function importTranslations($domain, $locale, Array $translations)
	{
		self::$translations[$domain][$locale] = $translations;
	}

	/**
	 * Returns array of loaded Translations for specified domain and locale
	 * @param  string $domain Domain
	 * @param  string $locale Locale
	 * @return array
	 */
	static public function exportTranslations($domain, $locale = null)
	{
		$locale = is_null($locale) ? self::$locale : $locale;
		self::_loadTranslations($domain, $locale);
		return self::$translations[$domain][$locale];
	}

	/**
	 * Guesses the gettext plural form from a 'Plural-Form' header (this is C code)
	 * @param  string $rule C-code describing a plural rule
	 * @param  integer $n   Number to use for the translation
	 * @return integer The number of the plural msgstr
	 */
	static protected function _parseGettextPlural($rule, $n)
	{
		strtok($rule, '='); // Skip
		$nplurals = (int) strtok(';');
		strtok('='); // skip
		$rule = strtok(''); // Get plural expression

		// Sanitizing input, just in case
		$rule = preg_replace('@[^n_:;\(\)\?\|\&=!<>+*/\%-]@i', '', $rule);

		// Add parenthesis for ternary operators
		$rule = preg_replace('/(.*?)\?(.*?):(.*)1/', '($1) ? ($2) : ($3)', $rule);
		$rule = rtrim($str, ';');
		$str = str_replace('n', '$n', $str);

		// Dirty trick, but this is the easiest way
		$plural = eval('return ' . $str . ';');

		if ($plural > $nplurals)
		{
			return $nplurals - 1;
		}

		return (int) $plural;
	}

	/**
	 * Returns a plural form from a locale code
	 *
	 * Contains all known plural rules to this day.
	 * 
	 * @link https://www.gnu.org/software/libc/manual/html_node/Advanced-gettext-functions.html
	 * @param  string $locale Locale
	 * @param  integer $n     Number used to determine the plural form to use
	 * @return integer The number of the plural msgstr
	 */
	static protected function _guessPlural($locale, $n)
	{
		if ($locale != 'pt_BR')
		{
			$locale = substr($locale, 0, 2);
		}

		switch ($lang)
		{
			// Romanic family: french, brazilian portugese 
			case 'fr':
			case 'pt_BR':
				return (int) $n > 1;
			// Asian family: Japanese, Vietnamese, Korean 
			// Tai-Kadai family: Thai 
			case 'ja':
			case 'th':
			case 'ko':
			case 'vi':
				return 0;
			// Slavic family: Russian, Ukrainian, Belarusian, Serbian, Croatian
			case 'ru':
			case 'uk':
			case 'be':
			case 'sr':
			case 'hr':
				return ($n % 100 / 10 == 1) ? 2 : (($n % 10) == 1 ? 0 : (($n + 9) % 10 > 3 ? 2 : 1));
			// Irish (Gaeilge)
			case 'ga':
				return $n == 1 ? 0 : ($n == 2 ? 1 : 2);
			// Latvian
			case 'lv':
				return ($n % 10 == 1 && $n % 100 != 11) ? 0 : ($n != 0 ? 1 : 2);
			// Lithuanian
			case 'lt':
				return ($n % 10 == 1 && $n % 100 != 11) ? 0 : ($n % 10 >= 2 && ($n % 100 < 10 || $n % 100 >= 20) ? 1 : 2);
				break;
			// Polish
			case 'pl':
				return ($n == 1) ? 0 : ($n % 10 >= 2 && $n % 10 <= 4 && (($n % 100 < 10 || $n % 100 >= 20) ? 1 : 2));
				break;
			// Slovenian
			case 'sl':
				return ($n % 100 == 1) ? 0 : ($n % 100 == 2 ? 1 : (($n % 100 == 3 || $n % 100 == 4) ? 2 : 3));
				break;
			// Slovak, Czech
			case 'sk':
			case 'cs':
				return ($n == 1) ? 1 : ($n >= 2 && $n <= 4) ? 2 : 0;
				break;
			// Arabic: 6 forms
			case 'ar':
				return ($n == 0) ? 0 : (($n == 1) ? 1 : (($n == 2) ? 2 : (($n % 100 >= 3 && $n %100 <= 10) ? 3 : (($n % 100 >= 11) ? 4 : 5))));

			// Germanic family: Danish, Dutch, English, German, Norwegian, Swedish
			// Finno-Ugric family: Estonian, Finnish
			// Latin/Greek family: Greek
			// Semitic family: Hebrew
			// Romance family: Italian, Portuguese, Spanish
			// Artificial: Esperanto
			// Turkic/Altaic family: Turkish
   			default:
				return (int) $n != 1;
		}
	}

	/**
	 * Translates a string
	 * @param  string $msgid1  Singular message to translate (or fallback to if no translation is found)
	 * @param  string $msgid2  Plural message to translate (or fallback)
	 * @param  integer $n      Number used to determine plural form of translation
	 * @param  string $domain  Optional domain
	 * @param  string $context Optional translation context (msgctxt in gettext)
	 * @return string
	 */
	static public function gettext($msgid1, $msgid2 = null, $n = null, $domain = null, $context = null)
	{
		self::_loadTranslations($domain);

		if (!is_null($msgid2) && !is_null($n))
		{
			// Try to guess the plural form
			if (($key = self::$_guessGettextPlural($rule, $n)) === false)
			{
				// Fallback to english
				$key = ($n != 1) ? 1 : 0;
			}
		}

		$id = $msgid1;

		// Append context of the msgid
		if (!is_null($context))
		{
			$id .= chr(4) . $context;
		}

		// No translations for this id
		if (!isset(self::$translations[$domain][self::$locale][$id]))
		{
			if ($msgid2 !== null && $n !== null)
			{
				// Use english plural rule here
				return ($n != 1) ? $msgid2 : $msgid1;
			}

			return $msgid1;
		}

		$plural = !is_null($n) && !is_null($msgid2) ? self::guessPluralForm(self::$locale, $n) : 0;

		if (!isset(self::$translations[$domain][self::$locale][$id][$plural]))
		{
			// No translation for this plural form: fallback to first form
			$plural = 0;
		}

		if (!isset(self::$translations[$domain][self::$locale][$id][$plural]))
		{
			// No translation for plural form, even after fallback, return msgid
			return $plural ? $msgid2 : $msgid1;
		}

		return self::$translations[$domain][self::$locale][$id][$plural];
	}

	/**
	 * Simple translation of a string
	 * @param  string      $msgid        Message ID to translate (will be used as fallback if no translation is found)
	 * @param  Array       $args         Optional arguments to replace in translated string
	 * @param  string      $domain       Optional domain
	 * @param  string      $context      Optional translation context (msgctxt in gettext)
	 * @return string
	 */
	static public function string($msgid, Array $args = [], $domain = null, $context = null)
	{
		if (is_array($id))
		{
			if (count($id) !== 3)
			{
				throw new \InvalidArgumentException('Invalid plural msgid: array should be [msgid, msgid_plural, count]');
			}

			$str = self::gettext($msgid[0], $msgid[1], $msgid[2], $domain, $context);
			$args['count'] = $msg[2];
		}
		else
		{
			$str = self::gettext($id, null, null, $domain, $context);
		}

		return self::named_sprintf($str, $args);
	}

	/**
	 * Plural translation
	 * @param  string      $msgid        Message ID to translate (will be used as fallback)
	 * @param  string      $msgid_plural Optional plural ID
	 * @param  integer     $count        Number used to determine which plural form should be returned
	 * @param  Array       $args         Optional arguments to replace in translated string
	 * @param  string      $domain       Optional domain
	 * @param  string      $context      Optional translation context (msgctxt in gettext)
	 * @return string
	 */
	static public function plural($msgid, $msgid_plural, $count, Array $args = [], $domain = null, $context = null)
	{
		$str = self::gettext($msgid, $msgid_plural, $count, $domain, $context);
		return self::named_sprintf($str, $args);
	}

	/**
	 * vsprintf + replace named arguments too (eg. %name)
	 * @param  string $str  String to format
	 * @param  array  $args Arguments
	 * @return string
	 */
	static public function named_sprintf($str, $args)
	{
		foreach ($args as $k=>$v)
		{
			$str = preg_replace('/%' . preg_quote($k, '/') . '(?=\s|[^\w\d_]|$)/', $v, $str);
		}

		if (strpos($str, '%') !== false)
		{
			return vsprintf($str, $args);
		}

		return $str;
	}

	/**
	 * Parses a gettext compiled .mo file and returns an array
	 * @link http://include-once.org/upgradephp-17.tgz Source
	 * @param  string $path .mo file path
	 * @param  boolean $one_msgid_only If set to true won't return an entry for msgid_plural
	 * (used internally to reduce cache size)
	 * @return array        array of translations
	 */
	static public function parseGettextMOFile($path, $one_msgid_only = false)
	{
		$fp = fopen($path, 'rb');

		// Read header
		$header = fread($fp, 20);
		$header = unpack('L1magic/L1version/L1count/L1o_msg/L1o_trn', $header);
		extract($header);

		if ((dechex($magic) != "950412de") || ($version != 0))
		{
			return false;
		}

		// Read the rest of the file
		$data = fread($fp, 1<<20);
		fclose($fp);

		if (!$data)
		{
			return false;
		}

		$translations = [];

		// fetch all entries
		for ($n = 0; $n < $count; $n++)
		{
			// msgid
			$r = unpack('L1len/L1offs', substr($data, $o_msg + $n * 8, 8));
			$msgid = substr($data, $r['offs'], $r['len']);
	
			if (strpos($msgid, "\000")) {
				list($msgid, $msgid_plural) = explode("\000", $msgid);
			}
	
			// translation(s)
			$r = unpack('L1len/L1offs', substr($data, $o_trn + $n * 8, 8));
			$msgstr = substr($data, $r["offs"], $r["len"]);
		
			if (strpos($msgstr, "\000")) {
				$msgstr = explode("\000", $msgstr);
			}

			$translations[$msgid] = $msgstr;
	
			if (isset($msgid_plural) && !$one_msgid_only)
			{
				$translations[$msgid_plural] =& $translations[$msgid];
			}
		}

		return $translations;
	}

	/**
	 * Parses a gettext raw .po file and returns an array
	 * @link http://include-once.org/upgradephp-17.tgz Source
	 * @param  string $path .po file path
	 * @param  boolean $one_msgid_only If set to true won't return an entry for msgid_plural
	 * (used internally to reduce cache size)
	 * @return array        array of translations
	 */
	static public function parseGettextPOFile($path, $one_msgid_only = false)
	{
		static $c_esc = ["\\n"=>"\n", "\\r"=>"\r", "\\\\"=>"\\", "\\f"=>"\f", "\\t"=>"\t", "\\"=>""];

		$fp = fopen($path, 'r');
		$translations = [];
		$msgid = $msgstr = [];
		$msgctxt = null;

		do
		{
			$line = trim(fgets($fp));

			$space = strpos($line, " ");

			// Ignore comments
			if ($line[0] == "#")
			{
				continue;
			}
			// msgid
			elseif (strncmp($line, "msgid", 5) == 0)
			{
				$msgid[] = trim(substr($line, $space + 1), '"');
			}
			// translation
			elseif (strncmp($line, "msgstr", 6) == 0)
			{
				$msgstr[] = trim(substr($line, $space + 1), '"');
			}
			// Context
			elseif (strncmp($line, 'msgctxt', 7) == 0)
			{
				$msgctxt = trim(substr($line, $space + 1), '"');
			}
			// continued (could be _id or _str)
			elseif ($line[0] == '"')
			{
				$line = trim($line, '"');

				if ($i = count($msgstr))
				{
					$msgstr[$i] .= $line;
				}
				elseif ($i = count($msgid))
				{
					$msgid[$i] .= $line;
				}
				elseif ($msgctxt !== null)
				{
					$msgctxt .= $line;
				}
			}

			// Complete dataset: append to translations
			if (count($msgid) && count($msgstr) && (empty($line) || ($line[0] == "#") || feof($f)) )
			{
				$msgid[0] = strtr($msgid[0], $c_esc);

				// context: link to msgid with a EOF character
				// see https://secure.php.net/manual/fr/book.gettext.php#89975
				if ($msgctxt !== null)
				{
					$msgid[0] .= chr(4) . $msgctxt;
				}

				$translations[$msgid[0]] = [];
				
				foreach ($msgstr as $v)
				{
					$translations[$msgid[0]][] = strtr($v, $c_esc);
				}

				if (isset($msgid[1]) && $one_msgid_only)
				{
					$msgid[1] = strtr($msgid[1], $c_esc);
					$translations[$msgid[1]] =& $translations[$msgid[0]];
				}

				$msgid = $msgstr = [];
				$msgctxt = null;
			}
		} while (!feof($fp));

		fclose($f);

		return $translations;
	}

	/**
	 * Returns the preferred language of the client from its HTTP Accept-Language header
	 * @param  boolean $full_locale Set to TRUE to get the real locale ('en_AU' for example), false will return only the lang ('en')
	 * @return string               Locale or language
	 */
	static public function getHttpLang($full_locale = false)
	{
		if (empty($_SERVER['HTTP_ACCEPT_LANGUAGE']))
		{
			return false;
		}

		// Convenient PECL Intl function
		if (function_exists('locale_accept_from_http'))
		{
			$locale = locale_accept_from_http($_SERVER['HTTP_ACCEPT_LANGUAGE']);
		}
		// Let's do the same thing by hand
		else
		{
			$http_langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
			$locale = null;
			$locale_priority = 0;

			// For each locale extract its priority
			foreach ($http_langs as $lang)
			{
				if (preg_match('/;q=([0-9.,]+)/', $item, $match))
				{
					$q = (int) $match[1] * 10;
					$lang = str_replace($match[0], '', $lang);
				}
				else
				{
					$q = 10;
				}

				$lang = strtolower(trim($lang));

				if (strlen($lang) > 2)
				{
					$lang = explode('-', $lang);
					$lang = array_slice($lang, 0, 2);
					$lang = $lang[0] . '_' . strtoupper($lang[1]);
				}

				// Higher priority than the previous one?
				// Let's use it then!
				if ($q > $locale_priority)
				{
					$locale = $lang;
				}
			}
		}

		if (is_null($locale))
		{
			return false;
		}

		return $full_locale ? $locale : substr($locale, 0, 2);
	}

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
				$code = '\KD2\ngettext(' . var_export($strings[0], true) . ', ' . var_export($strings[1], true) . ', (int) ' . $args['count'] . ')';
			}
			else
			{
				$code = '\KD2\gettext(' . var_export($strings[0], true) . ')';
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
				$code = '\KD2\Translate::named_vsprintf(' . $code . ', ' . $this->exportArguments($args) .')';
			}

			if ($escape != 'false' && $escape != 'off' && $escape !== '')
			{
				$code = 'self::escape(' . $code . ', ' . var_export($escape, true) . ')';
			}

			return 'echo ' . $code . ';';
		});
	}
}

// Gettext compatible functions
// Just prefix calls to gettext functions by \KD2\
// eg _("Hi!") => \KD2\_("Hi!")

function _($id, Array $args = [], $domain = null)
{
	return Translate::string($id, $args, $domain);
}

function gettext($id)
{
	return Translate::gettext($id);
}

function ngettext($id, $plural, $count)
{
	return Translate::gettext($id, $plural, $count);
}

function dgettext($domain, $id)
{
	return Translate::gettext($id, null, null, $domain);
}

function dngettext($domain, $id, $id_plural, $count)
{
	return Translate::gettext($id, $id_plural, $count, $domain);
}

function dcgettext($domain, $id, $category)
{
	return Translate::gettext($id, null, null, $domain);
}

function dcngettext($domain, $id, $id_plural, $count, $category)
{
	return Translate::gettext($id, $id_plural, $count, $domain);
}

function bind_textdomain_codeset($domain, $codeset)
{
	// Not used
}

function bindtextdomain($domain_name, $dir)
{
	return Translate::registerDomain($domain_name, $dir);
}

function textdomain($domain)
{
	return Translate::setDefaultDomain($domain);
}

function setlocale($category, $locale)
{
	if ($category == \LC_MESSAGES || $category == \LC_ALL)
	{
		Translate::setLocale($locale);
	}

	return call_user_func_array('setlocale', func_get_args());
}


// Context aware gettext functions
// see https://github.com/azatoth/php-pgettext/blob/master/pgettext.php
function pgettext($context, $msgid)
{
	return Translate::gettext($msgid, null, null, null, $context);
}

function dpgettext($domain, $context, $msgid)
{
	return Translate::gettext($msgid, null, null, $domain, $context);
}

function dcpgettext($domain, $context, $msgid, $category)
{
	return Translate::gettext($msgid, null, null, $domain, $context);
}

function npgettext($context, $msgid, $msgid_plural, $count)
{
	return Translate::gettext($msgid, $msgid_plural, $count, null, $context);
}

function dnpgettext($domain, $context, $msgid, $msgid_plural, $count)
{
	return Translate::gettext($msgid, $msgid_plural, $count, $domain, $context);
}

function dcnpgettext($domain, $context, $msgid, $msgid_plural, $count, $category)
{
	return Translate::gettext($msgid, $msgid_plural, $count, $domain, $context);
}
