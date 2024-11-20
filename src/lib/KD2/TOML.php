<?php

namespace KD2;

/**
 * This class converts a string or file to an array, following
 * part of the TOML specification.
 *
 * The following notations are unsupported:
 * - use of quoted literals inside keys (eg. my."name.is" = "value")
 * - positive/negative integers (+32/-17)
 * - large numbers (5_349_221)
 * - hexadecimal (0xDEADBEEF)
 * - octal (0o01234567)
 * - binary (0o755)
 * - floats
 * - infinity/nan
 * - dot notation inside inline tables (animal = { type.name = "pug" })
 * - array of tables ([[products]])
 * - possible various complicated nested tables
 *
 * @see https://toml.io/en/v1.0.0
 */
class TOML
{
	static public function parseString(string $ini): array
	{
		// Handle comments
		$ini = preg_replace('!^\s*#!m', '; ', $ini);
		$ini = preg_replace('!#([^"]*)$!m', ';$1', $ini);
		// Multi-line strings, with line ending backslash
		$ini = preg_replace_callback(':"""((?!""").*)""":s', fn($m) => '"' . str_replace("\n", "\\n", preg_replace("!\\\n!", '', $m[1])) . '"', $ini);
		$ini = preg_replace_callback(":'''((?!''').*)''':s", fn($m) => "'" . str_replace("\n", "\\n", preg_replace("!\\\n!", '', $m[1])) . "'", $ini);
		$ini = @parse_ini_string($ini, true, INI_SCANNER_RAW);

		if (!$ini) {
			throw new \InvalidArgumentException('Unsupported TOML string');
		}

		return self::transform($ini);
	}

	static public function parseFile(string $path): array
	{
		return self::parseString(file_get_contents($path));
	}

	static public function transform(array $ini): array
	{
		$toml = [];

		foreach ($ini as $name => $value) {
			if (is_array($value)) {
				$value = self::transform($value);
			}
			else {
				$value = self::value($value);
			}

			$name = self::dot($name);

			if (count($name) > 1) {
				$current =& $toml;

				foreach ($name as $n) {
					$current[$n] ??= [];
					$current =& $current[$n];
				}
			}
			else {
				$current =& $toml[current($name)];
			}

			$current = $value;
			unset($current);
		}

		return $toml;
	}

	static protected function value(string $value)
	{
		if ($value === 'false') {
			return false;
		}
		elseif ($value === 'true') {
			return true;
		}
		elseif (ctype_digit($value)
			&& $value < PHP_INT_MAX) {
			return (int) $value;
		}
		elseif (ctype_digit(substr($value, 0, 4))
			&& ($date = DateTime::createFromFormat(DATE_RFC3339, $value))) {
			return $date;
		}
		// Object notation (inline table)
		elseif (($trim = trim($value))
			&& strlen($trim) >= 2
			&& (($trim[0] === '[' && substr($trim, -1) === ']')
				|| ($trim[0] === '{' && substr($trim, -1) === '}'))) {
			// Replace single quotes with double quotes
			$value = preg_replace_callback('!\'([^\']*)\'!', fn($m) => '"' . str_replace('"', '\\"', $m[1]) . '"', $value);
			// Handle object notation: {name = "pug", "bla" = stuff}
			$value = preg_replace_callback('!([\{,])\s*(?:"([^"]+)"|([^\s"]+))\s*=!',
				fn($m) => $m[1] . (isset($m[3]) ? '"' . $m[3] . '"' : $m[2]) . ': ',
				$value);
			// This is a massive code shortcut, but it will handle most cases
			return json_decode($value, true);
		}

		return $value;
	}

	static protected function dot(string $str): array
	{
		$quoted = false;
		$out = [];
		$current = '';

		for ($i = 0; $i < strlen($str); $i++) {
			$s = $str[$i];

			if (!$quoted && $s === '"') {
				$quoted = true;
			}
			elseif ($quoted && $s === '"') {
				$quoted = false;
			}
			elseif (!$quoted && $s === ' ') {
				// Skip spaces
				continue;
			}
			elseif (!$quoted && $s === '.') {
				$out[] = $current;
				$current = '';
			}
			else {
				$current .= $s;
			}
		}

		$out[] = $current;
		return $out;
	}
}
