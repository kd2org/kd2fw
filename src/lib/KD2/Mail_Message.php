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

/*
	Mail_Message: a simple e-mail message reader/writer (supports MIME)
	Copyleft (C) 2012-2015 BohwaZ <http://bohwaz.net>
*/

class Mail_Message
{
	protected $headers = [];
	protected $raw = '';
	protected $parts = [];
	protected $boundaries = [];
	protected $output_boundary = '';

	public function __construct()
	{
		$this->output_boundary = '==_=_' . uniqid() . '-' . substr(sha1(microtime(true)), -10);
	}

	public function getHeaders()
	{
		return $this->headers;
	}

	public function getHeader($key)
	{
		$key = strtolower($key);

		if (!isset($this->headers[$key])) {
			return null;
		}

		return str_replace("\n", '', $this->headers[$key]);
	}

	public function getMessageId()
	{
		$value = $this->getHeader('message-id');

		if (preg_match('!<(.*?)>!', $value, $match))
		{
			return $match[1];
		}

		if (filter_var(trim($value), FILTER_VALIDATE_EMAIL))
		{
			return $value;
		}

		return false;
	}

	public function setMessageId($id = null)
	{
		if (is_null($id)) {
			$id = $this->generateMessageId();
		}

		$this->headers['message-id'] = '<' . $id . '>';
		return $id;
	}

	public function generateMessageId(): string
	{
		$id = uniqid();
		$hash = sha1($id . print_r($this->headers, true));

		if (!empty($_SERVER['SERVER_NAME']))
		{
			$host = $_SERVER['SERVER_NAME'];
		}
		else
		{
			$host = preg_replace('/[^a-z]/', '', base_convert($hash, 16, 36));
			$host = substr($host, 10, -3) . '.' . substr($host, -3);
		}

		$id = $id . '.' . substr(base_convert($hash, 16, 36), 0, 10) . '@' . $host;
		return $id;
	}

	public function getInReplyTo()
	{
		$value = $this->getHeader('in-reply-to');

		if (null === $value) {
			return null;
		}

		if (preg_match('!<(.*?)>!', $value, $match))
		{
			return $match[1];
		}

		if (filter_var(trim($value), FILTER_VALIDATE_EMAIL))
		{
			return $value;
		}

		return null;
	}

	public function getReferences()
	{
		$value = $this->getHeader('references');

		if (null === $value) {
			return null;
		}

		if (preg_match_all('!<(.*?)>!', $value, $match, PREG_PATTERN_ORDER))
		{
			return $match[1];
		}

		if (filter_var(trim($value), FILTER_VALIDATE_EMAIL))
		{
			return [$value];
		}

		return null;
	}

	/**
	 * Returns a HTTP(S) URL to request unsubscribe
	 * You should submit a POST request to that URL with "List-Unsubscribe=One-Click" in the body
	 * @see https://www.bortzmeyer.org/8058.html
	 * @return array
	 */
	public function getUnsubscribeURL(): ?string
	{
		$header = $this->getHeader('list-unsubscribe');

		if (null === $header) {
			return null;
		}

		if (preg_match_all('/<([^>]+)>/', $header, $matches, PREG_PATTERN_ORDER)) {
			foreach ($matches[1] as $match) {
				if (substr($match, 0, 4) === 'http' && filter_var($match, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)) {
					return $match;
				}
			}
		}
		elseif (filter_var($header, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)) {
			if (substr($header, 0, 4) === 'http') {
				return $header;
			}
		}

		return null;
	}

	public function getFrom()
	{
		return $this->getMultipleAddressHeader('from');
	}

	public function getTo()
	{
		return $this->getMultipleAddressHeader('to');
	}

	public function getCc()
	{
		return $this->getMultipleAddressHeader('cc');
	}

	public function getMultipleAddressHeader(?string $header, ?string $value = null)
	{
		$header = $value ?? $this->getHeader($header);

		if (!$header) {
			return [];
		}

		// Remove grouping, see RFC 2822 § section 3.4
		$header = preg_replace('/(?:[^:"<>,]+)\s*:\s*(.*?);/', '$1', $header);

		// Extract addresses
		preg_match_all('/(?:"(?!\\").*"\s*|[^"<>,]+)?<.*?>|[^<>",\s]+/s', $header, $match, PREG_PATTERN_ORDER);
		return array_map('trim', $match[0]);
	}

