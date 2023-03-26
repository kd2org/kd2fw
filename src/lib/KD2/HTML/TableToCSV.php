<?php

namespace KD2\HTML;

use DOMDocument;
use DOMNode;
use DOMXPath;

class TableToCSV
{
	protected string $csv = '';

	public function import(string $html): void
	{
		libxml_use_internal_errors(true);

		if (!stristr($html, '<body')) {
			$html = '<body>' . $html . '</body>';
		}

		$doc = new DOMDocument;
		$doc->loadHTML('<meta charset="utf-8" />' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		$this->csv = '';

		foreach ($this->xpath($doc, './/table') as $i => $table) {
			$this->add($table, $i);
		}

		unset($doc);
	}

	public function xpath(DOMNode $dom, string $query, int $item = null)
	{
		$xpath = new DOMXPath($dom instanceOf DOMDocument ? $dom : $dom->ownerDocument);
		$result = $xpath->query($query, $dom);

		if (null !== $item) {
			if (!$result->length || $result->length < $item + 1) {
				return null;
			}

			return $result->item($item);
		}

		return $result;
	}

	protected function add(DOMNode $table, int $count): void
	{
		foreach ($this->xpath($table, './/tr') as $row) {
			$cells = $this->xpath($row, './/td|.//th');

			$row = '';

			foreach ($cells as $cell) {
				$value = $cell->textContent;
				$value = html_entity_decode($value);
				$value = trim($value);

				// Remove space and non-breaking space
				$number_value = str_replace([' ', "\xC2\xA0"], '', $cell->getAttribute('data-spreadsheet-number') ?: $value);

				if (preg_match('/^-?\d+(?:[,.]\d+)?$/', $number_value)) {
					$value = $number_value;
				}

				$value = str_replace('"', '""', $value);
				$value = $value !== '' ? '"' . $value . '"' : '';

				$row .= ',' . $value;

				if ($colspan = $cell->getAttribute('colspan')) {
					$row .= str_repeat(',', $colspan - 1);
				}
			}

			$this->csv .= substr($row, 1) . PHP_EOL;
		}
	}

	public function save(string $filename): void
	{
		file_put_contents($filename, $this->csv);
	}

	public function fetch(): string
	{
		return $this->csv;
	}
}
