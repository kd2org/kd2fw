<?php

namespace KD2\HTML;

use KD2\HTML\CSSParser;
use KD2\ZipWriter;

/**
 * Supported CSS properties:
 * 'initial' value
 * font-style: italic
 * font-weight: bold
 * font-size: XXpt
 * color: #aabbcc
 * background-color: #aabbcc
 * text-align: left|center|right
 * vertical-align: top|middle|bottom
 * padding: XXmm
 * border[-left/right/bottom/top]: none|0.06pt solid #aabbcc
 * wrap: wrap|nowrap
 * hyphens: auto|none
 * transform: rotate(90deg)
 * -spreadsheet-cell-type: number|
 */
class ToODS
{
	protected array $styles = [];
	public string $default_sheet_name = 'Sheet%d';

	const DATA_TYPES = [
		'number',
		'datetime',
		'currency',
		'percent',
		'string',
		'auto',
	];

	const CUSTOM_CSS_PROPERTIES = [
		// fr-BE, en_AU, etc.
		'-spreadsheet-locale',
		// auto, string, currency, date, number, percent
		'-spreadsheet-cell-type',
		// see https://unicode-org.github.io/icu/userguide/format_parse/datetime/#date-field-symbol-table
		// or one of the strings in DATE_FORMATS array
		'-spreadsheet-date-format',
		// integer, float, percent_integer, percent_float
		'-spreadsheet-number-format',
		// €, GBP, etc.
		'-spreadsheet-currency-symbol',
		// 'prefix' (default) or 'suffix'
		'-spreadsheet-currency-position',
		// true or false
		'-spreadsheet-number-negative-color'
	];

	const DATE_FORMATS = [
		'short' => 'dd/MM/yyyy',
		'short_hours' => 'dd/MM/yyyy hh:mm',
		'hours' => 'hh:mm',
		'long' => 'eeee d LLLL yyyy',
		'long_hours' => 'eeee d LLLL yyyy, hh:mm',
	];

	const NUMBER_FORMATS = [
		'integer' => '<number:number number:decimal-places="0" number:min-decimal-places="0" number:min-integer-digits="1" number:grouping="true"/></number>',
		'float' => '<number:number number:decimal-places="2" number:min-decimal-places="2" number:min-integer-digits="1" number:grouping="true"/>',
		'percent_integer' => '<number:number number:decimal-places="0" number:min-decimal-places="0" number:min-integer-digits="1"/>
			<number:text> %</number:text>',
		'percent_float' => '<number:number number:decimal-places="2" number:min-decimal-places="2" number:min-integer-digits="1"/>
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

	public function __construct()
	{
		$this->css = new CSSParser;
	}

	public function import(string $html, string $css = null): void
	{
		libxml_use_internal_errors(true);

		$doc = new \DOMDocument;
		$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

		if ($css) {
			$this->css->import($css);
		}
		else {
			$this->css->importExternalFromDOM($doc, 'spreadsheet');
			$this->css->importInternalFromDOM($doc, 'spreadsheet');
		}

		foreach ($this->css->xpath($doc, './/table') as $i => $table) {
			$this->add($table, $i);
		}

		unset($doc);
	}

	protected function add(HTMLNode $table, int $count): void
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
			$this->newStyle('table', $styles)
		);

