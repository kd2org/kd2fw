<?php

namespace KD2;

use KD2\SMTP;

use DateTime;

class JSONSchema
{
	protected $schema;

	public function __construct($object)
	{
		$this->schema = $object;
	}

	static protected function parseFile(string $path)
	{
		$file = file_get_contents($path);
		return self::parse($file);
	}

	static protected function parse(string $raw, ?string $path = null)
	{
		$file = json_decode($raw);

		if (json_last_error()) {
			throw new \RuntimeException(sprintf('JSON parsing failed for "%s": %s', $path ?? $raw, json_last_error_msg()));
		}

		return $file;
	}

	static public function fromFile(string $file)
	{
		return new self(self::parseFile($file));
	}

	static public function fromString(string $raw)
	{
		return new self(self::parse($raw));
	}

	public function validateFile(string $file)
	{
		$this->validate(self::parse($file));
	}

	public function validate($object, $rules = null, $key = null): void
	{
		if (null === $rules) {
			$rules = $this->schema;
		}

		if (!isset($rules->type)) {
			throw new \RuntimeException('Invalid schema: no "type" supplied');
		}

		$name = $rules->description ?? ($key ?? 'root');

		if (!$this->checkType($rules->type, $object)) {
			throw new \RuntimeException(sprintf('%s: invalid object, expected "%s" got "%s"', $name, is_string($rules->type) ? $rules->type : implode('|', $rules->type), gettype($object)));
		}

		if (isset($rules->enum) && !in_array($object, $rules->enum, true)) {
			throw new \RuntimeException(sprintf('%s: did not match any of the accepted values (%s)', $name, implode($rules->enum)));
		}

		if (!is_array($rules->type)) {
			$rules->type = [$rules->type];
		}

		$types = $rules->type;

		if (is_null($object) && in_array('null', $types)) {
			return;
		}

		if (is_bool($object) && in_array('bool', $types)) {
			return;
		}

		if ((is_int($object) || is_float($object)) && (in_array('integer', $types) || in_array('number', $types))) {
			$this->validateNumber($object, $rules, $name);
		}
		elseif (is_string($object) && in_array('string', $types)) {
			$this->validateString($object, $rules, $name);
		}
		elseif (is_string($object) && in_array('string', $types)) {
			$this->validateString($object, $rules, $name);
		}
		elseif ($this->isAssociativeArrayOrObject($object) && in_array('object', $types)) {
			$this->validateObject($object, $rules, $name);
		}
		elseif (is_array($object) && in_array('array', $types)) {
			$this->validateArray($object, $rules, $name);
		}
		else {
			throw new \LogicException('Invalid type');
		}
	}

	protected function validateNumber($object, $rules, $name)
	{
		if (isset($rules->minimum) && $object < $rules->minimum) {
			throw new \RuntimeException(sprintf('%s: is too small (minimum %s)', $name, $rules->minimum));
		}

		if (isset($rules->maximum) && $object > $rules->maximum) {
			throw new \RuntimeException(sprintf('%s: is too big (maximum %s)', $name, $rules->maximum));
		}

		if (isset($rules->exclusiveMinimum) && $object <= $rules->exclusiveMinimum) {
			throw new \RuntimeException(sprintf('%s: is too small (minimum %s)', $name, $rules->exclusiveMinimum));
		}

		if (isset($rules->exclusiveMaximum) && $object >= $rules->exclusiveMaximum) {
			throw new \RuntimeException(sprintf('%s: is too big (maximum %s)', $name, $rules->exclusiveMaximum));
		}
	}

	protected function validateString($object, $rules, $name)
	{
		if (isset($rules->pattern) && !preg_match('/' . $rules->pattern . '/', $object)) {
			throw new \RuntimeException(sprintf('%s: did not match the specified pattern (%s)', $name, $rules->pattern));
		}

		if (isset($rules->minLength) && strlen($object) < $rules->minLength) {
			throw new \RuntimeException(sprintf('%s: is too short (minimum %d characters)', $name, $rules->minLength));
		}

		if (isset($rules->maxLength) && strlen($object) > $rules->minLength) {
			throw new \RuntimeException(sprintf('%s: is too long (maximum %d characters)', $name, $rules->minLength));
		}

		if (isset($rules->format)) {
			$this->validateFormat($rules->format, $object, $name);
		}
	}

