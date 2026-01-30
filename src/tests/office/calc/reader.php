<?php

use KD2\Test;
use KD2\Office\Calc\Reader;

require __DIR__ . '/../../_assert.php';

function test_reader(string $str)
{
  $r = new Reader;
  $r->openString($str);

  $sheets = $r->listSheets();
  Test::equals(2, count($sheets));
  Test::assert(in_array('Feuille1', $sheets));
  Test::assert(in_array('Test <> weird sheet name', $sheets));
  Test::assert(1 === array_search('Test <> weird sheet name', $sheets, true));

  $rows = iterator_to_array($r->iterate(1));
  Test::assert(2 === count($rows));
  Test::assert(3 === count($rows[0]));
  Test::assert(3 === count($rows[1]));
  Test::assert('A' === $rows[0][0]);
  Test::assert('B' === $rows[0][1]);
  Test::assert('' === $rows[0][2]);
  Test::assert(1 === $rows[1][0]);
  Test::assert(2 === $rows[1][1]);
  Test::assert(3 === $rows[1][2]);

  $rows = iterator_to_array($r->iterate(0));
  Test::assert(6 === count($rows));

  foreach ($rows as $row) {
    Test::assert(2 === count($row));
  }

  Test::strictlyEquals('2026-02-01', $rows[0][0]);
  Test::assert(3 === $rows[0][1]);
  Test::assert(1.02 === $rows[1][0]);
  Test::assert(0.1403 === $rows[2][0]);
  Test::strictlyEquals('2026-01-01T02:02:01', $rows[3][0]);
  Test::assert(3 === $rows[4][0]);
  Test::assert(3.0004 === $rows[4][1]);
  Test::assert("Line 1\nLine 2" === $rows[5][0]);
}

function test_reader_span(string $str)
{
	$r = new Reader;
	$r->openString($str);

	$sheets = $r->listSheets();
	Test::equals(1, count($sheets));
	Test::assert(in_array('Feuille1', $sheets));

	$rows = iterator_to_array($r->iterate(0));

  foreach ($rows as $row) {
    Test::assert(5 === count($row));
  }

  Test::strictlyEquals('2026-01-01', $rows[0][0]);
  Test::strictlyEquals('2026-01-01', $rows[0][1]);
  Test::strictlyEquals('Spanned 2 columns', $rows[2][0]);
  Test::strictlyEquals('', $rows[2][1]);
  Test::strictlyEquals('Spanned 2 rows', $rows[3][0]);
  Test::strictlyEquals('Not spanned', $rows[3][1]);
  Test::strictlyEquals('spanned', $rows[3][2]);
  Test::strictlyEquals('', $rows[4][0]);
}

$fods = <<<EOF
<?xml version="1.0" encoding="UTF-8"?>

