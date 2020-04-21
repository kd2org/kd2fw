<?php

use KD2\Test;
use KD2\DB\DB;
use KD2\DB\SQLite3;

require __DIR__ . '/../_assert.php';

test_import();

function test_import()
{
	$db = new SQLite3('sqlite', ['file' => ':memory:']);

	Test::strictlyEquals(true, $db->import(__DIR__ . '/data/test1.sql'));

	Test::exception(\Exception::class, function () use ($db) {
		$db->import(__DIR__ . '/data/test2_fail.sql');
	});

	Test::exception(\Exception::class, function () use ($db) {
		$db->import(__DIR__ . '/data/test3_fail_sub.sql');
	});

}
