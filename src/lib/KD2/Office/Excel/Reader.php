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
	protected ?string $styles_path = null;
	protected ?string $strings_path = null;

	protected bool $date1904 = false;
	protected ?array $date_styles = null;
	protected ?array $strings = null;
	protected array $sheets = [];

	/**
	 * Default Excel date formats
	 * @see https://hexdocs.pm/xlsxir/number_styles.html
	 */
	const DEFAULT_DATE_FORMAT_IDS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

	const DATE_TEMPLATE_TOKENS = [
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

		$xml = simplexml_load_string($this->zip->fetch('_rels/.rels'));
		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/package/2006/relationships');

		// find workbook.xml file
		$element = $xml->xpath('.//a:Relationship[@Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"]');

		if (!isset($element[0]['Target'])) {
			return 'cannot find workbook.xml';
		}

		$this->workbook_path = (string) $element[0]['Target'];

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
		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/package/2006/relationships');

		$relationships = [];
		$this->strings_path = null;
		$this->styles_path = null;

		foreach ($xml->xpath('.//a:Relationship') as $r) {
			$path = $dir . '/' . (string) $r['Target'];
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
		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

		$this->sheets = [];
		$this->date1904 = strval($xml['date1904']) === 'true';

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

		$this->date_styles = null;
		$this->strings = null;

		return null;
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

		$xml = simplexml_load_string($this->zip->fetch($path));
		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
		$d = $xml->xpath('.//a:dimension');

		if (!isset($d[0]['ref'])) {
			throw new \LogicException('Cannot find sheet dimensions');
		}

		$columns_count = $this->getColumnNumber((string) $d[0]['ref']);

		foreach ($xml->xpath('.//a:sheetData//a:row') as $row) {
			// Fill empty cells, as Excel doesn't provide <c> elements for empty cells
			$out = array_fill(0, $columns_count, '');

			$row->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

			foreach ($row->xpath('.//a:c') as $cell) {
				$num = $this->getColumnNumber((string) $cell['r']);

				$t = (string) $cell['t'];

				// Datetime float
				if ($this->isDate($t, (int) $cell['s'])) {
					$value = $this->parseDateTime((float) $cell->v);
				}
				// Boolean
				elseif ($t === 'b') {
					$value = (bool) $cell->v;
				}
				// Formula or error
				elseif ($t === 'str' || $t === 'e') {
					$value = (string) $cell->v;
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
					$value = $this->strings[(int)$cell->v] ?? null;
				}
				// Other numbers
				else {
					$value = (string) $cell->v;

					if ($value == (int) $value) {
						$value = (int) $value;
					}
					elseif ($value == (float) $value) {
						$value = (float) $value;
					}
				}

				$out[$num - 1] = $value;
			}

			yield $out;
		}
	}

	protected function isDate(string $t, int $s): bool
	{
		if (($t === 'n' || $t === '')
			&& $s > 0
			&& in_array($s, $this->date_styles, true)) {
			return true;
		}

		return false;
	}

	protected function isDateTemplate(string $template): bool
	{
		$template = strtolower($template);

		// Remove weird prefix and suffix
		// see https://stackoverflow.com/questions/4730152/what-indicates-an-office-open-xml-cell-contains-a-date-time-value
		$template = preg_replace('/^\[\$-\d+\]|;@$/', '', $template);

		// Split string
		$parts = preg_split('/\W+/', $template);

		foreach ($parts as $part) {
			if (!in_array($part, self::DATE_TEMPLATE_TOKENS)) {
				return false;
			}
		}

		return true;
	}

	protected function loadStyles(): void
	{
		if (isset($this->date_styles)) {
			return;
		}

		$this->date_styles = [];
		$date_formats_ids = self::DEFAULT_DATE_FORMAT_IDS;

		$xml = simplexml_load_string($this->zip->fetch($this->styles_path));
		$xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

		foreach ($xml->xpath('.//a:numFmts//a:numFmt') as $format) {
			if ($this->isDateTemplate((string) $format['formatCode'])) {
				$date_formats_ids[] = (int) $format['numFmtId'];
			}
		}

		foreach ($xml->xpath('.//a:cellXfs//a:xf') as $i => $style) {
			if (in_array((int) $style['numFmtId'], $date_formats_ids)) {
				$this->date_styles[] = $i;
			}
		}

		unset($xml);
	}

	protected function parseDateTime(float $v): string
	{
		$d = floor($v); // days since 1900 or 1904
		$t = $v - $d;

		if ($this->date1904) {
			$d += 1462;
		}

		$ts = (abs($d) > 0) ? ($d - 25569) * 86400 + round($t * 86400) : round($t * 86400);

		return gmdate('Y-m-d H:i:s', $ts);
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
