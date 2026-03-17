<?php

namespace KD2\Office\Excel;

use KD2\ZipReader;
use SimpleXMLElement;
use Generator;
use DateTime;

class Reader extends \KD2\Office\Calc\Reader
{
	protected ?ZipReader $zip = null;
	protected ?string $workbook_path = null;
	protected ?string $formats_path = null;
	protected ?string $strings_path = null;
	protected ?string $styles_path = null;
	protected ?int $active_sheet_index = null;

	protected bool $date1904 = false;
	protected ?array $number_formats = null;
	protected ?array $date_formats = null;
	protected ?array $strings = null;
	protected ?array $sheets = null;

	const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

	/**
	 * Default Excel date formats
	 * @see https://hexdocs.pm/xlsxir/number_formats.html
	 */
	const DEFAULT_DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

	const DEFAULT_NUMBER_FORMAT_IDS = [0, 1, 2, 3, 4, 9, 10, 11, 12, 13, 37, 38, 39, 40, 44, 48, 49, 59, 60, 61, 62, 67, 68, 69, 70];

	const DATE_FORMAT_TOKENS = [
		// Seconds (min two digits). Example: "05".
		'ss',
		// Minutes (min two digits). Example: "05". Could also be "Months". Weird.
		'mm',
		// Hours. Example: "1".
		'h',
		// Hours (min two digits). Example: "01".
		'hh',
		// "AM" part of "AM/PM". Lowercased just in case.
		'am',
		// "PM" part of "AM/PM". Lowercased just in case.
		'pm',
		// Day. Example: "1"
		'd',
		// Day (min two digits). Example: "01"
		'dd',
		// Month (numeric). Example: "1".
		'm',
		// Month (numeric, min two digits). Example: "01". Could also be "Minutes". Weird.
		'mm',
		// Month (shortened month name). Example: "Jan".
		'mmm',
		// Month (full month name). Example: "January".
		'mmmm',
		// Two-digit year. Example: "20".
		'yy',
		// Full year. Example: "2020".
		'yyyy',

		// It's used in "built-in" XLSX formats:
		// * 27 '[$-404]e/m/d';
		// * 36 '[$-404]e/m/d';
		// * 50 '[$-404]e/m/d';
		// * 57 '[$-404]e/m/d';
		'e'
	];

