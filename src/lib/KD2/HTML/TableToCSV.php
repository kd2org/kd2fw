<?php

namespace KD2\HTML;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Converts the first HTML table of a document to CSV
 *
 * - only the first table is handled
 * - colspan is supported
 * - rowspan is *NOT* supported
 *
 * Usage: $csv = new TableToCSV; $csv->import('<table...</table>'); $csv->save('file.csv');
 *
 * @author bohwaz <https://bohwaz.net/>
 */
class TableToCSV
{
	protected array $rows = [];
	protected string $csv = '';

	public function import(string $html): void
	{
		libxml_use_internal_errors(true);

		if (!stristr($html, '<body')) {
			$html = '<body>' . $html . '</body>';
		}

		$doc = new DOMDocument;
		$doc->loadHTML('<meta charset="utf-8" />' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		$this->rows = [];

		foreach ($this->xpath($doc, './/table') as $i => $table) {
			$this->add($table, $i);
			break; // We only support the first table currently
		}

		foreach ($this->rows as $row) {
			$this->csv .= implode(',', $row) . PHP_EOL;
		}

		$this->rows = [];

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
		$row_index = 0;

		foreach ($this->xpath($table, './/tr') as $row) {
			$col_index = 0;
			$cells = $this->xpath($row, './/td|.//th');

			foreach ($cells as $cell) {
				// Skip rowspan
				while (isset($this->rows[$row_index][$col_index])) {
					$col_index++;
				}

				$value = $cell->textContent;
				$value = html_entity_decode($value, ENT_QUOTES | ENT_XML1);
				$value = trim($value);

				// Remove space and non-breaking space
				$number_value = str_replace([' ', "\xC2\xA0"], '', $cell->getAttribute('data-spreadsheet-value') ?: $value);

				if ($cell->getAttribute('data-spreadsheet-type') == 'number' || preg_match('/^-?\d+(?:[,.]\d+)?$/', $number_value)) {
					$value = $number_value;
				}

				$value = str_replace('"', '""', $value);
				$value = $value !== '' ? '"' . $value . '"' : '';

				$this->rows[$row_index][$col_index++] = $value;

				$colspan = intval($cell->getAttribute('colspan') ?: 1);

				if ($colspan > 1) {
					for ($i = 1; $i < $colspan; $i++) {
						$this->rows[$row_index][$col_index++] = '';
					}
				}

				$rowspan = intval($cell->getAttribute('rowspan') ?: 1);

				if ($rowspan > 1) {
					// Pre-fill cells for rowspan
					for ($i = 1; $i < $rowspan; $i++) {
						if (!isset($this->rows[$row_index + $i])) {
							$this->rows[$row_index + $i] = [];
						}

						$this->rows[$row_index + $i][$col_index] = '';
					}
				}
			}

			$row_index++;
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
