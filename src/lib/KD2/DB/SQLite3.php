<?php
/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2020 BohwaZ <http://bohwaz.net/>
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
	along with KD2FW.  If not, see <https://www.gnu.org/licenses/>.
*/

/**
 * DB_SQLite3: a generic wrapper around SQLite3, adding easier access functions
 * Compatible API with DB, but instead of using PDO, uses SQLite3
 *
 * @author  bohwaz http://bohwaz.net/
 * @license AGPLv3
 */

namespace KD2\DB;

use PDO;

class SQLite3 extends DB
{
	/**
	 * @var SQLite3
	 */
	protected $db;

	/**
	 * @var int
	 */
	protected $transaction = 0;

	/**
	 * @var integer|null
	 */
	protected $flags = null;

	const DATE_FORMAT = 'Y-m-d';
	const DATETIME_FORMAT = 'Y-m-d H:i:s';

	public function close(): void
	{
		$this->__destruct();

		if (null !== $this->db) {
			$this->db->close();
		}

		$this->db = null;
	}

	public function __construct(string $driver, array $params)
	{
		if (!defined('\SQLITE3_OPEN_READWRITE'))
		{
			throw new \Exception('SQLite3 PHP module is not installed.');
		}

		if (isset($params['flags'])) {
			$this->flags = $params['flags'];
		}

		parent::__construct($driver, $params);
	}

	public function __destruct()
	{
		foreach ($this->statements as $st) {
			$st->close();
		}

		parent::__destruct();
	}

	public function connect(): void
	{
		if (null !== $this->db) {
			return;
		}

		$file = str_replace('sqlite:', '', $this->driver->url);

		if (null !== $this->flags) {
			$flags = $this->flags;
		}
		else {
			$flags = \SQLITE3_OPEN_READWRITE | \SQLITE3_OPEN_CREATE;
		}

		$this->db = new \SQLite3($file, $flags);

		$this->db->enableExceptions(true);

		$this->db->busyTimeout($this->pdo_attributes[PDO::ATTR_TIMEOUT] * 1000);

		foreach ($this->sqlite_functions as $name => $callback)
		{
			$this->db->createFunction($name, $callback);
		}

		// Force to rollback any outstanding transaction
		register_shutdown_function(function () {
			if ($this->db && $this->inTransaction())
			{
				$this->rollback();
			}
		});
	}

	public function createFunction(string $name, callable $callback): bool
	{
		if ($this->db)
		{
			return $this->db->createFunction($name, $callback);
		}
		else
		{
			$this->sqlite_functions[$name] = $callback;
			return true;
		}
	}

	public function createCollation(string $name, callable $callback): bool
	{
		if ($this->db)
		{
			return $this->db->createCollation($name, $callback);
		}
		else
		{
			$this->sqlite_collations[$name] = $callback;
			return true;
		}
	}

	public function escapeString(string $str): string
	{
		// escapeString is not binary safe: https://bugs.php.net/bug.php?id=62361
		$str = str_replace("\0", "\\0", $str);

		return \SQLite3::escapeString($str);
	}

	public function quote(string $str, int $parameter_type = 0): string
	{
		return '\'' . $this->escapeString($str) . '\'';
	}

	public function begin()
	{
		$this->transaction++;

		if ($this->transaction == 1) {
			$this->connect();
			return $this->db->exec('BEGIN;');
		}

		return true;
	}

	public function inTransaction()
	{
		return $this->transaction > 0;
	}

	public function commit()
	{
		if ($this->transaction == 0) {
			throw new \LogicException('Cannot commit a transaction: no transaction is running');
		}

		$this->transaction--;

		if ($this->transaction == 0) {
			$this->connect();
			return $this->db->exec('END;');
		}

		return true;
	}

	public function rollback()
	{
		if ($this->transaction == 0) {
			throw new \LogicException('Cannot rollback a transaction: no transaction is running');
		}

		$this->transaction = 0;
		$this->connect();
		$this->db->exec('ROLLBACK;');
		return true;
	}

