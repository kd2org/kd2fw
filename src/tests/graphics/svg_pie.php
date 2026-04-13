<?php

use KD2\Test;
use KD2\Graphics\SVG\Pie;
use KD2\Graphics\SVG\Pie_Data;

require __DIR__ . '/../_assert.php';

test_graph();

function test_graph()
{
	$expected = <<<EOF
<?xml version="1.0" encoding="utf-8" standalone="no"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.0//EN" "http://www.w3.org/TR/SVG/DTD/svg10.dtd">
<svg width="600" height="400" viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg" version="1.1">
<filter id="blur"><feGaussianBlur in="SourceGraphic" stdDeviation="2" /></filter>
<path d="M200,200 L396,200 A196,196 0 0,1 200,396 Z"
					fill="blue" stroke="white" stroke-width="1.96" stroke-linecap="round"
					stroke-linejoin="round" /><path d="M200,200 L200,396 A196,196 0 1,1 396,200 Z"
					fill="blue" stroke="white" stroke-width="1.96" stroke-linecap="round"
					stroke-linejoin="round" /><text x="588" y="28" font-size="20" fill="white" stroke="white" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">coucou</text>
<text x="588" y="28" font-size="20" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">coucou</text>
<rect x="558" y="34" width="24" height="20" fill="blue" stroke="black" stroke-width="1" rx="2" />
<text x="552.000000" y="50.000000" font-size="20.000000" fill="white" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;" stroke-width="4.000000" stroke-linejoin="round" stroke-linecap="round" stroke="rgba(255, 255, 255, 0.5)" filter="url(#blur)">aaa</text>
<text x="552.000000" y="50.000000" font-size="20.000000" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;" >aaa</text>
<rect x="558" y="66" width="24" height="20" fill="blue" stroke="black" stroke-width="1" rx="2" />
<text x="552.000000" y="82.000000" font-size="20.000000" fill="white" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;" stroke-width="4.000000" stroke-linejoin="round" stroke-linecap="round" stroke="rgba(255, 255, 255, 0.5)" filter="url(#blur)">bbb</text>
<text x="552.000000" y="82.000000" font-size="20.000000" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;" >bbb</text>
</svg>
EOF;

	$graph = new Pie;
	$graph->setTitle('coucou');

	$graph->add(new Pie_Data(5, 'aaa'));
	$graph->add(new Pie_Data(15, 'bbb'));
	$out = $graph->output();

	Test::strictlyEquals(trim($expected), trim($out));
}
