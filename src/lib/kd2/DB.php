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

class DB extends \PDO
{
	/**
	 * Default fetch mode
	 * @var integer
	 */
	public $default_fetch_mode = \PDO::FETCH_ASSOC;

	static public function getDriver($name, $params = [])
	{
		$driver = [
			'type'  => 	$name,
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

		// Enhance SQLite default
		if ($driver['type'] == 'sqlite')
		{
			$this->sqliteCreateFunction('rank', [$this, 'sqlite_rank']);
		}
	}

	/**
	 * SQLite search ranking user defined function
	 * Converted from C from SQLite manual: https://www.sqlite.org/fts3.html#appendix_a
	 * @param  string $aMatchInfo
	 * @return double Score
	 */
	/*
	** SQLite user defined function to use with matchinfo() to calculate the
	** relevancy of an FTS match. The value returned is the relevancy score
	** (a real value greater than or equal to zero). A larger value indicates 
	** a more relevant document.
	**
	** The overall relevancy returned is the sum of the relevancies of each 
	** column value in the FTS table. The relevancy of a column value is the
	** sum of the following for each reportable phrase in the FTS query:
	**
	**   (<hit count> / <global hit count>) * <column weight>
	**
	** where <hit count> is the number of instances of the phrase in the
	** column value of the current row and <global hit count> is the number
	** of instances of the phrase in the same column of all rows in the FTS
	** table. The <column weight> is a weighting factor assigned to each
	** column by the caller (see below).
	**
	** The first argument to this function must be the return value of the FTS 
	** matchinfo() function. Following this must be one argument for each column 
	** of the FTS table containing a numeric weight factor for the corresponding 
	** column. Example:
	**
	**     CREATE VIRTUAL TABLE documents USING fts3(title, content)
	**
	** The following query returns the docids of documents that match the full-text
	** query <query> sorted from most to least relevant. When calculating
	** relevance, query term instances in the 'title' column are given twice the
	** weighting of those in the 'content' column.
	**
	**     SELECT docid FROM documents 
	**     WHERE documents MATCH <query> 
	**     ORDER BY rank(matchinfo(documents), 1.0, 0.5) DESC
	 */
	public function sqlite_rank($aMatchInfo)
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
	 * Returns a MySQL DATETIME formatted string for insertion/update from a timestamp
	 * Note that DATETIME stores in local time, not UTC, and this function returns local time,
	 * unless $utc parameter is set to true
	 * @param  integer $timestamp UNIX timestamp
	 * @return string             Mysql DATETIME formatted string
	 */
	public function mysqlDateTime($timestamp, $utc = false)
	{
		if ($utc)
		{
			return gmdate('Y-m-d H:i:s', $timestamp);
		}
		else
		{
			return date('Y-m-d H:i:s', $timestamp);
		}
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

		if ($all_columns)
		{
			return $st->fetch($this->default_fetch_mode);
		}
		else
		{
			return $st->fetchColumn();
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

	public function simpleQueryFetch($query, $fetch_mode = null)
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

		return $this->fetch($this->simpleQuery($query, $args), $fetch_mode);
	}

	public function fetch($result, $fetch_mode = null)
	{
		if (is_null($fetch_mode))
		{
			$fetch_mode = $this->default_fetch_mode;
		}

		$rows = [];

		while ($row = $result->fetch($fetch_mode))
		{
			$rows[] = $row;
		}

		return $rows;
	}
}