	public function setHeader($key, $value)
	{
		$key = strtolower($key);
		$this->headers[$key] = $value;
		return true;
	}

	public function setHeaders($headers)
	{
		foreach ($headers as $key => $value) {
			$this->setHeader($key, $value);
		}
	}

	public function removeHeader($key)
	{
		unset($this->headers[strtolower($key)]);
	}

	public function getDate()
	{
		$date = $this->getHeader('date');
		return $date ? new \DateTime($date) : null;
	}

	public function setDate($date = null)
	{
		if (is_null($date))
		{
			$date = date(\DATE_RFC2822);
		}
		elseif (is_object($date) && $date instanceof \DateTime)
		{
			$date = $date->format(\DATE_RFC2822);
		}
		elseif (is_numeric($date))
		{
			$date = date(\DATE_RFC2822, $date);
		}
		else
		{
			throw new \InvalidArgumentException('Argument is not a valid date: ' . (string)$date);
		}

		return $this->setHeader('date', $date);
	}

	public function appendHeaders($headers)
	{
		foreach ($headers as $key=>$value)
		{
			$key = strtolower($key);
			$this->headers[$key] = $value;
		}
		return true;
	}

	public function setBody($content)
	{
		if (!is_string($content))
		{
			throw new \InvalidArgumentException('Content must be a string, but is a ' . gettype($content));
		}

		foreach ($this->parts as &$part)
		{
			if ($part['type'] == 'text/plain')
			{
				$part['content'] = $content;
				return true;
			}
		}

		return $this->addPart('text/plain', $content);
	}

	public function getBody($html = false)
	{
		if ($html)
		{
			foreach ($this->parts as $part)
			{
				if ($part['type'] == 'text/html')
					return $part['content'];
			}

			return false;
		}

		foreach ($this->parts as $part)
		{
			if ($part['type'] == 'text/plain')
			{
				$part['content'] = trim($part['content']);

				// Some emails are in HTML in the text/plain body (eg. laposte.net)
				if (substr($part['content'], 0, 1) == '<' && substr($part['content'], -1) == '>') {
					$part['content'] = $this->HTMLToText($part['content']);
				}
				// Fix a rare but weird bug, apparently caused by some webmails
				// where the plaintext email is HTML-encoded
				elseif (preg_match('/&[a-z]+;/', $part['content']) && utf8_decode($part['content']) == $part['content'])
				{
					$part['content'] = html_entity_decode($part['content'], ENT_QUOTES, 'UTF-8');
				}

				return $part['content'];
			}
		}

		// Fallback to html stripped of tags
		foreach ($this->parts as $part)
		{
			if ($part['type'] == 'text/html')
				return $this->HTMLToText($part['content']);
		}

		return false;
	}

	public function getParts()
	{
		return $this->parts;
	}

	public function listParts()
	{
		$out = [];

		foreach ($this->parts as $id=>$p)
		{
			$out[$id] = $p;
			unset($out[$id]['content']);
		}

		return $out;
	}

	public function findPart($type)
	{
		foreach ($this->parts as $id => $p)
		{
			if ($p['type'] == $type)
			{
				return $id;
			}
		}

		return false;
	}

	public function getPart($id)
	{
		return $this->parts[$id];
	}

	public function getPartContent($id)
	{
		return $this->parts[$id]['content'];
	}

