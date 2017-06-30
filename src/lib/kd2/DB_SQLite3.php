<?php

namespace KD2;

use SQLite3;
use PDO;

class DB_SQLite3 extends DB
{
	/**
	 * @var SQLite3
	 */
	protected $db;

	/**
	 * @var int
	 */
	protected $flags;

	/**
	 * @var string
	 */
	protected $file;

	/**
	 * @var boolean
	 */
	protected $transaction = false;

	const DATE_FORMAT = 'Y-m-d H:i:s';

	public function __construct($file, $flags = SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE)
	{
		$this->file = $file;
		$this->flags = $flags;
	}

	public function close()
	{
		$this->db->close();
		$this->db = null;
	}

	public function connect()
	{
		if ($this->db)
		{
			return true;
		}

		$this->db = new \SQLite3($this->file, $this->flags);

		$this->db->enableExceptions(true);

		$this->db->busyTimeout($this->pdo_attributes[PDO::ATTR_TIMEOUT] * 1000);

		foreach ($this->sqlite_functions as $name => $callback)
		{
			$this->db->createFunction($name, $callback);
		}

		// Force to rollback any outstanding transaction
		register_shutdown_function(function () {
			if ($this->inTransaction())
			{
				$this->rollback();
			}
		});
	}

	public function escapeString($str)
	{
		// escapeString n'est pas binary safe: https://bugs.php.net/bug.php?id=62361
		$str = str_replace("\0", "\\0", $str);

		return SQLite3::escapeString($str);
	}

	public function quote($str, $parameter_type = null)
	{
		return '\'' . $this->escapeString($str) . '\'';
	}

	public function begin()
	{
		if ($this->transaction)
		{
			throw new \Exception('A transaction is already running.');
		}

		$this->transaction = true;
		$this->connect();
		return $this->db->exec('BEGIN;');
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

	public function getArgType(&$arg, $name = '')
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
					$arg->setTimezone(new \DateTimezone('UTC'));
					$arg = $arg->format(self::DATE_FORMAT);
					return \SQLITE3_TEXT;
				}
			default:
				throw new \InvalidArgumentException('Argument '.$name.' is of invalid type '.gettype($arg));
		}
	}

	/**
	 * Performe une requête en utilisant les arguments contenus dans le tableau $args
	 * @param  string       $query Requête SQL
	 * @param  array|object $args  Arguments à utiliser comme bindings pour la requête
	 * @return \SQLite3Statement|boolean Retourne un booléen si c'est une requête 
	 * qui exécute une opération d'écriture, ou un statement si c'est une requête de lecture.
	 *
	 * Note: le fait que cette fonction retourne un booléen est un comportement
	 * volontaire pour éviter un bug dans le module SQLite3 de PHP, qui provoque
	 * un risque de faire des opérations en double en cas d'exécution de 
	 * ->fetchResult() sur un statement d'écriture.
	 */
	public function preparedQuery($query, $args = [])
	{
		assert(is_string($query));
		assert(is_array($args) || is_object($args));
		
		// Forcer en tableau
		$args = (array) $args;

		$this->connect();
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

	public function iterate($query)
	{
		$args = array_slice(func_get_args(), 1);
		$res = $this->preparedQuery($query, $args);

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			yield (object) $row;
		}

		unset($res);

		return;
	}

	public function get($query)
	{
		$args = array_slice(func_get_args(), 1);
		$res = $this->preparedQuery($query, $args);

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			$out[] = (object) $row;
		}

		return $out;
	}

	public function getAssoc($query)
	{
		$args = array_slice(func_get_args(), 1);
		$res = $this->preparedQuery($query, $args);

		while ($row = $res->fetchArray(\SQLITE3_NUM))
		{
			$out[$row[0]] = $row[1];
		}

		return $out;
	}

	public function getGrouped($query)
	{
		$args = array_slice(func_get_args(), 1);
		$res = $this->preparedQuery($query, $args);

		while ($row = $res->fetchArray(\SQLITE3_ASSOC))
		{
			$out[current($row)] = (object) $row;
		}

		return $out;
	}

	/**
	 * Exécute une requête SQL (alias pour query)
	 * @param  string $query Requête SQL
	 * @return boolean
	 *
	 * N'accepte PAS d'arguments supplémentaires
	 */
	public function exec($query)
	{
		$this->begin();

		try {
			$this->db->exec($query);
		}
		catch (\Exception $e)
		{
			$this->rollback();
			throw $e;
		}

		return $this->commit();
	}

	/**
	 * Exécute une requête et retourne la première ligne
	 * @param  string $query Requête SQL
	 * @return object
	 *
	 * Accepte un ou plusieurs arguments supplémentaires utilisés comme bindings.
	 */
	public function first($query)
	{
		$res = $this->preparedQuery($query, array_slice(func_get_args(), 1));

		$row = $res->fetchArray(SQLITE3_ASSOC);
		$res->finalize();

		return is_array($row) ? (object) $row : false;
	}

	/**
	 * Exécute une requête et retourne la première colonne de la première ligne
	 * @param  string $query Requête SQL
	 * @return object
	 *
	 * Accepte un ou plusieurs arguments supplémentaires utilisés comme bindings.
	 */
	public function firstColumn($query)
	{
		$res = $this->preparedQuery($query, array_slice(func_get_args(), 1));

		$row = $res->fetchArray(\SQLITE3_NUM);

		return count($row) > 0 ? $row[0] : false;
	}

	/**
	 * Compte le nombre de lignes dans un résultat
	 * @param  \SQLite3Result $result Résultat SQLite3
	 * @return integer
	 */
	public function countRows(\SQLite3Result $result)
	{
		$i = 0;

		while ($result->fetchArray(\SQLITE3_NUM))
		{
			$i++;
		}

		$result->reset();

		return $i;
	}

	public function lastInsertId($name = null)
	{
		return $this->db->lastInsertRowId();
	}

	/**
	 * Préparer un statement SQLite3
	 * @param  string $query Requête SQL
	 * @return \SQLite3Statement
	 */
	public function prepare($query, $driver_options = [])
	{
		return $this->db->prepare($query);
	}

	public function openBlob($table, $column, $rowid)
	{
		return $this->db->openBlob($table, $column, $rowid);
	}
}
