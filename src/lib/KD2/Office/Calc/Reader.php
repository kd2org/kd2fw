<?php

namespace KD2\Office\Calc;

use KD2\ZipReader;
use SimpleXMLElement;
use Generator;

class Reader
{
	protected SimpleXMLElement $xml;

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

				$this->xml = simplexml_load_string($zip->fetch('content.xml'));
				$zip = null;
			}
			elseif ($magic === '<?') {
				$raw = '';

				while (!feof($fp)) {
					$raw .= fread($fp, 8192);
				}

				$this->xml = simplexml_load_string($raw);
				unset($raw);
			}
			else {
				throw new \InvalidArgumentException('This file is not a valid OpenDocument spreadsheet');
			}
		}
		finally {
			fclose($fp);
		}

		$this->xml->registerXPathNamespace('table', self::NS_TABLE);
		$this->xml->registerXPathNamespace('office', self::NS_OFFICE);
	}

	public function listSheets(): array
	{
		$out = [];

		foreach ($this->xml->xpath('.//table:table') as $sheet) {
			$out[] = (string)$sheet->attributes(self::NS_TABLE)['name'];
		}

		return $out;
	}

	public function iterate(int $sheet = 0, bool $detailed = false): Generator
	{
		$table = $this->xml->xpath('.//table:table')[$sheet];

		foreach ($table->xpath('.//table:table-row') as $row) {
			$out = [];

			foreach ($row->xpath('.//table:table-cell') as $cell) {
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

				$out[] = $value;
			}

			yield $out;
		}
	}
}