	public function HTMLToText($str)
	{
		$str = preg_replace('!<br\s*/?>\n!i', '<br />', $str);
		$str = preg_replace('!</?(?:b|strong)(?:\s+[^>]*)?>!i', '*', $str);
		$str = preg_replace('!</?(?:i|em)(?:\s+[^>]*)?>!i', '/', $str);
		$str = preg_replace('!</?(?:u|ins)(?:\s+[^>]*)?>!i', '_', $str);
		$str = preg_replace_callback('!<h(\d)(?:\s+[^>]*)?>!i', function ($match) {
			return str_repeat('=', (int)$match[1]) . ' ';
		}, $str);
		$str = preg_replace_callback('!</h(\d)>!i', function ($match) {
			return ' ' . str_repeat('=', (int)$match[1]);
		}, $str);

		$str = str_replace("\r", "\n", $str);
		$str = preg_replace("!</p>\n*!i", "\n\n", $str);
		$str = preg_replace("!<br[^>]*>\n*!i", "\n", $str);

		$str = preg_replace('!<img[^>]*src=([\'"])([^\1]*?)\1[^>]*>!i', 'Image : $2', $str);

		preg_match_all('!<a[^>]href=([\'"])([^\1]*?)\1[^>]*>(.*?)</a>!i', $str, $match, PREG_SET_ORDER);

		if (!empty($match))
		{
			foreach ($match as $key=>$link)
			{
				if ($link[3] == $link[2])
				{
					unset($match[$key]);
				}
			}
		}

		if (!empty($match))
		{
			$i = 1;
			$str .= "\n\n== Liens cités ==\n";

			foreach ($match as $link)
			{
				$str = str_replace($link[0], $link[3] . '['.$i.']', $str);
				$str.= str_pad($i, 2, ' ', STR_PAD_LEFT).'. '.$link[2]."\n";
				$i++;
			}
		}

		$str = strip_tags($str);

		$str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
		$str = preg_replace('/^\h*/m', '', $str);
		$str = preg_replace("!\n{3,}!", "\n\n", $str);

		return trim($str);
	}

	public function getSignature($str)
	{
		// From http://www.cs.cmu.edu/~vitor/papers/sigFilePaper_finalversion.pdf
		if (preg_match('/^(?:--[ ]?\n|\s*[*#+^$\/=%:&~!_-]{10,}).*?\n/m', $str, $match, PREG_OFFSET_CAPTURE))
		{
			$str = substr($str, $match[0][1] + strlen($match[0][0]));
			return trim($str);
		}

		return false;
	}

	public function removeSignature($str)
	{
		if (preg_match('/^--[ ]*$/m', $str, $match, PREG_OFFSET_CAPTURE))
		{
			return trim(substr($str, 0, $match[0][1]));
		}

		return $str;
	}

	public function removePart($id)
	{
		unset($this->parts[$id]);
		return true;
	}

	public function addPart($type, $content, $name = null, $cid = null, $encoding = null)
	{
		$this->parts[] = [
			'type'		=>	$type,
			'content'	=>	$content,
			'name'		=>	$name,
			'cid'		=>	$cid,
			'encoding'  =>  $encoding,
		];

		return true;
	}

	public function attachMessage($content)
	{
		if (is_object($content) && $content instanceof self) {
			$content = $content->output();
		}

		return $this->addPart('message/rfc822', $content);
	}

	public function getRaw()
	{
		return $this->raw;
	}

	public function outputHeaders(array $headers = null, bool $for_sending = false)
	{
		if (null === $headers) {
			$headers = $this->headers;
		}

		if ($for_sending) {
			if (!isset($headers['date'])) {
				$headers['date'] = date(\DATE_RFC2822);
			}

			if (!isset($headers['message-id'])) {
				$headers['message-id'] = '<' . $this->generateMessageId() . '>';
			}
		}

		$out = '';

		$parts = array_values($this->parts);

		if (count($parts) <= 1)
		{
			if (!isset($headers['content-type']) || stristr($headers['content-type'], 'text/plain'))
			{
				// Force UTF-8
				$headers['content-type'] = $parts[0]['type'] . '; charset=utf-8';
				$headers['content-transfer-encoding'] = 'quoted-printable';
			}
		}
		else
		{
			if (!isset($headers['content-type']) || !stristr($headers['content-type'], 'multipart/')) {
				$headers['content-type'] = 'multipart/mixed; boundary="' . $this->output_boundary . '"';
				$headers['content-transfer-encoding'] = '8bit';
				$headers['mime-version'] = '1.0';
			}
		}

		foreach ($headers as $key=>$value)
		{
			if (is_array($value))
			{
				foreach ($value as $line)
				{
					$out .= $this->_encodeHeader($key, $line) . "\n";
				}
			}
			else
			{
				$out .= $this->_encodeHeader($key, $value) . "\n";
			}
		}

		$out = preg_replace("#(?<!\r)\n#si", "\r\n", $out);

		return rtrim($out);
	}

