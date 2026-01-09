<?php

namespace KD2\Office;

use stdClass;
use DateTime;
use DateTimeInterface;

/**
 * @see https://en.wikipedia.org/wiki/Quicken_Interchange_Format
 * @see https://github.com/MimoGraphix/qif-library/blob/master/src/Enums/DetailItems.php
 */
class QIFParser
{
	static public function isQIF(string $str): bool
	{
		return preg_match('/^D[\d/-]+$/', $str) && preg_match('/^T-?[\d,.]+$/', $str);
	}

	public function parse(string $str): array
	{
		// Normalize line endings
		$str = preg_replace("/\r\n|\r/", "\n", $str);

		$blocks = explode("\n^\n", $str);

		$out = [];

		foreach ($blocks as $block) {
			// Probably the last delimiter, ignore it
			if (trim($block) === '') {
				continue;
			}

			$out[] = $this->parseBlock($block);
		}

		return array_filter($out);
	}

	protected function parseBlock(string $str): stdClass
	{
		$lines = explode("\n", $str);
		$data = [];

		foreach ($lines as $line) {
			$code = substr($line, 0, 1);
			$value = trim(substr($line, 1));

			if (array_key_exists($code, $data)) {
				$data[$code] .= "\n" . $value;
			}
			else {
				$data[$code] = $value;
			}
		}

		$clear = strtoupper($data['C'] ?? '');

		return (object) [
			// Date. Leading zeroes on month and day can be skipped. Year can be either 4 digits or 2 digits or '6 (=2006).	All	D25 December 2006
			'date' => $this->parseDate($data['D'] ?? null),

			// Amount of the item. For payments, a leading minus sign is required. For deposits, either no sign or a leading plus sign is accepted. Do not include currency symbols ($, £, ¥, etc.). Comma separators between thousands are allowed.
			// U = Both T and U are present in QIF files exported from Quicken 2015.
			// $ = Amount for this split of the item. Same format as T field.
			'amount' => $this->parseAmount($data['T'] ?? ($data['U'] ?? null)),

			// Payee. Or a description for deposits, transfers, etc.
			'label' => $data['P'] ?? null,

			// Memo—any text you want to record about the item.
			'memo' => $data['M'] ?? null,

			// Cleared status. Values are blank (not cleared), "*" or "c" (cleared) and "X" or "R" (reconciled)
			'cleared' => $clear === 'C',
			'reconciled' => $clear === 'X' || $clear === 'R',

			// Number of the check. Can also be "Deposit", "Transfer", "Print", "ATM", "EFT".
			'check_number' => $data['N'] ?? null,

			// Address of Payee. Up to 5 address lines are allowed. A 6th address line is a message that prints on the check. 1st line is normally the same as the Payee line—the name of the Payee.
			'address' => $data['A'] ?? null,

			// Category or Transfer and (optionally) Class. The literal values are those defined in the Quicken Category list. SubCategories can be indicated by a colon (":") followed by the subcategory literal. If the Quicken file uses Classes, this can be indicated by a slash ("/") followed by the class literal. For Investments, MiscIncX or MiscExpX actions, Category/class or transfer/class. (40 characters maximum)
			'category' => $data['L'] ?? null,
		];
	}

	protected function parseDate(?string $date): ?DateTimeInterface
	{
		if ($date === null || $date === '') {
			return null;
		}

		try {
			// YYYYMMDD
			if (is_numeric($date) && strlen($date) === strlen(date('Ymd'))) {
				$out = DateTime::createFromFormat('!Ymd', $date);
			}
			// DD/MM/YYYY (US format is not supported)
			elseif (substr_count($date, '/') === 2 && strlen($date) === strlen(date('d/m/Y'))) {
				$d = explode('/', $date);
				$out = DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $d[2], $d[1], $d[0]));
			}
			// DD/MM/YY (US format is not supported)
			elseif (substr_count($date, '/') === 2 && strlen($date) === strlen(date('d/m/y'))) {
				$d = explode('/', $date);
				$out = DateTime::createFromFormat('!y-m-d', sprintf('%02d-%02d-%02d', $d[2], $d[1], $d[0]));
			}
			// ISO format / other
			else {
				$out = new DateTime($date);
			}

			return $out ?: null;
		}
		catch (\Exception $e) {
			return null;
		}
	}

	protected function parseAmount(?string $str): string
	{
		if ($str === '' || $str === null) {
			return null;
		}

		// International format
		// 2152,09
		if (strpos($str, ',') > strpos($str, '.')) {
			return strtr($value, ['.' => '', ',' => '.']);
		}

		// US format
		// 2,152.09
		return str_replace(',', '', $str);
	}

}
