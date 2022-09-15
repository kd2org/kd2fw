<?php
declare(strict_types=1);

/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
	All rights reserved.

	KD2FW is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Foobar is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2\DB;

/**
 * AbstractEntity: a generic entity that can be extended to build your entities
 * Use the EntityManager to persist entities in a database
 *
 * @author bohwaz
 * @license AGPLv3
 */

/**
 * Just a helper that tells us that the date should be stored as Y-m-d that's all
 */
class Date extends \DateTime {
	// For PHP 7.4
	static public function createFromInterface(\DateTimeInterface $object): \DateTime
	{
		$n = new self;
		$n->setTimestamp($object->getTimestamp());
		$n->setTimezone($object->getTimeZone());
		return $n;
	}

	#[\ReturnTypeWillChange]
	static public function createFromFormat($format, $datetime, ?\DateTimeZone $object = null)
	{
		$v = parent::createFromFormat($format, $datetime, $object);

		if (!$v) {
			return $v;
		}

		return self::createFromInterface($v);
	}
}

abstract class AbstractEntity
{
	protected $_exists = false;

	protected $_modified = [];
	protected $_types = [];

	static protected $_types_cache = [];

	/**
	 * Default constructor
	 */
	public function __construct()
	{
		if (!empty(self::$_types_cache[static::class])) {
			return $this;
		}

		// Generate _types array
		if (version_compare(PHP_VERSION, '7.4', '>=') && empty($this->_types)) {
			$r = new \ReflectionClass(static::class);
			self::$_types_cache[static::class] = [];

			foreach ($r->getProperties(\ReflectionProperty::IS_PROTECTED) as $p) {
				if ($p->name[0] == '_') {
					// Skip internal stuff
					continue;
				}

				if (array_key_exists($p->name, $this->_types)) {
					$type = $this->_types[$p->name];
				}
				else {
					$t = $p->getType();

					if (null === $t) {
						throw new \LogicException(sprintf('Property "%s" of entity "%s" has no type', $p->name, static::class));
					}

					$type = $t->getName();
					$type = ($t->allowsNull() ? '?' : '') . $type;
				}

				self::$_types_cache[static::class][$p->name] = $type;
			}

			$this->_types = self::$_types_cache[static::class];
		}
		else {
			self::$_types_cache[static::class] = $this->_types;
		}
	}

	public function __wakeup(): void
	{
		if (empty(self::$_types_cache[static::class])) {
			self::$_types_cache[static::class] = $this->_types;
		}
	}

	/**
	 * Loads data from an array into the entity properties
	 * Used for example to load data from a database. This will convert string values to typed properties.
	 * @param  array  $data
	 * @return self
	 */
	public function load(array $data): self
	{
		$properties = array_keys(self::$_types_cache[static::class]);

		foreach ($data as $key => $value) {
			if (!in_array($key, $properties)) {
				throw new \RuntimeException(sprintf('"%s" is not a property of the entity "%s"', $key, static::class));
			}
		}

		foreach ($properties as $key) {
			if (!array_key_exists($key, $data)) {
				throw new \RuntimeException('Missing key in array: ' . $key);
			}

			$value = $data[$key];
			$this->set($key, $value, true, false);
		}

		return $this;
	}

	/**
	 * Import data from an array of user-supplied values, only keys corresponding to entity properties
	 * will be used, others will be ignored.
	 * @param  array|null $source Source data array, if none is supplied $_POST will be used
	 * @return void
	 */
	public function import(array $source = null): self
	{
		if (null === $source) {
			$source = $_POST;
		}

		unset($source['id']);

		$data = array_intersect_key($source, self::$_types_cache[static::class]);

		foreach ($data as $key => $value) {
			$type = self::$_types_cache[static::class][$key];

			if (substr($type, 0, 1) == '?') {
				$type = substr($type, 1);

				if (is_string($value) && trim($value) === '') {
					$value = null;
				}
			}

			$value = $this->filterUserValue($type, $value, $key);
			$this->set($key, $value, true, true);
		}

		return $this;
	}