	public function outputBody()
	{
		$parts = array_values($this->parts);

		if (count($parts) <= 1)
		{
			$cte = $this->getHeader('content-transfer-encoding');

			if (null === $cte || stristr($cte, 'quoted-printable'))
			{
				$body = quoted_printable_encode($parts[0]['content']);
			}
			elseif (stristr($cte, 'base64'))
			{
				$body = chunk_split(base64_encode($parts[0]['content']));
			}
			else
			{
				$body = $parts[0]['content'];

				// Force maximum line length
				$body = explode("\n", $body);
				$body = array_map(function ($v) { return wordwrap($v, 990, "\n", true); }, $body);
				$body = implode("\n", $body);
			}
		}
		else
		{
			$body = "This is a message in multipart MIME format. \n";
			$body.= "Your mail client should not be displaying this. \n";
			$body.= "Consider upgrading your mail client to view this message correctly.";
			$body.= "\n\n";

			if (!empty($parts[0]) && !empty($parts[1])
				&& (($parts[0]['type'] == 'text/plain' && $parts[1]['type'] == 'text/html')
					|| ($parts[1]['type'] == 'text/plain' && $parts[0]['type'] == 'text/html')))
			{
				$body .= '--' . $this->output_boundary . "\n";
				$body .= 'Content-Type: multipart/alternative; boundary="alt=_-=';
				$body .= $this->output_boundary . "\"\n\n\n";

				$p = ($parts[0]['type'] == 'text/plain') ? 0 : 1;

				$body .= '--alt=_-=' . $this->output_boundary . "\n";
				$body .= $this->outputPart($parts[$p]) . "\n";

				$p = $p ? 0 : 1;
				$body .= '--alt=_-=' . $this->output_boundary . "\n";
				$body .= $this->outputPart($parts[$p]) . "\n";
				$body .= '--alt=_-=' . $this->output_boundary . "--\n\n"; // End

				$parts = array_slice($parts, 2);
			}

			foreach ($parts as $part)
			{
				$body .= '--' . $this->output_boundary . "\n";
				$body .= $this->outputPart($part) . "\n\n";
			}

			$body .= '--' . $this->output_boundary . "--\n";
		}

		$body = preg_replace("#(?<!\r)\n#si", "\r\n", $body);

		return $body;
	}

	public function encrypt(string $key): self
	{
		if (!Security::canUseEncryption()) {
			throw new \LogicException('Encryption is not available, check that gnupg module is installed and loaded.');
		}

		$enclosed = clone $this;
		$enclosed->headers = [];
		$enclosed = $enclosed->output();
		$enclosed = Security::encryptWithPublicKey($key, $enclosed);
		$this->parts = [];
		$this->headers['content-type'] = sprintf('multipart/encrypted; boundary="%s"; protocol="application/pgp-encrypted"', $this->output_boundary);
		$this->addPart('application/pgp-encrypted', 'Version: 1', null, null, 'raw');
		$this->addPart('application/octet-stream', $enclosed, null, null, 'raw');
		return $this;
	}

	public function outputPart($part)
	{
		$out = 'Content-Type: ' . $part['type'];

		if (!empty($part['name']))
		{
			$out .= '; name="' . str_replace('"', '', $part['name']) . '"';
		}

		if ($part['type'] == 'message/rfc822' || ($part['encoding'] ?? null) == 'raw')
		{
			$out .= "\nContent-Disposition: inline\n";
			$content = $part['content'];
		}
		elseif (stripos($part['type'], 'text/') === 0)
		{
			$out .= "; charset=utf-8\n";

			$out .= "Content-Transfer-Encoding: quoted-printable\n";
			$content = quoted_printable_encode(preg_replace("#(?<!\r)\n#si", "\r\n", rtrim($part['content']))) . "\r\n";
		}
		else
		{
			$out .= "\nContent-Transfer-Encoding: base64\n";
			$content = chunk_split(base64_encode($part['content']));
		}

		if (!empty($part['name']) && (!empty($part['cid']) || !empty($part['location'])))
		{
			$out .= 'Content-Disposition: inline; filename="' . $part['name'] . "\"\n";
		}
		elseif (!empty($part['name']))
		{
			$out .= 'Content-Disposition: attachment; filename="' . $part['name'] . "\"\n";
		}

		if (!empty($part['cid']))
		{
			$out .= 'Content-ID: <' . $part['cid'] . ">\n";
		}

		if (!empty($part['location']))
		{
			$out .= 'Content-Location: ' . $part['location'] . "\n";
		}

		$out = rtrim($out, "\r\n");
		$out .= "\n\n" . ltrim($content, "\r\n");

		return $out;
	}

