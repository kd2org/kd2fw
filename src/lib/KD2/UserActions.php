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
 * Log: a generic logging library for legal and user action logs
 *
 * @author  bohwaz http://bohwaz.net/
 */

namespace KD2;

use KD2\DB;

class UserActions
{
	const ACTION_LOGIN = 1;
	const ACTION_LOGOUT = 2;
	const ACTION_REGISTER = 3;
	const ACTION_REMIND_PASSWORD = 4;
	const ACTION_LEAVE = 5;
	const ACTION_CHANGE_PASSWORD = 6;
	const ACTION_CHANGE_EMAIL = 7;
	const ACTION_CHANGE_LOGIN = 8;

	const ACTION_CREATE_CONTENT = 20;
	const ACTION_EDIT_CONTENT = 21;
	const ACTION_DELETE_CONTENT = 22;
	const ACTION_ACCESS_CONTENT = 23;

	const ACTION_SEND_MESSAGE = 30;

	/**
	 * Default ban expiry (1 year)
	 */
	const DEFAULT_BAN_EXPIRY = 366 * 24 * 60 * 60;

	/**
	 * Default anonymisation delay for removing IP address (1 year)
	 */
	const DEFAULT_ANONYMIZE_DELAY = 366 * 24 * 60 * 60;

	protected $banned_ips;
	protected $banned_emails;

	public $ban_cookie_name = 'userSessionID2';
	public $ban_cookie_secret = '59bcc3ad6775562f845953cf01624225';

	protected $remote_addr_server_key = 'REMOTE_ADDR';

	protected $db = null;

	public function __construct(DB $db)
	{
		$this->db = $db;
	}

