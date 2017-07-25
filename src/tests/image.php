<?php

use KD2\Image;
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
		test_image('data/images/' . $name, $lib, $size[0], $size[1], $size[2]);
	}
}

function test_image($src, $lib, $w, $h, $o)
{
	$im = new Image($src, $lib);

	Test::equals($w, $im->width);
	Test::equals($h, $im->height);

	Test::equals($o, $im->getOrientation());

	Test::assert($im->resize(200, 200, true) instanceof Image);

	Test::equals(200, $im->width);
	Test::equals(200, $im->height);	

	if ($lib != 'epeg')
	{
		if ($o)
		{
			Test::assert($im->autoRotate() instanceof Image);
		}

		Test::assert($im->flip() instanceof Image);
		Test::assert($im->flip() instanceof Image);

		Test::assert($im->crop(100, 100) instanceof Image);
		Test::equals(100, $im->width);
		Test::equals(100, $im->height);	
	}

	$dest = sys_get_temp_dir() . DIRECTORY_SEPARATOR . sprintf('imtest_%s_%s.%s', md5($src), $lib, substr($src, -3));
	Test::equals(true, $im->save($dest));

	//unlink($dest);
}
