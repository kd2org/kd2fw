<?php

namespace KD2\HTML;

use KD2\HTML\CSSParser;
use KD2\ZipWriter;

use DOMDocument;
use DOMNode;


/**
 * This class takes one or more HTML tables, and convert them to a single ODS document.
 *
 * - a basic set of CSS properties are supported!
 * - colspan (but not rowspan)
 * - automatic column width
 * - custom CSS properties
 * - each table is handled as a sheet, the <caption> will act as the name of the sheet
 * - detection of cell type, or force cell type using '-spreadsheet-cell-type'
 * - provide the real number via the "data-spreadsheet-number" HTML attribute
 *   (eg. if the number is displayed as a graph, or something like that)
 * - provide the real date via the "data-spreadsheet-date" attribute
 *
 * What is NOT supported:
 * - rowspan
 * - formulas
 *
 * Usage: $ods = new TableToODS; $ods->import('<table...</table>'); $ods->save('file.ods');
 *
 * Supported CSS properties:
 * - the following color names: black, white, red, green, blue, yellow, magenta, cyan
 * - 'initial' value to restore to default
 * - font-style: italic
 * - font-weight: bold
 * - font-size: XXpt
 * - color: #aabbcc|name
 * - background-color: #aabbcc|name
 * - text-align: left|center|right
 * - vertical-align: top|middle|bottom
 * - padding: XXmm
 * - border[-left|-right|-bottom|-top]: none|0.06pt solid #aabbcc|color
 * - wrap: wrap|nowrap
 * - hyphens: auto|none
 * - transform: rotate(90deg)
 *
 * Other properties, as well as units (eg. '2em', '99%', etc.), are not
 * supported and might end up in weird results.
 *
 * This supports a number of custom CSS properties (note the leading dash '-').
 * See TableToODS::CUSTOM_CSS_PROPERTIES for details.
 * Note that those properties are also cascading.
 *
 * Note: CSS selectors support is limited to tag names, classes and IDs.
 * See KD2/HTML/CSSParser for details.
 *
 * @author bohwaz <https://bohwaz.net/>
 */

/*
	This software is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	This software is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with this software.  If not, see <https://www.gnu.org/licenses/>.
*/
class TableToODS
{
	protected array $styles = [];
	public string $default_sheet_name = 'Sheet%d';

	const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>';

	const DATA_TYPES = [
		'number',
		'date',
		'currency',
		'percentage',
		'string',
		'auto',
	];

	const CUSTOM_CSS_PROPERTIES = [
		// Which language is the spreadsheet document in?
		// fr-BE, en_AU, etc.
		'-spreadsheet-locale',

		// Force the type of the cell
		// auto (default), string, currency, date, number, percentage
		// 'auto' means the type will be detected as best as we can (see ::getCellType)
		'-spreadsheet-cell-type',

		// Force the displayed date format of dates
		// Default is 'short'
		// The format may be one of the strings in ::DATE_FORMATS array, or any ICU format:
		// https://unicode-org.github.io/icu/userguide/format_parse/date/#date-field-symbol-table
		'-spreadsheet-date-format',

		// Force the display of the number
		// Default is 'float'
		// integer, float, percentage_integer, percentage_float
		'-spreadsheet-number-format',

		// Force the currency of the number
		// EUR (default), GBP, etc.
		'-spreadsheet-currency',

		// Force the currency symbol
		// € (default if currency is EUR), $, etc.
		'-spreadsheet-currency-symbol',

		// Force the position of the currency symbol, to place it before or after the number
		// 'prefix' (default) or 'suffix'
		'-spreadsheet-currency-position',

		// Name of the text color for a negative number,
		// or 'none' to disable coloring negative numbers
		// Supported color names: black, white, red, green, blue, yellow, magenta, cyan
		'-spreadsheet-number-negative-color'
	];

	const DATE_FORMATS = [
		'short' => 'dd/MM/yyyy',
		'short_hours' => 'dd/MM/yyyy hh:mm',
		'hours' => 'hh:mm',
		'long' => 'EEEE d LLLL yyyy',
		'long_hours' => 'EEEE d LLLL yyyy, hh:mm',
	];

