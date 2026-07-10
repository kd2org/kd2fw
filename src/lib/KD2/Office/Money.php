<?php

namespace KD2\Office;

class Money
{
	static public function toInteger(string $value, int $decimals = 2): int
	{
		$int = strtok(' ' . $value, '.');
		$dec = strtok('');

		$dec = substr($dec, 0, $decimals);

		if (strlen($dec) < $decimals) {
			$dec = str_pad($dec, $decimals, '0', STR_PAD_RIGHT);
		}

		return intval($int . $dec);
	}

	/**
	 * @return null|int NULL if input value is not a valid number
	 */
	static public function fromUserEntry($value, int $decimals = 2): ?int
	{
		if (!is_scalar($value)
			&& !is_null($value)) {
			throw new \InvalidArgumentException('Invalid argument type: ' . gettype($value));
		}

		if (null === $value
			|| 0 === $value
			|| 0.0 === $value
			|| trim($value, "0.\r\n\t ") === '') {
			return 0;
		}

		// Remove whitespace characters
		$value = preg_replace('/\h/u', '', $value);

		// Remove plus sign
		$value = ltrim($value, '+');

		// Replace international decimal notation: ,12 ; 123,45 ; 1.043,12
		if (substr($value, 0, 1) === ','
			|| strpos($value, ',') > strpos($value, '.')) {
			$value = strtr($value, ['.' => '', ',' => '.']);
		}

		// Remove US thousands separator
		$value = str_replace(',', '', $value);

		if (!preg_match('/^-?(?:\d+|\d*\.\d+)$/', $value)) {
			return null;
		}

		return self::toInteger($value);
	}

	/**
	 * Parse "123.456" → [123456, 3]
	 */
	static public function parseScale(string $value): array
	{
		if (strpos($value, ',') !== false) {
			throw new \InvalidArgumentException('Comma in monetary value: ' . $value);
		}

		$value = trim($value);

		// No decimal point → scale 0
		if (strpos($value, '.') === false) {
			return [(int)$value, 0];
		}

		$int = strtok($value, '.');
		$dec = strtok('');

		if (strlen($dec) > 10) {
			throw new \InvalidArgumentException('Only up to 10 decimals are allowed');
		}

		// Allow "123." to be treated as "123.0"
		if ($dec === '') {
			$dec = '0';
		}

		$scale = strlen($dec);
		$int_value = (int)($int . $dec);

		return [$int_value, $scale];
	}

	/**
	 * 10^exp as integer, no floats.
	 */
	static private function pow10(int $exp): int
	{
		return (int)('1' . str_repeat('0', $exp));
	}

	/**
	 * Do a math calculation from money strings, returning a string
	 */
	static public function calc(string $a, string $op, string $b): string
	{
		// Directly use BCmath if available
		if (function_exists('bcadd')) {
			if ($op === '+') {
				$r = bcadd($a, $b, 40);
			}
			elseif ($op === '-') {
				$r = bcsub($a, $b, 40);
			}
			else {
				$r = bcmul($a, $b, 40);
			}

			// Remove trailing zeros, and separator if the result is an integer
			return rtrim($r, '0.');
		}

		[$a_value, $a_scale] = self::parseScale($a);
		[$b_value, $b_scale] = self::parseScale($b);

		// Align scales for + and -
		if ($op === '+' || $op === '-') {
			if ($a_scale > $b_scale) {
				$b_value *= self::pow10($a_scale - $b_scale);
				$scale = $a_scale;
			} elseif ($b_scale > $a_scale) {
				$a_value *= self::pow10($b_scale - $a_scale);
				$scale = $b_scale;
			} else {
				$scale = $a_scale;
			}
		} elseif ($op === '*') {
			$scale = $a_scale + $b_scale;
		} else {
			throw new \InvalidArgumentException('Unknown op: ' . $op);
		}

		// Integer operation
		if ($op === '+') {
			$raw = $a_value + $b_value;
		} elseif ($op === '-') {
			$raw = $a_value - $b_value;
		} else { // '*'
			$raw = $a_value * $b_value;
		}

		if ($raw > PHP_INT_MAX) {
			throw new \LogicException('The decimal number is too large: ' . $raw);
		}

		$raw = (string)$raw;
		$len = strlen($raw);

		// No decimals
		if ($scale === 0) {
			return $raw;
		}

		// Insert decimal point
		if ($len <= $scale) {
			$raw = str_pad($raw, $scale + 1, '0', STR_PAD_LEFT);
			$len = strlen($raw);
		}

		$int = substr($raw, 0, $len - $scale);
		$dec = substr($raw, $len - $scale);

		if ((int)$dec === 0) {
			return $int;
		}

		return $int . '.' . rtrim($dec, '0');
	}

	/**
	 * Round to 2 decimals for EN16931 monetary output (round half up)
	 */
	public static function round2(string $value): string
	{
		if (trim($value, '0.') === '') {
			return '0';
		}

		if (function_exists('bcround')) {
			// Half towards zero in PHP is half-up
			return bcround($value, 2, \RoundingMode::HalfTowardsZero);
		}

		$digits = strtok($value, '.');
		$decimals = substr(strtok(''), 0, 3);
		$sign = '';

		if (substr($digits, 0, 1) === '-') {
			$sign = '-';
			$digits = substr($digits, 1);
		}

		if (strlen($digits) && !ctype_digit($digits)) {
			throw new \InvalidArgumentException('Invalid digits value: ' . $value);
		}

		if (strlen($decimals) && !ctype_digit($decimals)) {
			throw new \InvalidArgumentException('Invalid decimals value: ' . $value);
		}

		if ($digits === '') {
			$digits = '0';
		}

		$decimals = str_pad($decimals, 3, '0', STR_PAD_RIGHT);

		$round = substr($decimals, 2, 1);
		$decimals = substr($decimals, 0, 2);

		// Round half-up
		if ($round >= 5) {
			$decimals = (int)$decimals + 1;

			if ((int) $decimals >= 100) {
				$digits += 1;
				$decimals = 0;
			}

			$decimals = (string) $decimals;
		}

		$decimals = str_pad($decimals, 2, '0', STR_PAD_LEFT);

		return $sign . $digits . '.' . $decimals;
	}
}
