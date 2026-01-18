<?php

namespace KD2\Office\Calc;

use KD2\ZipReader;
use SimpleXMLElement;
use Generator;
use DateTime;

class Reader
{
	protected ?ZipReader $zip = null;
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
				$this->zip = new ZipReader;
				$this->zip->setPointer($fp);

				if (!$this->zip->has('content.xml')
					|| !$this->zip->has('mimetype')
					|| trim($this->zip->fetch('mimetype')) !== 'application/vnd.oasis.opendocument.spreadsheet') {
					$this->zip = null;
					throw new \InvalidArgumentException('This file is not a valid OpenDocument spreadsheet');
				}

				$this->xml = simplexml_load_string($this->zip->fetch('content.xml'));
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
				if ($type === 'float') {
					$value = (string) $attributes['value'];

					if (ctype_digit($value)) {
						$value = (int) $value;
					}
					else {
						$value = (float) $value;
					}
				}
				elseif ($type === 'date') {
					$value = new DateTime($attributes['date-value']);
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