<office:document xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:css3t="http://www.w3.org/TR/css3-text/" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xforms="http://www.w3.org/2002/xforms" xmlns:dom="http://www.w3.org/2001/xml-events" xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" xmlns:math="http://www.w3.org/1998/Math/MathML" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" xmlns:ooow="http://openoffice.org/2004/writer" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:drawooo="http://openoffice.org/2010/draw" xmlns:oooc="http://openoffice.org/2004/calc" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" xmlns:tableooo="http://openoffice.org/2009/table" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" xmlns:rpt="http://openoffice.org/2005/report" xmlns:formx="urn:openoffice:names:experimental:ooxml-odf-interop:xmlns:form:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:field="urn:openoffice:names:experimental:ooo-ms-interop:xmlns:field:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0" office:version="1.4" office:mimetype="application/vnd.oasis.opendocument.spreadsheet">
 <office:meta><meta:creation-date>2026-01-16T03:11:09.633681353</meta:creation-date><dc:date>2026-01-18T16:25:21.123125703</dc:date><meta:editing-duration>P2DT13H14M9S</meta:editing-duration><meta:editing-cycles>8</meta:editing-cycles><meta:generator>LibreOffice/25.2.3.2\$Linux_X86_64 LibreOffice_project/520\$Build-2</meta:generator><meta:document-statistic meta:table-count="2" meta:cell-count="13" meta:object-count="0"/></office:meta>
 <office:settings>
  <config:config-item-set config:name="ooo:view-settings">
   <config:config-item config:name="VisibleAreaTop" config:type="int">0</config:config-item>
   <config:config-item config:name="VisibleAreaLeft" config:type="int">0</config:config-item>
   <config:config-item config:name="VisibleAreaWidth" config:type="int">4907</config:config-item>
   <config:config-item config:name="VisibleAreaHeight" config:type="int">3099</config:config-item>
   <config:config-item-map-indexed config:name="Views">
    <config:config-item-map-entry>
     <config:config-item config:name="ViewId" config:type="string">view1</config:config-item>
     <config:config-item-map-named config:name="Tables">
      <config:config-item-map-entry config:name="Feuille1">
       <config:config-item config:name="CursorPositionX" config:type="int">1</config:config-item>
       <config:config-item config:name="CursorPositionY" config:type="int">5</config:config-item>
       <config:config-item config:name="ActiveSplitRange" config:type="short">2</config:config-item>
       <config:config-item config:name="PositionLeft" config:type="int">0</config:config-item>
       <config:config-item config:name="PositionRight" config:type="int">0</config:config-item>
       <config:config-item config:name="PositionTop" config:type="int">0</config:config-item>
       <config:config-item config:name="PositionBottom" config:type="int">0</config:config-item>
       <config:config-item config:name="ZoomType" config:type="short">0</config:config-item>
       <config:config-item config:name="ZoomValue" config:type="int">100</config:config-item>
       <config:config-item config:name="PageViewZoomValue" config:type="int">60</config:config-item>
       <config:config-item config:name="ShowGrid" config:type="boolean">true</config:config-item>
       <config:config-item config:name="AnchoredTextOverflowLegacy" config:type="boolean">false</config:config-item>
       <config:config-item config:name="LegacySingleLineFontwork" config:type="boolean">false</config:config-item>
       <config:config-item config:name="ConnectorUseSnapRect" config:type="boolean">false</config:config-item>
       <config:config-item config:name="IgnoreBreakAfterMultilineField" config:type="boolean">false</config:config-item>
      </config:config-item-map-entry>
      <config:config-item-map-entry config:name="Test &lt;&gt; weird sheet name">
       <config:config-item config:name="CursorPositionX" config:type="int">2</config:config-item>
       <config:config-item config:name="CursorPositionY" config:type="int">2</config:config-item>
       <config:config-item config:name="ActiveSplitRange" config:type="short">2</config:config-item>
       <config:config-item config:name="PositionLeft" config:type="int">0</config:config-item>
       <config:config-item config:name="PositionRight" config:type="int">0</config:config-item>
       <config:config-item config:name="PositionTop" config:type="int">0</config:config-item>
       <config:config-item config:name="PositionBottom" config:type="int">0</config:config-item>
       <config:config-item config:name="ZoomType" config:type="short">0</config:config-item>
       <config:config-item config:name="ZoomValue" config:type="int">100</config:config-item>
       <config:config-item config:name="PageViewZoomValue" config:type="int">60</config:config-item>
       <config:config-item config:name="ShowGrid" config:type="boolean">true</config:config-item>
       <config:config-item config:name="AnchoredTextOverflowLegacy" config:type="boolean">false</config:config-item>
       <config:config-item config:name="LegacySingleLineFontwork" config:type="boolean">false</config:config-item>
       <config:config-item config:name="ConnectorUseSnapRect" config:type="boolean">false</config:config-item>
       <config:config-item config:name="IgnoreBreakAfterMultilineField" config:type="boolean">false</config:config-item>
      </config:config-item-map-entry>
     </config:config-item-map-named>
     <config:config-item config:name="ActiveTable" config:type="string">Feuille1</config:config-item>
     <config:config-item config:name="HorizontalScrollbarWidth" config:type="int">886</config:config-item>
     <config:config-item config:name="ZoomType" config:type="short">0</config:config-item>
     <config:config-item config:name="ZoomValue" config:type="int">100</config:config-item>
     <config:config-item config:name="PageViewZoomValue" config:type="int">60</config:config-item>
     <config:config-item config:name="ShowPageBreakPreview" config:type="boolean">false</config:config-item>
     <config:config-item config:name="ShowZeroValues" config:type="boolean">true</config:config-item>
     <config:config-item config:name="ShowNotes" config:type="boolean">true</config:config-item>
     <config:config-item config:name="ShowNoteAuthor" config:type="boolean">true</config:config-item>
     <config:config-item config:name="ShowFormulasMarks" config:type="boolean">false</config:config-item>
     <config:config-item config:name="ShowGrid" config:type="boolean">true</config:config-item>
     <config:config-item config:name="GridColor" config:type="int">12632256</config:config-item>
     <config:config-item config:name="ShowPageBreaks" config:type="boolean">true</config:config-item>
     <config:config-item config:name="HasColumnRowHeaders" config:type="boolean">true</config:config-item>
     <config:config-item config:name="HasSheetTabs" config:type="boolean">true</config:config-item>
     <config:config-item config:name="IsOutlineSymbolsSet" config:type="boolean">true</config:config-item>
     <config:config-item config:name="IsValueHighlightingEnabled" config:type="boolean">false</config:config-item>
     <config:config-item config:name="IsSnapToRaster" config:type="boolean">false</config:config-item>
     <config:config-item config:name="RasterIsVisible" config:type="boolean">false</config:config-item>
     <config:config-item config:name="RasterResolutionX" config:type="int">1000</config:config-item>
     <config:config-item config:name="RasterResolutionY" config:type="int">1000</config:config-item>
     <config:config-item config:name="RasterSubdivisionX" config:type="int">1</config:config-item>
     <config:config-item config:name="RasterSubdivisionY" config:type="int">1</config:config-item>
     <config:config-item config:name="IsRasterAxisSynchronized" config:type="boolean">true</config:config-item>
     <config:config-item config:name="FormulaBarHeight" config:type="short">1</config:config-item>
     <config:config-item config:name="AnchoredTextOverflowLegacy" config:type="boolean">false</config:config-item>
     <config:config-item config:name="LegacySingleLineFontwork" config:type="boolean">false</config:config-item>
     <config:config-item config:name="ConnectorUseSnapRect" config:type="boolean">false</config:config-item>
     <config:config-item config:name="IgnoreBreakAfterMultilineField" config:type="boolean">false</config:config-item>
    </config:config-item-map-entry>
   </config:config-item-map-indexed>
  </config:config-item-set>
  <config:config-item-set config:name="ooo:configuration-settings">
   <config:config-item config:name="AllowPrintJobCancel" config:type="boolean">true</config:config-item>
   <config:config-item config:name="ApplyUserData" config:type="boolean">true</config:config-item>
   <config:config-item config:name="AutoCalculate" config:type="boolean">true</config:config-item>
   <config:config-item config:name="CharacterCompressionType" config:type="short">0</config:config-item>
   <config:config-item config:name="EmbedAsianScriptFonts" config:type="boolean">true</config:config-item>
   <config:config-item config:name="EmbedComplexScriptFonts" config:type="boolean">true</config:config-item>
   <config:config-item config:name="EmbedFonts" config:type="boolean">false</config:config-item>
   <config:config-item config:name="EmbedLatinScriptFonts" config:type="boolean">true</config:config-item>
   <config:config-item config:name="EmbedOnlyUsedFonts" config:type="boolean">false</config:config-item>
   <config:config-item config:name="GridColor" config:type="int">12632256</config:config-item>
   <config:config-item config:name="HasColumnRowHeaders" config:type="boolean">true</config:config-item>
   <config:config-item config:name="HasSheetTabs" config:type="boolean">true</config:config-item>
   <config:config-item config:name="ImagePreferredDPI" config:type="int">0</config:config-item>
   <config:config-item config:name="IsDocumentShared" config:type="boolean">false</config:config-item>
   <config:config-item config:name="IsKernAsianPunctuation" config:type="boolean">false</config:config-item>
   <config:config-item config:name="IsOutlineSymbolsSet" config:type="boolean">true</config:config-item>
   <config:config-item config:name="IsRasterAxisSynchronized" config:type="boolean">true</config:config-item>
   <config:config-item config:name="IsSnapToRaster" config:type="boolean">false</config:config-item>
   <config:config-item config:name="LinkUpdateMode" config:type="short">3</config:config-item>
   <config:config-item config:name="LoadReadonly" config:type="boolean">false</config:config-item>
   <config:config-item config:name="PrinterName" config:type="string"/>
   <config:config-item config:name="PrinterPaperFromSetup" config:type="boolean">false</config:config-item>
   <config:config-item config:name="PrinterSetup" config:type="base64Binary"/>
   <config:config-item config:name="RasterIsVisible" config:type="boolean">false</config:config-item>
   <config:config-item config:name="RasterResolutionX" config:type="int">1000</config:config-item>
   <config:config-item config:name="RasterResolutionY" config:type="int">1000</config:config-item>
   <config:config-item config:name="RasterSubdivisionX" config:type="int">1</config:config-item>
   <config:config-item config:name="RasterSubdivisionY" config:type="int">1</config:config-item>
   <config:config-item config:name="SaveThumbnail" config:type="boolean">true</config:config-item>
   <config:config-item config:name="SaveVersionOnClose" config:type="boolean">false</config:config-item>
   <config:config-item config:name="ShowFormulasMarks" config:type="boolean">false</config:config-item>
   <config:config-item config:name="ShowGrid" config:type="boolean">true</config:config-item>
   <config:config-item config:name="ShowNoteAuthor" config:type="boolean">true</config:config-item>
   <config:config-item config:name="ShowNotes" config:type="boolean">true</config:config-item>
   <config:config-item config:name="ShowPageBreaks" config:type="boolean">true</config:config-item>
   <config:config-item config:name="ShowZeroValues" config:type="boolean">true</config:config-item>
   <config:config-item config:name="UpdateFromTemplate" config:type="boolean">true</config:config-item>
   <config:config-item-map-named config:name="ScriptConfiguration">
    <config:config-item-map-entry config:name="Feuille1">
     <config:config-item config:name="CodeName" config:type="string">Feuille1</config:config-item>
    </config:config-item-map-entry>
    <config:config-item-map-entry config:name="Test &lt;&gt; weird sheet name">
     <config:config-item config:name="CodeName" config:type="string">Feuille2</config:config-item>
    </config:config-item-map-entry>
   </config:config-item-map-named>
  </config:config-item-set>
 </office:settings>
 <office:scripts>
  <office:script script:language="ooo:Basic">
   <ooo:libraries xmlns:ooo="http://openoffice.org/2004/office" xmlns:xlink="http://www.w3.org/1999/xlink">
    <ooo:library-embedded ooo:name="Standard"/>
   </ooo:libraries>
  </office:script>
 </office:scripts>
 <office:font-face-decls>
  <style:font-face style:name="FreeSans" svg:font-family="FreeSans" style:font-family-generic="system" style:font-pitch="variable"/>
  <style:font-face style:name="Liberation Sans" svg:font-family="&apos;Liberation Sans&apos;" style:font-family-generic="swiss" style:font-pitch="variable"/>
  <style:font-face style:name="Tahoma" svg:font-family="Tahoma" style:font-family-generic="system" style:font-pitch="variable"/>
 </office:font-face-decls>
 <office:styles>
  <style:default-style style:family="table-cell">
   <style:paragraph-properties style:tab-stop-distance="1.25cm"/>
   <style:text-properties style:font-name="Liberation Sans" fo:font-size="10pt" fo:language="fr" fo:country="FR" style:font-name-asian="Tahoma" style:font-size-asian="10pt" style:language-asian="zh" style:country-asian="CN" style:font-name-complex="FreeSans" style:font-size-complex="10pt" style:language-complex="hi" style:country-complex="IN"/>
  </style:default-style>
  <style:default-style style:family="graphic">
   <style:graphic-properties svg:stroke-color="#3465a4" draw:fill-color="#729fcf" fo:wrap-option="no-wrap" draw:shadow-offset-x="0.3cm" draw:shadow-offset-y="0.3cm" style:writing-mode="page"/>
   <style:paragraph-properties style:text-autospace="ideograph-alpha" style:punctuation-wrap="simple" style:line-break="strict" style:writing-mode="page" style:font-independent-line-spacing="false">
    <style:tab-stops/>
   </style:paragraph-properties>
   <style:text-properties style:use-window-font-color="true" loext:opacity="0%" fo:font-family="&apos;Liberation Serif&apos;" style:font-family-generic="roman" style:font-pitch="variable" fo:font-size="12pt" fo:language="fr" fo:country="FR" style:letter-kerning="true" style:font-name-asian="Tahoma" style:font-size-asian="12pt" style:language-asian="zh" style:country-asian="CN" style:font-name-complex="Tahoma" style:font-size-complex="12pt" style:language-complex="hi" style:country-complex="IN"/>
  </style:default-style>
  <style:style style:name="Default" style:family="graphic"/>
  <style:style style:name="Note" style:family="graphic" style:parent-style-name="Default">
   <style:graphic-properties draw:stroke="solid" draw:marker-start="Extrémités_20_de_20_flèche_20_1" draw:marker-start-width="0.2cm" draw:marker-start-center="false" draw:fill="solid" draw:fill-color="#ffffc0" draw:auto-grow-height="true" draw:auto-grow-width="false" fo:padding-top="0.1cm" fo:padding-bottom="0.1cm" fo:padding-left="0.1cm" fo:padding-right="0.1cm" draw:shadow="visible" draw:shadow-offset-x="0.1cm" draw:shadow-offset-y="0.1cm"/>
   <style:text-properties style:font-name="Liberation Sans" fo:font-family="&apos;Liberation Sans&apos;" style:font-family-generic="swiss" style:font-pitch="variable" fo:font-size="10pt" style:font-name-asian="Tahoma" style:font-family-asian="Tahoma" style:font-family-generic-asian="system" style:font-pitch-asian="variable" style:font-size-asian="10pt" style:font-name-complex="FreeSans" style:font-family-complex="FreeSans" style:font-family-generic-complex="system" style:font-pitch-complex="variable" style:font-size-complex="10pt"/>
  </style:style>
  <number:number-style style:name="N0">
   <number:number number:min-integer-digits="1"/>
  </number:number-style>
  <number:currency-style style:name="N114P0" style:volatile="true">
   <number:number number:decimal-places="2" number:min-decimal-places="2" number:min-integer-digits="1" number:grouping="true"/>
   <number:text> </number:text>
   <number:currency-symbol number:language="fr" number:country="FR">€</number:currency-symbol>
  </number:currency-style>
  <number:currency-style style:name="N114">
   <style:text-properties fo:color="#ff0000"/>
   <number:text>-</number:text>
   <number:number number:decimal-places="2" number:min-decimal-places="2" number:min-integer-digits="1" number:grouping="true"/>
   <number:text> </number:text>
   <number:currency-symbol number:language="fr" number:country="FR">€</number:currency-symbol>
   <style:map style:condition="value()&gt;=0" style:apply-style-name="N114P0"/>
  </number:currency-style>
  <style:style style:name="Default" style:family="table-cell"/>
  <style:style style:name="Heading" style:family="table-cell" style:parent-style-name="Default">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:font-size="24pt" fo:font-style="normal" fo:font-weight="bold" style:font-size-asian="24pt" style:font-style-asian="normal" style:font-weight-asian="bold" style:font-size-complex="24pt" style:font-style-complex="normal" style:font-weight-complex="bold"/>
  </style:style>
  <style:style style:name="Heading_20_1" style:display-name="Heading 1" style:family="table-cell" style:parent-style-name="Heading">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:font-size="18pt" style:font-size-asian="18pt" style:font-size-complex="18pt"/>
  </style:style>
  <style:style style:name="Heading_20_2" style:display-name="Heading 2" style:family="table-cell" style:parent-style-name="Heading">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:font-size="12pt" style:font-size-asian="12pt" style:font-size-complex="12pt"/>
  </style:style>
  <style:style style:name="Text" style:family="table-cell" style:parent-style-name="Default">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
  </style:style>
  <style:style style:name="Note" style:family="table-cell" style:parent-style-name="Text">
   <style:table-cell-properties fo:background-color="#ffffcc" style:diagonal-bl-tr="none" style:diagonal-tl-br="none" fo:wrap-option="no-wrap" fo:border="0.74pt solid #808080" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#333333"/>
  </style:style>
  <style:style style:name="Footnote" style:family="table-cell" style:parent-style-name="Text">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#808080" fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"/>
  </style:style>
  <style:style style:name="Hyperlink" style:family="table-cell" style:parent-style-name="Text">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#0000ee" style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="#0000ee"/>
  </style:style>
  <style:style style:name="Status" style:family="table-cell" style:parent-style-name="Default">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
  </style:style>
  <style:style style:name="Good" style:family="table-cell" style:parent-style-name="Status">
   <style:table-cell-properties fo:background-color="#ccffcc" fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#006600"/>
  </style:style>
  <style:style style:name="Neutral" style:family="table-cell" style:parent-style-name="Status">
   <style:table-cell-properties fo:background-color="#ffffcc" fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#996600"/>
  </style:style>
  <style:style style:name="Bad" style:family="table-cell" style:parent-style-name="Status">
   <style:table-cell-properties fo:background-color="#ffcccc" fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#cc0000"/>
  </style:style>
  <style:style style:name="Warning" style:family="table-cell" style:parent-style-name="Status">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#cc0000"/>
  </style:style>
  <style:style style:name="Error" style:family="table-cell" style:parent-style-name="Status">
   <style:table-cell-properties fo:background-color="#cc0000" fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#ffffff" fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/>
  </style:style>
  <style:style style:name="Accent" style:family="table-cell" style:parent-style-name="Default">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"/>
  </style:style>
  <style:style style:name="Accent_20_1" style:display-name="Accent 1" style:family="table-cell" style:parent-style-name="Accent">
   <style:table-cell-properties fo:background-color="#000000" fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#ffffff"/>
  </style:style>
  <style:style style:name="Accent_20_2" style:display-name="Accent 2" style:family="table-cell" style:parent-style-name="Accent">
   <style:table-cell-properties fo:background-color="#808080" fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:color="#ffffff"/>
  </style:style>
  <style:style style:name="Accent_20_3" style:display-name="Accent 3" style:family="table-cell" style:parent-style-name="Accent">
   <style:table-cell-properties fo:background-color="#dddddd" fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
  </style:style>
  <style:style style:name="Result" style:family="table-cell" style:parent-style-name="Default">
   <style:table-cell-properties fo:wrap-option="no-wrap" style:shrink-to-fit="false"/>
   <style:text-properties fo:font-style="italic" style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color" fo:font-weight="bold" style:font-style-asian="italic" style:font-weight-asian="bold" style:font-style-complex="italic" style:font-weight-complex="bold"/>
  </style:style>
  <draw:marker draw:name="Extrémités_20_de_20_flèche_20_1" draw:display-name="Extrémités de flèche 1" svg:viewBox="0 0 20 30" svg:d="M10 0l-10 30h20z"/>
  <loext:theme loext:name="Office">
   <loext:theme-colors loext:name="LibreOffice">
    <loext:color loext:name="dark1" loext:color="#000000"/>
    <loext:color loext:name="light1" loext:color="#ffffff"/>
    <loext:color loext:name="dark2" loext:color="#000000"/>
    <loext:color loext:name="light2" loext:color="#ffffff"/>
    <loext:color loext:name="accent1" loext:color="#18a303"/>
    <loext:color loext:name="accent2" loext:color="#0369a3"/>
    <loext:color loext:name="accent3" loext:color="#a33e03"/>
    <loext:color loext:name="accent4" loext:color="#8e03a3"/>
    <loext:color loext:name="accent5" loext:color="#c99c00"/>
    <loext:color loext:name="accent6" loext:color="#c9211e"/>
    <loext:color loext:name="hyperlink" loext:color="#0000ee"/>
    <loext:color loext:name="followed-hyperlink" loext:color="#551a8b"/>
   </loext:theme-colors>
  </loext:theme>
 </office:styles>
 <office:automatic-styles>
  <style:style style:name="co1" style:family="table-column">
   <style:table-column-properties fo:break-before="auto" style:column-width="2.649cm"/>
  </style:style>
  <style:style style:name="co2" style:family="table-column">
   <style:table-column-properties fo:break-before="auto" style:column-width="2.258cm"/>
  </style:style>
  <style:style style:name="ro1" style:family="table-row">
   <style:table-row-properties style:row-height="0.452cm" fo:break-before="auto" style:use-optimal-row-height="true"/>
  </style:style>
  <style:style style:name="ro2" style:family="table-row">
   <style:table-row-properties style:row-height="0.841cm" fo:break-before="auto" style:use-optimal-row-height="true"/>
  </style:style>
  <style:style style:name="ta1" style:family="table" style:master-page-name="Default">
   <style:table-properties table:display="true" style:writing-mode="lr-tb"/>
  </style:style>
  <number:number-style style:name="N2">
   <number:number number:decimal-places="2" number:min-decimal-places="2" number:min-integer-digits="1"/>
  </number:number-style>
  <number:percentage-style style:name="N11">
   <number:number number:decimal-places="2" number:min-decimal-places="2" number:min-integer-digits="1"/>
   <number:text> %</number:text>
  </number:percentage-style>
  <number:date-style style:name="N37" number:automatic-order="true">
   <number:day number:style="long"/>
   <number:text>/</number:text>
   <number:month number:style="long"/>
   <number:text>/</number:text>
   <number:year/>
  </number:date-style>
  <number:date-style style:name="N70" number:automatic-order="true" number:format-source="language">
   <number:day/>
   <number:text>/</number:text>
   <number:month/>
   <number:text>/</number:text>
   <number:year/>
   <number:text> </number:text>
   <number:hours number:style="long"/>
   <number:text>:</number:text>
   <number:minutes number:style="long"/>
  </number:date-style>
  <style:style style:name="ce1" style:family="table-cell" style:parent-style-name="Default" style:data-style-name="N37"/>
  <style:style style:name="ce2" style:family="table-cell" style:parent-style-name="Default" style:data-style-name="N114"/>
  <style:style style:name="ce3" style:family="table-cell" style:parent-style-name="Default" style:data-style-name="N11"/>
  <style:style style:name="ce4" style:family="table-cell" style:parent-style-name="Default" style:data-style-name="N70"/>
  <style:page-layout style:name="pm1">
   <style:page-layout-properties style:writing-mode="lr-tb"/>
   <style:header-style>
    <style:header-footer-properties fo:min-height="0.75cm" fo:margin-left="0cm" fo:margin-right="0cm" fo:margin-bottom="0.25cm"/>
   </style:header-style>
   <style:footer-style>
    <style:header-footer-properties fo:min-height="0.75cm" fo:margin-left="0cm" fo:margin-right="0cm" fo:margin-top="0.25cm"/>
   </style:footer-style>
  </style:page-layout>
  <style:page-layout style:name="pm2">
   <style:page-layout-properties style:writing-mode="lr-tb"/>
   <style:header-style>
    <style:header-footer-properties fo:min-height="0.75cm" fo:margin-left="0cm" fo:margin-right="0cm" fo:margin-bottom="0.25cm" fo:border="1.5pt solid #000000" fo:padding="0.018cm" fo:background-color="#c0c0c0">
     <style:background-image/>
    </style:header-footer-properties>
   </style:header-style>
   <style:footer-style>
    <style:header-footer-properties fo:min-height="0.75cm" fo:margin-left="0cm" fo:margin-right="0cm" fo:margin-top="0.25cm" fo:border="1.5pt solid #000000" fo:padding="0.018cm" fo:background-color="#c0c0c0">
     <style:background-image/>
    </style:header-footer-properties>
   </style:footer-style>
  </style:page-layout>
 </office:automatic-styles>
 <office:master-styles>
  <style:master-page style:name="Default" style:page-layout-name="pm1">
   <style:header>
    <text:p><text:sheet-name>???</text:sheet-name></text:p>
   </style:header>
   <style:header-left style:display="false"/>
   <style:header-first style:display="false"/>
   <style:footer>
    <text:p>Page <text:page-number>1</text:page-number></text:p>
   </style:footer>
   <style:footer-left style:display="false"/>
   <style:footer-first style:display="false"/>
  </style:master-page>
  <style:master-page style:name="Report" style:page-layout-name="pm2">
   <style:header>
    <style:region-left>
     <text:p><text:sheet-name>???</text:sheet-name><text:s/>(<text:title>???</text:title>)</text:p>
    </style:region-left>
    <style:region-right>
     <text:p><text:date style:data-style-name="N2" text:date-value="2026-01-18">00/00/0000</text:date>, <text:time>00:00:00</text:time></text:p>
    </style:region-right>
   </style:header>
   <style:header-left style:display="false"/>
   <style:header-first style:display="false"/>
   <style:footer>
    <text:p>Page <text:page-number>1</text:page-number><text:s/>/ <text:page-count>99</text:page-count></text:p>
   </style:footer>
   <style:footer-left style:display="false"/>
   <style:footer-first style:display="false"/>
  </style:master-page>
 </office:master-styles>
 <office:body>
  <office:spreadsheet>
   <table:calculation-settings table:automatic-find-labels="false" table:use-regular-expressions="false" table:use-wildcards="true"/>
   <table:table table:name="Feuille1" table:style-name="ta1">
    <table:table-column table:style-name="co1" table:default-cell-style-name="Default"/>
    <table:table-column table:style-name="co2" table:default-cell-style-name="Default"/>
    <table:table-row table:style-name="ro1">
     <table:table-cell table:style-name="ce1" office:value-type="date" office:date-value="2026-02-01" calcext:value-type="date">
      <text:p>01/02/26</text:p>
     </table:table-cell>
     <table:table-cell office:value-type="float" office:value="3" calcext:value-type="float">
      <text:p>3</text:p>
     </table:table-cell>
    </table:table-row>
    <table:table-row table:style-name="ro1">
     <table:table-cell table:style-name="ce2" office:value-type="currency" office:currency="EUR" office:value="1.02" calcext:value-type="currency">
      <text:p>1,02 €</text:p>
     </table:table-cell>
     <table:table-cell/>
    </table:table-row>
    <table:table-row table:style-name="ro1">
     <table:table-cell table:style-name="ce3" office:value-type="percentage" office:value="0.1403" calcext:value-type="percentage">
      <text:p>14,03 %</text:p>
     </table:table-cell>
     <table:table-cell/>
    </table:table-row>
    <table:table-row table:style-name="ro1">
     <table:table-cell table:style-name="ce4" office:value-type="date" office:date-value="2026-01-01T02:02:01" calcext:value-type="date">
      <text:p>01/01/26 02:02</text:p>
     </table:table-cell>
     <table:table-cell/>
    </table:table-row>
    <table:table-row table:style-name="ro1">
     <table:table-cell table:formula="of:=SUM([.B1:.B1])" office:value-type="float" office:value="3" calcext:value-type="float">
      <text:p>3</text:p>
     </table:table-cell>
     <table:table-cell office:value-type="float" office:value="3.0004" calcext:value-type="float">
      <text:p>3,0004</text:p>
     </table:table-cell>
    </table:table-row>
    <table:table-row table:style-name="ro2">
     <table:table-cell office:value-type="string" calcext:value-type="string"><text:p>Line 1</text:p><text:p>Line 2</text:p>
     </table:table-cell>
     <table:table-cell/>
    </table:table-row>
   </table:table>
   <table:table table:name="Test &lt;&gt; weird sheet name" table:style-name="ta1">
    <table:table-column table:style-name="co2" table:number-columns-repeated="3" table:default-cell-style-name="Default"/>
    <table:table-row table:style-name="ro1">
     <table:table-cell office:value-type="string" calcext:value-type="string">
      <text:p>A</text:p>
     </table:table-cell>
     <table:table-cell office:value-type="string" calcext:value-type="string">
      <text:p>B</text:p>
     </table:table-cell>
     <table:table-cell/>
    </table:table-row>
    <table:table-row table:style-name="ro1">
     <table:table-cell office:value-type="float" office:value="1" calcext:value-type="float">
      <text:p>1</text:p>
     </table:table-cell>
     <table:table-cell office:value-type="float" office:value="2" calcext:value-type="float">
      <text:p>2</text:p>
     </table:table-cell>
     <table:table-cell office:value-type="float" office:value="3" calcext:value-type="float">
      <text:p>3</text:p>
     </table:table-cell>
    </table:table-row>
   </table:table>
   <table:named-expressions/>
  </office:spreadsheet>
 </office:body>
