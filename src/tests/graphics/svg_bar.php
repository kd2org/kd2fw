<?php

use KD2\Test;
use KD2\Graphics\SVG\Bar;
use KD2\Graphics\SVG\Bar_Data_Set;

require __DIR__ . '/../_assert.php';

test_avatar();

function test_avatar()
{
	$expected = <<<EOF
<?xml version="1.0" encoding="utf-8" standalone="no"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.0//EN" "http://www.w3.org/TR/SVG/DTD/svg10.dtd">
<svg width="600" height="400" viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg" version="1.1">
<filter id="blur"><feGaussianBlur in="SourceGraphic" stdDeviation="2" /></filter>
<line x1="60.000000" y1="370.000000" x2="600.000000" y2="370.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="370.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">0</text></g>
<line x1="60.000000" y1="335.363636" x2="600.000000" y2="335.363636" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="335.363636" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">1</text></g>
<line x1="60.000000" y1="300.727273" x2="600.000000" y2="300.727273" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="300.727273" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">2</text></g>
<line x1="60.000000" y1="266.090909" x2="600.000000" y2="266.090909" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="266.090909" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">3</text></g>
<line x1="60.000000" y1="231.454545" x2="600.000000" y2="231.454545" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="231.454545" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">4</text></g>
<line x1="60.000000" y1="196.818182" x2="600.000000" y2="196.818182" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="196.818182" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">5</text></g>
<line x1="60.000000" y1="162.181818" x2="600.000000" y2="162.181818" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="162.181818" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">6</text></g>
<line x1="60.000000" y1="127.545455" x2="600.000000" y2="127.545455" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="127.545455" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">7</text></g>
<line x1="60.000000" y1="92.909091" x2="600.000000" y2="92.909091" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="92.909091" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">8</text></g>
<line x1="60.000000" y1="58.272727" x2="600.000000" y2="58.272727" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="58.272727" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">9</text></g>
<line x1="60.000000" y1="23.636364" x2="600.000000" y2="23.636364" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="23.636364" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">10</text></g>
<text x="324.500000" y="386.000000" font-size="12.000000" fill="#666" text-anchor="middle" style="font-family: Verdana, Arial, sans-serif;" >aaa</text>
<rect x="64" y="204" width="263" height="166" fill="blue" />
<rect x="329" y="38" width="263" height="332" fill="blue" />
<text x="588" y="28" font-size="20" fill="white" stroke="white" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">coucou</text>
<text x="588" y="28" font-size="20" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">coucou</text>
<rect x="564" y="36" width="24" height="16" fill="blue" stroke="black" stroke-width="1" rx="2" />
<text x="552.000000" y="50.000000" font-size="20.000000" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;" stroke-width="4.000000" stroke-linejoin="round" stroke-linecap="round" stroke="white" filter="url(#blur)">cinq</text>
<text x="552.000000" y="50.000000" font-size="20.000000" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;" >cinq</text>
<rect x="564" y="64" width="24" height="16" fill="blue" stroke="black" stroke-width="1" rx="2" />
<text x="552.000000" y="78.000000" font-size="20.000000" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;" stroke-width="4.000000" stroke-linejoin="round" stroke-linecap="round" stroke="white" filter="url(#blur)">dix</text>
<text x="552.000000" y="78.000000" font-size="20.000000" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;" >dix</text>
</svg>
EOF;

	$graph = new Bar;
	$graph->setTitle('coucou');

	$set = new Bar_Data_Set('aaa');
	$set->add(5, 'cinq');
	$set->add(10, 'dix');
	$graph->add($set);
	$out = $graph->output();

	Test::strictlyEquals(trim($expected), trim($out));
}
