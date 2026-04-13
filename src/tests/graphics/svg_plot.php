<?php

use KD2\Test;
use KD2\Graphics\SVG\Plot;
use KD2\Graphics\SVG\Plot_Data;

require __DIR__ . '/../_assert.php';

test_graph();

function test_graph()
{
	$expected = <<<EOF
<?xml version="1.0" encoding="utf-8" standalone="no"?>
<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.0//EN" "http://www.w3.org/TR/SVG/DTD/svg10.dtd">
<svg width="600" height="400" viewBox="0 0 600 400" xmlns="http://www.w3.org/2000/svg" version="1.1">
<text x="300" y="28" font-size="20" fill="white" stroke="white" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" text-anchor="middle" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">coucou</text>
<text x="300" y="28" font-size="20" fill="black" text-anchor="middle" style="font-family: Verdana, Arial, sans-serif; font-weight: bold;">coucou</text>
<line x1="60.000000" y1="362.000000" x2="600.000000" y2="362.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="362.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">0</text></g>
<line x1="60.000000" y1="314.000000" x2="600.000000" y2="314.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="314.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">2</text></g>
<line x1="60.000000" y1="266.000000" x2="600.000000" y2="266.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="266.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">4</text></g>
<line x1="60.000000" y1="218.000000" x2="600.000000" y2="218.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="218.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">6</text></g>
<line x1="60.000000" y1="170.000000" x2="600.000000" y2="170.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="170.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">8</text></g>
<line x1="60.000000" y1="122.000000" x2="600.000000" y2="122.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="122.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">10</text></g>
<line x1="60.000000" y1="74.000000" x2="600.000000" y2="74.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="74.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">12</text></g>
<line x1="60.000000" y1="26.000000" x2="600.000000" y2="26.000000" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" />
<g><text x="48.000000" y="26.000000" font-size="16.000000" fill="gray" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">14</text></g>
<line x1="60" y1="372" x2="60" y2="2" stroke-width="1" stroke="rgba(127, 127, 127, 0.5)" /><polyline fill="none" stroke="blue" stroke-width="3" stroke-linecap="round" points="60.000000,242.000000 " />
<polyline fill="none" stroke="blue" stroke-width="3" stroke-linecap="round" points="60.000000,2.000000 " />
<rect x="564" y="36" width="24" height="16" fill="blue" stroke="black" stroke-width="1" rx="2" />
<text x="552" y="50" font-size="20" fill="white" stroke="white" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">aaa</text>
<text x="552" y="50" font-size="20" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">aaa</text>
<rect x="564" y="64" width="24" height="16" fill="blue" stroke="black" stroke-width="1" rx="2" />
<text x="552" y="78" font-size="20" fill="white" stroke="white" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">bbb</text>
<text x="552" y="78" font-size="20" fill="black" text-anchor="end" style="font-family: Verdana, Arial, sans-serif;">bbb</text>
</svg>
EOF;

	$graph = new Plot;
	$graph->setTitle('coucou');

	$graph->add(new Plot_Data(5, 'aaa'));
	$graph->add(new Plot_Data(15, 'bbb'));
	$out = $graph->output();

	Test::strictlyEquals(trim($expected), trim($out));
}
