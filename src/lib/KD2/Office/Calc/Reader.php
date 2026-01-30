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

		foreach ($table->xpath('.//table:table-row') as $row) {
			$out = [];

			foreach ($row->children(self::NS_TABLE) as $cell) {
				$tag_name = $cell->getName();

				if ($tag_name !== 'table-cell' && $tag_name !== 'covered-table-cell') {
					continue;
				}

				$attributes = $cell->attributes(self::NS_OFFICE);
				$type = (string) ($attributes['value-type'] ?? 'string');

				// FIXME: other currency, percentage…
				if ($type === 'float'
					|| $type === 'percentage'
					|| $type === 'currency') {
					$value = (string) $attributes['value'];

					if (ctype_digit($value)) {
						$value = (int) $value;
					}
					else {
						$value = (float) $value;
					}
				}
				elseif ($type === 'boolean') {
					$type = 'bool';
					$value = (string) $attributes['value'] === 'true' ? true : false;
				}
				elseif ($type === 'date') {
					$value = (string) $attributes['date-value'];
				}
				else {
					$value = '';

					foreach ($cell->xpath('.//text:p') as $p) {
						$value .= (string) $p . "\n";
					}

					$value = rtrim($value);
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

				// repeat cell value (n) times
				for ($j = 0; $j < $repeat; $j++) {
					$out[] = $value;
				}
			}

			$repeat = intval($row->attributes(self::NS_TABLE)['number-rows-repeated']) ?: 1;

			for ($i = 0; $i < $repeat; $i++) {
				yield $out;
			}
		}
	}
}
