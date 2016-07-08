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

/**
 * Log: a generic logging library for legal and user action logs
 *
 * @author  bohwaz  http://bohwaz.net/
 * @license BSD
 * @version 0.1
 */

namespace KD2;

use \KD2\DB;

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
	const DEFAULT_BAN_EXPIRY = 31536000;

	/**
	 * Default anonymisation delay for removing IP address (1 year)
	 */
	const DEFAULT_ANONYMIZE_DELAY = 31536000;

	protected $banned_ips = [];

	protected $banned_emails = [];

	protected $ban_cookie_name = 'userSessionID2';
	protected $ban_cookie_value = '59bcc3ad6775562f845953cf01624225';

	protected $remote_addr_server_key = 'REMOTE_ADDR';

	protected $db = null;

	public function __construct(DB $db)
	{
		$this->db = $db;
	}

	public function createTables($driver)
	{
		$this->db->exec('
			CREATE TABLE user_actions_log (
				id INTEGER UNSIGNED NOT NULL PRIMARY KEY auto_increment,
				date INTEGER UNSIGNED NOT NULL,
				action TINYINT NOT NULL,
				success TINYINT NULL,
				details VARCHAR(255) NULL,
				ip VARCHAR(255) NULL,
				user_id INTEGER UNSIGNED NULL,
				content_id INTEGER UNSIGNED NULL
			);

			CREATE INDEX user_actions_log_ip_action ON user_actions_log (ip, action, success);
			CREATE INDEX user_actions_log_user_action ON user_actions_log (user_id, action, success);

			CREATE TABLE user_actions_bans (
				id INTEGER UNSIGNED NOT NULL PRIMARY KEY auto_increment,
				details VARCHAR(255) NULL,
				expiry INTEGER UNSIGNED NULL,
				user_id INTEGER UNSIGNED NULL,
				email VARCHAR(255) NULL,
				ip TEXT NULL,
				shadow_ban TINYINT UNSIGNED NOT NULL DEFAULT 0
			);

			CREATE INDEX user_actions_bans_ip ON user_actions_bans (ip, expiry);
			CREATE INDEX user_actions_bans_user ON user_actions_bans (user_id, expiry);
			CREATE INDEX user_actions_bans_email ON user_actions_bans (email, expiry);
			');
	}

	public function register($action, $success = null, $user_id = null, $content_type = null, $content_id = null)
	{

	}

	public function listByUser($id)
	{

	}

	public function listByIP($ip)
	{
		
	}

	public function listByContentID($id)
	{

	}

	public function listBans($expired = false)
	{

	}

	public function banIP($ip, $expiry = self::DEFAULT_BAN_EXPIRY, $details = null, $shadow = false)
	{

	}

	public function banUser($id, $email = null, $expiry = self::DEFAULT_BAN_EXPIRY, $details = null, $shadow = false)
	{

	}

	public function banEmail($email, $expiry = self::DEFAULT_BAN_EXPIRY, $details = null, $shadow = false)
	{

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

		// fixme
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

	public function isEmailBanned($email)
	{

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

}