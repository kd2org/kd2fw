<?php

use KD2\Test;
use KD2\DB\DB;
use KD2\DB\SQLite3;

require __DIR__ . '/_assert.php';

$db = new DB('sqlite', ['file' => ':memory:']);

Test::assert($db instanceof DB);

test_methods($db, PDOException::class);

$db = new SQLite3('sqlite', ['file' => ':memory:']);
test_methods($db, PHP_VERSION_ID >= 80300 ? 'SQLite3Exception' : Exception::class);

function test_methods($db, $exception_name)
{
	Test::assert($db->exec('CREATE TABLE test (a INTEGER PRIMARY KEY, b); INSERT INTO test VALUES (1, 2);'));

	Test::exception($exception_name, function () use ($db) {
		$db->execMultiple('INSERT INTO test VALUES (3, 4); FAIL;');
	});

	// Should have rollbacked
	Test::equals(1, $db->firstColumn('SELECT COUNT(*) FROM test;'));

	Test::assert($db->delete('test', $db->where('a', 1)));

	Test::equals(0, $db->firstColumn('SELECT COUNT(*) FROM test;'));

	Test::assert($db->insert('test', ['a' => 42]));

	// test using mixed type bindings, should fail
	Test::exception($exception_name, function () use ($db) {
		$db->first('SELECT * FROM test WHERE a = ? AND b = :b;', [42, 'abc']);
	});

	Test::equals(42, $db->firstColumn('SELECT a FROM test;'));
	Test::equals((object) ['a' => '42', 'b' => null], $db->first('SELECT * FROM test;'));

	Test::assert($db->update('test', ['b' => 'abc'], $db->where('a', 42)));
	Test::equals('abc', $db->firstColumn('SELECT b FROM test;'));

	Test::assert($db->firstColumn('SELECT 1 FROM test WHERE a > ? AND b = ?;', 41, 'abc'));
	Test::assert($db->firstColumn('SELECT 1 FROM test WHERE a > :a AND b = :b;', ['a' => 41, 'b' => 'abc']));

	// Same name
	Test::assert($db->firstColumn('SELECT 1 FROM test WHERE a >= :a AND a <= :a;', ['a' => 42]));

	Test::equals(['42' => 'abc'], $db->getAssoc('SELECT a, b FROM test;'));
	Test::equals(['42' => (object) ['a' => '42', 'b' => 'abc']], $db->getGrouped('SELECT a, b FROM test;'));

	// test insert object
	Test::assert($db->insert('test', (object) ['a' => 9, 'b' => 10]));

	// test transactions
	Test::assert($db->begin());

	Test::assert($db->insert('test', ['a' => 43, 'b' => 43]));

	// test nested transaction
	Test::exception($exception_name, function () use ($db) {
		Test::assert($db->begin());
	});

	Test::assert($db->insert('test', ['a' => 44, 'b' => 45]));

	// test rollback
	Test::assert($db->rollback());

	Test::equals(2, $db->firstColumn('SELECT COUNT(*) FROM test;'));

	// test successful commit
	Test::assert($db->begin());

	Test::assert($db->insert('test', ['a' => 45, 'b' => 43]));

	Test::assert($db->commit());

	Test::equals(3, $db->firstColumn('SELECT COUNT(*) FROM test;'));

	// test auto increment
	Test::assert($db->insert('test', ['b' => 'lol']));
	Test::equals(46, $db->lastInsertId());

	// Test distance between dijon and auckland
	Test::equals('18586.107', $db->firstColumn('SELECT haversine_distance(47.332, 5.0323, -36.8862, 174.7776);'));

	// test method
	Test::strictlyEquals(true, $db->test('test', $db->where('b', 'lol')));
	Test::strictlyEquals(false, $db->test('test', $db->where('b', 'glop')));
	Test::strictlyEquals(true, $db->test('test', 'b = ?', 'lol'));
	Test::strictlyEquals(true, $db->test('test', 'b = :str', ['str' => 'lol']));

	// count
	Test::strictlyEquals(1, $db->count('test', $db->where('b', 'lol')));
}

