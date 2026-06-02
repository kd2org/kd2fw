<?php

namespace KD2\Office\Calc;

use KD2\ZipReader;
use SimpleXMLElement;
use Generator;

class Reader
{
	protected ?array $sheets = null;
	protected SimpleXMLElement $xml;
	protected ?string $active_sheet_name = null;

	const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
	const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

	public function openString(string $str): void
	{
		$fp = fopen('php://temp', 'w+');
		fwrite($fp, $str);
		fseek($fp, 0, SEEK_SET);
		$this->setPointer($fp);
	}

	public function openFile(string $path): void
	{
		$fp = fopen($path, 'rb');
		$this->setPointer($fp);
	}

	public function setPointer($fp): void
	{
		$magic = fread($fp, 2);
		fseek($fp, 0, SEEK_SET);

		libxml_use_internal_errors(true);

		try {
			// .ods file
			if ($magic === 'PK') {
				$zip = new ZipReader;
				$zip->setPointer($fp);

				if (!$zip->has('content.xml')
					|| !$zip->has('mimetype')
					|| trim($zip->fetch('mimetype')) !== 'application/vnd.oasis.opendocument.spreadsheet') {
					$zip = null;
					throw new \InvalidArgumentException('This file is not a valid OpenDocument spreadsheet');
				}

				if ($zip->has('settings.xml')
					&& ($raw = $zip->fetch('settings.xml'))
					&& preg_match('!<(?:[a-z]+:)?config-item[^>]*name="ActiveTable"[^>]*>([^<]*)</config:config-item>!U', $raw, $match)) {
					$this->active_sheet_name = trim(htmlspecialchars_decode($match[1]));
				}

				$meta = $zip->getMetadata('content.xml');

				if (!$meta) {
					throw new \LogicException('Missing content.xml');
				}

				// If the uncompressed file is too large, we probably can't load the cells
				if ($meta['size'] > 50*1024*1024) {
					throw new \LogicException(sprintf('XML file is too big: %d MB (max. allowed = %d MB)', $meta['size']/1024/1024, 50));
				}

				$xml = simplexml_load_string($zip->fetch('content.xml'));
				$zip = null;
			}
			elseif ($magic === '<?') {
				$raw = '';

				while (!feof($fp)) {
					$raw .= fread($fp, 8192);
				}

				$xml = simplexml_load_string($raw);
				unset($raw);
			}
			else {
				throw new \InvalidArgumentException('This file is not a valid OpenDocument spreadsheet');
			}
		}
		finally {
			fclose($fp);
		}

		if (false === $xml) {
			throw new \LogicException(sprintf('Invalid XML in "%s": %s', $path, implode('; ', libxml_get_errors())));
		}

		$this->sheets = null;
		$this->xml = $xml;
		$this->xml->registerXPathNamespace('table', self::NS_TABLE);
		$this->xml->registerXPathNamespace('office', self::NS_OFFICE);
	}

	public function listSheets(): array
	{
		if (!isset($this->sheets)) {
			$this->sheets = [];

			foreach ($this->xml->xpath('.//table:table') as $sheet) {
				$this->sheets[] = (string)$sheet->attributes(self::NS_TABLE)['name'];
			}
		}

		return $this->sheets;
	}

	public function getActiveSheet(): int
	{
		return array_search($this->active_sheet_name, $this->listSheets(), true) ?: 0;
	}

