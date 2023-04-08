<?php

namespace KD2\HTML;

use KD2\HTML\TableToCSV;
use KD2\HTML\TableToODS;
use KD2\HTML\TableToXLSX;

class TableExport
{
	static public function download(string $format, string $name, string $html, string $css): void
	{
		if ('ods' == $format) {
			header('Content-type: application/vnd.oasis.opendocument.spreadsheet');
			header(sprintf('Content-Disposition: attachment; filename="%s.ods"', $name));

			self::toODS('php://output', $html, $css, $name);
		}
		elseif ('xlsx' == $format) {
			header('Content-type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
			header(sprintf('Content-Disposition: attachment; filename="%s.xlsx"', $name));

			self::toXLSX('php://output', $html, $css, $name);
		}
		elseif ('csv' == $format) {
			header('Content-type: application/csv');
			header(sprintf('Content-Disposition: attachment; filename="%s.csv"', $name));
			self::toCSV('php://output', $html);
		}
		else {
			throw new \InvalidArgumentException('Invalid format: ' . $format);
		}
	}

	static public function toODS(string $output, string $html, string $css, ?string $title = null): void
	{
		$ods = new TableToODS;

		if (isset($title)) {
			$ods->default_sheet_name = $title;
		}

		$ods->import($html, $css);
		$ods->save($output);
	}

	static public function toXLSX(string $output, string $html, string $css, ?string $title = null): void
	{
		$x = new TableToXLSX;

		if (isset($title)) {
			$x->default_sheet_name = $title;
		}

		$x->import($html, $css);
		$x->save($output);
	}

	static public function toCSV(string $output, string $html): void
	{
		$csv = new TableToCSV;
		$csv->import($html);
		$csv->save($output);
	}
}