	/**
	 * @see https://deepwiki.com/shuchkin/simplexlsx/2.3-xlsx-file-structure
	 */
	protected function parse($fp): ?string
	{
		$magic = fread($fp, 2);
		fseek($fp, 0, SEEK_SET);

		if ($magic !== 'PK') {
			return 'not a ZIP file';
		}

		$this->zip = new ZipReader;
		$this->zip->setPointer($fp);

		if (!$this->zip->has('_rels/.rels')) {
			return 'missing _rels/.rels file in ZIP';
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($this->zip->fetch('_rels/.rels'));

		if (false === $xml) {
			throw new \LogicException(sprintf('Invalid XML in "%s": %s', '_rels/.rels', implode('; ', libxml_get_errors())));
		}

		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/package/2006/relationships');

		// find workbook.xml file
		$element = $xml->xpath('.//a:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"]');

		if (!isset($element[0]['Target'])) {
			return 'cannot find workbook.xml';
		}

		$this->workbook_path = $this->normalizePath((string) $element[0]['Target']);

		if (!$this->zip->has($this->workbook_path)) {
			return 'workbook.xml is missing';
		}

		unset($xml, $element);

		$dir = dirname($this->workbook_path);
		$name = basename($this->workbook_path);
		$rels_path = $dir . '/_rels/' . $name . '.rels';

		if (!$this->zip->has($rels_path)) {
			return $rels_path . ' is missing';
		}

		$xml = simplexml_load_string($this->zip->fetch($rels_path));

		if (false === $xml) {
			throw new \LogicException(sprintf('Invalid XML in "%s": %s', $rels_path, implode('; ', libxml_get_errors())));
		}

		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/package/2006/relationships');

		$relationships = [];
		$this->strings_path = null;
		$this->styles_path = null;

		foreach ($xml->xpath('.//a:Relationship') as $r) {
			$path = $this->normalizePath((string) $r['Target'], $dir);
			$type = (string) $r['Type'];

			if ($type === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings') {
				$this->strings_path = $path;
			}
			elseif ($type === 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles') {
				$this->styles_path = $path;
			}
			else {
				$relationships[(string)$r['Id']] = $path;
			}
		}

		unset($xml);

		$xml = simplexml_load_string($this->zip->fetch($this->workbook_path));

		if (false === $xml) {
			throw new \LogicException(sprintf('Invalid XML in "%s": %s', $this->workbook_path, implode('; ', libxml_get_errors())));
		}

		$xml->registerXPathNamespace('a', self::NS_MAIN);

		$this->sheets = [];
		$this->date1904 = isset($xml->workbookPr['date1904']) && strval($xml->workbookPr['date1904']) === 'true';

		if (isset($xml->bookViews->workbookView['activeTab'])) {
			$this->active_sheet_index = (int) $xml->bookViews->workbookView['activeTab'];
		}

		foreach ($xml->xpath('.//a:sheet') as $sheet) {
			// Skip hidden sheets
			if ((string)$sheet['state'] === 'veryHidden') {
				continue;
			}

			$attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
			$id = (string)$attrs['id'];
			$this->sheets[] = [
				'id'   => $id,
				'file' => $relationships[$id],
				'name' => (string)$sheet['name'],
			];
		}

		$this->date_formats = null;
		$this->number_formats = null;
		$this->strings = null;

		return null;
	}

	protected function normalizePath(string $path, ?string $prefix = null): string
	{
		$path = str_replace('\\', '/', $path);
		$path = ltrim($path, '/');

		if ($prefix && 0 !== strpos($path, $prefix . '/')) {
			$path = $prefix . '/' . $path;
		}

		return $path;
	}

	public function setPointer($fp): void
	{
		$r = $this->parse($fp);

		if ($r !== null) {
			$this->zip = null;
			throw new \InvalidArgumentException('This file is not a valid Excel spreadsheet: ' . $r);
		}
	}

	public function listSheets(): array
	{
		$out = [];

		foreach ($this->sheets as $sheet) {
			$out[] = $sheet['name'];
		}

		return $out;
	}

	public function getActiveSheet(): int
	{
		return $this->active_sheet_index ?? 0;
	}

	protected function getColumnNumber(string $col): int
	{
		if (!preg_match('/^(?:[A-Z]+\d+:)?([A-Z]+)\d+$/', $col, $match)) {
			throw new \LogicException('Invalid dimensions: ' . $col);
		}

		$col = $match[1];
		$col_len = strlen($col);
		$num = 0;

		for ($i = $col_len - 1; $i >= 0; $i--) {
			$num += (ord($col[$i]) - 64) * pow(26, $col_len - $i - 1);
		}

		return $num;
	}

	public function iterate(int $sheet = 0, bool $detailed = false): Generator
	{
		$this->loadStrings();
		$this->loadStyles();

		$path = $this->sheets[$sheet]['file'];

		$meta = $this->zip->getMetadata($path);

		if (!$meta) {
			throw new \LogicException('Missing file: ' . $path);
		}

		// If the uncompressed file is too large, we probably can't process the millions of cells
		if ($meta['size'] > 50*1024*1024) {
			throw new \LogicException(sprintf('Sheet #%d is too big: %d MB (max. allowed = %d MB)', $sheet, $meta['size']/1024/1024, 50));
		}

		$xml = simplexml_load_string($this->zip->fetch($path));

		if (false === $xml) {
			throw new \LogicException(sprintf('Invalid XML in "%s": %s', $path, implode('; ', libxml_get_errors())));
		}

		$xml->registerXPathNamespace('a', self::NS_MAIN);
		$d = $xml->xpath('.//a:dimension');

		// Fast method
		if (isset($d[0]['ref'])) {
			$columns_count = $this->getColumnNumber((string) $d[0]['ref']);
		}
		// Slow method, for malformed XLSX files
		else {
			$columns_count = 0;

			// Selector: 'sheetData row c:last-child'
			foreach ($xml->xpath('.//a:sheetData//a:row//a:c[not(following-sibling::*)]') as $last_cell) {
				$columns_count = max($columns_count, $this->getColumnNumber((string)$last_cell['r']));
			}
		}

		if (!$columns_count) {
			throw new \LogicException('This sheet has no columns');
		}

		// Fill empty cells, as Excel doesn't provide <c> elements for empty cells
		$empty_row = array_fill(0, $columns_count, '');
		$empty_rows_count = 0;
		$i = 0;

		$sheet = $xml->xpath('.//a:sheetData')[0];

		foreach ($sheet->children(self::NS_MAIN) as $row) {
			if ($row->getName() !== 'row') {
				continue;
			}

			// Stop at 500_000 rows
			if ($i++ > 500000) {
				break;
			}

			$out = $empty_row;

			foreach ($row->children(self::NS_MAIN) as $cell) {
				if ($cell->getName() !== 'c') {
					continue;
				}

				$attributes = $cell->attributes();

				$num = $this->getColumnNumber((string) $attributes['r']);

				$t = (string) $attributes['t'];
				$s = (int) $attributes['s'];
				$v = null;

				foreach ($cell->children(self::NS_MAIN) as $tag) {
					if ($tag->getName() !== 'v') {
						continue;
					}

					$v = $tag;
					break; // there should be only one children here
				}

				// Datetime float
				if ($this->isDate($t, $s)) {
					$value = $this->parseDateTime((float) $v);
				}
				// Boolean
				elseif ($t === 'b') {
					$value = (bool) $v;
				}
				// Formula or error
				elseif ($t === 'str' || $t === 'e') {
					$value = (string) $v;
				}
				// Inline string
				elseif ($t === 'inlineStr') {
					$value = [];

					if (isset($cell->is->t)) {
						$value[] = (string)$cell->is->t;
					} elseif (isset($cell->is->r)) {
						foreach ($cell->is->r as $r) {
							$value[] = (string)$r->t;
						}
					}

					$value = implode('', $value);
				}
				// shared string
				elseif ($t === 's') {
					$value = $this->strings[(int)$v] ?? null;
				}
				// Other numbers
				else {
					$value = (string) $v;
					$formats = null;

					if ($s
						&& !in_array($s, self::DEFAULT_NUMBER_FORMAT_IDS, true)
						&& array_key_exists($s, $this->number_formats)) {
						$formats = $this->number_formats[$s];
					}

					$value = $this->formatExcelNumber($value, $formats);
				}

				$out[$num - 1] = $value;
			}

			// Skip empty rows
			if ($out === $empty_row) {
				// More than 20 empty rows: stop here, the rest of the document is probably empty
				// (some XLSX documents have 65.000 empty rows… stupid)
				if ($empty_rows_count++ > 20) {
					break;
				}

				continue;
			}

			$empty_rows_count = 0;

			yield $out;
		}
	}

	protected function isDate(string $t, int $s): bool
	{
		if (($t === 'n' || $t === '')
			&& $s > 0
			&& in_array($s, $this->date_formats, true)) {
			return true;
		}

		return false;
	}

	protected function isDateFormat(string $format): bool
	{
		$format = strtolower($format);

		// Remove weird prefix and suffix
		// see https://stackoverflow.com/questions/4730152/what-indicates-an-office-open-xml-cell-contains-a-date-time-value
		$format = preg_replace('/^\[\$-\d+\]|;@$/', '', $format);

		// Split string
		$parts = preg_split('/\W+/', $format);

		foreach ($parts as $part) {
			if (!in_array($part, self::DATE_FORMAT_TOKENS)) {
				return false;
			}
		}

		return true;
	}

	public function formatExcelNumber(string $number, ?array $formats)
	{
		$value = $this->applyNumberFormat($number, $formats);

		// applyNumberFormat can return NULL if the number is just an int or a float
		if ($value !== null) {
			return $value;
		}

		if ($number == (int) $number) {
			$number = (int) $number;
		}
		elseif ($number == (float) $number) {
			$number = (float) $number;
		}

		return $number;
	}

	public function applyNumberFormat(string $number, ?array $formats)
	{
		if (null === $formats) {
			return null;
		}

		// String literal
		if (array_key_exists(3, $formats) && !is_numeric($number)) {
			$format = $formats[3];
		}
		// Zero
		elseif ($number == 0 && array_key_exists(2, $formats)) {
			$format = $formats[2];
		}
		// Negative number
		elseif ($number < 0 && array_key_exists(1, $formats)) {
			$format = $formats[1];
		}
		// Positive and other cases
		else {
			$format = $formats[0];
		}

		// Fallback to automatic handling
		if ($format === null) {
			return null;
		}
		// Text
		elseif ($format === '@') {
			return $number;
		}
		// Skip complex formatting
		elseif ($format === '0') {
			return $number < PHP_INT_MAX ? (int) $number : preg_replace('/\..*$/', '', $number);
		}
		elseif ($format === '0.00') {
			return (float) $number;
		}
		// Percentage value, we skip complex stuff, most of the times we just want a simple number
		elseif ($format === '0.00%') {
			return sprintf('%.2f%%', ($number * 100));
		}

		// Replace text literal, eg. "Shipped in "@
		$count = 0;
		$out = preg_replace('/(?<!\\\\)@/', $number, $format, -1, $count);

		// If a text literal was replaced, this means it's not a number, we can stop here
		if ($count) {
			return $out;
		}

		$number_parts = explode('.', $number);
		$format_parts = preg_split('/(?<!\\\\)\./', $format);
		$decimals = '';
		$digits = [];

		// Replace decimals: we don't accept any weird decimal format, just copy the decimals here
		if (count($format_parts) > 1) {
			$decimals = '.' . ($number_part[1] ?? '0');
		}

		$format = array_reverse(str_split($format_parts[0], 1));
		$number = str_split($number_parts[0], 1);
		end($number);

		// reverse walking of string format
		foreach ($format as $i => $char) {
			$prev = $format[$i + 1] ?? '';

			if ($prev !== '\\' && $char === '0') {
				$value = current($number) ?: '0';
				prev($number);
			}
			elseif ($prev !== '\\' && ($char === '#' || $char === '?')) {
				$value = current($number);
				prev($number);
			}
			elseif ($char === '\\') {
				$value = '';
			}
			else {
				$value = $char;
			}

			$digits[] = $value;
		}

		return implode('', array_reverse($digits)) . $decimals;
	}

	public function parseNumberFormats(string $formats): array
	{
		// Split parts: positive;negative;zero;text
		$formats = explode(';', $formats);
		$formats = array_map([$this, 'parseNumberFormat'], $formats);
		return $formats;
	}

	/**
	 * Prepare a number format code template for later use
	 * This doesn't parse datetime formats, as this is handled by ::isDateFormat
	 * @see https://www.ablebits.com/office-addins-blog/custom-excel-number-format/
	 * @see https://github.com/tealeg/xlsx/blob/master/format_code.go
	 */
	public function parseNumberFormat(string $format): ?string
	{
		// Replace currency symbols [$<Currency String>-<Language Info>]
		// Currently unused, as we want to get the actual value, not the currency name
		//$format = preg_replace('/\[\$(.*?)-\d+\]/', '$1', $format);

		// Remove parts between brackets [$-40C] [Red]...
		// remove indentation code / repeat with underscore _(
		// remove left/rightpad with asterisk (*): *= *0
		// remove thousands separator (,): not interesting
		// remove scientific notation
		// remove fractions
		// remove antislash before a minus sign
		$format = preg_replace('!\[.*?\]|_.|\*.|,|E\+|/|\\\\(?=-)!u', '', $format);

		// unescape quoted text + quote number literals
		$format = preg_replace_callback('/"(.*?)"/',
			fn($match) => preg_replace('/[#\?0]/', '\\\\$0', $match[1]),
			$format);

		$format = preg_replace('/^(?:[€$£]|EUR|GBP|USD|NZD|AUD)/u', '', $format);
		$format = preg_replace('/(?:[€$£]|EUR|GBP|USD|NZD|AUD)$/u', '', $format);

		// Remove white spaces, parenthesis and brackets
		$format = trim($format, ' ()[]\\');

		// Quick way to return integer if there are only numbers and no decimal separator
		if (preg_match('/^-?[0#?]+$/', $format)) {
			$format = '0';
		}
		// Quick way to return float if format code is only numbers and a decimal separator
		elseif (preg_match('/^-?[0#?]+\.[0#?]+$/', $format)) {
			$format = '0.00';
		}
		// Quick way to get percentage value
		elseif (preg_match('/(?<!\\\\)%/', $format)) {
			$format = '0.00%';
		}
		// Don't accept weird decimal formatting, handle any decimal number as a float
		elseif (preg_match('/(?<!\\\\)\./', $format)) {
			$format = '0.00';
		}

		return $format;
	}

	protected function loadStyles(): void
	{
		if (isset($this->date_formats, $this->number_formats)) {
			return;
		}

		$this->date_formats = [];
		$this->number_formats = [];

		$date_formats_ids = self::DEFAULT_DATE_FORMAT_IDS;

		$xml = simplexml_load_string($this->zip->fetch($this->styles_path));

		if (false === $xml) {
			throw new \LogicException(sprintf('Invalid XML in "%s": %s', $this->styles_path, implode('; ', libxml_get_errors())));
		}

		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

		foreach ($xml->xpath('.//a:numFmts//a:numFmt') as $format) {
			$id = (int) $format['numFmtId'];
			$code = (string) $format['formatCode'];

			if ($this->isDateFormat($code)) {
				$date_formats_ids[] = $id;
			}
			// Skip style if it's general
			elseif (false != strpos($code, 'General')) {
				continue;
			}
			elseif (!in_array($id, self::DEFAULT_NUMBER_FORMAT_IDS, true)) {
				$number_formats[$id] = $this->parseNumberFormats($code);
			}
		}

		foreach ($xml->xpath('.//a:cellXfs//a:xf') as $i => $format) {
			$id = (int) $format['numFmtId'];

			if (in_array($id, $date_formats_ids, true)) {
				$this->date_formats[] = $i;
			}
			elseif (array_key_exists($id, $number_formats)) {
				$this->number_formats[$i] = $number_formats[$id];
			}
		}

		unset($xml);
	}

	protected function parseDateTime(float $v): string
	{
		if ((int)$v === 0) {
			return '';
		}

		$d = floor($v); // days since 1900 or 1904
		$t = $v - $d;

		if ($this->date1904) {
			$d += 1462;
		}

		$ts = (abs($d) > 0) ? ($d - 25569) * 86400 + round($t * 86400) : round($t * 86400);

		if (!$t) {
			$format = 'Y-m-d';
		}
		else {
			$format = 'Y-m-d\TH:i:s';
		}

		return gmdate($format, $ts);
	}

	protected function loadStrings(): void
	{
		if (isset($this->strings)) {
			return;
		}

		$this->strings = [];

		if (!$this->strings_path) {
			return;
		}

		$xml = simplexml_load_string($this->zip->fetch($this->strings_path));

		if (false === $xml) {
			throw new \LogicException(sprintf('Invalid XML in "%s": %s', $this->strings_path, implode('; ', libxml_get_errors())));
		}

		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

		foreach ($xml->xpath('.//a:si') as $i => $e) {
			$e->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
			$str = '';

			// An `<si/>` element can contain a `<t/>` (simplest case) or a set of `<r/>` ("rich formatting") elements having `<t/>`.
			foreach ($e->xpath('.//a:t') as $t) {
				$str .= (string) $t;
			}

			$this->strings[$i] = $str;
		}
	}
}