	public function createTables()
	{
		$this->db->exec('
			CREATE TABLE __PREFIX__user_actions_log (
				id INTEGER UNSIGNED NOT NULL PRIMARY KEY auto_increment,
				date INTEGER UNSIGNED NOT NULL,
				ip BINARY(16) NULL,
				action TINYINT UNSIGNED NOT NULL,
				success TINYINT UNSIGNED NULL,
				user_id VARCHAR(255) UNSIGNED NULL,
				content_type VARCHAR(255) NULL,
				content_id VARCHAR(255) NULL
			);

			CREATE INDEX __PREFIX__user_actions_log_ip_action ON __PREFIX__user_actions_log (ip, action, success);
			CREATE INDEX __PREFIX__user_actions_log_user_action ON __PREFIX__user_actions_log (user_id, action, success);

			CREATE TABLE __PREFIX__user_actions_bans (
				id INTEGER UNSIGNED NOT NULL PRIMARY KEY auto_increment,
				details VARCHAR(255) NULL,
				expiry INTEGER UNSIGNED NULL,
				user_id VARCHAR(255) UNSIGNED NULL,
				email VARCHAR(255) NULL,
				ip BINARY(16) NULL,
				shadow_ban TINYINT UNSIGNED NOT NULL DEFAULT 0
			);

			CREATE INDEX __PREFIX__user_actions_bans_ip ON __PREFIX__user_actions_bans (ip, expiry);
			CREATE INDEX __PREFIX__user_actions_bans_user ON __PREFIX__user_actions_bans (user_id, expiry);
			CREATE INDEX __PREFIX__user_actions_bans_email ON __PREFIX__user_actions_bans (email, expiry);
			');
	}

	public function register($action, $success = null, $user_id = null, $content_type = null, $content_id = null)
	{
		return $this->db->insert('__PREFIX__user_actions_log', [
			'action'       => (int) $action,
			'date'         => time(),
			'success'      => is_null($success) ? null : (int) $success,
			'user_id'      => $user_id,
			'ip'           => inet_pton($this->getIP(true)),
			'content_type' => $content_type,
			'content_id'   => $content_id,
		]);
	}

	public function listByUser($id)
	{
		return $this->getList([$this->db->where('user_id', $id)]);
	}

	public function listByIP($ip)
	{
		return $this->getList([$this->db->where('ip', inet_pton($ip))]);
	}

	public function listByContent($type = null, $id = null)
	{
		$where = [];

		if ($type)
		{
			$where[] = $this->db->where('content_type', $type);
		}

		if ($id)
		{
			$where[] = $this->db->where('content_id', $id);
		}

		if (!count($where))
		{
			throw new \BadMethodCallException('Either type or ID arguments are required');
		}

		return $this->getList($where);
	}

	protected function getList(array $where)
	{
		return $this->db->get(
			sprintf('SELECT * FROM __PREFIX__user_actions_log WHERE %s ORDER BY date DESC LIMIT 500;',
				implode(' AND ', $where)
			)
		);
	}

	public function listBans($expired = false)
	{
		$where = $this->db->where('expiry', $expired ? '<=' : '>', time());
		return $this->db->get(sprintf('SELECT * FROM __PREFIX__user_actions_bans WHERE %s ORDER BY date DESC LIMIT 500;', $where));
	}

	public function listBannedIPs()
	{
		if (is_null($this->banned_ips))
		{
			$this->banned_ips = $this->db->getAssoc('SELECT ip, expiry FROM __PREFIX__user_actions_bans WHERE ip IS NOT NULL AND expiry > ?;', time());
			$this->banned_ips = array_map('inet_ntop', array_flip($this->banned_ips));
			$this->banned_ips = array_flip($this->banned_ips);
		}

		return $this->banned_ips;
	}

	public function banIP($ip, $expiry = self::DEFAULT_BAN_EXPIRY, $details = null, $shadow = false)
	{
		return $this->db->insert('__PREFIX__user_actions_bans', [
			'ip'      => $ip,
			'details' => $details,
			'expiry'  => time() + $expiry,
			'shadow'  => (int) $shadow,
		]);
	}

	public function banUser($id, $email = null, $expiry = self::DEFAULT_BAN_EXPIRY, $details = null, $shadow = false)
	{
		return $this->db->insert('__PREFIX__user_actions_bans', [
			'id'      => $id,
			'email'   => $email,
			'details' => $details,
			'expiry'  => time() + $expiry,
			'shadow'  => (int) $shadow,
		]);
	}

	public function banEmail($email, $expiry = self::DEFAULT_BAN_EXPIRY, $details = null, $shadow = false)
	{
		return $this->db->insert('__PREFIX__user_actions_bans', [
			'email'   => $email,
			'details' => $details,
			'expiry'  => time() + $expiry,
			'shadow'  => (int) $shadow,
		]);
	}

	public function isEmailBanned($email)
	{
		return $this->db->firstColumn('SELECT expiry FROM __PREFIX__user_actions_bans WHERE email = ? AND expiry > ?;', $email, time());
	}

	public function isUserBanned($user_id)
	{
		return $this->db->firstColumn('SELECT expiry FROM __PREFIX__user_actions_bans WHERE user_id = ? AND expiry > ?;', $user_id, time());
	}

	public function isIPBanned($ip = null)
	{
		if (is_null($ip))
		{
			foreach ($this->getIp(false) as $ip)
			{
				if ($this->isIPBanned($ip))
					return true;
			}

			return false;
		}

		return self::matchIP($ip, $this->listBannedIPs());
	}

	public function getIP($short = true)
	{
		if ($short)
		{
			return $_SERVER[$this->remote_addr_server_key];
		}

		$list = [];

		$headers = ['REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'];

		foreach ($headers as $header)
		{
			if (!empty($_SERVER[$header]) && filter_var($_SERVER[$header], FILTER_VALIDATE_IP))
			{
				$list[] = $_SERVER[$header];
			}
		}

		return $list;
	}

	public function setBanCookie($type, $age = self::DEFAULT_BAN_EXPIRY)
	{
		return setcookie($this->ban_cookie_name, sha1($this->ban_cookie_secret . $type), time() + $age, '/');
	}

	public function isBanned($user_id = null, $email = null)
	{
		if (isset($_COOKIE[$this->ban_cookie_name]))
		{
			return true;
		}

		if ($this->isIPBanned())
		{
			$this->setBanCookie('ip');
			return true;
		}

		if ($user_id && $this->isUserBanned($user_id))
		{
			$this->setBanCookie('id');
			return true;
		}

		if (isset($email) && trim($email) !== '' && $this->isEmailBanned($email))
		{
			$this->setBanCookie('email');
			return true;
		}

		return false;
	}

	public function isFlooding($action = null, $max_actions = 10, $time = 60)
	{
		$action = $action ? $this->db->where('action', $action) : 1;
		$ip = $this->db->where('ip', 'IN', $this->getIP());

		$query = sprintf('SELECT COUNT(*) > ? FROM user_actions_log
			WHERE %s AND date > ? AND (%s);', $action, $ip);

		return $this->db->test($query, $max_actions, time() - $time);
	}

	/**
	 * Creates random errors and problems for hell-banned users and IPs
	 * @return	void
	 * @link	https://www.drupal.org/project/misery Inspiration
	 * @link	https://blog.codinghorror.com/suspension-ban-or-hellban/
	 */
	public function randomHellBan()
	{
		if ((mt_rand() % 15) == 0)
		{
			header('HTTP/1.1 404 Not Found', true, 404);
			echo '<h1>Not Found</h1><p>The requested URL was not found on this server.</p>';
			exit;
		}
		else if ((mt_rand() % 10) == 0)
		{
			header('HTTP/1.1 500 Internal Server Error', true, 500);
			echo '<h1>Internal Server Error</h1><p>The server encountered an internal error or misconfiguration and was unable to complete your request.</p>';
			exit;
		}
		// Empty page
		else if ((mt_rand() % 3) == 0)
		{
			exit;
		}
		// Remove POST data and session data (= forced logout), as well as cookies (but not the ban cookie)
		else if ((mt_rand() % 5) == 0)
		{
			$_POST = [];
			$_SESSION = [];
			
			if (!empty($_COOKIE) && !headers_sent())
			{
				foreach ($_COOKIE as $name=>$value)
				{
					if ($name == $this->ban_cookie_name)
						continue;

					setcookie($name, '', 0, '/');
				}
			}
		}
	}

	/**
	 * Delete old records from the logs
	 * @param  integer $age Expiry delay after which rows should be deleted (in seconds)
	 * @return boolean
	 */
	public function purge($age)
	{
		$expiry = time() - $age;

		$st = $this->db->prepare('DELETE FROM user_actions_log WHERE date < :expiry;');
		$st->bindValue(':expiry', (int)$expiry);
		return $st->execute();
	}

	/**
	 * Anonymize old records from the logs (remove the IP address)
	 *
	 * Useful to comply with local regulations that require to delete or anomymize private data
	 * after X delay.
	 *
	 * In France this delay is one year.
	 * 
	 * @param  integer $age Expiry delay after which rows should be anonymised (in seconds, default is one year)
	 * @return boolean
	 */
	public function anonymize($age = self::DEFAULT_ANONYMIZE_DELAY)
	{
		$expiry = time() - $age;

		$st = $this->db->prepare('UPDATE user_actions_log SET ip = NULL WHERE date < :expiry;');
		$st->bindValue(':expiry', (int)$expiry);
		return $st->execute();
	}

	/**
	 * Matches an IP address against a list of IP address range
	 *
	 * Returns true if $ip matches one IP address or range given in $check array
	 *
	 * Supports IPv6 and IPv4 addresses, as well as wildcards and netmasks.
	 *
	 * Examples:
	 * - matchIP('192.168.1.102', array('192.168.1.*'))
	 * - matchIP('2a01:e34:ee89:c060:503f:d19b:b8fa:32fd', array('2a01::*'))
	 * - matchIP('2a01:e34:ee89:c060:503f:d19b:b8fa:32fd', array('2a01:e34:ee89:c06::/64'))
	 *
	 * @param string $ip IP address (v6 or v4)
	 * @param array $check List of IP addresses or ranges to match against
	 * @return bool TRUE if $ip matches one of the $check address or range, FALSE if no match is made
	 */
	static public function matchIP($ip, array $check)
	{
		if (!filter_var($ip, FILTER_VALIDATE_IP))
		{
			throw new \InvalidArgumentException('Invalid IP address: ' . $ip);
		}

		$check = array_keys($check);

		if (strpos($ip, ':') === false)
		{
			$ipv6 = false;
			$ip = ip2long($ip);
		}
		else
		{
			$ipv6 = true;
			$ip = bin2hex(inet_pton($ip));
		}

		foreach ($check as $c)
		{
			if (strpos($c, ':') === false)
			{
				if ($ipv6)
				{
					continue;
				}

				// Check against mask
				if (strpos($c, '/') !== false)
				{
					list($c, $mask) = explode('/', $c);
					$c = ip2long($c);
					$mask = ~((1 << (32 - $mask)) - 1);

					if (($ip & $mask) == $c)
					{
						return $c;
					}
				}
				elseif (strpos($c, '*') !== false)
				{
					$c = substr($c, 0, -1);
					$mask = substr_count($c, '.');
					$c .= '0' . str_repeat('.0', (3 - $mask));
					$c = ip2long($c);
					$mask = ~((1 << (32 - ($mask * 8))) - 1);

					if (($ip & $mask) == $c)
					{
						return $c;
					}
				}
				else
				{
					if ($ip == ip2long($c))
					{
						return $c;
					}
				}
			}
			else
			{
				if (!$ipv6)
				{
					continue;
				}

				// Check against mask
				if (strpos($c, '/') !== false)
				{
					list($c, $mask) = explode('/', $c);
					$c = bin2hex(inet_pton($c));
					$mask = $mask / 4;
					$c = substr($c, 0, $mask);

					if (substr($ip, 0, $mask) == $c)
					{
						return $c;
					}
				}
				elseif (strpos($c, '*') !== false)
				{
					$c = substr($c, 0, -1);
					$c = bin2hex(inet_pton($c));
					$c = rtrim($c, '0');

					if (substr($ip, 0, strlen($c)) == $c)
					{
						return $c;
					}
				}
				else
				{
					if ($ip == inet_pton($c))
					{
						return $c;
					}
				}
			}
		}

		return false;
	}
}