	public function output(bool $for_sending = false)
	{
		return trim($this->outputHeaders(null, $for_sending)) . "\r\n\r\n" . trim($this->outputBody());
	}

	/**
	 * Encodes a header
	 * @param  string $key   Header name
	 * @param  mixed  $value Header value (if it's an array it will be concatenated)
	 * @return string        Name: Value header content
	 */
	protected function _encodeHeader($key, $value)
	{
		$key = strtolower($key);

		$key = preg_replace_callback('/(^\w|-\w)/i', function ($match) {
			return strtoupper($match[1]);
		}, $key);

		if (is_array($value))
		{
			$value = array_map('trim', $value);
			$value = array_map(fn ($a) =>$this->_encodeHeaderValue($a, $key), $value);

			$glue = in_array($key, ['From', 'Cc', 'To', 'Bcc', 'Reply-To']) ? ', ' : '';
			$value = implode($glue, $value);
		}
		elseif (in_array($key, ['From', 'Cc', 'To', 'Bcc', 'Reply-To'])) {
			return $this->_encodeHeader($key, $this->getMultipleAddressHeader($key, $value));
		}
		else
		{
			$value = $this->_encodeHeaderValue($value, $key);
		}

		$value = $key . ': ' . trim($value);

		// Force-wrap long lines to respect RFC (max line length is 998)
		if (strlen($value) > 997) {
			$value = explode("\n", $value);
			$value = array_map(function ($v) { return wordwrap($v, 997, "\n ", true); }, $value);
			$value = implode("\n", $value);
		}

		return $value;
	}

	/**
	 * Encodes header value if it's not ASCII
	 * @param  string $value Header value
	 * @param  string $key   Header name
	 * @return string        Encoded header value
	 */
	protected function _encodeHeaderValue($value, $key = null)
	{
		// Don't encode spam report here as we want it to be readable in the source
		if ($key == 'X-Spam-Report') {
			return $value;
		}

		if (in_array($key, ['From', 'Cc', 'To', 'Bcc', 'Reply-To']))
		{
			if (!preg_match('/^((?:"?(?P<name>(?:(?!\\").)*?)"?)\s*<(?P<namedEmail>[^>]+)>|(?P<email>.+))$/', $value, $matches))
			{
				return $value;
			}

			if (!empty($matches['name']))
			{
				return '"' . $this->_encodeHeaderValue(trim($matches['name'])) . '" <' . $matches['namedEmail'] . '>';
			}

			return $value;
		}

		if (!$this->is_utf8($value)) {
			return $value;
		}

		if (function_exists('mb_internal_encoding')) {
			mb_internal_encoding('UTF-8');
			return mb_encode_mimeheader($value, 'UTF-8');
		}

		if (function_exists('iconv_mime_encode')) {
			return iconv_mime_encode('', $value);
		}

		if ($this->is_utf8($value)) {
			$value = '=?UTF-8?B?'.base64_encode($value).'?=';
		}

		return $value;
	}