	public function getArgType(&$arg, string $name = ''): int
	{
		switch (gettype($arg))
		{
			case 'double':
				return \SQLITE3_FLOAT;
			case 'integer':
			case 'boolean':
				return \SQLITE3_INTEGER;
			case 'NULL':
				return \SQLITE3_NULL;
			case 'string':
				return \SQLITE3_TEXT;
			case 'array':
				if (count($arg) == 2
					&& in_array($arg[0], [\SQLITE3_FLOAT, \SQLITE3_INTEGER, \SQLITE3_NULL, \SQLITE3_TEXT, \SQLITE3_BLOB]))
				{
					$type = $arg[0];
					$arg = $arg[1];

					return $type;
				}
			case 'object':
				if ($arg instanceof \DateTime)
				{
					if ($arg->format('His') === '000000') {
						$arg = $arg->format(self::DATE_FORMAT);
					}
					else {
						$arg = $arg->format(self::DATETIME_FORMAT);
					}

					return \SQLITE3_TEXT;
				}
			default:
				throw new \InvalidArgumentException('Argument '.$name.' is of invalid type '.gettype($arg));
		}
	}

	/**
	 * Returns a statement after having checked a query is a SELECT,
	 * doesn't seem to contain anything that could help an attacker,
	 * and if $allowed is not NULL, will try to restrict the query to tables
	 * specified as array keys, and to columns (PHP8+ only) of these tables.
	 *
	 * Note that before PHP8+ this is less secure and doesn't restrict columns.
	 *
	 * @param  array  $allowed List of allowed tables and columns
	 * @param  string $query   SQL query
	 * @return \SQLite3Stmt
	 */
	public function protectSelect(?array $allowed, string $query)
	{
		if (preg_match('/;\s*(.+?)$/', $query))
		{
			throw new \LogicException('Only one single statement can be executed at the same time.');
		}

		// Forbid use of some strings that could give hints to an attacker:
		// PRAGMA, sqlite_version(), sqlite_master table, comments
		if (preg_match('/PRAGMA\s+|sqlite_version|sqlite_master|load_extension|ATTACH\s+|randomblob|sqlite_compileoption_|sqlite_offset|sqlite_source_|zeroblob|X\'\w|0x\w|sqlite_dbpage|fts3_tokenizer/i', $query, $match))
		{
			throw new \LogicException('Invalid SQL query.');
		}

		if (null !== $allowed) {
			// PHP 8+
			if (method_exists($this->db, 'setAuthorizer')) {
				$this->setAuthorizer(function (int $action, ...$args) use ($allowed) {
					if ($action === \SQLite3::SELECT || $action === \SQLite3::FUNCTION) {
						return \SQLite3::OK;
					}

					if ($action !== \SQLite3::READ) {
						return \SQLite3::DENY;
					}

					list($table, $column) = $args;

					if (!array_key_exists($table, $allowed)) {
						return \SQLite3::DENY;
					}

					if (null !== $allowed[$table] && !in_array($column, $allowed[$table])) {
						return \SQLite3::IGNORE;
					}

					return \SQLite3::OK;
				});
			}
			else {
				static $forbidden = ['ALTER', 'ADD', 'ATTACH', 'CREATE', 'COMMIT', 'CREATE', 'DELETE', 'DETACH', 'DROP', 'INSERT', 'PRAGMA', 'REINDEX', 'RENAME', 'REPLACE', 'ROLLBACK', 'SAVEPOINT', 'SET', 'TRIGGER', 'UPDATE', 'VACUUM', 'WITH'];

				$parsed = $this->parseQuery($query);

				foreach ($parsed as $keyword) {
					if (in_array($keyword, $forbidden)) {
						throw new \RuntimeException('Unauthorized keyword: ' . $keyword);
					}

					foreach ($keyword->tables as $table) {
						if (!array_key_exists($table, $allowed)) {
							throw new \RuntimeException('Unauthorized table: ' . $table);
						}

						if (null !== $allowed[$table]) {
							//throw new \InvalidArgumentException('Cannot protect columns without PHP 8+');
						}
					}
				}
			}
		}

		try {
			$st = $this->prepare($query);
		}
		catch (\Exception $e) {
			if ($this->db->lastErrorCode() == 23) {
				throw new \RuntimeException($this->db->lastErrorMsg(), $this->db->lastErrorCode(), $e);
			}

			throw $e;
		}
		finally {
			$this->setAuthorizer(null);
		}

		if (!$st->readOnly())
		{
			throw new \LogicException('Only read-only queries are accepted.');
		}

		return $st;
	}

