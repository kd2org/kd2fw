<?php

use KD2\Graphics\Image;
use KD2\Test;

require __DIR__ . '/_assert.php';

$images = [
	'icon.png' => [300, 300, false],
	'black_bluff.jpg' => [1200, 900, 8],
	'Portrait_5.jpg' => [600, 450, 5],
	'onepoto.gif' => [600, 250, false],
	'invoice.pdf' => [595, 842, false],
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
		test_resize('data/images/' . $name, $lib, $size[0], $size[1]);
		test_rotate('data/images/' . $name, $lib, $size[2]);
		test_crop('data/images/' . $name, $lib, $size[1], $size[2]);
	}
}

function test_rotate($src, $lib, $o) {
	$im = new Image($src, $lib);

	Test::equals($o, $im->getOrientation());

	if ($lib != 'epeg')
	{
		if ($o)
		{
			Test::assert($im->autoRotate() instanceof Image);
		}

		Test::assert($im->flip() instanceof Image);
	}
}

function test_resize($src, $lib, $w, $h)
{
	$im = new Image($src, $lib);

	Test::equals($w, $im->width);
	Test::equals($h, $im->height);

	Test::assert($im->resize(200, 200, true) instanceof Image);

	Test::equals(200, $im->width);
	Test::equals(200, $im->height);

	$dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf('imtest_%s_%s.%s', md5($src), $lib, substr($src, -3));
	Test::equals(true, $im->save($dest));

	//unlink($dest);

	unset($im);
}

function test_crop($src, $lib)
{
	if ($lib == 'epeg')
	{
		return;
	}

	$im = new Image($src, $lib);
	Test::assert($im->crop(100, 100) instanceof Image);
	Test::equals(100, $im->width);
	Test::equals(100, $im->height);

}