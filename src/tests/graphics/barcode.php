<?php

use KD2\Test;
use KD2\Graphics\BarCode;

require __DIR__ . '/../_assert.php';

test_barcode();

function test_barcode()
{
	$barcode = new BarCode('0012345678905');

	$expected = <<<EOF
<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">
<svg viewBox="0 0 318 150" width="200px" version="1.1" xmlns="http://www.w3.org/2000/svg">
  <text x="3" y="135" font-family="monospace" font-size="24">0</text>
  <rect x="21" y="7" width="3" height="135" fill="black" stroke-width="0" />
  <rect x="27" y="7" width="3" height="135" fill="black" stroke-width="0" />
 <text x="36" y="135" font-family="monospace" font-size="24">0</text>
  <rect x="39" y="7" width="6" height="105" fill="black" stroke-width="0" />
  <rect x="48" y="7" width="3" height="105" fill="black" stroke-width="0" />
 <text x="57" y="135" font-family="monospace" font-size="24">1</text>
  <rect x="57" y="7" width="6" height="105" fill="black" stroke-width="0" />
  <rect x="69" y="7" width="3" height="105" fill="black" stroke-width="0" />
 <text x="78" y="135" font-family="monospace" font-size="24">2</text>
  <rect x="78" y="7" width="3" height="105" fill="black" stroke-width="0" />
  <rect x="87" y="7" width="6" height="105" fill="black" stroke-width="0" />
 <text x="99" y="135" font-family="monospace" font-size="24">3</text>
  <rect x="96" y="7" width="12" height="105" fill="black" stroke-width="0" />
  <rect x="111" y="7" width="3" height="105" fill="black" stroke-width="0" />
 <text x="120" y="135" font-family="monospace" font-size="24">4</text>
  <rect x="117" y="7" width="3" height="105" fill="black" stroke-width="0" />
  <rect x="129" y="7" width="6" height="105" fill="black" stroke-width="0" />
 <text x="141" y="135" font-family="monospace" font-size="24">5</text>
  <rect x="138" y="7" width="6" height="105" fill="black" stroke-width="0" />
  <rect x="153" y="7" width="3" height="105" fill="black" stroke-width="0" />
  <rect x="159" y="7" width="3" height="135" fill="black" stroke-width="0" />
  <rect x="165" y="7" width="3" height="135" fill="black" stroke-width="0" />
 <text x="177" y="135" font-family="monospace" font-size="24">6</text>
  <rect x="171" y="7" width="3" height="105" fill="black" stroke-width="0" />
  <rect x="177" y="7" width="3" height="105" fill="black" stroke-width="0" />
 <text x="198" y="135" font-family="monospace" font-size="24">7</text>
  <rect x="192" y="7" width="3" height="105" fill="black" stroke-width="0" />
  <rect x="204" y="7" width="3" height="105" fill="black" stroke-width="0" />
 <text x="219" y="135" font-family="monospace" font-size="24">8</text>
  <rect x="213" y="7" width="3" height="105" fill="black" stroke-width="0" />
  <rect x="222" y="7" width="3" height="105" fill="black" stroke-width="0" />
 <text x="240" y="135" font-family="monospace" font-size="24">9</text>
  <rect x="234" y="7" width="9" height="105" fill="black" stroke-width="0" />
  <rect x="246" y="7" width="3" height="105" fill="black" stroke-width="0" />
 <text x="261" y="135" font-family="monospace" font-size="24">0</text>
  <rect x="255" y="7" width="9" height="105" fill="black" stroke-width="0" />
  <rect x="270" y="7" width="3" height="105" fill="black" stroke-width="0" />
 <text x="282" y="135" font-family="monospace" font-size="24">5</text>
  <rect x="276" y="7" width="3" height="105" fill="black" stroke-width="0" />
  <rect x="285" y="7" width="9" height="105" fill="black" stroke-width="0" />
  <rect x="297" y="7" width="3" height="135" fill="black" stroke-width="0" />
  <rect x="303" y="7" width="3" height="135" fill="black" stroke-width="0" />
</svg>
EOF;

	Test::strictlyEquals(true, $barcode->verify());
	Test::strictlyEquals(trim($expected), trim($barcode->toSVG()));
}