</office:document>
EOF;

$ods = <<<EOF
UEsDBBQAAAgAAGt7MlyFbDmKLgAAAC4AAAAIAAAAbWltZXR5cGVhcHBsaWNhdGlvbi92bmQub2Fz
aXMub3BlbmRvY3VtZW50LnNwcmVhZHNoZWV0UEsDBBQAAAgAAGt7MlwAAAAAAAAAAAAAAAAfAAAA
Q29uZmlndXJhdGlvbnMyL2ltYWdlcy9CaXRtYXBzL1BLAwQUAAAIAABrezJcAAAAAAAAAAAAAAAA
GgAAAENvbmZpZ3VyYXRpb25zMi90b29scGFuZWwvUEsDBBQAAAgAAGt7MlwAAAAAAAAAAAAAAAAY
AAAAQ29uZmlndXJhdGlvbnMyL3Rvb2xiYXIvUEsDBBQAAAgAAGt7MlwAAAAAAAAAAAAAAAAaAAAA
Q29uZmlndXJhdGlvbnMyL3N0YXR1c2Jhci9QSwMEFAAACAAAa3syXAAAAAAAAAAAAAAAABgAAABD
b25maWd1cmF0aW9uczIvZmxvYXRlci9QSwMEFAAACAAAa3syXAAAAAAAAAAAAAAAABwAAABDb25m
aWd1cmF0aW9uczIvYWNjZWxlcmF0b3IvUEsDBBQAAAgAAGt7MlwAAAAAAAAAAAAAAAAaAAAAQ29u
ZmlndXJhdGlvbnMyL3BvcHVwbWVudS9QSwMEFAAACAAAa3syXAAAAAAAAAAAAAAAABwAAABDb25m
aWd1cmF0aW9uczIvcHJvZ3Jlc3NiYXIvUEsDBBQAAAgAAGt7MlwAAAAAAAAAAAAAAAAYAAAAQ29u
ZmlndXJhdGlvbnMyL21lbnViYXIvUEsDBBQACAgIAGt7MlwAAAAAAAAAAAAAAAAKAAAAc3R5bGVz
LnhtbOUb227byPW9XyEo6GIXKE1Ssh1LayvodjdtgSRdZFP0cTEmh9QgQw4xHFl2Hvs1XaB/kT/p
l/TMlXeKVmLHaZXAieZc51znkOPLF7cZnd1gXhKWX83Dk2A+w3nEYpKnV/O/v3vpXcxfbH53yZKE
RHgds2iX4Vx4pbijuJwBcV6uC45LWERC8djxfM1QScp1jjJcrkW0ZgXOLem6S7NWYvV6VJZLcTXf
ClGsfX+/35/slyeMp/67t76EeQLfCt9ipzyOaR/2IgiWfurHSCDvhuD9M0txuxVZL0W4Wq18BbWo
McsGWIc+YHj4BjZQWuwy4qQQU7evsesbTxjPplJL3DpthsR2YE8X/msAqh+vX1l87c2p0ozva/IY
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
EOF;

