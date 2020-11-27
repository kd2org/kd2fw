<?php

use KD2\Test;
use KD2\DB\DB;
use KD2\DB\SQLite3;

require __DIR__ . '/../_assert.php';

test_import();
test_authorizer();

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

function test_authorizer()
{
	if (!method_exists(\SQLite3::class, 'setAuthorizer')) {
		echo "Skip authorizer test: old PHP version" . PHP_EOL;
		return;
	}

	$db = new SQLite3('sqlite', ['file' => ':memory:']);
	$db->exec('CREATE TABLE test (a, b);');
	$db->exec('INSERT INTO test VALUES (1, 2);');

	$db->setAuthorizer(function (int $action, ... $args) {
		if ($action == \SQLite3::READ || $action == \SQLite3::SELECT) {
			return \SQLite3::OK;
		}

		return \SQLite3::DENY;
	});

	Test::strictlyEquals(1, $db->firstColumn('SELECT a FROM test;'));

	try {
		$db->exec('INSERT INTO test VALUES (1, 2);');
	}
	catch (\Exception $e) {
		Test::strictlyEquals('not authorized', $e->getMessage());
	}

	$db->setAuthorizer(null);

	Test::strictlyEquals(true, $db->exec('INSERT INTO test VALUES (1, 2);'));
}