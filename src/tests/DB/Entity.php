<?php

use KD2\Test;
use KD2\DB\DB;
use KD2\DB\AbstractEntity;
use KD2\DB\EntityManager;

require __DIR__ . '/../_assert.php';

class TestEntity extends AbstractEntity
{
	const TABLE = 'test';

	protected $name;
	protected $age;
	protected $birth;
	protected $updated;

	protected $_types = [
		'name'    => 'string',
		'age'     => '?integer',
		'birth'   => 'DateTime',
		'updated' => '?DateTime',
	];
}


$db = new DB('sqlite', ['file' => ':memory:']);

Test::assert($db instanceof DB);

$db->exec('CREATE TABLE IF NOT EXISTS test (id INTEGER PRIMARY KEY, name, age, birth, updated);');

EntityManager::setGlobalDB($db);

test_entity();
test_entity_with_manager();

function test_entity()
{
	$a = new TestEntity;
	Test::assert($a instanceof AbstractEntity);

	$data = [
		'name'    => 'Test Mike',
		'age'     => null,
		'birth'   => new \DateTime('1990-01-02'),
		'updated' => new \DateTime,
	];

	$a->load($data);

	foreach ($data as $key => $value) {
		Test::strictlyEquals($value, $a->$key);
	}

	// Try import from POST data
	$_POST['age'] = '42';

	$a->import();

	Test::strictlyEquals(42, $a->age);
}

function test_entity_with_manager()
{
	global $db;

	$a = new TestEntity;
	Test::assert($a instanceof AbstractEntity);

	$a->import([
		'name' => 'Test Mike',
		'age' => null,
		'birth' => new DateTime('1990-01-02'),
		'updated' => null,
	]);

	$a->save();

	Test::strictlyEquals(true, $a->exists());
	Test::assert(is_integer($a->id) && $a->id > 0);
	Test::strictlyEquals('Test Mike', $db->firstColumn('SELECT name FROM test WHERE id = ? LIMIT 1;', $a->id));
	Test::strictlyEquals(null, $db->firstColumn('SELECT age FROM test WHERE id = ? LIMIT 1;', $a->id));

	$a->age = 42;

	$a->save();

	Test::equals(42, $db->firstColumn('SELECT age FROM test WHERE id = ? LIMIT 1;', $a->id));

	$a->updated = new \DateTime;
	$a->save();

	Test::strictlyEquals($a->updated->format('Y-m-d H:i:s'), $db->firstColumn('SELECT updated FROM test WHERE id = ? LIMIT 1;', $a->id));

	$a->delete();

	Test::equals(0, $db->firstColumn('SELECT COUNT(*) FROM test WHERE id = ? LIMIT 1;', $a->id));
	Test::strictlyEquals(false, $a->exists());

	// Test re-saving the same entity
	$a->save();
	Test::assert(is_integer($a->id) && $a->id > 0);
	Test::equals(1, $db->firstColumn('SELECT COUNT(*) FROM test WHERE id = ? LIMIT 1;', $a->id));
	Test::strictlyEquals(true, $a->exists());
}
