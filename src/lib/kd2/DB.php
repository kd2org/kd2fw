<?php

namespace KD2;

class DB extends \PDO
{
	static protected $driver = null;
	static protected $user = null;
	static protected $password = null;

	static public function setDriver($name, $params = [])
	{
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
				$params['charset'] = 'UTF8';
			}

			self::$driver = 'mysql:dbname='.$params['database'].';charset=UTF8;host='.$params['host'];
			self::$user = $params['user'];
			self::$password = $params['password'];
		}
		else if ($name == 'sqlite')
		{
			if (empty($params['file']))
			{
				throw new \BadMethodCallException('No file parameter passed.');
			}

			self::$driver = 'sqlite:' . $params['file'];
		}
	}

	public function __construct()
	{
		if (empty(self::$driver))
		{
			throw new \LogicException('No PDO driver is set.');
		}

		parent::__construct(self::$driver, self::$user, self::$password);
        $this->setAttribute(self::ATTR_ERRMODE, self::ERRMODE_EXCEPTION);
	}

	public function simpleQuerySingle($query, $all_columns = false)
	{
		if (func_num_args() < 3)
		{
			$args = [];
		}
		else if (func_num_args() == 3 && is_array(func_get_arg(2)))
		{
			$args = func_get_arg(2);
		}
		else
		{
			$args = array_slice(func_get_args(), 2);
		}

		$st = $this->prepare($query);
		
		if (!$st->execute($args))
		{
			return false;
		}

		$row = $st->fetch(self::FETCH_ASSOC);

		if ($all_columns)
		{
			return $row;
		}
		else
		{
			return (is_array($row) && count($row) >= 1) ? current($row) : $row;
		}
	}

	public function querySingle($query, $all_columns = false)
	{
		return $this->simpleQuerySingle($query, $all_columns);
	}

	public function simpleInsert($table, $fields)
	{
		$query = 'INSERT INTO ' . $table . ' (' . implode(', ', array_keys($fields)) 
			. ') VALUES (:' . implode(', :', array_keys($fields)) . ');';

		foreach ($fields as $key=>$value)
		{
			if ($value == 'NOW()')
			{
				$query = str_replace(':' . $key, $value, $query);
				unset($fields[$key]);
			}
		}
		
		$st = $this->prepare($query);
		return $st->execute($fields);
	}

	public function simpleUpdate($table, $fields, $where)
	{
		$query = 'UPDATE ' . $table . ' SET ';

		foreach ($fields as $key=>$value)
		{
			if ($value == 'NOW()')
			{
				$query .= $key . ' = ' . $value . ', ';
				unset($fields[$key]);
			}
			else
			{
				$query .= $key . ' = :' . $key . ', ';
			}
		}

		$query = substr($query, 0, -2) . ' WHERE ' . $where;
		
		$st = $this->prepare($query);
		return $st->execute($fields);
	}

	public function simpleQuery($query)
	{
		if (func_num_args() == 1)
		{
			$args = [];
		}
		else if (func_num_args() == 2 && is_array(func_get_arg(1)))
		{
			$args = func_get_arg(1);
		}
		else
		{
			$args = array_slice(func_get_args(), 1);
		}

		$st = $this->prepare($query);
		return $st->execute($args) ? $st : false;
	}
}