$ods_span_merged = <<<EOF
UEsDBBQAAAgAAG23PlyFbDmKLgAAAC4AAAAIAAAAbWltZXR5cGVhcHBsaWNhdGlvbi92bmQub2Fz
aXMub3BlbmRvY3VtZW50LnNwcmVhZHNoZWV0UEsDBBQAAAgAAG23PlwAAAAAAAAAAAAAAAAfAAAA
Q29uZmlndXJhdGlvbnMyL2ltYWdlcy9CaXRtYXBzL1BLAwQUAAAIAABttz5cAAAAAAAAAAAAAAAA
GgAAAENvbmZpZ3VyYXRpb25zMi90b29scGFuZWwvUEsDBBQAAAgAAG23PlwAAAAAAAAAAAAAAAAY
AAAAQ29uZmlndXJhdGlvbnMyL3Rvb2xiYXIvUEsDBBQAAAgAAG23PlwAAAAAAAAAAAAAAAAaAAAA
Q29uZmlndXJhdGlvbnMyL3N0YXR1c2Jhci9QSwMEFAAACAAAbbc+XAAAAAAAAAAAAAAAABgAAABD
b25maWd1cmF0aW9uczIvZmxvYXRlci9QSwMEFAAACAAAbbc+XAAAAAAAAAAAAAAAABwAAABDb25m
aWd1cmF0aW9uczIvYWNjZWxlcmF0b3IvUEsDBBQAAAgAAG23PlwAAAAAAAAAAAAAAAAaAAAAQ29u
ZmlndXJhdGlvbnMyL3BvcHVwbWVudS9QSwMEFAAACAAAbbc+XAAAAAAAAAAAAAAAABwAAABDb25m
aWd1cmF0aW9uczIvcHJvZ3Jlc3NiYXIvUEsDBBQAAAgAAG23PlwAAAAAAAAAAAAAAAAYAAAAQ29u
ZmlndXJhdGlvbnMyL21lbnViYXIvUEsDBBQACAgIAG23PlwAAAAAAAAAAAAAAAAKAAAAc3R5bGVz
LnhtbOUb227byPW9XyEo6GIXKE1Ssh1LayvodjdtgSRdZFP0cTEmh9QgQw4xHFl2Hvs1XaB/kT/p
l/TMlXeKVmLHaZXAieZc51znkOPLF7cZnd1gXhKWX83Dk2A+w3nEYpKnV/O/v3vpXcxfbH53yZKE
RHgds2iX4Vx4pbijuJwBcV6uC45LWERC8djxfM1QScp1jjJcrkW0ZgXOLem6S7NWYvV6VJZLcTXf
ClGsfX+/35/slyeMp/67t76EeQLfCt9ipzyOaR/2IgiWfurHSCDvhuD9M0txuxVZL0W4Wq18BbWo
McsGWIc+YHj4BjZQWuwy4qQQU7evsesbTxjPplJL3DpthsR2YE8X/msAqh+vX1l87c2p0ozva/IY
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
QhUEUIUqVFFQhSpUUVCFKlRRUIUqVFFQhSpUUVCFKlRRUIUqVFFQhSpUUVCFKlRRUIUqVFFQhSpU
UVCFKlRRUIUqVFFQhSpUoQoCqEIVqiioQhWqKKhCFaooqEIVqiioQhWqKKhCFaooqEIVqiioQhWq
KKhCFaooqEIVqiioQhWqKKhCFaooqEIVqiioQhWqUAUBVKEKVRRUoQpVFFShClUUVKEKVRRUoQpV
FFShClUUVKEKVRRUoQpVFFShClUUVKEKVRRUoQpVFFShClUUVKEKVRRUoQpVqIIAqlCFKgqqUIUq
CqpQhSoKqlCFKgqqUIUqCqpQhSoKqlCFKgqqUIUqCqpQhSoKqlCFKgqqUIUqCqpQhSoKqlCFKgqq
UIUqVEEAVahCFQVVqEIVBVWoQhUFVahCFQVVqEIVBVWoQhUFVahCFQVVqEIVBVWoQhUFVahCFQVV
qEIVBVWoQhUFVahCFQVVqEIVqiCAKlShioIqVKGKgipUoYqCKlShioIqVKGKgipUoYqCKlShioIq
VKGKgipUoYqCKlShioIqVKGKgipUoYqCKlShioIqVKEKVRBAFapQRUEVqlBFQRWqUEVBFapQRUEV
qlBFQRWqUEVBFapQRUEVqlBFQRWqUEVBFapQRUEVqlBFQRWqUEVBFapQRUEVqlCFKgigClWooqAK
VaiioApVqKKgClWooqAKVaiioApVqKKgCtVPWv8A54g5qeixPSoAAAAASUVORK5CYIJQSwMEFAAI
CAgAbbc+XAAAAAAAAAAAAAAAABUAAABNRVRBLUlORi9tYW5pZmVzdC54bWytk0FqwzAQRfc5hdG2
WEpLF0XEyaLQE6QHUK2RI5BHQhqF5PaVTZy4lEAM2Wk0o/f/fNBmd+pddYSYrMeGvfI1qwBbry12
Dfvef9UfbLddbXqF1kAiOR2q8g7TtWxYjii9SjZJVD0kSa30AVD7NveAJP/Oy1HpWs0MvLML2nk4
TdzYyQlkfEatqExfhOAUINqhpZz0xtgW5IwwKm1X1W0FYx3UZTyebwZMdq4Oig4NE3d93UIAbVVN
5wANUyE4246GxBE1HzPg89V5ChGUTgcAYmKJlU+PxnY5jvT0Jh60kDLykgDPlrdzwjLxRGcHaQDd
kaWSrhjai7DTHY/aPLBPmXpZrFG2piH4p3sHUk+HJiAqv+35Se8Puf9BZV0SNB15wO6OiO1VB2Lo
F5WN+Pfjt79QSwcInx2KqjIBAAAsBAAAUEsBAhQAFAAACAAAbbc+XIVsOYouAAAALgAAAAgAAAAA
AAAAAAAAAAAAAAAAAG1pbWV0eXBlUEsBAhQAFAAACAAAbbc+XAAAAAAAAAAAAAAAAB8AAAAAAAAA
AAAAAAAAVAAAAENvbmZpZ3VyYXRpb25zMi9pbWFnZXMvQml0bWFwcy9QSwECFAAUAAAIAABttz5c
AAAAAAAAAAAAAAAAGgAAAAAAAAAAAAAAAACRAAAAQ29uZmlndXJhdGlvbnMyL3Rvb2xwYW5lbC9Q
SwECFAAUAAAIAABttz5cAAAAAAAAAAAAAAAAGAAAAAAAAAAAAAAAAADJAAAAQ29uZmlndXJhdGlv
bnMyL3Rvb2xiYXIvUEsBAhQAFAAACAAAbbc+XAAAAAAAAAAAAAAAABoAAAAAAAAAAAAAAAAA/wAA
AENvbmZpZ3VyYXRpb25zMi9zdGF0dXNiYXIvUEsBAhQAFAAACAAAbbc+XAAAAAAAAAAAAAAAABgA
AAAAAAAAAAAAAAAANwEAAENvbmZpZ3VyYXRpb25zMi9mbG9hdGVyL1BLAQIUABQAAAgAAG23PlwA
AAAAAAAAAAAAAAAcAAAAAAAAAAAAAAAAAG0BAABDb25maWd1cmF0aW9uczIvYWNjZWxlcmF0b3Iv
UEsBAhQAFAAACAAAbbc+XAAAAAAAAAAAAAAAABoAAAAAAAAAAAAAAAAApwEAAENvbmZpZ3VyYXRp
b25zMi9wb3B1cG1lbnUvUEsBAhQAFAAACAAAbbc+XAAAAAAAAAAAAAAAABwAAAAAAAAAAAAAAAAA
3wEAAENvbmZpZ3VyYXRpb25zMi9wcm9ncmVzc2Jhci9QSwECFAAUAAAIAABttz5cAAAAAAAAAAAA
AAAAGAAAAAAAAAAAAAAAAAAZAgAAQ29uZmlndXJhdGlvbnMyL21lbnViYXIvUEsBAhQAFAAICAgA
bbc+XF8SrB8ECgAAzzoAAAoAAAAAAAAAAAAAAAAATwIAAHN0eWxlcy54bWxQSwECFAAUAAgICABt
tz5ctPdo0gUBAACDAwAADAAAAAAAAAAAAAAAAACLDAAAbWFuaWZlc3QucmRmUEsBAhQAFAAICAgA
bbc+XAWCmF0bBQAA8hgAAAsAAAAAAAAAAAAAAAAAyg0AAGNvbnRlbnQueG1sUEsBAhQAFAAICAgA
bbc+XIOjS5KZAQAASwMAAAgAAAAAAAAAAAAAAAAAHhMAAG1ldGEueG1sUEsBAhQAFAAICAgAbbc+
XKN4uwuIBAAAnSQAAAwAAAAAAAAAAAAAAAAA7RQAAHNldHRpbmdzLnhtbFBLAQIUABQAAAgAAG23
Plwcpv9Cfg8AAH4PAAAYAAAAAAAAAAAAAAAAAK8ZAABUaHVtYm5haWxzL3RodW1ibmFpbC5wbmdQ
SwECFAAUAAgICABttz5cnx2KqjIBAAAsBAAAFQAAAAAAAAAAAAAAAABjKQAATUVUQS1JTkYvbWFu
aWZlc3QueG1sUEsFBgAAAAARABEAZQQAANgqAAAAAA==
EOF;

test_reader($fods);
test_reader(base64_decode($ods));
test_reader_span(base64_decode($ods_span_merged));