	protected function filterUserValue(string $type, $value, string $key)
	{
		if (is_null($value)) {
			return $value;
		}

		switch ($type)
		{
			case 'date':
			case Date::class:
				$d = new Date($value);
				$d->setTime(0, 0, 0);
				return $d;
			case 'DateTime':
				return new \DateTime($value);
			case 'int':
				return (int) $value;
			case 'bool':
				return (bool) $value;
			case 'string':
				return trim($value);
		}

		return $value;
	}

	protected function assert($test, string $message = null): void
	{
		if ($test) {
			return;
		}

		if (null === $message) {
			$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
			$caller_class = array_pop($backtrace);
			$caller = array_pop($backtrace);
			$message = sprintf('Entity assertion fail from class %s on line %d', $caller_class['class'], $caller['line']);
		}

		throw new \UnexpectedValueException($message);
	}

	public function selfCheck(): void
	{
		$this->assert(!isset($this->id) || (is_numeric($this->id) && $this->id > 0));

		foreach (self::$_types_cache[static::class] as $key => $type) {
			// Skip ID
			if ($key == 'id') {
				continue;
			}

			if (!isset($this->$key) && substr($type, 0, 1) != '?') {
				throw new \UnexpectedValueException(sprintf('Entity property "%s" cannot be left null', $key));
			}
		}
	}

	public function asArray(bool $for_database = false): array
	{
		$vars = get_object_vars($this);

		// Remove internal stuff
		foreach ($vars as $key => &$value) {
			if ($key[0] == '_') {
				unset($vars[$key]);
				continue;
			}

			if (!$for_database) {
				continue;
			}

			$value = $this->getAsString($key);
		}

		return $vars;
	}

	public function getAsString(string $key)
	{
		if (null === $this->$key) {
			return null;
		}

		$type = self::$_types_cache[static::class][$key];

		if (substr($type, 0, 1) == '?') {
			$type = substr($type, 1);
		}

		switch ($type) {
			// Export dates
			case 'date':
			case Date::class:
				return $this->$key->format('Y-m-d');
			case 'DateTime':
				return $this->$key->format('Y-m-d H:i:s');
			case 'bool':
			case 'boolean':
				return (int) $this->$key;
			case 'stdClass':
				return json_encode($this->$key);
			case 'array':
				return json_encode($this->$key);
			case 'int':
			case 'integer':
			case 'double':
			case 'float':
				return $this->$key;
			default:
				return (string) $this->$key;
		}
	}

	public function modifiedProperties(bool $for_database = false): array
	{
		return array_intersect_key($this->asArray($for_database), $this->_modified);
	}

	public function clearModifiedProperties(): void
	{
		$this->_modified = [];
	}

	public function isModified(?string $property = null): bool
	{
		if ($property !== null) {
			return array_key_exists($property, $this->_modified);
		}
		else {
			return count($this->_modified) > 0;
		}
	}

	public function id(int $id = null): int
	{
		if (null !== $id) {
			$this->id = $id;
		}

		if (null === $this->id) {
			throw new \LogicException('This entity does not have an ID yet');
		}

		return $this->id;
	}

	public function exists(bool $exists = null): bool
	{
		if (null !== $exists) {
			$this->_exists = $exists;

			if ($exists === false) {
				$this->id = null;
			}
		}

		return $this->_exists;
	}

