<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/
  
  Copyright (c) 2001-2016 BohwaZ <http://bohwaz.net/>
  All rights reserved.
  
  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:
  1. Redistributions of source code must retain the above copyright notice,
  this list of conditions and the following disclaimer.
  2. Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.
  
  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF
  THE POSSIBILITY OF SUCH DAMAGE.
*/

namespace KD2;

use PDO;
use PDOException;
use PDOStatement;

class DB
{
	/**
	 * Attributes for PDO instance
	 * @var array
	 */
	protected $pdo_attributes = [
		PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
		PDO::ATTR_TIMEOUT            => 5, // in seconds
		PDO::ATTR_EMULATE_PREPARES   => false,
	];

	/**
	 * Current driver
	 * @var null
	 */
	protected $driver;

	/**
	 * Store PDO object
	 * @var null
	 */
	protected $pdo;

	protected $sqlite_functions = [
		'base64_encode'      => 'base64_encode',
		'rank'               => [__CLASS__, 'sqlite_rank'],
		'haversine_distance' => [__CLASS__, 'sqlite_haversine'],
	];

	/**
	 * Class construct, expects a driver configuration
	 * @param array $driver Driver configurtaion
	 */
	public function __construct($name, array $params)
	{
		$driver = (object) [
			'type'     => $name,
			'url'      => null,
			'user'     => null,
			'password' => null,
			'options'  => [],
			'tables_prefix' => '',
		];

		if ($name == 'mysql')
		{
			if (empty($params['database']))
			{
				throw new \BadMethodCallException('No database parameter passed.');
			}

			if (empty($params['host']))
			{
				throw new \BadMethodCallException('No host parameter passed.');
			}

			if (empty($params['user']))
			{
				throw new \BadMethodCallException('No user parameter passed.');
			}

			if (empty($params['password']))
			{
				throw new \BadMethodCallException('No password parameter passed.');
			}

			if (empty($params['charset']))
			{
				$params['charset'] = 'utf8mb4';
			}

			if (empty($params['port']))
			{
				$params['port'] = 3306;
			}

			$driver->url = sprintf('mysql:dbname=%s;charset=%s;host=%s;port=%d', $params['database'], $params['charset'], $params['host'], $params['port']);
			$driver->user = $params['user'];
			$driver->password = $params['password'];
		}
		else if ($name == 'sqlite')
		{
			if (empty($params['file']))
			{
				throw new \BadMethodCallException('No file parameter passed.');
			}

			$driver->url = 'sqlite:' . $params['file'];
		}
		else
		{
			throw new \BadMethodCallException('Invalid driver name.');
		}

		$this->driver = $driver;
	}

	/**
	 * Connect to the currently defined driver if needed
	 * @return void
	 */
	public function connect()
	{
		if ($this->pdo)
		{
			return true;
		}

		try {
			$this->pdo = new PDO($this->driver->url, $this->driver->user, $this->driver->password, $this->driver->options);

			// Set attributes
			foreach ($this->pdo_attributes as $attr => $value)
			{
				$this->pdo->setAttribute($attr, $value);
			}
		}
		catch (PDOException $e)
		{
			// Catch exception to avoid showing password in backtrace
			throw new PDOException('Unable to connect to database. Check username and password.');
		}

		if ($this->driver->type == 'sqlite')
		{
			// Enhance SQLite with default functions
			foreach ($this->sqlite_functions as $name => $callback)
			{
				$this->pdo->sqliteCreateFunction($name, $callback);
			}

			// Force to rollback any outstanding transaction
			register_shutdown_function(function () {
				if ($this->inTransaction())
				{
					$this->rollback();
				}
			});
		}

		$this->driver->password = '******';
	}

	public function close()
	{
		$this->pdo = null;
	}

	protected function applyTablePrefix($statement)
	{
		if (strpos('__PREFIX__', $statement) !== false)
		{
			$statement = preg_replace('/(?<=\s|^)__PREFIX__(?=\w)/', $this->driver->tables_prefix, $statement);
		}

		return $statement;
	}

	public function query($statement)
	{
		$this->connect();
		$statement = $this->applyTablePrefix($statement);
		return $this->pdo->query($statement);
	}

