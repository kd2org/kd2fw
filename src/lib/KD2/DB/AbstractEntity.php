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

use KD2\Form;

/**
 * AbstractEntity: a generic entity that can be extended to build your entities
 * Use the EntityManager to persist entities in a database
 *
 * @author bohwaz
 * @license AGPLv3
 */

abstract class AbstractEntity
{
	protected $id;

	protected $_exists = false;

	protected $_modified = [];
	protected $_types = [];
	protected $_validation_rules = [];

	/**
	 * Default constructor
	 */
	public function __construct()
	{
		// Generate _types array
		if (version_compare(PHP_VERSION, '7.4', '>=') && empty($this->_types)) {
			$r = new \ReflectionClass(static::class);
			foreach ($r->getProperties(\ReflectionProperty::IS_PROTECTED) as $p) {
				if ($p->name[0] == '_') {
					// Skip internal stuff
					continue;
				}

				if ($p->name == 'id') {
					$type = 'integer';
				}
				else {
					$t = $p->getType();

					if (null === $t) {
						throw new \LogicException(sprintf('Property "%s" of entity "%s" has no type', $p->name, static::class));
					}

					$type = $t->getName();

					// Make sure type names are consistent (not the case in PHP...)
					if ($type == 'int') {
						$type = 'integer';
					}

					$type = ($t->allowsNull() ? '?' : '') . $type;
				}

				$this->_types[$p->name] = $type;
			}
		}
	}

	/**
	 * Loads data from an array into the entity properties
	 * Used for example to load data from a database. This will convert string values to typed properties.
	 * @param  array  $data
	 * @return void
	 */
	public function load(array $data): void
	{
		$properties = array_keys($this->_types);

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
			$this->set($key, $value, true);
		}
	}

	/**
	 * Import data from an array of user-supplied values, only keys corresponding to entity properties
	 * will be used, others will be ignored.
	 * @param  array|null $source Source data array, if none is supplied $_POST will be used
	 * @return void
	 */
	public function import(array $source = null): void
	{
		if (null === $source) {
			$source = $_POST;
		}

		$properties = array_keys($this->_types);
		$data = array_intersect_key($source, $properties);

		foreach ($data as $key => $value) {
			$value = $this->filterUserValue($key, $value, $source);

			$this->set($key, $value, true);
		}
	}

	protected function filterUserValue(string $key, $value, array $source)
	{
		if (isset($this->_validation_rules[$key])) {
			$errors = Form::validateField($key, $this->_validation_rules[$key], $source);

			if (0 !== count($errors)) {
				throw new \UnexpectedValueException('Validation error');
			}
		}

		$value = Form::filterField($value, $this->_types[$key]);
		return $value;
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

		// Remove internal stuff
		foreach ($vars as $key => $value) {
			if ($key[0] == '_') {
				unset($vars[$key]);
			}
		}

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

	public function exists(bool $exists = null): bool
	{
		if (null !== $exists) {
			$this->_exists = $exists;
		}

		return $this->_exists;
	}

	protected function set(string $key, $value, bool $loose = false) {
		if (!property_exists($this, $key)) {
			throw new \InvalidArgumentException(sprintf('Unknown "%s" property: "%s"', static::class, $key));
		}

		$type = $this->_types[$key];
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
				if ($type == 'integer' && is_string($value) && ctype_digit($value)) {
					$value = (int)$value;
				}
				elseif ($type == 'DateTime' && is_string($value) && ($d = \DateTime::createFromFormat('Y-m-d H:i:s', $value))) {
					$value = $d;
				}
			}
		}

		if (!$nullable && null === $value) {
			throw new \RuntimeException(sprintf('Unexpected NULL value for "%s"', $key));
		}

		if (null !== $value && !$this->_checkType($key, $value, $type)) {
			$found_type = gettype($value);

			if ('object' == $found_type) {
				$found_type = get_class($value);
			}

			throw new \UnexpectedValueException(sprintf('Value of type \'%s\' for property \'%s\' is invalid (expected \'%s\')', $found_type, $key, $type));
		}

		$this->$key = $value;
	}

	public function __set(string $key, $value)
	{
		if (isset($this->$key)) {
			$original_value = $this->$key;
		}
		else {
			$original_value = null;
		}

		$this->set($key, $value);

		if ($original_value !== $value) {
			$this->_modified[$key] = true;
		}
	}

	public function __get(string $key)
	{
		return $this->$key;
	}

	protected function _checkType(string $key, $value, string $type)
	{
		switch ($type) {
			case 'DateTime':
				return is_object($value) && $value instanceof \DateTime;
			default:
				return gettype($value) == $type;
		}
	}

	// Helpful helpers
	public function save(): bool
	{
		return EntityManager::getInstance(static::class)->save($this);
	}

	public function delete(): bool
	{
		return EntityManager::getInstance(static::class)->delete($this);
	}

}
