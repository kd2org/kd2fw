<?php

namespace KD2\DB;

use KD2\AbstractEntity;
use KD2\DB;
use KD2\DB_SQLite3;
use PDO;

class EntityManager
{
	protected $class;

	static protected $_instances = [];
	static protected $_db;

	static public function getInstance(string $class)
	{
		// Create a new entity manager for this entity if it does not exist
		if (!array_key_exists($class, self::$_instances)) {
			// Check that the class is a child of AbstractEntity
			if (!is_a($class, AbstractEntity::class, true)) {
				throw new \InvalidArgumentException(sprintf('Class "%s" does not extend "%s"', $class, AbstractEntity::class));
			}

			// The entity manager works with SQL tables, so the entity needs to specify a table
			if (!defined($class . '::TABLE')) {
				throw new \InvalidArgumentException(sprintf('Class "%s" does not define a TABLE constant', $class));
			}

			self::$_instances[$class] = new EntityManager($class);
		}

		return self::$_instance[$class];
	}

	static public function setDB(DB $db): void
	{
		self::$_db = $db;
	}

	protected function __construct(string $class)
	{
		$this->class = $class;
	}

	static public function findOne(string $class, string $query, ...$params)
	{
		return self::getInstance($class)->one($query, ...$params);
	}

	protected function formatQuery(string $query): string
	{
		$class = self::$class;
		$query = str_replace('{$table}', $class::TABLE, $query);
		return $query;
	}

	public function all(string $query, ...$params): array
	{
		$res = $this->iterate($query, ...$params);
		$out = [];

		foreach ($res as $row) {
			$out[] = $row;
		}

		return $out;
	}

	public function iterate(string $query, ...$params): iterable
	{
		$db = self::$_db;
		$query = $this->formatQuery($query);
		$res = $db->preparedQuery($query, $params);

		if ($db instanceof DB_SQLite3) {
			while ($row = $res->fetchArray(\SQLITE3_ASSOC)) {
				$obj = new $this->class;
				$obj->load($row);
				yield $row;
			}

			$res->finalize();
		}
		else {
			while ($row = $res->fetch(PDO::FETCH_ASSOC)) {
				$obj = new $this->class;
				$obj->load($row);
				yield $row;
			}
		}
	}

	public function one(string $query, ...$params)
	{
		$db = self::$_db;

		$query = $this->formatQuery($query);
		$res = $db->preparedQuery($query, $params);

		if ($db instanceof DB_SQLite3) {
			$row = $res->fetchArray(\SQLITE3_ASSOC);
			$res->finalize();
		}
		else {
			$row = $res->fetch(PDO::FETCH_ASSOC);
		}

		if (false === $row) {
			return null;
		}

		$obj = new $this->class;
		$obj->load($row);
		return $obj;
	}

	public function col(string $query, ...$params)
	{
		$query = $this->formatQuery($query);
		return self::$_db->firstColumn($query, ...$params);
	}

	protected function getSQLFields(array $fields)
	{
		foreach ($fields as &$field) {
			if ($field instanceof DateTime) {
				$field = $field->format('Y-m-d H:i:s');
			}
		}

		return $fields;
	}

	public function save(AbstractEntity $entity): bool
	{
		$entity->selfCheck();
		$data = $entity->modifiedProperties();
		$data = $this->getSQLFields($data);
		$db = self::$_db;

		if ($entity->exists()) {
			return $db->update($entity::TABLE, $data, $db->where('id', $entity->id()));
		}
		else {
			$return = $db->insert($entity::TABLE, $data);

			if ($return) {
				$id = (int) $db->lastInsertId();

				if ($id < 1) {
					throw new \LogicException('Error inserting entity in DB: invalid ID = ' . $id);
				}

				$entity->id($id);
			}

			return $return;
		}
	}

	public function delete(AbstractEntity $entity): bool
	{
		$db = self::$_db;
		return $db->delete($entity::TABLE, $db->where('id', $entity->id()));
	}
}