	public function parse($raw)
	{
		$this->raw = $raw;
		$this->parts = [];
		$this->headers = [];
		$this->boundaries = [];

		list($headers, $body) = $this->_parseHeadersAndBody($raw);

		if (!empty($headers['content-type']) && stristr($headers['content-type'], 'multipart/')
			&& preg_match('/boundary=(?:"(.*?)"|([^\s]+))/mi', $headers['content-type'], $match))
		{
			$this->boundaries[] = !empty($match[2]) ? $match[2] : $match[1];

			// Multipart handling
			$this->_decodeMultipart($body);

			$this->boundaries = [];
		}

		// Either the message is not multipart, or decoding multipart failed, treat it as plain text
		if (empty($this->parts))
		{
			if (empty($headers['content-type']) || stristr($headers['content-type'], 'multipart/'))
			{
				$headers['content-type'] = 'text/plain';
			}

			$encoding = isset($headers['content-transfer-encoding']) ? $headers['content-transfer-encoding'] : '';

			$body = implode("\n", $body);
			$body = $this->_decodePart($body, $headers['content-type'], $encoding);

			$type = preg_replace('/;.*$/s', '', $headers['content-type']);
			$type = trim($type);

			$this->parts[] = [
				'name'      =>  null,
				'content'   =>  $body,
				'type'      =>  $type,
				'cid'       =>  null,
				'encoding'  =>  null,
			];
		}

		$this->headers = $headers;

		return true;
	}

	protected function _parseHeadersAndBody($raw)
	{
		$lines = is_array($raw) ? $raw : preg_split("/(\r?\n|\r)/", $raw);
		$body = [];
		$headers = [];

		$current_header = null;

		$i = 0;
		foreach ($lines as $line)
		{
			if(trim($line, "\r\n") === '')
			{
				// end of headers
				$body = array_slice($lines, $i);
				break;
			}

			// start of new header
			if (preg_match('/^(\w[^:]*): ?(.*)$/i', $line, $matches))
			{
				if (!is_null($current_header))
				{
					$current_header = trim($current_header);
				}

				$header = strtolower($matches[1]);
				$value = $matches[2];

				// this is a multiple header (like Received:)
				if (array_key_exists($header, $headers))
				{
					if (!is_array($headers[$header]))
					{
						$headers[$header] = [$headers[$header]];
					}

					$headers[$header][] = $value;
					$current_header =& $headers[$header][count($headers[$header])-1];
				}
				else
				{
					$headers[$header] = $value;
					$current_header =& $headers[$header];
				}
			}
			else // more lines related to the current header
			{
				if (!is_null($current_header) && preg_match('/^\h/', $line))
				{
					// Keep lines inside folded headers
					$current_header .= "\n" . $line;
				}
			}

			$i++;
		}

		// Decode headers
		foreach ($headers as &$value)
		{
			if (is_array($value))
			{
				foreach ($value as &$subvalue)
				{
					$subvalue = $this->_decodeHeader($subvalue);
				}
			}
			else
			{
				$value = $this->_decodeHeader($value);
			}
		}

		unset($i, $current_header, $lines);
		return [$headers, $body];
	}

	protected function _decodePart($body, $type, $encoding)
	{
		if (trim($encoding) && stristr('quoted-printable', $encoding))
		{
			$body = quoted_printable_decode($body);
		}
		elseif (trim($encoding) && stristr('base64', $encoding))
		{
			$body = base64_decode($body);
		}

		if (stristr($type, 'text/'))
		{
			$body = $this->utf8_encode(rtrim($body));
		}

		return $body;
	}

	protected function _decodeHeader($value)
	{
		$value = rtrim($value);

		if (strpos($value, '=?') === false)
		{
			return $this->utf8_encode($value);
		}

		if (function_exists('iconv_mime_decode'))
		{
			$value = $this->utf8_encode(iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR));
		}
		elseif (function_exists('mb_decode_mimeheader'))
		{
			$value = $this->utf8_encode(mb_decode_mimeheader($value));
		}
		elseif (function_exists('imap_mime_header_decode'))
		{
			$_value = '';

			// subject can span into several lines
			foreach (imap_mime_header_decode($value) as $h)
			{
				$charset = ($h->charset == 'default') ? 'US-ASCII' : $h->charset;
				$_value .= iconv($charset, "UTF-8//TRANSLIT", $h->text);
			}

			$value = $_value;
		}