	public function iterate(int $sheet = 0, bool $detailed = false): Generator
	{
		$tables = $this->xml->xpath('.//table:table');

		if (!isset($tables[$sheet])) {
			throw new \InvalidArgumentException('There is no sheet at index ' . $sheet);
		}

		$table = $tables[$sheet];
		$columns_count = 0;
		$rows_count = 0;
		$xpath = $table->xpath('.//table:table-row');

		// Count max number of columns, don't trust what the file says, it might say bullshit
		// For example one file had <table:table-cell table:number-columns-repeated="1006"/> in each row :-/
		foreach ($xpath as $row) {
			$i = 0;
			$last_non_empty_column = 0;

			foreach ($row->children(self::NS_TABLE) as $cell) {
				$tag_name = $cell->getName();

				// Skip
				if ($tag_name !== 'table-cell' && $tag_name !== 'covered-table-cell') {
					continue;
				}

				$attributes = $cell->attributes(self::NS_OFFICE);
				$has_value = isset($attributes['value'])
					|| isset($attributes['date-value'])
					|| count($cell->children(self::NS_TEXT));

				$attributes = $cell->attributes(self::NS_TABLE);
				$repeat = intval($attributes['number-columns-repeated']) ?: 1;

				$i += $repeat;

				if ($has_value) {
					$last_non_empty_column = $i;
				}
			}

			// Max 200k rows in a sheet: this is suspicious
			if ($rows_count++ >= 200000) {
				throw new \LogicException('This file has more than 200.000 rows');
			}

			$columns_count = max($columns_count, $last_non_empty_column);
		}

		if ($columns_count >= 1000) {
			throw new \LogicException('This file has more than 1000 columns');
		}

		$line = 0;

		foreach ($xpath as $row) {
			$out = [];
			$line++;

			foreach ($row->children(self::NS_TABLE) as $cell) {
				$tag_name = $cell->getName();

				if ($tag_name !== 'table-cell' && $tag_name !== 'covered-table-cell') {
					continue;
				}

				$attributes = $cell->attributes(self::NS_OFFICE);
				$type = (string) ($attributes['value-type'] ?? 'string');

				if ($type === 'float') {
					$value = $this->formatNumber((string) $attributes['value'], $this->getInnerText($cell));
				}
				elseif ($type === 'currency') {
					$value = (float) $attributes['value'];
				}
				elseif ($type === 'percentage') {
					$value = sprintf('%.2f%%', floatval($attributes['value'])*100);
				}
				elseif ($type === 'boolean') {
					$type = 'bool';
					$value = (string) $attributes['value'] === 'true' ? true : false;
				}
				elseif ($type === 'date') {
					$value = (string) $attributes['date-value'];
				}
				else {
					$value = $this->getInnerText($cell);
				}

				if ($detailed) {
					$out[] = (object) [
						'value'     => $value,
						'type'      => $type,
						'raw_value' => $type !== 'string' ? (string) $attributes['value'] : null,
					];
				}

				$attributes = $cell->attributes(self::NS_TABLE);
				$repeat = intval($attributes['number-columns-repeated']) ?: 1;

				$repeat = min($repeat, $columns_count - count($out));

				// repeat cell value (n) times
				for ($j = 0; $j < $repeat; $j++) {
					$out[] = $value;
				}
			}

			// Skip empty lines
			if (!count(array_filter($out))) {
				continue;
			}

			// Fill with empty cells, if required
			for ($i = count($out); $i < $columns_count; $i++) {
				$out[] = '';
			}

			yield $line => $out;
		}
	}

	/**
	 * Returns a number, either as an integer or float if it is a simple number,
	 * or as a string if it is specially formatted.
	 * We are skipping reimplementing the complex number formatting and styling,
	 * by just reusing the text representation that should be inside the tag.
	 * @return int|float|string
	 */
	public function formatNumber(string $value, string $formatted_value)
	{
		if ((int) $value == $value) {
			$value = (int) $value;
		}
		else {
			$value = (float) $value;
		}

		// Protect us from bad implementations that would only fill office:value
		// and not the tag text content
		if ($formatted_value === '') {
			return $value;
		}

		// Try to return early if possible for simple values, eg. 42 == "42"
		if (is_int($value)
			&& strlen($value) === strlen($formatted_value)
			&& $value == $formatted_value) {
			return $value;
		}

		$simple = str_replace(' ', '', $formatted_value);

		if (strpos($value, '.') < strpos($value, ',')) {
			// Remove thousands separator in english format: 1,042.42 -> 1042.42
			$simple = str_replace(',', '', $simple);
		}
		else {
			// remove thousands separator in international format: 1.042,42
			$simple = str_replace('.', '', $simple);
			// replace decimal separator
			$simple = str_replace(',', '.', $simple);
		}

		if (false !== strpos($simple, '.')) {
			// Remove zeros after the decimal separator
			$simple = preg_replace('/0+$/', '', $simple);
		}

		$simple = preg_replace('/\.$/', '', $simple);

		// When removing spaces, dots and commas from formatted value,
		// if it matches the raw value, and has the same length,
		// it means this is basic number formatting
		// this shouldn't match "01 02 03 04 05" (phone number)
		// but should match "1 024,42" (basic number with thousands and decimals separator)
		// or "208 123 024"
		if (strlen($value) === strlen($simple)
			&& ((int) $simple === $value || (float) $simple === $value)) {
			return $value;
		}

		// If it isn't simple number formatting, just return the formatted number as a string
		return $formatted_value;
	}

	protected function getInnerText(SimpleXMLElement $element): string
	{
		$value = '';

		foreach ($element->xpath('.//text:p') as $p) {
			$p = dom_import_simplexml($p);
			$value .= (string) $p->nodeValue . "\n";
		}

		return rtrim($value);
	}
}
