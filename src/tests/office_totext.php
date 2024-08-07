<?php

use KD2\Test;
use KD2\Office\ToText;
use KD2\Brindille_Exception;

require __DIR__ . '/_assert.php';

test_fodt();

function test_fodt()
{
	$test = <<<EOF
	<?xml version="1.0" encoding="UTF-8"?>

<office:document xmlns:officeooo="http://openoffice.org/2009/office" xmlns:css3t="http://www.w3.org/TR/css3-text/" xmlns:grddl="http://www.w3.org/2003/g/data-view#" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:formx="urn:openoffice:names:experimental:ooxml-odf-interop:xmlns:form:1.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:chart="urn:oasis:names:tc:opendocument:xmlns:chart:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:oooc="http://openoffice.org/2004/calc" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:ooow="http://openoffice.org/2004/writer" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:rpt="http://openoffice.org/2005/report" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:ooo="http://openoffice.org/2004/office" xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:dr3d="urn:oasis:names:tc:opendocument:xmlns:dr3d:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:tableooo="http://openoffice.org/2009/table" xmlns:drawooo="http://openoffice.org/2010/draw" xmlns:loext="urn:org:documentfoundation:names:experimental:office:xmlns:loext:1.0" xmlns:dom="http://www.w3.org/2001/xml-events" xmlns:field="urn:openoffice:names:experimental:ooo-ms-interop:xmlns:field:1.0" xmlns:math="http://www.w3.org/1998/Math/MathML" xmlns:form="urn:oasis:names:tc:opendocument:xmlns:form:1.0" xmlns:script="urn:oasis:names:tc:opendocument:xmlns:script:1.0" xmlns:xforms="http://www.w3.org/2002/xforms" office:version="1.3" office:mimetype="application/vnd.oasis.opendocument.text">

 <office:body>
  <office:text>
   <text:sequence-decls>
    <text:sequence-decl text:display-outline-level="0" text:name="Illustration"/>
    <text:sequence-decl text:display-outline-level="0" text:name="Table"/>
    <text:sequence-decl text:display-outline-level="0" text:name="Text"/>
    <text:sequence-decl text:display-outline-level="0" text:name="Drawing"/>
    <text:sequence-decl text:display-outline-level="0" text:name="Figure"/>
   </text:sequence-decls>
   <text:p text:style-name="Title">Titre principal</text:p>
   <text:h text:style-name="Heading_20_1" text:outline-level="1">Titre 1</text:h>
   <text:h text:style-name="Heading_20_2" text:outline-level="2">Titre 2</text:h>
   <text:h text:style-name="Heading_20_3" text:outline-level="3">Titre 3</text:h>
   <text:p text:style-name="Quotations">Quote</text:p>
   <text:p text:style-name="P1">Titre normal</text:p>
   <text:p text:style-name="P2">Paragraphe</text:p>
   <text:list xml:id="list459117337" text:style-name="L1">
    <text:list-item>
     <text:p text:style-name="P3">Liste à puce</text:p>
    </text:list-item>
    <text:list-item>
     <text:p text:style-name="P3">Numéro 2</text:p>
    </text:list-item>
   </text:list>
   <text:p text:style-name="P2">Paragraphe</text:p>
   <text:list xml:id="list721042863" text:style-name="L3">
    <text:list-item>
     <text:p text:style-name="P4">Liste numérotée</text:p>
    </text:list-item>
    <text:list-item>
     <text:p text:style-name="P4">Numéro 2</text:p>
    </text:list-item>
   </text:list>
  </office:text>
 </office:body>
</office:document>
EOF;

	$expected = <<<EOF
Titre principal

# Titre 1

## Titre 2

### Titre 3

Quote

Titre normal

Paragraphe

* Liste à puce

* Numéro 2

Paragraphe

* Liste numérotée

* Numéro 2
EOF;

	Test::equals($expected, ToText::fromString(ltrim($test)));
}
