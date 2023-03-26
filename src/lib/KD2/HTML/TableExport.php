<?php

namespace KD2\HTML;

use KD2\HTML\TableToODS;

class TableExport
{
	static public function download(string $format, string $name, string $html, string $css): void
	{
		if ('ods' == $format) {
			header('Content-type: application/vnd.oasis.opendocument.spreadsheet');
			header(sprintf('Content-Disposition: attachment; filename="%s.ods"', $name));

			self::toODS('php://output', $html, $css);
		}
		elseif ('xlsx' == $format) {
		}
		elseif ('csv' == $format) {
		}
		else {
			throw new \InvalidArgumentException('Invalid format: ' . $format);
		}
	}

	static public function toODS(string $output, string $html, string $css): void
	{
		$ods = new TableToODS;
		$ods->import($html, $css);
		$ods->save($output);
	}
}