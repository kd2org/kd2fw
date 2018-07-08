<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/
  
  Copyright (c) 2001-2016 BohwaZ <http://bohwaz.net/>
  All rights reserved.
  
  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:
  1. Redistributions of source code must retain the above copyright notice,
  this list of conditions and the following disclaimer.
  2. Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.
  
  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF
  THE POSSIBILITY OF SUCH DAMAGE.
*/

namespace KD2;

use KD2\ZipWriter;
use DOMDocument;

/**
 * OpenDocument Spreadsheet exporter (very simple)
 */
class ODSWriter
{
	const DEFAULT_TABLE_NAME = 'Sheet1';
	const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>';

	public $table_name = 'Sheet1';

	protected $rows = [];
	
	public function add(array $columns)
	{
		if (count($this->rows) && count(end($this->rows)) != count($columns))
		{
			throw new \LogicException('Mismatching column count');
		}

		$this->rows[] = $columns;
	}

	protected function toXML()
	{
		$out = self::XML_HEADER . '<office:document-content office:version="1.2" xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" xmlns:css3t="http://www.w3.org/TR/css3-text/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dom="http://www.w3.org/2001/xml-events" xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:drawooo="http://openoffice.org/2010/draw" xmlns:field="urn:openoffice:names:experimental:ooo-ms-interop:xmlns:field:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" xmlns:formx="urn:openoffice:names:experimental:ooxml-odf-interop:xmlns:form:1.0" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0" xmlns:math="http://www.w3.org/1998/Math/MathML" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:oooc="http://openoffice.org/2004/calc" xmlns:ooow="http://openoffice.org/2004/writer" xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:rpt="http://openoffice.org/2005/report" xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:tableooo="http://openoffice.org/2009/table" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:xforms="http://www.w3.org/2002/xforms" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
		<office:body>
			<office:automatic-styles>
				<style:style style:family="table-column" style:name="co1">
					<style:table-column-properties fo:break-before="auto" style:use-optimal-column-width="true"/>
				</style:style>
				<style:style style:family="table-row" style:name="ro1">
					<style:table-row-properties fo:break-before="auto" style:use-optimal-row-height="true"/>
				</style:style>
			</office:automatic-styles>
			<office:spreadsheet>';

		$nb_columns = isset($this->rows[0]) ? count($this->rows[0]) : 0;

		$out .= '
			<table:table table:name="' . htmlspecialchars($this->table_name, ENT_XML1, 'UTF-8') . '">';

		for ($i = 0; $i < $nb_columns; $i++)
		{
			$out .= '
				<table:table-column/>';
		}

		foreach ($this->rows as $row)
		{
			$out .= '
				<table:table-row>';

			foreach ($row as $column)
			{
				if (is_int($column) || is_float($column))
				{
					$params = sprintf('calcext:value-type="float" office:value="%s" office:value-type="float"', $column);
				}
				elseif (is_object($column) && $column instanceof \DateTimeInterface)
				{
					$format = ($column->format('hi') == '0000') ? 'Y-m-d' : 'Y-m-d\TH:i:s';
					$params = sprintf('calcext:value-type="date" office:date-value="%s" office:value-type="date"', $column->format($format));
					$column = $column->format($format);
				}
				else
				{
					$params = 'calcext:value-type="string" office:value-type="string"';
				}

				$out .= '
					<table:table-cell ' . $params . '>';

				$column = explode("\n", $column);

				foreach ($column as $line)
				{
						$out .= sprintf('
							<text:p>%s</text:p>', htmlspecialchars($line, ENT_XML1, 'UTF-8'));
				}

				$out .= '
					</table:table-cell>';
			}

			$out .= '
				</table:table-row>';
		}

		$out .= '
				</table:table>
			</office:spreadsheet>
		</office:body>
		</office:document-content>';

		return $out;
	}

	protected function zip($file)
	{
		$z = new ZipWriter($file);
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

	public function output($file = null)
	{
		$this->zip($file ?: 'php://output')->close();
	}

	public function get()
	{
		$zip = $this->zip('php://temp');
		$data = $zip->get();
		$zip->close();
		return $data;
	}
}