		$column_count = null;
		foreach ($this->css->xpath($table, './/tr') as $row) {
			$styles = $css_parser->get($row);

			$this->xml .= sprintf('<table:table-row table:style-name="%s">', $this->newStyle('row', $styles));

			$cells = $this->css->xpath($table, './/td|.//th');

			/*
			// Count number of columns in table
			if (null === $column_count) {
				foreach ($cells as $cell) {
					$column_count++;

					if ($colspan = $cell->getAttribute('colspan')) {
						$column_count += $colspan - 1;
					}
				}
			}
			*/

			foreach ($cells as $cell) {
				$styles = $css_parser->get($cell);

				$value = $cell->textContent;
				$value = htmlspecialchars_decode($value);
				$value = trim($value);
				$styles['-spreadsheet-cell-type'] = $this->getCellType($value, $styles['-spreadsheet-cell-type'] ?? null);

				$end = '';
				$this->xml .= sprintf('<table:table-cell table:style-name="%s"', $this->newStyle('cell', $styles));

				if ($colspan = $cell->getAttribute('colspan')) {
					$this->xml .= sprintf(' table:number-columns-spanned="%d"', $colspan);
					$end .= str_repeat('<table:covered-table-cell/>', $colspan);
				}

				/*
				if ($rowspan = $cell->getAttribute('rowspan')) {
					$this->xml .= sprintf(' table:number-rows-spanned="%d"', $rowspan);
				}
				*/

				$this->xml .= '</table:table-cell>' . $end;
			}

			$this->xml .= '</table:table-row>';
		}
	}

	protected function getCellType(string $value, ?string $type = null)
	{
		if ($type != 'auto') {
			return $type;
		}

		if (is_object($value) && $value instanceof \DateTimeInterface) {
			return 'date';
		}
		elseif (is_int($value) || is_float($value) || (substr((string) $value, 0, 1) != '0' && preg_match('/^-?\d+(?:[,.]\d+)?$/', (string) $value))) {
			return 'number';
		}
		elseif (preg_match('!^(?:\d\d?/\d\d?/\d\d(?:\d\d)?)|\d{4}-\d{2}-\d{2})(?:\s+\d\d?[:\.]\d\d?(?:[:\.]\d\d?))?$!', $value)) {
			return 'date';
		}
		elseif (preg_match('/^-?\d+(?:[,.]\d+)?\s*%$/', trim($value))) {
			return 'percent';
		}
		elseif (preg_match('/^-?\d+(?:[,.]\d+)?\s*(?:€|\$|EUR|CHF)$/', trim($value))) {
			return 'currency';
		}

		return 'string';
	}

	public function save(string $filename): void
	{
		$this->zip($destination)->close();
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
		$z->add('settings.xml', self::XML_HEADER . '<office:document-settings office:version="1.2" xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:xlink="http://www.w3.org/1999/xlink"></office:document-settings>');
		$z->add('content.xml', $this->toXML());
		$z->add('meta.xml', self::XML_HEADER . '<office:document-meta office:version="1.2" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:xlink="http://www.w3.org/1999/xlink"></office:document-meta>');
		$z->add('styles.xml', self::XML_HEADER . '<office:document-styles office:version="1.2" xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" xmlns:css3t="http://www.w3.org/TR/css3-text/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dom="http://www.w3.org/2001/xml-events" xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:drawooo="http://openoffice.org/2010/draw" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0" xmlns:math="http://www.w3.org/1998/Math/MathML" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:oooc="http://openoffice.org/2004/calc" xmlns:ooow="http://openoffice.org/2004/writer" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:rpt="http://openoffice.org/2005/report" xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:tableooo="http://openoffice.org/2009/table" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:xlink="http://www.w3.org/1999/xlink"></office:document-styles>');
		$z->add('manifest.rdf', self::XML_HEADER . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="styles.xml"><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/odf#StylesFile"/></rdf:Description><rdf:Description rdf:about=""><ns0:hasPart xmlns:ns0="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#" rdf:resource="styles.xml"/></rdf:Description><rdf:Description rdf:about="content.xml"><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/odf#ContentFile"/></rdf:Description><rdf:Description rdf:about=""><ns0:hasPart xmlns:ns0="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#" rdf:resource="content.xml"/></rdf:Description><rdf:Description rdf:about=""><rdf:type rdf:resource="http://docs.oasis-open.org/ns/office/1.2/meta/pkg#Document"/></rdf:Description></rdf:RDF>');
		$z->add('META-INF/manifest.xml', self::XML_HEADER . '
			<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">
				<manifest:file-entry manifest:full-path="/" manifest:version="1.2" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>
				<manifest:file-entry manifest:full-path="settings.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
				<manifest:file-entry manifest:full-path="manifest.rdf" manifest:media-type="application/rdf+xml"/>
				<manifest:file-entry manifest:full-path="Configurations2/accelerator/current.xml" manifest:media-type=""/>
				<manifest:file-entry manifest:full-path="Configurations2/" manifest:media-type="application/vnd.sun.xml.ui.configuration"/>
			</manifest:manifest>');
		$z->finalize();
		return $z;
	}

	public function outputXML(): string
	{
		$out = '<?xml version="1.0" encoding="UTF-8"?><office:document-content office:version="1.2" xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" xmlns:css3t="http://www.w3.org/TR/css3-text/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dom="http://www.w3.org/2001/xml-events" xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:drawooo="http://openoffice.org/2010/draw" xmlns:field="urn:openoffice:names:experimental:ooo-ms-interop:xmlns:field:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" xmlns:formx="urn:openoffice:names:experimental:ooxml-odf-interop:xmlns:form:1.0" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0" xmlns:math="http://www.w3.org/1998/Math/MathML" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:oooc="http://openoffice.org/2004/calc" xmlns:ooow="http://openoffice.org/2004/writer" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:rpt="http://openoffice.org/2005/report" xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:tableooo="http://openoffice.org/2009/table" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xforms="http://www.w3.org/2002/xforms" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';

		$out .= '<office:automatic-styles>
			<style:style style:family="table" style:name="ta1">
				<style:table-properties style:writing-mode="lr-tb" table:display="true" />
			</style:style>';

		$out .= $this->outputAutomaticStyles();

		$out .= '</office:automatic-styles><office:styles>';

		$out .= $this->outputStyles();

		$out .= '</office:styles><office:body>';

		$out .= $this->xml;

		$out .= '</office:body></office:document-content>';

		return $out;
	}

	/**
	 * Normalize styles from CSS to LibreOffice styles
	 * Some are similar, others not
	 */
	protected function normalizeStyles(array $styles): array
	{
		$out = [];

		foreach ($styles as $name => $value) {
			if ($name == 'wrap') {
				// We only support wrap, default is nowrap
				if ($value != 'wrap') {
					continue;
				}

				// Alias wrap -> wrap-option
				$name = 'wrap-option';
				$value = 'wrap';
			}
			elseif ($name == 'hyphens') {
				if ($value != 'auto') {
					// We only support auto
					continue;
				}

				// Alias hyphens -> hyphenate
				$name = 'hyphenate';
				$value = 'true';
			}
			// Alias transform -> rotation-angle
			elseif ($name == 'transform') {
				// We only support rotating the text X degrees
				if (!preg_match('/rotate\((\d+)deg\)/', $value, $m)) {
					continue;
				}

				$name = 'rotation-angle';
				$value = (int) $m[1];
			}

			// Remove style property
			if ($value == 'initial') {
				continue;
			}

			if ($name == 'text-align') {
				// Alias left/right to start/end
				$value = strtr($value, ['left' => 'start', 'right' => 'end']);
				$out['text-align-source'] = 'fix';
			}

			$out[$name] = $value;
		}

		return $out;
	}

	protected function newStyle(string $style_type, array $styles): string
	{
		$styles = $this->normalizeStyles($styles);
		ksort($styles);
		$hash = md5(implode(',', array_merge(array_keys($styles), array_values($styles))));

		$key = $style_type . '_' . $hash;

		if (array_key_exists($key, $this->styles)) {
			return $key;
		}

		$this->styles[$key] = $styles;
		return $key;
	}

	protected function styles(): string
	{
		$xml = '';

		foreach ($this->styles as $style_name => $properties) {
			$type = substr($style_name, strpos($style_name, '_'));
			$attrs = '';
			$tags = [];

			foreach ($properties as $name => $value) {
				if ($name == 'data-style-name') {
					$attrs .= sprintf(' style:%s="%s"', $name, $value);
				}
				else {
					$tag = $this->getStyleTagName($name);

					if (!isset($tags[$tag])) {
						$tags[$tag] = '';
					}

					$tags[$tag] .= sprintf(' style:%s="%s"', $name, $value);
				}
			}

			$xml .= sprintf('<style:style style:name="%s" style:family="table-%s" style:parent-style-name="Default"%s>', $style_name, $type, $attrs);

			foreach ($tags as $name => $attrs) {
				$xml .= sprintf('<%s%s/>', $name, $attrs);
			}

			$xml .= '</style:style>';
		}

		return $xml;
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

	public function getNumberStyle(string $name, array $styles): string
	{
		$type = $styles['-spreadsheet-cell-type'] ?? 'number';
		$format = $styles['-spreadsheet-number-format'] ?? 'float';
		$color = $styles['-spreadsheet-number-negative-color'] ?? null;
		$tag_name = 'number:number-style';

		if ($type == 'percent') {
			$tag_name = 'number:percentage-style';

			if ($format == 'float') {
				$format = 'percent_float';
			}
			else {
				$format = 'percent_integer';
			}
		}

		$number = self::NUMBER_FORMATS[$format] ?? self::NUMBER_FORMATS['float'];

		if ($type == 'currency') {
			$symbol = $styles['-spreadsheet-currency-symbol'] ?? '€';
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

			$out = sprintf('<%s style:name="%sP0" style:volatile="true">%s</%1$s>', $tag_name, $number);
			$out .= sprintf('<%s style:name="%s" style:volatile="true">%s%s%s</%1$s>', $tag_name, $prefix, $number, $suffix);
		}
		else {
			$out = sprintf('<%s style:name="%s" style:volatile="true">%s</%1$s>', $tag_name, $number);
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

		$out .= preg_replace_callback('/yyyy|yy|y|M{1,4}|L{1,4}|dd|d|E{1,4}|HH|H|mm|m|ss|s|.+/', function ($m) {
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
}