	public function setAuthorizer(?callable $fn): bool
	{
		if (method_exists(\SQLite3::class, 'setAuthorizer')) {
			$this->connect();
			$this->db->setAuthorizer($fn);
			return true;
		}

		return false;
	}

	public function parseQuery(string $query): array
	{
		static $keywords_string = 'ABORT ACTION ADD AFTER ALL ALTER ALWAYS ANALYZE AND AS ASC ATTACH AUTOINCREMENT BEFORE BEGIN BETWEEN BY CASCADE CASE CAST CHECK COLLATE COLUMN COMMIT CONFLICT CONSTRAINT CREATE CROSS CURRENT CURRENT_DATE CURRENT_TIME CURRENT_TIMESTAMP DATABASE DEFAULT DEFERRABLE DEFERRED DELETE DESC DETACH DISTINCT DO DROP EACH ELSE END ESCAPE EXCEPT EXCLUDE EXCLUSIVE EXISTS EXPLAIN FAIL FILTER FIRST FOLLOWING FOR FOREIGN FROM FULL GENERATED GLOB GROUP GROUPS HAVING IF IGNORE IMMEDIATE IN INDEX INDEXED INITIALLY INNER INSERT INSTEAD INTERSECT INTO IS ISNULL JOIN KEY LAST LEFT LIKE LIMIT MATCH NATURAL NO NOT NOTHING NOTNULL NULL NULLS OF OFFSET ON OR ORDER OTHERS OUTER OVER PARTITION PLAN PRAGMA PRECEDING PRIMARY QUERY RAISE RANGE RECURSIVE REFERENCES REGEXP REINDEX RELEASE RENAME REPLACE RESTRICT RIGHT ROLLBACK ROW ROWS SAVEPOINT SELECT SET TABLE TEMP TEMPORARY THEN TIES TO TRANSACTION TRIGGER UNBOUNDED UNION UNIQUE UPDATE USING VACUUM VALUES VIEW VIRTUAL WHEN WHERE WINDOW WITH WITHOUT';

		$keywords = explode(' ', $keywords_string);
		$keywords = str_replace(' ', '|', $keywords);

		$query = rtrim($query, ';');

		preg_match_all('/((["\'])(?:\\\2|.)*?\2|\b(?:' . implode('|', $keywords) . ')\b|[\w]+(?:\s*\.\s*[\w]+)*)/ims', $query, $match);

		$current = null;
		$query = [];

		foreach ($match[0] as $v) {
			$kw = strtoupper($v);

			if (in_array($kw, $keywords)) {
				$query[$kw] = (object) ['tables' => [], 'content' => []];
				$current = $kw;
			}
			elseif (null !== $current) {
				if ($current == 'FROM' || $current == 'JOIN') {
					$query[$current]->tables[] = $v;
				}
				else {
					$query[$current]->content[] = $v;
				}
			}
		}

		return $query;
	}

	/**
	 * Executes a prepared query using $args array
	 * @return \SQLite3Stmt|boolean Returns a boolean if the query is writing
	 * to the database, or a statement if it's a read-only query.
	 *
	 * The fact that this method returns a boolean is voluntary, to avoid a bug
	 * in SQLite3/PHP where you can re-run a query by calling fetchResult
	 * on a statement. This could cause double writing.
	 */
	public function preparedQuery(string $query, ...$args)
	{
		return parent::preparedQuery($query, ...$args);
	}

