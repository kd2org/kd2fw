<?php

use KD2\Test;
use KD2\ZipReader;

require __DIR__ . '/_assert.php';

test_zip_simple();

test_zip_bomb();
test_zip_bomb2();

function test_zip_simple()
{
	$zip = new ZipReader('data/zip/test.zip');
	Test::equals(20, $zip->uncompressedSize());
	Test::equals('test', trim($zip->fetch('a/test.txt')));

	$files = [];
	foreach ($zip->iterate() as $path => $file) {
		$files[$path] = $file->getSize();
	}

	Test::equals('{"a\/test.txt":5,"b\/test.txt":5,"c\/test.txt":5,"d\/test.txt":5}', json_encode($files));

	unset($zip);
}

function test_zip_bomb()
{
	$zip = new ZipReader('data/zip/zipbomb_1G.zip');
	Test::equals(1048576000, $zip->uncompressedSize());
	$zip->setMaxUncompressedSize(1024*1024*500);
	Test::exception('OutOfBoundsException', function() use ($zip) {
		$zip->securityCheck();
	});
	unset($zip);
}

function test_zip_bomb2()
{
	$zip = new ZipReader('data/zip/zbsm.zip');
	Test::exception('OutOfBoundsException', function() use ($zip) {
		$zip->iterate();
	});
	unset($zip);
}