	protected function validateArray($object, $rules, $name)
	{
		if (isset($rules->minItems) && count($object) < $rules->minItems) {
			throw new \RuntimeException(sprintf('%s: does not contain enough items (minimum %s)', $name, $rules->minItems));
		}

		if (isset($rules->maxItems) && count($object) > $rules->maxItems) {
			throw new \RuntimeException(sprintf('%s: does contain more items than accepted (maximum %s)', $name, $rules->maxItems));
		}

		if (isset($rules->uniqueItems) && count($object) != count(array_unique($object))) {
			throw new \RuntimeException(sprintf('%s: some items are not unique', $name));
		}

		if (!isset($rules->items) && !isset($rules->prefixItems)) {
			return;
		}

		// Only one rule to validate against
		if (is_object($rules->items)) {
			foreach ($object as $_item) {
				$this->validate($_item, $rules->items, $name);
			}
		}

		// New syntax for new drafts, but same feature
		if (isset($rules->items) && is_array($rules->items)) {
			$rules->prefixItems = $rules->items;
		}

		if (isset($rules->items) && $rules->items === false && count($object) > count($rules->prefixItems)) {
			throw new \RuntimeException(sprintf('%s: only %d items are allowed at most', $name, count($rules->prefixItems)));
		}

		if (isset($rules->prefixItems)) {
			// Validate each row against a rule
			foreach ($rules->prefixItems as $i => $rule) {
				if (!isset($object[$i])) {
					break;
				}

				$this->validate($object[$i], $rule, $name);
			}
		}
	}

	protected function validateObject($object, $rules, $name)
	{
		if (is_array($object)) {
			$object = (object) $object;
		}

		if (isset($rules->minProperties) && count($object) < $rules->minProperties) {
			throw new \RuntimeException(sprintf('%s: does not contain enough properties (minimum %s)', $name, $rules->minProperties));
		}

		if (isset($rules->maxProperties) && count($object) > $rules->maxProperties) {
			throw new \RuntimeException(sprintf('%s: does contain more properties than accepted (maximum %s)', $name, $rules->maxProperties));
		}

		if (isset($rules->required)) {
			foreach ($rules->required as $required) {
				if (!property_exists($object, $required)) {
					throw new \RuntimeException(sprintf('%s: the "%s" property is required but missing', $name, $required));
				}
			}
		}

		if (!isset($rules->properties)) {
			return;
		}

		if (isset($rules->additionalProperties) && $rules->additionalProperties === false &&
			count(array_intersect_key((array) $rules->properties, array_keys(get_object_vars($object)))) != count((array) $rules->properties)) {
			throw new \RuntimeException(sprintf('%s: additional properties are not allowed', $name));
		}

		foreach ($rules->properties as $_key => $_rules) {
			if (property_exists($object, $_key)) {
				$this->validate($object->$_key, $_rules, $name . '.' . $_key);
			}
		}
	}

	protected function checkType($type, $object): bool
	{
		if (is_array($type)) {
			foreach ($type as $_type) {
				if ($this->checkType($_type, $object)) {
					return true;
				}
			}

			return false;
		}

		if ($type == 'null' && is_null($object)) {
			return true;
		}
		elseif ($type == 'array' && is_array($object)) {
			return true;
		}
		elseif ($type == 'string' && is_string($object)) {
			return true;
		}
		elseif ($type == 'integer' && is_int($object)) {
			return true;
		}
		elseif ($type == 'object' && $this->isAssociativeArrayOrObject($object)) {
			return true;
		}
		elseif ($type == 'number' && is_numeric($object)) {
			return true;
		}
		elseif ($type == 'bool' && is_bool($object)) {
			return true;
		}

		return false;
	}

	protected function isAssociativeArrayOrObject($var) {
		return is_object($var)
			|| (is_array($var) && array_keys($var) !== range(0, count($var) - 1));
	}

	protected function validateFormat(string $format, string $object, string $name): void
	{
		if ($format == 'email' && !SMTP::checkEmailIsValid($object)) {
			throw new \RuntimeException(sprintf('%s: is not a valid email address', $name));
		}
		elseif ($format == 'date-time' && !DateTime::createFromFormat(\DATE_RFC3339, $object)) {
			$example = date(DATE_RFC3339);
			throw new \RuntimeException(sprintf('%s: is not a valid date and time (expected format: %s)', $name, $example));
		}
		elseif ($format == 'date' && !DateTime::createFromFormat('Y-m-d', $object)) {
			$example = date('Y-m-d');
			throw new \RuntimeException(sprintf('%s: is not a valid date (expected format: %s)', $name, $example));
		}
		elseif ($format == 'time' && !DateTime::createFromFormat('H:i:s', $object)) {
			$example = date('H:i:s');
			throw new \RuntimeException(sprintf('%s: is not a valid time (expected format: %s)', $name, $example));
		}
		elseif ($format == 'regex' && preg_match('/' . $object . '/', '') && preg_last_error()) {
			throw new \RuntimeException(sprintf('%s: invalid regexp', $name));
		}
	}
}
