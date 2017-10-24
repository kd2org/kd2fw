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

class SMTP_Exception extends \Exception {}

class SMTP
{
	const NONE = 0;
	const TLS = 1;
	const STARTTLS = 1;
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

	public function __construct($server = 'localhost', $port = 25, $username = null, $password = null, $secure = self::NONE)
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
		if (is_null($this->conn))
		{
			return true;
		}

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

	/**
	 * Send a raw email
	 * @param  string $from From address (MAIL FROM:)
	 * @param  mixed  $to   To address (RCPT TO:), can be a string (single recipient) 
	 *                      or an array (multiple recipients)
	 * @param  string $data Mail data (DATA)
	 * @return boolean TRUE if success, exception if it fails
	 */
	public function rawSend($from, $to, $data)
	{
		if (is_null($this->conn))
		{
			$this->connect();
			$this->authenticate();
		}

		$this->_write('RSET');

		$code = $this->_readCode();

		if ($code != 250 && $code != 200)
		{
			throw new SMTP_Exception('SMTP RSET error: '.$this->last_line);
		}

		$this->_write('MAIL FROM: <'.$from.'>');

		if ($this->_readCode() != 250)
		{
			throw new SMTP_Exception('SMTP MAIL FROM error: '.$this->last_line);
		}

		if (is_string($to))
		{
			$to = array($to);
		}

		foreach ($to as $dest)
		{
			$this->_write('RCPT TO: <'.$dest.'>');

			$code = $this->_readCode();

			if ($code != 250 && $code != 251)
			{
				throw new SMTP_Exception('SMTP RCPT TO error: '.$this->last_line);
			}
		}

		$data = rtrim($data) . self::EOL;

		// if first character of a line is a period, then append another period
		// to avoid confusion with "end of data marker"
		// see https://tools.ietf.org/html/rfc5321#section-4.5.2
		$data = preg_replace('/^\./m', '..', $data);

		$this->_write('DATA');
		
		if ($this->_readCode() != 354)
		{
			throw new SMTP_Exception('SMTP DATA error: '.$this->last_line);
		}

		$this->_write($data . '.');

		if ($this->_readCode() != 250)
		{
			throw new SMTP_Exception('Can\'t send message. SMTP said: ' . $this->last_line);
		}

		return true;
	}

	/**
	 * Send an email to $to, using $subject as a subject and $message as content
	 * @param  mixed  $to      List of recipients, as an array or a string
	 * @param  string $subject Message subject
	 * @param  string $message Message content
	 * @param  mixed  $headers Additional headers, either as an array of key=>value pairs or a string
	 * @return boolean		   TRUE if success, exception if it fails
	 */
	public function send($to, $subject, $message, $headers = array())
	{
		// Parse $headers if it's a string
		if (is_string($headers))
		{
			preg_match_all('/^(\\S.*?):(.*?)\\s*(?=^\\S|\\Z)/sm', $headers, $match, PREG_SET_ORDER);
			$headers = array();

			foreach ($match as $header)
			{
				$headers[$header[1]] = $header[2];
			}
		}

		// Normalize headers
		$headers_normalized = array();

		foreach ($headers as $key=>$value)
		{
			$key = preg_replace_callback('/^.|(?<=-)./', function ($m) { return ucfirst($m[0]); }, strtolower(trim($key)));
			$headers_normalized[$key] = $value;
		}

		$headers = $headers_normalized;
		unset($headers_normalized);

		// Set default headers if they are missing
		if (!isset($headers['Date']))
		{
			$headers['Date'] = date(DATE_RFC822);
		}

		$headers['Subject'] = (trim($subject) == '') ? '' : '=?UTF-8?B?'.base64_encode($subject).'?=';

		if (!isset($headers['MIME-Version']))
		{
			$headers['MIME-Version'] = '1.0';
		}

		if (!isset($headers['Content-Type']))
		{
			$headers['Content-Type'] = 'text/plain; charset=UTF-8';
		}

		if (!isset($headers['From']))
		{
			$headers['From'] = 'mail@'.$this->servername;
		}

		if (!isset($headers['Message-ID']))
		{
			// With headers + uniqid, it is presumed to be sufficiently unique
			// so that two messages won't have the same ID
			$headers['Message-ID'] = sha1(uniqid() . var_export($headers, true)) . '@' . $this->servername;
		}

		$content = '';

		foreach ($headers as $name=>$value)
		{
			$content .= $name . ': ' . $value . self::EOL;
		}

		$content = trim($content) . self::EOL . self::EOL . $message . self::EOL;
		$content = preg_replace("#(?<!\r)\n#si", self::EOL, $content);
		$content = wordwrap($content, 998, self::EOL, true);

		// Extract and filter recipients addresses
		$to = self::extractEmailAddresses($to);
		$headers['To'] = implode(', ', $to);

		if (isset($headers['Cc']))
		{
			$headers['Cc'] = self::extractEmailAddresses($headers['Cc']);
			$to = array_merge($to, $headers['Cc']);

			$headers['Cc'] = implode(', ', $headers['Cc']);
		}

		if (isset($headers['Bcc']))
		{
			$headers['Bcc'] = self::extractEmailAddresses($headers['Bcc']);
			$to = array_merge($to, $headers['Bcc']);

			$headers['Bcc'] = implode(', ', $headers['Bcc']);
		}

		$from = self::extractEmailAddresses($headers['From']);

		// Send email
		return $this->rawSend(current($from), $to, $content);
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
		if (is_array($str))
		{
			$out = array();

			// Filter invalid email addresses
			foreach ($str as $email)
			{
				if (filter_var($email, FILTER_VALIDATE_EMAIL))
				{
					$out[] = $email;
				}
			}

			return $out;
		}

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
				// unrecognized, skip
			}
		}

		return $out;
	}
}