	const NUMBER_FORMATS = [
		'integer' => '<number:number number:decimal-places="0" number:min-decimal-places="0" number:min-integer-digits="1" number:grouping="true"/>',
		'float' => '<number:number number:decimal-places="2" number:min-decimal-places="2" number:min-integer-digits="1" number:grouping="true"/>',
		'percentage_integer' => '<number:number number:decimal-places="0" number:min-decimal-places="0" number:min-integer-digits="1"/>
			<number:text> %</number:text>',
		'percentage_float' => '<number:number number:decimal-places="2" number:min-decimal-places="2" number:min-integer-digits="1"/>
			<number:text> %</number:text>',
	];

	/**
	 * Only a restricted set of colors is allowed for conditional numbers styles
	 */
	const NUMBER_COLORS = [
		'black'   => '#000000',
		'white'   => '#FFFFFF',
		'red'     => '#FF0000',
		'green'   => '#00FF00',
		'blue'    => '#0000FF',
		'yellow'  => '#FFFF00',
		'magenta' => '#FF00FF',
		'cyan'    => '#00FFFF',
	];

	protected CSSParser $css;

	protected string $xml = '';

	public function __construct()
	{
		$this->css = new CSSParser;
	}

	public function import(string $html, string $css = null): void
	{
		libxml_use_internal_errors(true);

		if (!stristr($html, '<body')) {
			$html = '<body>' . $html . '</body>';
		}

		$doc = new DOMDocument;
		$doc->loadHTML('<meta charset="utf-8" />' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		if ($css) {
			$this->css->import($css);
		}
		else {
			$this->css->importExternalFromDOM($doc, 'spreadsheet');
			$this->css->importInternalFromDOM($doc, 'spreadsheet');
		}

		$this->xml = '';

		foreach ($this->css->xpath($doc, './/table') as $i => $table) {
			$this->add($table, $i);
		}

		unset($doc);
	}

	protected function add(DOMNode $table, int $count): void
	{
		$styles = $this->css->get($table);

		if ($caption = $this->css->xpath($table, './/caption', 0)) {
			$name = $caption->textContent;
		}
		else {
			$name = sprintf($this->default_sheet_name, $count + 1);
		}

		$this->xml .= sprintf('<table:table table:name="%s" table:style-name="%s">',
			htmlspecialchars($name, ENT_XML1),
			$this->newStyle('table', $styles) ?? 'Default'
		);

		$rows = '';
		$columns_widths = [];

		foreach ($this->css->xpath($table, './/tr') as $row) {
			$styles = $this->css->get($row);

			$cells = $this->css->xpath($row, './/td|.//th');

			$rows .= sprintf('<table:table-row table:style-name="%s">', $this->newStyle('row', $styles) ?? 'ro-default');
			$index = 0;

			foreach ($cells as $cell) {
				$styles = $this->css->get($cell);

				$value = $cell->textContent;
				$value = html_entity_decode($value);
				$value = trim($value);
				$type = $this->getCellType($value, $styles['-spreadsheet-cell-type'] ?? null);
				$styles['-spreadsheet-cell-type'] = $type;

				$end = '';
				$rows .= sprintf('<table:table-cell table:style-name="%s"', $this->newStyle('cell', $styles) ?? 'Default');

				if ($colspan = $cell->getAttribute('colspan')) {
					$rows .= sprintf(' table:number-columns-spanned="%d"', $colspan);
					$end .= str_repeat('<table:covered-table-cell/>', (int)$colspan - 1);
				}

				/*
				// FIXME: support rowspan
				if ($rowspan = $cell->getAttribute('rowspan')) {
					$this->xml .= sprintf(' table:number-rows-spanned="%d"', $rowspan);
				}
				*/

				if ($value === '') {
					// Handle empty cells
					$column_width = 10;
					$rows .= '>';
				}
				else {
					// Remove space and non-breaking space
					$number_value = str_replace([' ', "\xC2\xA0"], '', $cell->getAttribute('data-spreadsheet-number') ?: $value);

					if (preg_match('/^-?\d+(?:[,.]\d+)?$/', $number_value)) {
						$number_value = str_replace(',', '.', $number_value);
					}
					else {
						$number_value = null;
					}

					if ($type == 'date'
						&& ($date = strtotime($cell->getAttribute('data-spreadsheet-date') ?: $value)))
					{
						$format = !intval(date('His', $date)) ? 'Y-m-d' : 'Y-m-d\TH:i:s';
						$rows .= sprintf(' calcext:value-type="date" office:date-value="%s" office:value-type="date" ', date($format, $date));
					}
					elseif ($type == 'percentage' && null !== $number_value) {
						$rows .= sprintf(' office:value-type="percentage" office:value="%f" calcext:value-type="percentage"', $number_value / 100);
					}
					elseif ($type == 'currency' && null !== $number_value) {
						$currency = $styles['-spreadsheet-currency'] ?? 'EUR';
						$rows .= sprintf(' office:value-type="currency" office:currency="%s" office:value="%f" calcext:value-type="currency"', $currency, $number_value);
					}
					elseif ($type == 'number' && null !== $number_value) {
						$rows .= sprintf(' calcext:value-type="float" office:value="%f" office:value-type="float"', $number_value);
					}
					else {
						$rows .= ' calcext:value-type="string" office:value-type="string"';
					}

					$rows .= '>';

					// get innerhtml
					$html = implode(array_map([$cell->ownerDocument,"saveHTML"], iterator_to_array($cell->childNodes)));

					// Break in multiple lines if required
					$html = preg_replace("/[\n\r]/", '', $html);
					$html = preg_replace('/<br[^>]*>/U', "\n", $html);
					$html = strip_tags($html);
					$html = html_entity_decode($html);
					$html = explode("\n", trim($html));

					$column_width = 0;

					foreach ($html as $line)
					{
						$rows .= sprintf('<text:p>%s</text:p>', htmlspecialchars($line, ENT_XML1, 'UTF-8'));

						if (!$colspan) {
							$cell_width = $this->getCellWidth($line, $styles);

							if ($cell_width > $column_width) {
								$column_width = $cell_width;
							}
						}
					}
				}

				if ($column_width > ($columns_widths[$index] ?? -1)) {
					$columns_widths[$index] = $column_width;
				}

				$rows .= '</table:table-cell>' . $end;
				$index += $colspan ?: 1;
			}

			$rows .= '</table:table-row>';
		}

		ksort($columns_widths);

		foreach ($columns_widths as $width) {
			$name = $this->newStyle('column', ['break-before' => 'auto', 'column-width' => $width . 'pt']);
			$this->xml .= sprintf('<table:table-column table:style-name="%s" />', $name);
		}

		$this->xml .= $rows;
		$this->xml .= '</table:table>';
	}

	protected function getCellType(string $value, ?string $type = null)
	{
		if ($type && $type != 'auto') {
			return $type;
		}

		$number_value = str_replace([' ', "\xC2\xA0"], '', trim($value));

		if (is_object($value) && $value instanceof \DateTimeInterface) {
			return 'date';
		}
		elseif (is_int($value) || is_float($value) || (substr((string) $number_value, 0, 1) != '0' && preg_match('/^-?\d+(?:[,.]\d+)?$/', (string) $number_value))) {
			return 'number';
		}
		elseif (preg_match('!^(?:\d\d?/\d\d?/\d\d(?:\d\d)?|\d{4}-\d{2}-\d{2})(?:\s+\d\d?[:\.]\d\d?(?:[:\.]\d\d?))?$!', $value)) {
			return 'date';
		}
		elseif (preg_match('/^-?\d+(?:[,.]\d+)?\s*%$/', $number_value)) {
			return 'percentage';
		}
		elseif (preg_match('/^-?\d+(?:[,.]\d+)?\s*(?:€|\$|EUR|CHF)$/', $number_value)) {
			return 'currency';
		}

		return 'string';
	}

	public function save(string $filename): void
	{
		$this->zip($filename)->close();
	}

	public function output(): void
	{
		$this->zip('php://output')->close();
	}

	public function fetch(): string
	{
		$zip = $this->zip('php://temp');
		$data = $zip->get();
		$zip->close();
		return $data;
	}

	public function zip(?string $destination = null): ZipWriter
	{
		if (null === $destination) {
			$destination = 'php://output';
		}

		$z = new ZipWriter($destination);
		$z->add('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
		$z->setCompression(9);
		$z->add('settings.xml', self::XML_HEADER . '<office:document-settings office:version="1.3" xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:xlink="http://www.w3.org/1999/xlink"></office:document-settings>');
		$z->add('content.xml', $this->XML());
		$z->add('meta.xml', self::XML_HEADER . '<office:document-meta office:version="1.3" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:xlink="http://www.w3.org/1999/xlink"></office:document-meta>');
		$z->add('manifest.rdf', self::XML_HEADER . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="styles.xml"><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/odf#StylesFile"/></rdf:Description><rdf:Description rdf:about=""><ns0:hasPart xmlns:ns0="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#" rdf:resource="styles.xml"/></rdf:Description><rdf:Description rdf:about="content.xml"><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/odf#ContentFile"/></rdf:Description><rdf:Description rdf:about=""><ns0:hasPart xmlns:ns0="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#" rdf:resource="content.xml"/></rdf:Description><rdf:Description rdf:about=""><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#Document"/></rdf:Description></rdf:RDF>');
		$z->add('META-INF/manifest.xml', self::XML_HEADER . '<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0">
				<manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>
				<manifest:file-entry manifest:full-path="settings.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="manifest.rdf" manifest:media-type="application/rdf+xml"/>
			</manifest:manifest>');
		$z->finalize();
		return $z;
	}

	public function XML(): string
	{
		$out = self::XML_HEADER . '<office:document-content office:version="1.3" xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" xmlns:css3t="http://www.w3.org/TR/css3-text/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dom="http://www.w3.org/2001/xml-events" xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:drawooo="http://openoffice.org/2010/draw" xmlns:field="urn:openoffice:names:experimental:ooo-ms-interop:xmlns:field:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" xmlns:formx="urn:openoffice:names:experimental:ooxml-odf-interop:xmlns:form:1.0" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0" xmlns:math="http://www.w3.org/1998/Math/MathML" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:oooc="http://openoffice.org/2004/calc" xmlns:ooow="http://openoffice.org/2004/writer" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:rpt="http://openoffice.org/2005/report" xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:tableooo="http://openoffice.org/2009/table" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xforms="http://www.w3.org/2002/xforms" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';

		$out .= '<office:automatic-styles>
			<style:style style:family="table-row" style:name="ro-default">
				<style:table-row-properties style:use-optimal-row-height="true" />
			</style:style>
			<style:style style:family="table-column" style:name="co-default">
				<style:table-column-properties fo:break-before="auto" />
			</style:style>';

		$out .= $this->outputStyles();

		$out .= '</office:automatic-styles>';


		//$out .= '</office:styles>';
		$out .= '<office:body><office:spreadsheet>';

		$out .= $this->xml;

		$out .= '</office:spreadsheet></office:body></office:document-content>';

		return $out;
	}

	protected function outputAutomaticStyles(): string
	{
		return '';
	}

	protected function outputStyles(): string
	{
		$xml = '';

		foreach ($this->styles as $style_name => $properties) {
			$type = substr($style_name, 0, strpos($style_name, '_'));
			$tags = [];

			if ($type == 'table') {
				continue;
			}

			foreach ($properties as $name => $value) {
				if ($name[0] == '-') {
					continue;
				}

				$tag = $this->getStyleTagName($name);

				if (!$tag) {
					continue;
				}

				if (!isset($tags[$tag])) {
					$tags[$tag] = '';
				}

				$prefix = $this->getStyleAttributePrefix($name);
				$tags[$tag] .= sprintf(' %s:%s="%s"', $prefix,$name, $value);
			}

			$data_style = $this->getDataStyle($style_name, $properties);
			$attrs = '';

			if ($data_style) {
				$xml .= $data_style;
				$attrs .= sprintf(' style:data-style-name="data_%s"', $style_name);
			}

			$xml .= sprintf('<style:style style:family="table-%s" style:name="%s" %s>', $type, $style_name, $attrs);

			foreach ($tags as $name => $attrs) {
				$xml .= sprintf('<style:%s-properties %s/>', $name, $attrs);
			}

			if ($type == 'row') {
				$xml .= '<style:table-row-properties style:use-optimal-row-height="true" />';
			}

			$xml .= '</style:style>' . PHP_EOL;
		}

		return $xml;
	}

	/**
	 * Normalize styles from CSS to LibreOffice styles
	 * Some are similar, others not
	 */
	protected function normalizeStyles(array $styles): array
	{
		$out = [];

		foreach ($styles as $name => $value) {
			if ($value == 'initial' || !$value) {
				// Remove style property
				continue;
			}

			$value = $this->normalizeStyle($name, $value, $out);

			if (null === $value) {
				continue;
			}

			$out[$name] = $value;
		}

		return $out;
	}

	protected function normalizeStyle(string &$name, string $value, array &$out): ?string
	{
		switch ($name) {
			case 'wrap':
				if ($value != 'wrap') {
					// We only support wrap, default is nowrap
					return null;
				}

				// Alias wrap -> wrap-option
				$name = 'wrap-option';
				return $value;
			case 'hyphens':
				if ($value != 'auto') {
					// We only support auto
					return null;
				}

				// Alias hyphens -> hyphenate
				$name = 'hyphenate';
				return 'true';
			// Alias transform -> rotation-angle
			case 'transform':
				// We only support rotating the text X degrees
				if (!preg_match('/rotate\((\d+)deg\)/', $value, $m)) {
					return null;
				}

				$name = 'rotation-angle';
				return $m[1];
			case 'text-align':
				// Alias left/right to start/end
				$value = strtr($value, ['left' => 'start', 'right' => 'end']);
				$out['text-align-source'] = 'fix';
				return $value;
			case '-spreadsheet-locale':
				return trim($value, '"\' ');
			default:
				return $value;
		}
	}

	protected function newStyle(string $style_type, array $styles): ?string
	{
		unset($styles['-spreadsheet-locale']);
		$styles = $this->normalizeStyles($styles);

		if (!count($styles)) {
			return null;
		}

		ksort($styles);
		$hash = md5(implode(',', array_merge(array_keys($styles), array_values($styles))));

		$key = $style_type . '_' . $hash;

		if (array_key_exists($key, $this->styles)) {
			return $key;
		}

		$this->styles[$key] = $styles;
		return $key;
	}

	protected function getStyleTagName(string $name): ?string
	{
		switch ($name) {
			case 'text-align':
				return 'paragraph';
			case 'text-align-source':
			case 'vertical-align':
			case 'wrap-option':
			case 'border':
			case 'border-left':
			case 'border-right':
			case 'border-top':
			case 'border-bottom':
			case 'padding':
			case 'background-color':
			case 'rotation-angle':
				return 'table-cell';
			case 'break-before':
			case 'column-width':
				return 'table-column';
			case 'font-weight':
			case 'font-style':
			case 'font-size':
			case 'color':
			case 'hyphenate':
				return 'text';
			default:
				return null;
		}
	}

	protected function getStyleAttributePrefix(string $name): ?string
	{
		switch ($name) {
			case 'text-align-source':
			case 'vertical-align':
			case 'rotation-angle':
			case 'column-width':
				return 'style';
			case 'break-before':
			case 'wrap-option':
			case 'border':
			case 'border-left':
			case 'border-right':
			case 'border-top':
			case 'border-bottom':
			case 'padding':
			case 'background-color':
			case 'font-weight':
			case 'font-style':
			case 'font-size':
			case 'color':
			case 'hyphenate':
			case 'text-align':
				return 'fo';
			default:
				return null;
		}
	}

	protected function getDataStyle(string $style_name, array $properties): ?string
	{
		$type = $properties['-spreadsheet-cell-type'] ?? null;

		if (!$type || $type == 'auto' || $type == 'string') {
			return null;
		}

		$style_name = 'data_' . $style_name;

		if ($type == 'date') {
			return $this->getDateStyle($style_name, $properties);
		}
		else {
			return $this->getNumberStyle($style_name, $properties);
		}
	}

	protected function getNumberStyle(string $name, array $styles): string
	{
		$type = $styles['-spreadsheet-cell-type'] ?? 'number';
		$format = $styles['-spreadsheet-number-format'] ?? 'float';
		$color = $styles['-spreadsheet-number-negative-color'] ?? null;
		$tag_name = 'number:number-style';

		if ($type == 'percentage') {
			$tag_name = 'number:percentage-style';

			if ($format == 'float') {
				$format = 'percentage_float';
			}
			else {
				$format = 'percentage_integer';
			}
		}

		$number = self::NUMBER_FORMATS[$format] ?? self::NUMBER_FORMATS['float'];

		if ($type == 'currency') {
			$symbol = $styles['-spreadsheet-currency-symbol'] ?? null;
			$currency = $styles['-spreadsheet-currency'] ?? 'EUR';

			if (!$symbol && $currency == 'EUR') {
				$symbol = '€';
			}

			$position = $styles['-spreadsheet-currency-position'] ?? 'suffix';

			if ($color === null) {
				$color = 'red';
			}

			if (isset($styles['-spreadsheet-locale']) && preg_match('/^[a-z]{2}[_-][A-Z]{2}$', $styles['-spreadsheet-locale'])) {
				$lang = substr($styles['-spreadsheet-locale'], 0, 2);
				$country = substr($styles['-spreadsheet-locale'], 3, 2);
			}
			else {
				$lang = 'fr';
				$country = 'FR';
			}

			$tag_name = 'number:currency-style';
			$space = '<number:text> </number:text>';

			$currency = sprintf('<number:currency-symbol number:language="%s" number:country="%s">%s</number:currency-symbol>',
				$lang, $country, htmlspecialchars($symbol, ENT_XML1));

			if ($position == 'suffix') {
				$number = $number . $space . $currency;
			}
			else {
				$number = $currency . $space . $number;

			}
		}

		if ($color && $color != 'none') {
			if (!isset(self::NUMBER_COLORS[$color])) {
				$color = 'red';
			}

			$color = self::NUMBER_COLORS[$color];

			$prefix = sprintf('<style:text-properties fo:color="%s"/><number:text>-</number:text>', $color);
			$suffix = sprintf('<style:map style:condition="value()&gt;=0" style:apply-style-name="%sP0"/>', $name);

			$out = sprintf('<%s style:name="%sP0" style:volatile="true">%s</%1$s>', $tag_name, $name, $number);
			$out .= sprintf('<%s style:name="%s" style:volatile="true">%s%s%s</%1$s>', $tag_name, $name, $prefix, $number, $suffix);
		}
		else {
			$out = sprintf('<%s style:name="%s" style:volatile="true">%s</%1$s>', $tag_name, $name, $number);
		}

		return $out;
	}

	public function getDateStyle(string $name, array $styles): string
	{
		$out = '<number:date-style style:name="%s" number:automatic-order="true" number:format-source="language">';

		$format = $styles['-spreadsheet-date-format'] ?? 'short';

		if (isset(self::DATE_FORMATS[$format])) {
			$format = self::DATE_FORMATS[$format];
		}

		$out .= preg_replace_callback('/yyyy|yy|y|M{1,4}|L{1,4}|dd|d|E{1,4}|HH|H|mm|m|ss|s|.+?/', function ($m) {
			switch ($m[0]) {
				case 'yyyy':
					return '<number:year number:style="long"/>';
				case 'y':
				case 'yy':
					return '<number:year />';
				case 'M':
				case 'L':
					return '<number:month />';
				case 'MM':
				case 'LL':
					return '<number:month number:style="long"/>';
				case 'MMM':
				case 'LLL':
					return '<number:month number:textual="true"/>';
				case 'MMMM':
				case 'LLLL':
					return '<number:month number:style="long" number:textual="true"/>';
				case 'dd':
					return '<number:day number:style="long"/>';
				case 'd':
					return '<number:day />';
				case 'E':
				case 'EE':
				case 'EEE':
					return '<number:day-of-week/>';
				case 'EEEE':
					return '<number:day-of-week number:style="long"/>';
				case 'HH':
					return '<number:hours number:style="long"/>';
				case 'H':
					return '<number:hours />';
				case 'mm':
					return '<number:minutes number:style="long"/>';
				case 'm':
					return '<number:minutes />';
				case 'ss':
					return '<number:seconds number:style="long"/>';
				case 's':
					return '<number:seconds />';
				default:
					return sprintf('<number:text>%s</number:text>', htmlspecialchars($m[0]));
			}
		}, $format);

		$out .= '</number:date-style>';

		return sprintf($out, $name);
	}

	public function getCellWidth(string $line, array $styles): int
	{
		$font_size = $this->getFontSize($styles['font-size'] ?? '10pt');

		$width = mb_strlen($line) * 8;
		$width = $width * $font_size / 11;

		// The size is different if text is rotated
		if (!empty($styles['rotation-angle'])) {
			$width = $width * cos(deg2rad($styles['rotation-angle'])) + $font_size * abs(sin(deg2rad($styles['rotation-angle']))) / 5;
		}

		return (int) $width;
	}

	public function getFontSize(string $size): int
	{
		$size = strtolower($size);
		$v = preg_replace('/[^0-9]+/', '', $size);

		if (substr($size, -2) == 'px') {
			return (int) ceil($v * 1.25);
		}

		return (int) $size;
	}
}
