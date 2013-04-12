<?php
namespace KD2;

/*
	Simple SMTP library for PHP
	Copyright 2012 BohwaZ <http://bohwaz.net/>

	This program is free software: you can redistribute it and/or modify
	it under the terms of the GNU Lesser General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU Lesser General Public License
	along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

class SMTP_Exception extends \Exception {}

class SMTP
{
	const NONE = 0;
	const TLS = 1;
	const SSL = 2;

	const EOL = "\r\n";

	protected $server;
	protected $port;
	protected $username = null;
	protected $password = null;
	protected $secure = 0;

	protected $conn = null;
	protected $last_line = null;

	protected $servername = 'localhost';

	public $timeout = 30;

	protected function _read()
	{
		$data = '';

		while ($str = fgets($this->conn, 4096))
		{
			$data .= $str;

			if ($str[3] == ' ')
			{
				break;
			}
		}

		return $data;
	}

	protected function _readCode($data = null)
	{
		if (is_null($data))
		{
			$data = $this->_read();
			$this->last_line = $data;
		}

		return substr($data, 0, 3);
	}

	protected function _write($data, $eol = true)
	{
		fputs($this->conn, $data . ($eol ? self::EOL : ''));
	}

	public function __construct($server, $port, $username = null, $password = null, $secure = self::NONE)
	{
		$this->server = $secure == self::SSL ? 'ssl://' . $server : $server;
		$this->port = $port;
		$this->username = $username;
		$this->password = $password;
		$this->secure = (int)$secure;
		$this->servername = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : gethostname();
	}

	public function __destruct()
	{
		$this->disconnect();
	}

	public function disconnect()
	{
		$this->_write('QUIT');
		$this->_read();
		fclose($this->conn);
		$this->conn = null;
		$this->last_line = null;
		return true;
	}

	public function connect()
	{
		$this->conn = fsockopen($this->server, $this->port, $errno, $errstr, $this->timeout);

		if (!$this->conn)
		{
			throw new SMTP_Exception('Unable to connect to server ' . $this->server . ': ' . $errno . ' - ' . $errstr);
		}

		if ($this->_readCode() != 220)
		{
			throw new SMTP_Exception('SMTP error: '.$this->last_line);
		}

		return true;
	}

	public function authenticate()
	{
		$this->_write('HELO '.$this->servername);
		$this->_read();

		if ($this->secure == self::TLS)
		{
			$this->_write('STARTTLS');

			if ($this->_readCode() != 220)
			{
				throw new SMTP_Exception('Can\'t start TLS session: '.$this->last_line);
			}

			stream_socket_enable_crypto($this->conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

			$this->_write('HELO ' . $this->servername);

			if ($this->_readCode() != 250)
			{
				throw new SMTP_Exception('SMTP error on HELO: '.$this->last_line);
			}
		}

		if (!is_null($this->username) && !is_null($this->password))
		{
			$this->_write('AUTH LOGIN');

			if ($this->_readCode() != 334)
			{
				throw new SMTP_Exception('SMTP AUTH error: '.$this->last_line);
			}

			$this->_write(base64_encode($this->username));

			if ($this->_readCode() != 334)
			{
				throw new SMTP_Exception('SMTP AUTH error: '.$this->last_line);
			}

			$this->_write(base64_encode($this->password));

			if ($this->_readCode() != 235)
			{
				throw new SMTP_Exception('SMTP AUTH error: '.$this->last_line);
			}
		}

		return true;
	}

	public function send($to, $subject, $message, $headers = array())
	{
		if (is_null($this->conn))
		{
			$this->connect();
			$this->authenticate();
		}

		if (!isset($headers['Date']))
		{
			$headers['Date'] = date(DATE_RFC822);
		}

		$headers['To'] = $to;
		$headers['Subject'] = '=?UTF-8?B?'.base64_encode($subject).'?=';
		$headers['MIME-Version'] = '1.0';
		$headers['Content-type'] = 'text/plain; charset=UTF-8';

		if (!isset($headers['From']))
		{
			$headers['From'] = 'mail@'.$this->servername;
		}

		$content = '';

		foreach ($headers as $name=>$value)
		{
			$content .= $name . ': ' . $value . self::EOL;
		}

		$content = trim($content) . self::EOL . self::EOL . $message . self::EOL;
		$content = preg_replace("#(?<!\r)\n#si", self::EOL, $content);
		$content = wordwrap($content, 998, self::EOL, true);

		list($from) = self::extractEmailAddresses($headers['From']);

		$to = self::extractEmailAddresses($headers['To']);

		if (isset($headers['Cc']))
		{
			$to = array_merge(self::extractEmailAddresses($headers['Cc']));
		}

		if (isset($headers['Bcc']))
		{
			$to = array_merge(self::extractEmailAddresses($headers['Bcc']));
		}

		$this->_write('MAIL FROM: <'.$from.'>');
		$this->_read();

		foreach ($to as $dest)
		{
			$this->_write('RCPT TO: <'.$dest.'>');
			$this->_read();
		}

		$this->_write('DATA');
		$this->_read();
		$this->_write($content . '.');

		if ($this->_readCode() != 250)
		{
			throw new SMTP_Exception('Can\'t send message. SMTP said: ' . $this->last_line);
		}

		return true;
	}

	/**
	 * Takes a string like a From, Cc, To or Bcc header and gets out all the email
	 * addresses it can find out of it.
	 * This is not perfect as it won't handle addresses like "uncommon,email"@email.tld
	 * because of the comma, but FILTER_VALIDATE_EMAIL doesn't accept it as an email address either
	 * (though it's perfectly valid if you follow the RFC).
	 */
	public static function extractEmailAddresses($str)
	{
		$str = explode(',', $str);
		$out = array();

		foreach ($str as $s)
		{
			$s = trim($s);
			if (preg_match('/(?:([\'"]).*?\1\s*)?<([^>]*)>/', $s, $match) && filter_var(trim($match[2]), FILTER_VALIDATE_EMAIL))
			{
				$out[] = trim($match[2]);
			}
			elseif (filter_var($s, FILTER_VALIDATE_EMAIL))
			{
				$out[] = $s;
			}
			else
			{
				echo "$s\n";
			}
		}

		return $out;
	}
}

?>