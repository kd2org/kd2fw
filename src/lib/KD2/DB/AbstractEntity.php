<?php

namespace KD2\DB;

abstract class AbstractEntity
{
	protected $id;

	protected $_modified = [];
	protected $_fields = [];

	public function load(array $data): void
	{
		foreach ($this->_fields as $key => $type) {
			if (!array_key_exists($key, $data)) {
				throw new \RuntimeException('Missing key in array: ' . $key);
			}

			$value = $data[$key];
			$nullable = false;

			if ($type[0] == '?') {
				$nullable = true;
				$type = substr($type, 1);
			}

			if (!$nullable && is_null($value)) {
				throw new \RuntimeException(sprintf('Unexpected NULL value for "%s"', $key));
			}

			if ($type == 'datetime') {
				$value = DateTime::createFromFormat('Y-m-d H:i:s', $value);
			}
			elseif ($type == 'date') {
				$value = DateTime::createFromFormat('Y-m-d', $value);
			}

			$this->$key = $value;
		}

		foreach ($data as $key => $value) {
			if (!array_key_exists($key, $this->_fields) || !property_exists($this, $key)) {
				throw new \RuntimeException(sprintf('"%s" key is not property of entity "%s"', $key, self::class));
			}
		}
	}

	public function save(): bool
	{
		return EntityManager::getInstance($this::class)->save($this);
	}

	public function import(array $data = null): void
	{
		if (null === $data) {
			$data = $_POST;
		}

		return $this->load($data);
	}

	protected function assert(bool $test, string $message = null): void
	{
		if (!$test) {
			if (null === $message) {
				$caller = array_shift(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1));
				$message = sprintf('Entity check fail from class %s on line %d', $caller['class'], $caller['line']);
			}

			throw new \UnexpectedValueException($message);
		}
	}

	public function selfCheck(): void
	{
		$this->assert(is_null($this->id) || (is_numeric($this->id) && $this->id > 0));
	}

	public function asArray(): array
	{
		$vars = get_object_vars($this);
		unset($vars['_modified'], $vars['_fields']);
		return $vars;
	}

	public function modifiedProperties(): array
	{
		return array_intersect_key($this->asArray(), $this->_modified);
	}

	public function id(int $id = null): int
	{
		if (null !== $id) {
			$this->id = $id;
		}

		return $this->id;
	}

	public function __set(string $key, $value)
	{
		if (!array_key_exists($key, $this->_fields)) {
			throw new \InvalidArgumentException(sprintf('Unknown "%s" property: "%s"', static::class, $key));
		}

		$type = $this->_fields[$key];
		$nullable = false;

		if ($type[0] == '?') {
			$nullable = true;
			$type = substr($type, 1);
		}

		if (!$this->_checkType($key, $value, $type, $nullable)) {
			throw new \UnexpectedValueException(sprintf('Value for property \'%s\' is invalid (expected \'%s\')', $key, $type));
		}

		if ($this->$key !== $value) {
			$this->$key = $value;
			$this->_modified[$key] = true;
		}
	}

	public function __get(string $key)
	{
		return $this->$key;
	}

	protected function _checkType(string $key, $value, string $type, bool $nullable)
	{
		if (null === $value && $nullable) {
			return true;
		}

		switch ($type) {
			case 'date':
			case 'datetime':
				return is_object($value) && $value instanceof DateTime;
			default:
				return gettype($value) == $type;
		}
	}
}
