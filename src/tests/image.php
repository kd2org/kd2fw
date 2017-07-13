<?php

use KD2\Image;
use KD2\Test;

require __DIR__ . '/_assert.php';

$images = [
	'pp.jpg' => [1920, 1440],
	'black_bluff.jpg' => [1200, 900],
	'onepoto.gif' => [600, 250],
	'invoice.pdf' => [595, 842],
];

foreach ($images as $name => $size)
{
	$format = substr($name, -3);

	if ($format == 'jpg')
	{
		$format = 'jpeg';
	}

	$libs = Image::getLibrariesForFormat($format);

	Test::assert(count($libs) > 0);

	foreach ($libs as $lib)
	{
		test_image('data/images/' . $name, $lib, $size[0], $size[1]);
	}
}

function test_image($src, $lib, $w, $h)
{
	$im = new Image($src, $lib);

	Test::equals($w, $im->width);
	Test::equals($h, $im->height);

	Test::assert($im->resize(200, 200, true) instanceof Image);

	Test::equals(200, $im->width);
	Test::equals(200, $im->height);	

	if ($lib != 'epeg')
	{
		Test::assert($im->crop(100, 100, true) instanceof Image);
		Test::equals(100, $im->width);
		Test::equals(100, $im->height);	
	}

	$dest = tempnam(sys_get_temp_dir(), 'imtest');
	Test::equals(true, $im->save($dest));

	unlink($dest);
}