	public function execute($statement, ...$args)
	{
		if (!($statement instanceof \SQLite3Stmt)) {
			throw new \InvalidArgumentException('Statement must be of type SQLite3Stmt');
		}

		// Forcer en tableau
		$args = (array) $args;

		$this->connect();

		$statement->reset();
		$nb = $statement->paramCount();

		if (!empty($args))
		{
			if (is_array($args) && count($args) == 1 && is_array(current($args)))
			{
				$args = current($args);
			}

			if (count($args) != $nb)
			{
				throw new \LengthException(sprintf('Arguments error: %d supplied, but %d are required by query.', 
					count($args), $nb));
			}

			reset($args);

			if (is_int(key($args)))
			{
				foreach ($args as $i=>$arg)
				{
					if (is_string($i))
					{
						throw new \InvalidArgumentException(sprintf('%s requires argument to be a keyed array, but key %s is a string.', __FUNCTION__, $i));
					}

					$type = $this->getArgType($arg, $i+1);
					$statement->bindValue((int)$i+1, $arg, $type);
				}
			}
			else
			{
				foreach ($args as $key=>$value)
				{
					if (is_int($key))
					{
						throw new \InvalidArgumentException(sprintf('%s requires argument to be a named-associative array, but key %s is an integer.', __FUNCTION__, $key));
					}

					$type = $this->getArgType($value, $key);
					$statement->bindValue(':' . $key, $value, $type);
				}
			}
		}

		try {
			// Return a boolean for write queries to avoid accidental duplicate execution
			// see https://bugs.php.net/bug.php?id=64531

			$result = $statement->execute();
			return $statement->readOnly() ? $result : (bool) $result;
		}
		catch (\Exception $e)
		{
			throw new \RuntimeException($e->getMessage() . "\n" . json_encode($args, true), 0, $e);
		}
	}

	public function query(string $statement)
	{
		$this->connect();
		$statement = $this->applyTablePrefix($statement);
		return $this->db->query($statement);
	}

	public function iterate(string $statement, ...$args): iterable
	{
		$res = $this->preparedQuery($statement, ...$args);

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			yield (object) $row;
		}

		$res->finalize();

