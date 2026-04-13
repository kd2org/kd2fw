<?php

use KD2\Test;
use KD2\DB\AbstractEntity;
use KD2\DB\SQLite3;

require __DIR__ . '/_assert.php';

class MyClass
{
	public function __toString(): string {
		return 'OK';
	}
}

class MyEntity extends AbstractEntity
{
	protected int $id;
	protected string $name;
	protected \DateTime $added;
	protected bool $enabled;
	protected ?string $optional;
	protected ?\stdClass $data;
	protected MyClass $class;
}

$e = test_create();
test_export($e);

function test_create(): MyEntity
{
	$e = new MyEntity;
	$e->import([
		'name' => 'Coucou',
		'added' => '2020-01-01 01:01:01',
		'enabled' => '1',
		'optional' => '',
		'data' => '',
	]);

	Test::strictlyEquals('Coucou', $e->name);
	Test::strictlyEquals('2020-01-01 01:01:01', $e->added->format('Y-m-d H:i:s'));
	Test::strictlyEquals(true, $e->enabled);
	Test::strictlyEquals(null, $e->optional);
	Test::strictlyEquals(null, $e->data);

	$e->data = (object)['pizza' => 42];

	Test::strictlyEquals(42, $e->data->pizza ?? null);

	$e->set('class', new MyClass);
	$e->selfCheck();

	Test::exception(\UnexpectedValueException::class, fn() => $e->set('name', 42));
	Test::exception(\UnexpectedValueException::class, fn() => $e->set('added', '2020-01-01'));
	Test::exception(\UnexpectedValueException::class, fn() => $e->set('enabled', '0'));
	Test::exception(\UnexpectedValueException::class, fn() => $e->set('enabled', null));
	Test::exception(\UnexpectedValueException::class, fn() => $e->set('name', null));
	Test::exception(\UnexpectedValueException::class, fn() => $e->set('data', ['test']));
	Test::exception(\UnexpectedValueException::class, fn() => $e->set('class', new \stdClass));

	return $e;
}

function test_export(MyEntity $e)
{
	Test::strictlyEquals('2020-01-01 01:01:01', $e->getAsString('added'));
	Test::strictlyEquals(1, $e->getAsString('enabled'));
	Test::strictlyEquals(null, $e->getAsString('optional'));
	Test::strictlyEquals('{"pizza":42}', $e->getAsString('data'));
	Test::strictlyEquals('OK', $e->getAsString('class'));
}
