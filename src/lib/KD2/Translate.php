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

namespace KD2;

/**
 * Translate: a drop-in (almost) replacement to gettext functions
 * with no dependency on system locales or gettext
 */

use KD2\MemCache;
use IntlDateFormatter;

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
		\setlocale(LC_ALL, $locale);

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
	static public function registerDomain($domain, $directory = null)
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

	static public function unregisterDomain($domain)
	{
		unset(self::$translations[$domain], self::$domains[$domain]);

		if (self::$default_domain === $domain)
		{
			self::$default_domain = null;
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

		return true;
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
		if (isset(self::$translations[$domain][$locale]) || isset(self::$translations[$domain][$locale_short]))
		{
			return $domain;
		}

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

		self::$translations[$domain][$locale] = [];

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
			return $domain;
		}

		// File path is domain_directory/locale/LC_MESSAGES/domain.mo
		// example: myApp/fr_BE/LC_MESSAGES/errors.mo
		$file = implode(DIRECTORY_SEPARATOR, [$dir, 'LC_MESSAGES', $domain]);

		if (file_exists($file . '.mo'))
		{
			self::$translations[$domain][$locale] = self::parseGettextMOFile($file . '.mo', true);
		}
		elseif (file_exists($file . '.po'))
		{
			self::$translations[$domain][$locale] = self::parseGettextPOFile($file . '.po', true);
		}

		return $domain;
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
		if (!array_key_exists($domain, self::$translations))
		{
			self::registerDomain($domain);
		}

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
		$rule = rtrim($rule, ';');
		$rule = str_replace('n', '$n', $rule);

		// Dirty trick, but this is the easiest way
		$plural = eval('return ' . $rule . ';');

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

		switch ($locale)
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
				return ($n == 1) ? 1 : (($n >= 2 && $n <= 4) ? 2 : 0);
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
		$domain = self::_loadTranslations($domain);

		$id = $msgid1;

		// Append context of the msgid
		if (!is_null($context))
		{
			$id = $context . chr(4) . $id;
		}

		$locale_short = strtok(self::$locale, '_');
		$str = null;

		if (isset(self::$translations[$domain][self::$locale][$id]))
		{
			$str = self::$translations[$domain][self::$locale][$id];
		}
		elseif (isset(self::$translations[$domain][$locale_short][$id]))
		{
			$str = self::$translations[$domain][$locale_short][$id];
		}

		// No translations for this id
		if ($str === null)
		{
			if ($msgid2 !== null && $n !== null)
			{
				// Use english plural rule here
				return ($n != 1) ? $msgid2 : $msgid1;
			}

			return $msgid1;
		}

		$plural = !is_null($n) && !is_null($msgid2) ? self::_guessPlural(self::$locale, $n) : 0;

		if (!isset($str[$plural]))
		{
			// No translation for this plural form: fallback to first form
			$plural = 0;
		}

		if (!isset($str[$plural]))
		{
			// No translation for plural form, even after fallback, return msgid
			return $plural ? $msgid2 : $msgid1;
		}

		return $str[$plural];
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
		if (is_array($msgid))
		{
			if (count($msgid) !== 3)
			{
				throw new \InvalidArgumentException('Invalid plural msgid: array should be [msgid, msgid_plural, count]');
			}

			$str = self::gettext($msgid[0], $msgid[1], $msgid[2], $domain, $context);
			$args['count'] = $msgid[2];
		}
		else
		{
			$str = self::gettext($msgid, null, null, $domain, $context);
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
		$data = fread($fp, 20);
		$header = unpack('L1magic/L1version/L1count/L1o_msg/L1o_trn', $data);
		extract($header);

		if ((dechex($magic) != '950412de') || ($version != 0))
		{
			return false;
		}

		// Read the rest of the file
		$data .= fread($fp, 1<<20);

		if (!$data)
		{
			return false;
		}

		$translations = [];

		// fetch all entries
		for ($n = 0; $n < $count; $n++)
		{
			// msgid
			$r = unpack('L1length/L1offset', substr($data, $o_msg + $n * 8, 8));
			$msgid = substr($data, $r['offset'], $r['length']);

			if (strpos($msgid, "\000")) {
				list($msgid, $msgid_plural) = explode("\000", $msgid);
			}

			// translation(s)
			$r = unpack('L1length/L1offset', substr($data, $o_trn + $n * 8, 8));
			$msgstr = explode(chr(0), substr($data, $r['offset'], $r['length']));

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
			if (substr($line, 0, 1) == "#")
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
			elseif (substr($line, 0, 1) == '"')
			{
				$line = trim($line, '"');

				if ($i = count($msgstr))
				{
					if (!isset($msgstr[$i]))
					{
						$msgstr[$i] = '';
					}

					$msgstr[$i] .= $line;
				}
				elseif ($i = count($msgid))
				{
					if (!isset($msgid[$i]))
					{
						$msgid[$i] = '';
					}

					$msgid[$i] .= $line;
				}
				elseif ($msgctxt !== null)
				{
					$msgctxt .= $line;
				}
			}

			// Complete dataset: append to translations
			if (count($msgid) && count($msgstr) && (empty($line) || ($line[0] == "#") || feof($fp)))
			{
				$msgid[0] = strtr($msgid[0], $c_esc);

				// context: link to msgid with a EOF character
				// see https://secure.php.net/manual/fr/book.gettext.php#89975
				if ($msgctxt !== null)
				{
					$msgid[0] = $msgctxt . chr(4) . $msgid[0];
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

		fclose($fp);

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
			return null;
		}

		// Convenient PECL Intl function
		if (function_exists('locale_accept_from_http'))
		{
			$default = ini_get('intl.use_exceptions');
			ini_set('intl.use_exceptions', 1);

			try {
				$locale = locale_accept_from_http($_SERVER['HTTP_ACCEPT_LANGUAGE']);
				return ($full_locale || !$locale) ? $locale : substr($locale, 0, 2);
			}
			catch (\IntlException $e) {
				return null;
			}
			finally {
				ini_set('intl.use_exceptions', $default);
			}
		}

		// Let's do the same thing by hand
		$http_langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
		$locale = null;
		$locale_priority = 0;

		// For each locale extract its priority
		foreach ($http_langs as $lang)
		{
			if (preg_match('/;q=([0-9.,]+)/', $lang, $match))
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

		return ($full_locale || !$locale) ? $locale : substr($locale, 0, 2);
	}

	/**
	 * Locale-formatted strftime using IntlDateFormatter (PHP 8.1 compatible)
	 * @param  string $format Date format
	 * @param  integer|string|DateTime $timestamp Timestamp
	 * @return string
	 */
	static public function strftime(string $format, $timestamp = null, ?string $locale = null): string
	{
		if (null === $timestamp) {
			$timestamp = new \DateTime;
		}
		elseif (is_numeric($timestamp)) {
			$timestamp = date_create('@' . $timestamp);
		}
		elseif (is_string($timestamp)) {
			$timestamp = date_create('!' . $timestamp);
		}

		if (!($timestamp instanceof \DateTimeInterface)) {
			throw new \InvalidArgumentException('$timestamp argument is neither a valid UNIX timestamp, a valid date-time string or a DateTime object.');
		}

		$locale = substr($locale ?? self::$locale, 0, 5);

		$intl_formats = [
			'%a' => 'EEE',	// An abbreviated textual representation of the day	Sun through Sat
			'%A' => 'EEEE',	// A full textual representation of the day	Sunday through Saturday
			'%b' => 'MMM',	// Abbreviated month name, based on the locale	Jan through Dec
			'%B' => 'MMMM',	// Full month name, based on the locale	January through December
			'%h' => 'MMM',	// Abbreviated month name, based on the locale (an alias of %b)	Jan through Dec
			'%p' => 'aa',	// UPPER-CASE 'AM' or 'PM' based on the given time	Example: AM for 00:31, PM for 22:23
			'%P' => 'aa',	// lower-case 'am' or 'pm' based on the given time	Example: am for 00:31, pm for 22:23
		];

		$intl_formatter = function (\DateTimeInterface $timestamp, string $format) use ($intl_formats, $locale) {
			$tz = $timestamp->getTimezone();
			$date_type = IntlDateFormatter::FULL;
			$time_type = IntlDateFormatter::FULL;
			$pattern = '';

			// %c = Preferred date and time stamp based on locale
			// Example: Tue Feb 5 00:45:10 2009 for February 5, 2009 at 12:45:10 AM
			if ($format == '%c') {
				$date_type = IntlDateFormatter::LONG;
				$time_type = IntlDateFormatter::SHORT;
			}
			// %x = Preferred date representation based on locale, without the time
			// Example: 02/05/09 for February 5, 2009
			elseif ($format == '%x') {
				$date_type = IntlDateFormatter::SHORT;
				$time_type = IntlDateFormatter::NONE;
			}
			// Localized time format
			elseif ($format == '%X') {
				$date_type = IntlDateFormatter::NONE;
				$time_type = IntlDateFormatter::MEDIUM;
			}
			else {
				$pattern = $intl_formats[$format];
			}

			return (new IntlDateFormatter($locale, $date_type, $time_type, $tz, null, $pattern))->format($timestamp);
		};

		// Same order as https://www.php.net/manual/en/function.strftime.php
		$translation_table = [
			// Day
			'%a' => $intl_formatter,
			'%A' => $intl_formatter,
			'%d' => 'd',
			'%e' => 'j',
			'%j' => function ($timestamp) {
				// Day number in year, 001 to 366
				return sprintf('%03d', $timestamp->format('z')+1);
			},
			'%u' => 'N',
			'%w' => 'w',

			// Week
			'%U' => function ($timestamp) {
				// Number of weeks between date and first Sunday of year
				$day = new \DateTime(sprintf('%d-01 Sunday', $timestamp->format('Y')));
				return intval(($timestamp->format('z') - $day->format('z')) / 7);
			},
			'%W' => function ($timestamp) {
				// Number of weeks between date and first Monday of year
				$day = new \DateTime(sprintf('%d-01 Monday', $timestamp->format('Y')));
				return intval(($timestamp->format('z') - $day->format('z')) / 7);
			},
			'%V' => 'W',

			// Month
			'%b' => $intl_formatter,
			'%B' => $intl_formatter,
			'%h' => $intl_formatter,
			'%m' => 'm',

			// Year
			'%C' => function ($timestamp) {
				// Century (-1): 19 for 20th century
				return (int) $timestamp->format('Y') / 100;
			},
			'%g' => function ($timestamp) {
				return substr($timestamp->format('o'), -2);
			},
			'%G' => 'o',
			'%y' => 'y',
			'%Y' => 'Y',

			// Time
			'%H' => 'H',
			'%k' => 'G',
			'%I' => 'h',
			'%l' => 'g',
			'%M' => 'i',
			'%p' => $intl_formatter, // AM PM (this is reversed on purpose!)
			'%P' => $intl_formatter, // am pm
			'%r' => 'G:i:s A', // %I:%M:%S %p
			'%R' => 'H:i', // %H:%M
			'%S' => 's',
			'%X' => $intl_formatter,// Preferred time representation based on locale, without the date

			// Timezone
			'%z' => 'O',
			'%Z' => 'T',

			// Time and Date Stamps
			'%c' => $intl_formatter,
			'%D' => 'm/d/Y',
			'%F' => 'Y-m-d',
			'%s' => 'U',
			'%x' => $intl_formatter,
		];

		$out = preg_replace_callback('/(?<!%)(%[a-zA-Z])/', function ($match) use ($translation_table, $timestamp) {
			if ($match[1] == '%n') {
				return "\n";
			}
			elseif ($match[1] == '%t') {
				return "\t";
			}

			if (!isset($translation_table[$match[1]])) {
				throw new \InvalidArgumentException(sprintf('Format "%s" is unknown in time format', $match[1]));
			}

			$replace = $translation_table[$match[1]];

			if (is_string($replace)) {
				return $timestamp->format($replace);
			}
			else {
				return $replace($timestamp, $match[1]);
			}
		}, $format);

		$out = str_replace('%%', '%', $out);
		return $out;
	}

	/**
	 * Returns an associative array list of countries (ISO-3166:2013)
	 *
	 * @param  string $lang Language to use (only 'fr' and 'en' are available)
	 * @return array
	 */
	static public function getCountriesList($lang = null)
	{
		if (null === $lang)
		{
			$lang = substr(self::$locale, 0, 2);
		}

		if ($lang != 'fr')
		{
			$lang = 'en';
		}

		$path = sprintf('%s/data/countries.%s.json', __DIR__, $lang);
		$file = file_get_contents($path);

		return json_decode($file, true);
	}

	/**
	 * Register a new template block in Smartyer to call KD2Intl::gettext()
	 * @param  Smartyer &$tpl Smartyer instance
	 * @return Smartyer
	 */
	static public function extendSmartyer(Smartyer &$tpl)
	{
		$tpl->register_modifier('date_format', function ($timestamp, $format = '%c') {
			if (!is_numeric($timestamp))
			{
				$timestamp = strtotime($timestamp);
			}

			if (strpos('DATE_', $format) === 0 && defined($format))
			{
				return date(constant($format), $timestamp);
			}

			return \KD2\Translate::strftime($format, $timestamp);
		});

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

			$code = sprintf('\KD2\Translate::gettext(%s, ', var_export($strings[0], true));

			if ($nb_strings > 1)
			{
				if (!isset($args['n']))
				{
					$this->parseError($pos, 'Multiple strings in translation block, but no \'n\' argument.');
				}

				$code .= sprintf('%s, (int) %s, ', var_export($strings[1], true), $args['n']);
			}
			else
			{
				$code .= 'null, null, ';
			}

			// Add domain and context
			$code .= sprintf('%s, %s)',
				isset($args['domain']) ? $args['domain'] : 'null',
				isset($args['context']) ? $args['context'] : 'null');

			$escape = $this->escape_type;

			if (isset($args['escape']))
			{
				$escape = strtolower($args['escape']);
			}

			unset($args['escape'], $args['domain'], $args['context']);

			// Use named arguments: %name, %nb_apples...
			// This will cause weird bugs if you use %s, or %d etc. before or between named arguments
			if (!empty($args))
			{
				$code = sprintf('\KD2\Translate::named_sprintf(%s, %s)', $code, $this->exportArguments($args));
			}

			if ($escape != 'false' && $escape != 'off' && $escape !== '')
			{
				$code = sprintf('self::escape(%s, %s)', $code, var_export($escape, true));
			}

			return 'echo ' . $code . ';';
		});
	}
}

/*
	Gettext compatible functions
	Just prefix calls to gettext functions by \KD2\
	eg _("Hi!") => \KD2\_("Hi!")
	Or add at the top of your files:

	// PHP 5.6
	use function \KD2\_;
	use function \KD2\gettext;
	use function \KD2\ngettext;
	use function \KD2\dgettext;
	use function \KD2\dngettext;
	use function \KD2\bindtextdomain;
	use function \KD2\textdomain;
	use function \KD2\setlocale;

	// PHP 7+
	use function \KD2\{_, gettext, ngettext, dgettext, dngettext, bindtextdomain, textdomain, setlocale}
*/

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
