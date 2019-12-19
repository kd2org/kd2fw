<?php
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
	 * @var boolean
	 */
	protected $transaction = false;

	const DATE_FORMAT = 'Y-m-d H:i:s';

	public function close(): void
	{
		$this->db->close();
		$this->db = null;
	}

	public function connect(): void
	{
		if ($this->db) {
			return;
		}

		$file = str_replace('sqlite:', '', $this->driver->url);

		if (isset($this->driver->options[PDO::SQLITE_ATTR_OPEN_FLAGS])) {
			$flags = $this->driver->options[PDO::SQLITE_ATTR_OPEN_FLAGS];
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
		if ($this->transaction)
		{
			throw new \Exception('A transaction is already running: started at ' . $this->transaction);
		}

		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);

		$this->transaction = $trace[0]['file'] . ':' . $trace[0]['line'];
		$this->connect();
		return $this->db->exec('BEGIN;');
	}

	public function inTransaction()
	{
		return $this->transaction;
	}

	public function commit()
	{
		if (!$this->transaction)
		{
			throw new \Exception('No transaction is currently running.');
		}

		$this->connect();
		$this->transaction = false;
		return $this->db->exec('END;');
	}

	public function rollback()
	{
		if (!$this->transaction)
		{
			throw new \Exception('No transaction is currently running.');
		}

		$this->connect();
		$this->db->exec('ROLLBACK;');
		$this->transaction = false;
		return true;
	}

	public function getArgType(string &$arg, string $name = ''): int
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
					$arg = clone $arg;
					$arg->setTimezone(new \DateTimeZone('UTC'));
					$arg = $arg->format(self::DATE_FORMAT);
					return \SQLITE3_TEXT;
				}
			default:
				throw new \InvalidArgumentException('Argument '.$name.' is of invalid type '.gettype($arg));
		}
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
	public function preparedQuery(string $query, array $args = [])
	{
		assert(is_string($query));
		assert(is_array($args) || is_object($args));

		// Forcer en tableau
		$args = (array) $args;

		$this->connect();

		$query = $this->applyTablePrefix($query);
		$statement = $this->db->prepare($query);

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
			throw new \RuntimeException($e->getMessage() . "\n" . $query . "\n" . json_encode($args, true));
		}
	}

	public function query(string $statement)
	{
		$this->connect();
		$statement = $this->applyTablePrefix($statement);
		return $this->db->query($statement);
	}

	/**
	 * Performs a user SELECT query in the database
	 *
	 * This is meant to allow users to make SELECT statements without altering the database
	 * and staying as safe as possible.
	 *
	 * Warning! There are probably still some ways to extract valuable information
	 * for a hacker. This feature should not be available to all your users!
	 *
	 * @param  string $query SQL SELECT query
	 * @return array Rows of the result, as stdClass objects
	 */
	public function userSelectStatement(string $statement)
	{
		if (preg_match('/;\s*(.+?)$/', $statement))
		{
			throw new \LogicException('Only one single statement can be executed at the same time.');
		}

		// Forbid use of some strings that could allow give hints to an attacker:
		// PRAGMA, sqlite_version(), sqlite_master table, comments
		if (preg_match('/PRAGMA\s+|sqlite_version|sqlite_master|--|\/\*|\*\/|load_extension|ATTACH\s+|randomblob|sqlite_compileoption_|sqlite_offset|sqlite_source_|zeroblob|X\'\w|0x\w|sqlite_dbpage|fts3_tokenizer/i', $statement, $match))
		{
			throw new \LogicException('Invalid SQL query.');
		}

		if (!preg_match('/^\s*SELECT\s+/i', $statement))
		{
			$query = 'SELECT ' . $statement;
		}

		$st = $this->db->prepare($statement);

		if (!$st->readOnly())
		{
			throw new \LogicException('Only read-only queries are accepted.');
		}

		return $st;
	}

	public function userSelectGet(string $statement): array
	{
		$st = $this->userSelectStatement($statement);

		$res = $st->execute();

		$out = [];

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			$out[] = (object) $row;
		}

		return $out;
	}

	public function iterate(string $statement, ...$args): iterable
	{
		$res = $this->preparedQuery($statement, $args);

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			yield (object) $row;
		}

		unset($res);

		return;
	}

	public function get(string $statement, ...$args): array
	{
		$res = $this->preparedQuery($statement, $args);
		$out = [];

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			$out[] = (object) $row;
		}

		return $out;
	}

	public function getAssoc(string $statement, ...$args): array
	{
		$res = $this->preparedQuery($statement, $args);
		$out = [];

		while ($row = $res->fetchArray(\SQLITE3_NUM))
		{
			$out[$row[0]] = $row[1];
		}

		return $out;
	}

	public function getGrouped(string $statement, ...$args): array
	{
		$res = $this->preparedQuery($statement, $args);
		$out = [];

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			$out[current($row)] = (object) $row;
		}

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
		$res = $this->preparedQuery($query, $args);

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
		$res = $this->preparedQuery($query, $args);

		$row = $res->fetchArray(\SQLITE3_NUM);

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
		$query = $this->applyTablePrefix($statement);
		return $this->db->prepare($statement);
	}

	public function openBlob(string $table, string $column, int $rowid, string $dbname = 'main', int $flags = \SQLITE3_OPEN_READONLY): resource
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

	static public function getDatabaseDetailsFromString(string $source_string): array
	{
		if (substr($source_string, 0, 16) !== "SQLite format 3\0" || strlen($source_string) < 100) {
			return null;
		}

		$user_version = bin2hex(substr($source_string, 60, 4));
		$application_id = bin2hex(substr($source_string, 68, 4));

		return compact('user_version', 'application_id');
	}

    public function deleteUndoTriggers()
    {
        $triggers = $this->getAssoc('SELECT name, name FROM sqlite_master
            WHERE type = \'trigger\' AND name LIKE \'!_%!_undolog!__t\' ESCAPE \'!\';');

        foreach ($triggers as $trigger)
        {
            $this->exec(sprintf('DROP TRIGGER %s;', $this->quoteIdentifier($trigger)));
        }
    }

    public function createUndoTriggers(array $tables)
    {
        $this->exec('CREATE TABLE IF NOT EXISTS undolog (
            seq INTEGER PRIMARY KEY,
            table TEXT NOT NULL,
            action TEXT NOT NULL
            sql TEXT NOT NULL,
            date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        );');

        $query = 'CREATE TRIGGER _%table_log_it AFTER INSERT ON %table BEGIN
                DELETE FROM undolog WHERE rowid IN (SELECT rowid FROM undolog LIMIT 500,1000);
                INSERT INTO undolog (table, action, sql) VALUES (\'%table\', \'I\', \'DELETE FROM %table WHERE rowid=\'||new.rowid);
            END;
            CREATE TRIGGER _%table_log_ut AFTER UPDATE ON %table BEGIN
                DELETE FROM undolog WHERE rowid IN (SELECT rowid FROM undolog LIMIT 500,1000);
                INSERT INTO undolog (table, action, sql) VALUES (\'%table\', \'U\',  \'UPDATE %table SET %columns_update WHERE rowid = \'||old.rowid);
            END;
            CREATE TRIGGER _%table_log_dt BEFORE DELETE ON %table BEGIN
                DELETE FROM undolog WHERE rowid IN (SELECT rowid FROM undolog LIMIT 500,1000);
                INSERT INTO undolog (table, action, sql) VALUES (\'%table\', \'D\', \'INSERT INTO %table (rowid, %columns_list) VALUES(\'||old.rowid||\', %columns_insert)\');
            END;';

        foreach ($tables as $table)
        {
            $columns = $this->getAssoc(sprintf('PRAGMA table_info(%s);', $this->quoteIdentifier($table)));
            $columns_insert = [];
            $columns_update = [];

            foreach ($columns as &$name)
            {
                $columns_update[] = sprintf('%s = \'||quote(old.%1$s)||\'', $name);
                $columns_insert[] = sprintf('\'||quote(old.%s)||\'', $name);
            }

            $sql = strtr($query, [
                '%table' => $table,
                '%columns_list' => implode(', ', $columns),
                '%columns_update' => implode(', ', $columns_update),
                '%columns_insert' => implode(', ', $columns_insert),
            ]);

            $this->exec($sql);
        }
    }
}