	public function exec($statement)
	{
		$this->connect();
		$statement = $this->applyTablePrefix($statement);
		return $this->pdo->exec($statement);
	}

	public function execMultiple($statement)
	{
		$this->connect();

		$this->begin();

		try
		{
			if ($this->driver->type == 'mysql')
			{
				$emulate = $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
				$this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true); // required to allow multiple queries in same statement

				$st = $this->prepare($statement);
				$st->execute();

				while ($st->nextRowset())
				{
					// Iterate over rowsets, see https://bugs.php.net/bug.php?id=61613 
				}

				$return = $this->commit();
			}
			else
			{
				$return = $this->pdo->exec($statement);
				$this->commit();
			}
		}
		catch (\PDOException $e)
		{
			$this->rollBack();
			throw $e;
		}
		finally
		{
			if ($this->driver->type == 'mysql')
			{
				$this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
			}
		}

		return $return;
	}

	public function createFunction($name, callable $callback)
	{
		if ($this->driver->type != 'sqlite')
		{
			throw new \LogicException('This driver does not support functions.');
		}

		if ($this->pdo)
		{
			return $this->pdo->sqliteCreateFunction($name, $callback);
		}
		else
		{
			$this->sqlite_functions[$name] = $callback;
			return true;
		}
	}

	public function import($file)
	{
		if (!is_readable($file))
		{
			throw new \RuntimeException(sprintf('Cannot read file %s', $file));
		}

		return $this->execMultiple(file_get_contents($file));
	}

	public function prepare($statement, $driver_options = [])
	{
		$this->connect();
		$statement = $this->applyTablePrefix($statement);
		return $this->pdo->prepare($statement, $driver_options);
	}

	public function begin()
	{
		$this->connect();
		return $this->pdo->beginTransaction();
	}

	public function inTransaction()
	{
		$this->connect();
		return $this->pdo->inTransaction();
	}

	public function commit()
	{
		$this->connect();
		return $this->pdo->commit();
	}

	public function rollback()
	{
		$this->connect();
		return $this->pdo->rollBack();
	}

	public function lastInsertId($name = null)
	{
		$this->connect();
		return $this->pdo->lastInsertId($name);
	}

	public function quote($value, $parameter_type = PDO::PARAM_STR)
	{
		if ($this->driver->type == 'sqlite')
		{
			// PHP quote() is truncating strings on NUL bytes
			// https://bugs.php.net/bug.php?id=63419
			
			$value = str_replace("\0", '\\0', $value);
		}

		$this->connect();
		return $this->pdo->quote($value, $parameter_type);
	}


	public function preparedQuery($query, $args = [])
	{
        assert(is_string($query));

        // Only one argument, which is an array: this is an associative array
        if (isset($args[0]) && is_array($args[0]))
        {
        	$args = $args[0];
        }

        assert(is_array($args) || is_object($args));

        $args = (array) $args;

		$st = $this->prepare($query);
		$st->execute($args);

		return $st;
	}

	public function iterate($query)
	{
		$args = array_slice(func_get_args(), 1);
		$st = $this->preparedQuery($query, $args);

		while ($row = $st->fetch())
		{
			yield $row;
		}

		unset($st);

		return;
	}

	public function get($query)
	{
		$args = array_slice(func_get_args(), 1);
		return $this->preparedQuery($query, $args)->fetchAll();
	}

	public function getAssoc($query)
	{
		$args = array_slice(func_get_args(), 1);
		$st = $this->preparedQuery($query, $args);

		while ($row = $st->fetch(PDO::FETCH_NUM))
		{
			$out[$row[0]] = $row[1];
		}

		return $out;
	}

	public function getGrouped($query)
	{
		$args = array_slice(func_get_args(), 1);
		$st = $this->preparedQuery($query, $args);

		while ($row = $st->fetch(PDO::FETCH_ASSOC))
		{
			$out[current($row)] = (object) $row;
		}

		return $out;
	}

	/**
	 * Runs a query and returns the first row
	 * @param  string $query SQL query
	 * @return object
	 *
	 * Accepts one or more arguments as part of bindings for the statement
	 */
	public function first($query)
	{
		$st = $this->preparedQuery($query, array_slice(func_get_args(), 1));

		return $st->fetch();
	}

	/**
	 * Runs a query and returns the first column
	 * @param  string $query SQL query
	 * @return object
	 *
	 * Accepts one or more arguments as part of bindings for the statement
	 */
	public function firstColumn($query)
	{
		$st = $this->preparedQuery($query, array_slice(func_get_args(), 1));

		return $st->fetchColumn();
	}

	/**
	 * Inserts a row in $table, using $fields as data to fill
	 * @param  string $table  Table où insérer
	 * @param  array|object $fields Champs à remplir
	 * @return boolean
	 */
	public function insert($table, $fields)
	{
		assert(is_array($fields) || is_object($fields));

		$fields = (array) $fields;

		$fields_names = array_keys($fields);
		$query = sprintf('INSERT INTO %s (%s) VALUES (:%s);', $table, 
			implode(', ', $fields_names), implode(', :', $fields_names));

		return $this->preparedQuery($query, $fields);
	}

	/**
	 * Updates lines in $table using $fields, selecting using $where
	 * @param  string       $table  Table name
	 * @param  array|object $fields List of fields to update
	 * @param  string       $where  Content of the WHERE clause
	 * @param  array|object $args   Arguments for the WHERE clause
	 * @return boolean
	 */
	public function update($table, $fields, $where = null, $args = [])
	{
		assert(is_string($table));
		assert((is_string($where) && strlen($where)) || is_null($where));
		assert(is_array($fields) || is_object($fields));
		assert(is_array($args) || is_object($args));

		// Forcer en tableau
		$fields = (array) $fields;
		$args = (array) $args;

		// No fields to update? no need to do a query
		if (empty($fields))
		{
			return false;
		}

		$column_updates = [];
		
		foreach ($fields as $key => $value)
		{
			if (is_object($value) && $value instanceof \DateTimeInterface)
			{
				$value = $value->format('Y-m-d H:i:s');
			}

			// Append to arguments
			$args['field_' . $key] = $value;

			$column_updates[] = sprintf('%s = :field_%s', $key, $key);
		}

		if (is_null($where))
		{
			$where = '1';
		}

		// Assemblage de la requête
		$column_updates = implode(', ', $column_updates);
		$query = sprintf('UPDATE %s SET %s WHERE %s;', $table, $column_updates, $where);

		return $this->preparedQuery($query, $args);
	}


	/**
	 * Supprime une ou plusieurs lignes d'une table
	 * @param  string $table Nom de la table
	 * @param  string $where Clause WHERE
	 * @return boolean
	 *
	 * Accepte un ou plusieurs arguments supplémentaires utilisés comme bindings
	 * pour la clause WHERE.
	 */
	public function delete($table, $where = '1')
	{
		$query = sprintf('DELETE FROM %s WHERE %s;', $table, $where);
		return $this->preparedQuery($query, array_slice(func_get_args(), 2));
	}

	public function test($table, $where = '1')
	{
		$args = array_merge(
			[sprintf('SELECT 1 FROM %s WHERE %s LIMIT 1;', $table, $where)],
			array_slice(func_get_args(), 2)
		);

		return (bool) call_user_func_array([$this, 'firstColumn'], $args);
	}

	public function count($table, $where = '1')
	{
		$args = array_merge(
			[sprintf('SELECT COUNT(*) FROM %s WHERE %s LIMIT 1;', $table, $where)],
			array_slice(func_get_args(), 2)
		);

		return (int) call_user_func_array([$this, 'firstColumn'], $args);
	}

	public function where($name)
	{
		$num_args = func_num_args();

		$value = func_get_arg($num_args - 1);

		if (is_object($value) && $value instanceof \DateTimeInterface)
		{
			$value = $value->format('Y-m-d H:i:s');
		}

		if (is_object($value))
		{
			$value = (array) $value;
		}

		if ($num_args == 2)
		{
			if (is_array($value))
			{
				$operator = 'IN';
			}
			elseif (is_null($value))
			{
				$operator = 'IS';
			}
			else
			{
				$operator = '=';
			}
		}
		elseif ($num_args == 3)
		{
			$operator = strtoupper(func_get_arg(1));

			if (is_array($value))
			{
				if ($operator == 'IN' || $operator == '=')
				{
					$operator = 'IN';
				}
				elseif ($operator == 'NOT IN' || $operator == '!=')
				{
					$operator = 'NOT IN';
				}
				else
				{
					throw new \InvalidArgumentException(sprintf('Invalid operator \'%s\' for value of type array or object (only IN and NOT IN are accepted)', $operator));
				}
			}
			elseif (is_null($value))
			{
				if ($operator != '=' && $operator != '!=')
				{
					throw new \InvalidArgumentException(sprintf('Invalid operator \'%s\' for value of type null (only = and != are accepted)', $operator));
				}

				$operator = ($operator == '=') ? 'IS' : 'IS NOT';
			}
		}
		else
		{
			throw new \BadMethodCallException('Method ::where requires 2 or 3 parameters');
		}

		if (is_array($value))
		{
			$value = array_values($value);

			array_walk($value, function (&$row) {
				$row = $this->quote($row);
			});

			$value = sprintf('(%s)', implode(', ', $value));
		}
		elseif (is_null($value))
		{
			$value = 'NULL';
		}
		elseif (is_bool($value))
		{
			$value = $value ? 'TRUE' : 'FALSE';
		}
		elseif (is_string($value))
		{
			$value = $this->quote($value);
		}

		return sprintf('%s %s %s', $name, $operator, $value);
	}
	/**
	 * SQLite search ranking user defined function
	 * Converted from C from SQLite manual: https://www.sqlite.org/fts3.html#appendix_a
	 * @param  string $aMatchInfo
	 * @return double Score
	 */
	static public function sqlite_rank($aMatchInfo)
	{
		$iSize = 4; // byte size
		$iPhrase = (int) 0;                 // Current phrase //
		$score = (double)0.0;               // Value to return //

		/* Check that the number of arguments passed to this function is correct.
		** If not, jump to wrong_number_args. Set aMatchinfo to point to the array
		** of unsigned integer values returned by FTS function matchinfo. Set
		** nPhrase to contain the number of reportable phrases in the users full-text
		** query, and nCol to the number of columns in the table.
		*/
		$aMatchInfo = (string) func_get_arg(0);
		$nPhrase = ord(substr($aMatchInfo, 0, $iSize));
		$nCol = ord(substr($aMatchInfo, $iSize, $iSize));

		if (func_num_args() > (1 + $nCol))
		{
			throw new \Exception("Invalid number of arguments : ".$nCol);
		}

		// Iterate through each phrase in the users query. //
		for ($iPhrase = 0; $iPhrase < $nPhrase; $iPhrase++)
		{
			$iCol = (int) 0; // Current column //

			/* Now iterate through each column in the users query. For each column,
			** increment the relevancy score by:
			**
			**   (<hit count> / <global hit count>) * <column weight>
			**
			** aPhraseinfo[] points to the start of the data for phrase iPhrase. So
			** the hit count and global hit counts for each column are found in
			** aPhraseinfo[iCol*3] and aPhraseinfo[iCol*3+1], respectively.
			*/
			$aPhraseinfo = substr($aMatchInfo, (2 + $iPhrase * $nCol * 3) * $iSize);

			for ($iCol = 0; $iCol < $nCol; $iCol++)
			{
				$nHitCount = ord(substr($aPhraseinfo, 3 * $iCol * $iSize, $iSize));
				$nGlobalHitCount = ord(substr($aPhraseinfo, (3 * $iCol + 1) * $iSize, $iSize));
				$weight = ($iCol < func_num_args() - 1) ? (double) func_get_arg($iCol + 1) : 0;

				if ($nHitCount > 0 && $nGlobalHitCount != 0)
				{
					$score += ((double)$nHitCount / (double)$nGlobalHitCount) * $weight;
				}
			}
		}

		return $score;
	}

	/**
	 * Haversine distance between two points
	 * @return double Distance in kilometres
	 */
	static public function sqlite_haversine()
	{
		if (count($geo = array_map('deg2rad', array_filter(func_get_args(), 'is_numeric'))) != 4)
		{
			throw new \InvalidArgumentException('4 arguments expected for haversine_distance');
		}
		
		return round(acos(sin($geo[0]) * sin($geo[2]) + cos($geo[0]) * cos($geo[2]) * cos($geo[1] - $geo[3])) * 6372.8, 3);
	}
}