		return;
	}

	public function get(string $statement, ...$args): array
	{
		$res = $this->preparedQuery($statement, ...$args);
		$out = [];

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			$out[] = (object) $row;
		}

		$res->finalize();

		return $out;
	}

	public function getAssoc(string $statement, ...$args): array
	{
		$res = $this->preparedQuery($statement, ...$args);
		$out = [];

		while ($row = $res->fetchArray(\SQLITE3_NUM))
		{
			$out[$row[0]] = $row[1];
		}

		$res->finalize();

		return $out;
	}

	public function getGrouped(string $statement, ...$args): array
	{
		$res = $this->preparedQuery($statement, ...$args);
		$out = [];

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			$out[current($row)] = (object) $row;
		}

		$res->finalize();

		return $out;
	}

	/**
	 * Executes multiple queries in a transaction
	 */
	public function execMultiple(string $statement)
	{
		$this->begin();

		try {
			$statement = $this->applyTablePrefix($statement);
			$this->db->exec($statement);
		}
		catch (\Exception $e)
		{
			$this->rollback();
			throw $e;
		}

		return $this->commit();
	}

	public function exec(string $statement)
	{
		$this->connect();
		$query = $this->applyTablePrefix($statement);
		return $this->db->exec($statement);
	}

	/**
	 * Runs a query and returns the first row from the result
	 * @param  string $query
	 * @return object|bool
	 *
	 * Accepts one or more arguments for the prepared query
	 */
	public function first(string $query, ...$args)
	{
		$res = $this->preparedQuery($query, ...$args);

		$row = $res->fetchArray(\SQLITE3_ASSOC);
		$res->finalize();

		return is_array($row) ? (object) $row : false;
	}

	/**
	 * Runs a query and returns the first column of the first row of the result
	 * @param  string $query
	 * @return object
	 *
	 * Accepts one or more arguments for the prepared query
	 */
	public function firstColumn(string $query, ...$args)
	{
		$res = $this->preparedQuery($query, ...$args);

		$row = $res->fetchArray(\SQLITE3_NUM);
		$res->finalize();

		return (is_array($row) && count($row) > 0) ? $row[0] : false;
	}

	public function countRows(\SQLite3Result $result): int
	{
		$i = 0;

		while ($result->fetchArray(\SQLITE3_NUM))
		{
			$i++;
		}

		$result->reset();

		return $i;
	}

	public function lastInsertId($name = null): string
	{
		return $this->db->lastInsertRowId();
	}

	public function lastInsertRowId(): string
	{
		return $this->db->lastInsertRowId();
	}

	public function prepare(string $statement, array $driver_options = [])
	{
		$this->connect();
		$query = $this->applyTablePrefix($statement);
		return $this->db->prepare($statement);
	}

	public function openBlob(string $table, string $column, int $rowid, string $dbname = 'main', int $flags = \SQLITE3_OPEN_READONLY)
	{
		if (\PHP_VERSION_ID >= 70200)
		{
			return $this->db->openBlob($table, $column, $rowid, $dbname, $flags);
		}
		else
		{
			if ($flags != \SQLITE3_OPEN_READONLY)
			{
				throw new \Exception('Cannot open blob with read/write. Only available from PHP 7.2.0');
			}

			return $this->db->openBlob($table, $column, $rowid, $dbname);
		}
	}

	/**
	 * Import a file containing SQL commands
	 * Allows to use the statement ".read other_file.sql" to load other files
	 * Also supported is the ".import file.csv table"
	 * @param  string $file Path to file containing SQL commands
	 * @return boolean
	 */
	public function import(string $file)
	{
		$sql = file_get_contents($file);
		$sql = str_replace("\r\n", "\n", $sql);
		$sql = preg_split("/\n{2,}/", $sql, -1, PREG_SPLIT_NO_EMPTY);

		$statement = '';
		$i = 0;

		$dir = realpath(dirname($file));

		foreach ($sql as $line) {
			$line = trim($line);

			// Sub-import statements
			if (preg_match('/^\.read (.+\.sql)$/', $line, $match)) {
				$this->import($dir . DIRECTORY_SEPARATOR . $match[1]);
				$statement = '';
				continue;
			}
			elseif (preg_match('/^\.import (.+\.csv) (\w+)$/', $line, $match)) {
				$fp = fopen($dir . DIRECTORY_SEPARATOR . $match[1], 'r');
				$st = null;

				while ($row = fgetcsv($fp)) {
					if (null === $st) {
						$columns = substr(str_repeat('?, ', count($row)), 0, -2);
						$st = $this->db->prepare(sprintf('INSERT INTO %s VALUES (%s);', $this->quoteIdentifier($match[2]), $columns));
					}

					foreach ($row as $i => $value) {
						$st->bindValue($i + 1, $value);
					}

					$st->execute();
					$st->reset();
					$st->clear();
				}

				$statement = '';
				continue;
			}

			$statement .= $line . "\n";

			if (substr($line, -1) !== ';') {
				continue;
			}

			try {
				$this->exec($statement);
			}
			catch (\Exception $e) {
				throw new \Exception(sprintf("Error in '%s': %s\n%s", basename($file), $e->getMessage(), $statement), 0, $e);
			}

			$statement = '';
		}

		return true;
	}

	/**
	 * Performs a foreign key check and throws an exception if any error is found
	 * @return void
	 * @throws \LogicException
	 * @see https://www.sqlite.org/pragma.html#pragma_foreign_key_check
	 */
	public function foreignKeyCheck(): void
	{
		$result = $this->get('PRAGMA foreign_key_check;');

		// No error
		if (!count($result)) {
			return;
		}

		$errors = [];
		$tables = [];
		$ref = null;

		foreach ($result as $row) {
			if (!array_key_exists($row->table, $tables)) {
				$tables[$row->table] = $this->get(sprintf('PRAGMA foreign_key_list(%s);', $row->table));
			}

			// Findinf the referenced foreign key
			foreach ($tables[$row->table] as $fk) {
				if ($fk->id == $row->fkid) {
					$ref = $fk;
					break;
				}
			}

			$errors[] = sprintf('%s (%s): row %d has an invalid reference to %s (%s)', $row->table, $ref->from, $row->rowid, $row->parent, $ref ? $ref->to : null);
		}

		throw new \LogicException(sprintf("Foreign key check: %d errors found\n", count($errors)) . implode("\n", $errors));
	}

	public function backup($destination, string $sourceDatabase = 'main' , string $destinationDatabase = 'main'): bool
	{
		if (is_a($destination, self::class)) {
			$destination = $destination->db;
		}

		return $this->db->backup($destination, $sourceDatabase, $destinationDatabase);
	}

	static public function getDatabaseDetailsFromString(string $source_string): array
	{
		if (substr($source_string, 0, 16) !== "SQLite format 3\0" || strlen($source_string) < 100) {
			return null;
		}

		$user_version = bin2hex(substr($source_string, 60, 4));
		$application_id = bin2hex(substr($source_string, 68, 4));

		return compact('user_version', 'application_id');
	}

}