	public function set(string $key, $value, bool $loose = false, bool $check_for_changes = true) {
		if (!property_exists($this, $key)) {
			throw new \InvalidArgumentException(sprintf('Unknown "%s" property: "%s"', static::class, $key));
		}

		if (isset($this->$key)) {
			$original_value = $this->getAsString($key);
		}
		else {
			$original_value = null;
		}

		$type = self::$_types_cache[static::class][$key];
		$nullable = false;

		if ($type[0] == '?') {
			$nullable = true;
			$type = substr($type, 1);
		}

		if ($loose) {
			if (is_string($value) && trim($value) === '' && $nullable) {
				$value = null;
			}

			if ($value !== null) {
				if (strpos($type, 'int') !== false && is_string($value) && ctype_digit($value)) {
					$value = (int)$value;
				}
				elseif ($type == 'DateTime' && is_string($value) && strlen($value) === 19 && ($d = \DateTime::createFromFormat('!Y-m-d H:i:s', $value))) {
					$value = $d;
				}
				elseif ($type == 'DateTime' && is_string($value) && strlen($value) === 16 && ($d = \DateTime::createFromFormat('!Y-m-d H:i', $value))) {
					$value = $d;
				}
				elseif (($type == 'date' || $type == Date::class) && is_string($value) && strlen($value) === 10 && ($d = Date::createFromFormat('!Y-m-d', $value))) {
					$value = $d;
				}
				elseif (($type == 'date' || $type == Date::class) && is_object($value) && $value instanceof \DateTime && !($value instanceof Date)) {
					$value = Date::createFromInterface($value);
				}
				elseif ($type == 'bool' && is_numeric($value) && ($value == 0 || $value == 1)) {
					$value = (bool) $value;
				}
				elseif ($type == 'array' && is_string($value)) {
					$value = json_decode($value, true);

					if (null === $value) {
						throw new \RuntimeException(sprintf('Cannot decode JSON string for key "%s"', $key));
					}
				}
				elseif ($type == 'stdClass' && is_string($value)) {
					$value = json_decode($value);

					if (null === $value) {
						throw new \RuntimeException(sprintf('Cannot decode JSON string for key "%s"', $key));
					}
				}
			}
		}

		if (!$nullable && null === $value) {
			throw new \RuntimeException(sprintf('Unexpected NULL value for "%s"', $key));
		}

		if (null !== $value && !$this->_checkType($key, $value, $type)) {
			$found_type = $this->_getType($value);

			if ('object' == $found_type) {
				$found_type = get_class($value);
			}

			throw new \UnexpectedValueException(sprintf('Value of type \'%s\' for property \'%s\' is invalid (expected \'%s\')', $found_type, $key, $type));
		}

		$this->$key = $value;

		if ($check_for_changes && $original_value !== $this->getAsString($key)) {
			$this->_modified[$key] = $original_value;
		}
	}

	public function get(string $key)
	{
		return $this->$key ?? null;
	}

	public function __set(string $key, $value)
	{
		$this->set($key, $value, false, true);
	}

	public function __get(string $key)
	{
		return $this->get($key);
	}

	public function __isset($key)
	{
		return property_exists($this, $key) && isset($this->$key);
	}

	/**
	 * Make sure the cloned object doesn't have the same ID, it's a brand new entity!
	 */
	public function __clone()
	{
		$this->id = null;
		$this->_exists = false;
	}

	protected function _checkType(string $key, $value, string $type): bool
	{
		if (false !== strpos($type, '|')) {
			$types = explode('|', $type);

			foreach ($types as $type) {
				if ($this->_checkType($key, $value, $type)) {
					return true;
				}
			}

			return false;
		}

		switch ($type) {
			case 'date':
			case Date::class:
				return is_object($value) && $value instanceof \DateTimeInterface;
			case 'DateTime':
				return is_object($value) && $value instanceof \DateTimeInterface;
			default:
				return $this->_getType($value) == $type;
		}
	}

	protected function _getType($value)
	{
		$type = gettype($value);

		// Type names are not consistent in PHP...
		// see https://mlocati.github.io/articles/php-type-hinting.html
		$type = strtr($type, ['boolean' => 'bool', 'integer' => 'int', 'double' => 'float']);

		if ($type === 'object') {
			$type = get_class($value);
		}

		return $type;
	}

	// Helpful helpers
	public function save(bool $selfcheck = true): bool
	{
		return EntityManager::getInstance(static::class)->save($this, $selfcheck);
	}

	public function delete(): bool
	{
		return EntityManager::getInstance(static::class)->delete($this);
	}
}
