<?php

namespace KD2;

class DB extends \PDO
{
	static public function getDriver($name, $params = [])
	{
		$driver = [
			'url'	=>	null,
			'user'	=>	null,
			'password'	=>	null
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
				$params['charset'] = 'UTF8';
			}

			$driver['url'] = 'mysql:dbname='.$params['database'].';charset=UTF8;host='.$params['host'];
			$driver['user'] = $params['user'];
			$driver['password'] = $params['password'];
		}
		else if ($name == 'sqlite')
		{
			if (empty($params['file']))
			{
				throw new \BadMethodCallException('No file parameter passed.');
			}

			$driver['url'] = 'sqlite:' . $params['file'];
		}
		else
		{
			throw new \BadMethodCallException('Invalid driver name.');
		}

		return $driver;
	}

	public function __construct($driver)
	{
		if (empty($driver['url']))
		{
			throw new \LogicException('No PDO driver is set.');
		}

		parent::__construct($driver['url'], $driver['user'], $driver['password']);
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

	public function simpleQueryFetch($query, $fetchMode = self::FETCH_ASSOC)
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

		return $this->fetch($this->simpleQuery($query, $args), $fetchMode);
	}

	public function fetch($result)
	{
		$rows = [];

		while ($row = $result->fetch($fetchMode))
		{
			$rows[] = $row;
		}

		return $rows;
	}
}
