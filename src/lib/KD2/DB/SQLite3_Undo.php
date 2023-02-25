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
 * DB_SQLite3_Undo: adds the ability to undo/redo any SQL statement to a SQLite3 database
 *
 * @author  bohwaz http://bohwaz.net/
 * @license AGPLv3
 */

namespace KD2\DB;

use PDO;

class SQLite3_Undo
{
	protected $db;

	public function __construct(DB $db)
	{
		$this->db = $db;
	}

	public function disable(array $tables)
	{
		$db = $this->db;

		foreach ($tables as $name) {
			$sql = 'SELECT name, name FROM sqlite_master WHERE type = \'trigger\' AND name LIKE \'!_%s_log!__t\' ESCAPE \'!\';';
			$sql = sprintf($sql, $name);
			$triggers = $db->getAssoc($sql);

			foreach ($triggers as $trigger)
			{
				$db->exec(sprintf('DROP TRIGGER %s;', $db->quoteIdentifier($trigger)));
			}
		}
	}

	public function enable(array $tables)
	{
		$db = $this->db;

		$db->exec('CREATE TABLE IF NOT EXISTS undolog (
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
			$columns = $db->getAssoc(sprintf('PRAGMA table_info(%s);', $this->quoteIdentifier($table)));
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

			$db->exec($sql);
		}
	}
}
