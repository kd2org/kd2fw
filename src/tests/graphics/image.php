<?php

use KD2\Graphics\Image;
use KD2\Test;

require __DIR__ . '/../_assert.php';

const ROOT = __DIR__ . '/../data/images/';

$images = [
	'animated.gif' => [64, 64, false],
	'icon.png' => [300, 300, false],
	'black_bluff.jpg' => [1200, 900, 8],
	'Portrait_5.jpg' => [600, 450, 5],
	'onepoto.gif' => [600, 250, false],
	'transparent.gif' => [42, 44, false],
	'transparent.png' => [64, 57, false],
	//'test.svg' => [750, 489, false],
	//'invoice.pdf' => [595, 842, false],
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
		@mkdir(ROOT . 'result/' . $lib);
		test_resize(ROOT . $name, $lib, $size[0], $size[1]);
		test_thumb(ROOT . $name, $lib);
		test_rotate(ROOT . $name, $lib, $size[2]);
		test_crop(ROOT . $name, $lib, $size[1], $size[2]);
		test_crop_blob(ROOT . $name, $lib, $size[1], $size[2]);
		test_crop_pointer(ROOT . $name, $lib, $size[1], $size[2]);
	}
}

function test_rotate($src, $lib, $o) {
	$im = new Image($src, $lib);

	Test::equals($o, $im->getOrientation());

	if ($o)
	{
		Test::assert($im->autoRotate() instanceof Image);
	}
	else {
		Test::assert($im->rotate(90) instanceof Image);
		Test::assert($im->flip() instanceof Image);
	}

	$dest = sprintf(ROOT . 'result/%s/rotate_%s', $lib, basename($src));
	Test::equals(true, $im->save($dest));
}

function test_resize($src, $lib, $w, $h)
{
	$im = new Image($src, $lib);

	Test::equals($w, $im->width, $src);
	Test::equals($h, $im->height, $src);

	Test::assert($im->resize(32, 32, true) instanceof Image);

	Test::equals(32, $im->width);
	Test::equals(32, $im->height);

	$dest = sprintf(ROOT . 'result/%s/resize_%s', $lib, basename($src));
	Test::equals(true, $im->save($dest));

	//unlink($dest);

	unset($im);
}

function test_thumb($src, $lib)
{
	$im = new Image($src, $lib);

	Test::assert($im->resize(32) instanceof Image);

	Test::assert($im->width <= 32);
	Test::assert($im->height <= 32);

	$dest = sprintf(ROOT . 'result/%s/thumb_%s', $lib, basename($src));
	Test::equals(true, $im->save($dest));

	unset($im);
}

function test_crop($src, $lib)
{
	$im = new Image($src, $lib);
	Test::assert($im->crop(32, 32) instanceof Image);
	Test::equals(32, $im->width);
	Test::equals(32, $im->height);

	$dest = sprintf(ROOT . 'result/%s/crop_%s', $lib, basename($src));
	Test::equals(true, $im->save($dest));
}

function test_crop_blob($src, $lib)
{
	$im = Image::createFromBlob(file_get_contents($src), $lib);
	Test::assert($im->crop(32, 32) instanceof Image);
	Test::equals(32, $im->width);
	Test::equals(32, $im->height);

	$dest = sprintf(ROOT . 'result/%s/crop_%s', $lib, basename($src));
	Test::equals(true, $im->save($dest));
}

function test_crop_pointer($src, $lib)
{
	$im = Image::createFromPointer(fopen($src, 'rb'), $lib);
	Test::assert($im->crop(32, 32) instanceof Image);
	Test::equals(32, $im->width);
	Test::equals(32, $im->height);

	$dest = sprintf(ROOT . 'result/%s/crop_%s', $lib, basename($src));
	Test::equals(true, $im->save($dest));
}
