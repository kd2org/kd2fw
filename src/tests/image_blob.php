<?php

use KD2\Image_Blob as IB;
use KD2\Test;

require __DIR__ . '/_assert.php';

$images = [
	'Portrait_5.jpg' => [600, 450, 5],
	'icon.png' => [300, 300, false],
	'black_bluff.jpg' => [1200, 900, 8],
	'onepoto.gif' => [600, 250, false],
];

foreach ($images as $name => $expected)
{
	test_image('data/images/' . $name, $expected[0], $expected[1], $expected[2]);
}

function test_image($src, $w, $h, $o)
{
	$header = IB::getFileHeader($src);

	Test::assert(strlen($header) > 1);

	$size = IB::getSize($header);

	Test::assert($size !== false);

	Test::equals($w, $size[0]);
	Test::equals($h, $size[1]);

	if ($o !== false)
	{
		Test::equals($o, IB::getOrientationJPEG(file_get_contents($src)));
	}
}