		return $value;
	}

	protected function _decodeMultipart($lines)
	{
		$i = 0;
		$start = null;
		$end = null;

		// Skip to beginning of next part
		foreach ($lines as $line)
		{
			if (preg_match('!(?:Content-Type:.*|^\s+)boundary=(?:"(.*?)"|([^\s;]+))!si', $line, $match))
			{
				$this->boundaries[] = !empty($match[2]) ? $match[2] : $match[1];
			}

			if (preg_match('/^--(.*)--$/', $line, $match) && in_array($match[1], $this->boundaries))
			{
				if (!is_null($start))
				{
					$end = $i;
					break;
				}
			}
			else if (preg_match('/^--(.*)$/', trim($line), $match) && in_array($match[1], $this->boundaries))
			{
				if (is_null($start))
				{
					$start = $i;
				}
				else if (is_null($end))
				{
					$end = $i;
					break;
				}
			}

			$i++;
		}

		if (is_null($start) && is_null($end))
		{
			return false;
		}

		list($headers, $body) = $this->_parseHeadersAndBody(array_slice($lines, $start, $end - $start));

		if (empty($headers['content-type']))
		{
			$headers['content-type'] = 'text/plain';
		}

		// Sub-multipart
		if (stristr($headers['content-type'], 'multipart/'))
		{
			$this->_decodeMultipart(array_slice($lines, $end));
			return false;
		}

		$encoding = isset($headers['content-transfer-encoding']) ? $headers['content-transfer-encoding'] : '';

		$name = $cid = null;

		$type = preg_replace('/;.*$/s', '', $headers['content-type']);
		$type = trim($type);

		if (preg_match('/name=(?:"(.*?)"|([^\s]*))/mi', $headers['content-type'], $match))
		{
			$name = !empty($match[2]) ? $match[2] : $match[1];
		}
		elseif (!empty($headers['content-disposition']) && preg_match('/filename=(?:"(.*?)"|([^\s]+))/mi', $headers['content-disposition'], $match))
		{
			$name = !empty($match[2]) ? $match[2] : $match[1];
		}

		if (!empty($headers['content-id']) && preg_match('/<(.*?)>/m', $headers['content-id'], $match))
		{
			$cid = $match[1];
		}

		$part = [
			'type'  =>  $type,
			'name'  =>  $name,
			'cid'   =>  $cid,
			'content'=> $body,
			'encoding' => null,
		];

		$part['content'] = implode("\n", $part['content']);
		$part['content'] = $this->_decodePart($part['content'], $headers['content-type'], $encoding);

		$this->parts[] = $part;

		return $this->_decodeMultipart(array_slice($lines, $end));
	}

	public function utf8_encode($str)
	{
		// Check if string is already UTF-8 encoded or not
		if (!preg_match('//u', $str))
		{
			return utf8_encode($str);
		}

		return $str;
	}

	public function is_utf8($str)
	{
		return preg_match('%(?:
			[\xC2-\xDF][\x80-\xBF]        # non-overlong 2-byte
			|\xE0[\xA0-\xBF][\x80-\xBF]               # excluding overlongs
			|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}      # straight 3-byte
			|\xED[\x80-\x9F][\x80-\xBF]               # excluding surrogates
			|\xF0[\x90-\xBF][\x80-\xBF]{2}    # planes 1-3
			|[\xF1-\xF3][\x80-\xBF]{3}                  # planes 4-15
			|\xF4[\x80-\x8F][\x80-\xBF]{2}    # plane 16
			)+%xs', $str);
	}

	/**
	 * Send email using native PHP function mail()
	 */
	public function send(array $additional_parameters = []): bool
	{
		$to = $this->getTo() + $this->getCc();

		$success = 0;
		$count = 0;
		$headers = array_diff_key($this->getHeaders(), ['subject' => null, 'to' => null, 'cc' => null]);

		$subject = $this->getHeader('Subject');

		if ($subject) {
			$subject = $this->_encodeHeaderValue($subject, 'Subject');
		}
		else {
			$subject = '[no subject]';
		}

		foreach ($to as $address) {
			$count++;
			$success += mail($address, $this->getHeader('Subject') ?? '[no subject]', $this->outputBody(), $this->outputHeaders($headers, true));
		}

		return ($success == $count);
	}

	/**
	 * Tente de trouver le statut de rejet (définitif ou temporaire) d'un message à partir du message d'erreur reçu
	 * @param  string $error_message
	 * @return boolean|null TRUE if the rejection is permanent, FALSE if temporary, NULL if status is unknown
	 */
	public function isPermanentRejection(string $error_message): ?bool
	{
		if (preg_match('/unavailable|doesn\'t\s*have|quota|does\s*not\s*exist|invalid|Unrouteable|unknown|illegal|no\s*such\s*user|blocked|disabled|Relay\s*access\s*denied|not\s*found/i', $error_message))
		{
			return true;
		}
		elseif (preg_match('/rejete|rejected|spam\s*detected|Service\s*refus|greylist|expired/i', $error_message))
		{
			return false;
		}

		return null;
	}

	/**
	 * Tries to identify what a received message is: either an auto-reply, a complaint (spam report), a mailer daemon message, etc.
	 * @return array An array containing at least a 'type' key (autoreply/complaint/temporary/permanent/genuine, where genuine is just a normal message)
	 * If the message is a temporary, a permanent or complaint, the array will also contain a 'recipient' value with the email address of the person who received the email or filed the complaint and a 'message' value containing the original error message
	 */
	public function identifyBounce()
	{
		$from = current($this->getFrom());

		if (!$from) {
			return null;
		}

		if (stripos($from, 'MAILER-DAEMON@') === 0 || $this->getHeader('X-Failed-Recipients') || stristr($this->getHeader('Content-Type'), 'report-type=delivery-status'))
		{
			$part_id = $this->findPart('message/delivery-status');

			if (!$part_id)
			{
				// Cas de certains mails, par exemple:
				// <xx@yy.fr>: delivery to host gmail.fr[failed] timed out
				if (stristr($this->getRaw(), 'Content-Description: Undelivered Message')
					&& preg_match('/\?c=\d+&e=(.*)>/', $this->getRaw(), $match))
				{
					return [
						'type'      => 'permanent',
						'recipient' => rawurldecode($match[1]),
						'message'   => 'Undelivered message',
					];
				}

				// We cannot find out who is the recipient
				return null;
			}

			// Make the delivery status look like an email
			$status = $this->getPartContent($part_id);
			$status = str_replace(["\r\n", "\n\n"], "\n", $status);
			$status = trim($status) . "\n\nFake";

			$s = new Mail_Message;
			$s->parse($status);

			$recipient = trim(str_replace('rfc822;', '', $s->getHeader('Final-Recipient') ?? ''));
			$diagnostic = trim(str_replace('smtp;', '', $s->getHeader('Diagnostic-Code') ?? ''));

			$rejection_status = $this->isPermanentRejection($diagnostic);

			return [
				'type'      => $rejection_status ? 'permanent' : 'temporary',
				'recipient' => $recipient,
				'message'   => $diagnostic,
			];
		}
		elseif (strpos($from, 'complaints@') === 0)
		{
			$part_id = $this->findPart('message/rfc822');

			if ($part_id === false)
			{
				throw new \RuntimeException('The complaint does not contain a sub-message?!');
			}

			$orig = new Mail_Message;
			$orig->parse(ltrim($this->getPartContent($part_id)));
			list($recipient) = $orig->getTo();
			list($recipient) = SMTP::extractEmailAddresses($recipient);

			return [
				'type'      => 'complaint',
				'recipient' => $recipient,
				'message'   => null,
			];
		}
		// Ignore auto-replies
		elseif ($this->getHeader('precedence') || $this->getHeader('X-Autoreply')
			|| $this->getHeader('X-Autorespond') || $this->getHeader('auto-submitted')
			|| stristr($this->getHeader('Delivered-To') ?? '', 'Autoresponder')
			|| preg_match('/spamenmoins\.com/', $this->getHeader('From') ?? '')
			|| preg_match('/^(?:Réponse\s*automatique|Out\s*of\s*office|Automatic\s*reply|Auto:\s+)/i', $this->getHeader('Subject') ?? ''))
		{
			return [
				'type' => 'autoreply',
			];
		}
		else
		{
			return [
				'type' => 'genuine',
			];
		}
	}
}
