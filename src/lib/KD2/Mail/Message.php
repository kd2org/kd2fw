<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/

  Copyright (c) 2001+ BohwaZ <http://bohwaz.net/>
  All rights reserved.
*/

namespace KD2\Mail;

use KD2\Security;
use stdClass;
use KD2\SMTP;

/*
	Mail_Message: a simple e-mail message reader/writer (supports MIME)
*/

class Message
{
	const HEADER_BODY_SEPARATOR = "\r\n\r\n";

	protected array $headers = [];
	protected string $raw_headers = '';
	protected string $raw_body = '';

	/**
	 * Message body. This is usually not available to end-users if the message is multipart.
	 */
	protected string $body = '';

	/**
	 * Contains the ID of the plaintext part of the email
	 */
	protected ?string $text_part_id = null;

	/**
	 * Contains the ID to the HTML part of the email
	 */
	protected ?string $html_part_id = null;

	/**
	 * Contains a flat list of parts
	 */
	protected array $parts = [];

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

		if (preg_match('!<(.*?)>!', $value, $match)) {
			return $match[1];
		}

		if (filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
			return $value;
		}

		return false;
	}

	public function setMessageId(string $id = null)
	{
		if (is_null($id)) {
			$id = $this->generateMessageId();
		}
		elseif (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
			throw new \InvalidArgumentException('Invalid Message-Id: ' . $id);
		}

		$this->headers['message-id'] = '<' . $id . '>';
		return $id;
	}

	public function generateMessageId(): string
	{
		$id = uniqid();
		$hash = sha1($id . print_r($this->headers, true));

		if (!empty($_SERVER['SERVER_NAME'])) {
			$host = $_SERVER['SERVER_NAME'];
		}
		else {
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

		if (preg_match('!<(.*?)>!', $value, $match)) {
			return $match[1];
		}

		if (filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
			return $value;
		}

		return null;
	}

	public function getReferences(): ?array
	{
		$value = $this->getHeader('references');

		if (null === $value) {
			return null;
		}

		if (preg_match_all('!<(.*?)>!', $value, $match, PREG_PATTERN_ORDER)) {
			return $match[1];
		}

		if (filter_var(trim($value), FILTER_VALIDATE_EMAIL)) {
			return [$value];
		}

		return null;
	}

	/**
	 * Return address of sender (MAIL FROM / Envelope-from)
	 */
	public function getSenderAddress(): ?string
	{
		$header = $this->getHeader('Return-Path');
		$header ??= $this->getHeader('From');
		return self::extractAddressFromHeader($header) ?: null;
	}

	/**
	 * Return list of recipients addresses (RCPT TO)
	 */
	public function getRecipientsAddresses(): array
	{
		$list = array_merge($this->getTo(), $this->getCc(), $this->getBcc());
		$list = array_map([self::class, 'extractAddressFromHeader'], $list);
		return $list;
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

	public function getFrom(): array
	{
		return $this->getMultipleAddressHeader('from');
	}

	public function getFromName(): string
	{
		return self::extractNameFromHeader(current($this->getFrom()));
	}

	public function getFromAddress(): string
	{
		return self::extractAddressFromHeader(current($this->getFrom()));
	}

	static public function extractNameFromHeader(string $value): string
	{
		if (preg_match('/["\'](.+?)[\'"]/', $value, $match)) {
			return $match[1];
		}
		elseif (preg_match('/\\((.+?)\\)/', $value, $match)) {
			return $match[1];
		}
		elseif (($pos = strpos($value, '<')) > 0) {
			return trim(substr($value, 0, $pos));
		}
		elseif (($pos = strpos($value, '@')) > 0) {
			return trim(substr($value, 0, $pos));
		}
		else {
			return $value;
		}
	}

	static public function extractAddressFromHeader(string $value): string
	{
		if (preg_match('/<(.+@.+)>/', $value, $match)) {
			return $match[1];
		}
		elseif (preg_match('/([^\s]+@[^\s]+)/', $value, $match)) {
			return $match[1];
		}
		elseif (preg_match('/\\((.+?)\\)/', $value, $match)) {
			return trim(str_replace($match[0], '', $value));
		}
		else {
			return $value;
		}
	}

	public function getTo()
	{
		return $this->getMultipleAddressHeader('to');
	}

	public function getCc()
	{
		return $this->getMultipleAddressHeader('cc');
	}

	public function getBcc()
	{
		return $this->getMultipleAddressHeader('bcc');
	}

	public function getMultipleAddressHeader(string $header): array
	{
		$value = $this->getHeader($header);

		if (!$value) {
			return [];
		}

		return self::splitMultipleAddressHeaderValue($value);
	}

	static public function splitMultipleAddressHeaderValue(string $value): array
	{
		if (!trim($value)) {
			return [];
		}

		// Remove grouping, see RFC 2822 § section 3.4
		$value = preg_replace('/(?:[^:"<>,]+)\s*:\s*(.*?);/', '$1', $value);

		// Extract addresses
		preg_match_all('/(?:"(?!\\").*"\s*|[^"<>,]+)?<.*?>|[^<>",\s]+/s', $value, $match, PREG_PATTERN_ORDER);
		return array_map('trim', $match[0]);
	}

	public function setHeader(string $key, string $value)
	{
		$key = strtolower($key);
		$this->headers[$key] = $value;
	}

	public function setHeaders(array $headers)
	{
		foreach ($headers as $key => $value) {
			$this->setHeader($key, $value);
		}
	}

	public function appendToHeaders(array $headers): void
	{
		foreach ($headers as $key => $value) {
			$this->setHeader($key, $value);
		}
	}

	public function removeHeader(string $key)
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
		if (is_null($date)) {
			$date = date(\DATE_RFC2822);
		}
		elseif (is_object($date) && $date instanceof \DateTime) {
			$date = $date->format(\DATE_RFC2822);
		}
		elseif (is_numeric($date)) {
			$date = date(\DATE_RFC2822, $date);
		}
		else {
			throw new \InvalidArgumentException('Argument is not a valid date: ' . (string)$date);
		}

		return $this->setHeader('date', $date);
	}

	public function setTextBody(string $content): stdClass
	{
		if ($this->text_part_id) {
			$part = $this->getPart($this->text_part_id);
			$part->content = $content;
		}
		else {
			$part = $this->addPart('text/plain', $content, ['multipart_parent' => 'alternative']);
		}

		return $part;
	}

	public function setHTMLBody(string $content): stdClass
	{
		if ($this->html_part_id) {
			$part = $this->getPart($this->html_part_id);
			$part->content = $content;
		}
		else {
			$part = $this->addPart('text/html', $content, ['multipart_parent' => 'alternative']);
		}

		return $part;
	}

	/**
	 * Return body text, using HTML as "best source" if available
	 * (as some HTML emails contain a shitty plaintext alternative),
	 * but converted to plaintext (MarkDown).
	 */
	public function getBody(bool $prefer_html = true)
	{
		$body = '';

		// If we prefer HTML as source, try it first
		if ($prefer_html && $this->html_part_id) {
			$body = $this->HTMLToText($this->getHTMLBody());
		}

		// If body is still empty, try to fetch from plaintext
		if ($body === '' || !trim($body)) {
			$body = $this->getTextBody();

			// Some emails are in HTML in the text/plain body (eg. laposte.net)
			if (substr($body, 0, 1) == '<' && substr($body, -1) == '>') {
				$body = $this->HTMLToText($body);
			}
			// Fix a rare but weird bug, apparently caused by some webmails
			// where the plaintext email is HTML-encoded
			elseif (preg_match('/&[a-z]+;/', $body)) {
				$body = html_entity_decode($body, ENT_QUOTES, 'UTF-8');
			}
		}

		// Fallback to HTML body converted to text, if the plaintext is empty
		if (!$prefer_html && !trim($body) && $this->html_part_id) {
			$body = $this->HTMLToText($this->getHTMLBody());
		}

		return $body;
	}

	public function getHTMLBody(): ?string
	{
		if (!$this->html_part_id) {
			return null;
		}

		return $this->getPart($this->html_part_id)->content;
	}

	public function getTextBody(): ?string
	{
		if (!$this->text_part_id) {
			return null;
		}

		return $this->getPart($this->text_part_id)->content;
	}

	public function getAllParts(): array
	{
		return $this->parts;
	}

	public function getParts(): array
	{
		$out = [];

		foreach ($this->parts as $id => $part) {
			if (!empty($part->parts)) {
				continue;
			}

			$out[$id] =& $part;
		}

		return $out;
	}

	public function findPartByType(string $type): ?stdClass
	{
		foreach ($this->parts as $part) {
			if ($part->type === $type) {
				return $part;
			}
		}

		return null;
	}

	public function getRootPart(): ?stdClass
	{
		foreach ($this->parts as $part) {
			if ($part->parent === null) {
				return $part;
			}
		}

		return null;
	}

	public function getPart(string $id): stdClass
	{
		return $this->parts[$id];
	}

	public function getPartContent(string $id): string
	{
		return $this->parts[$id]->content;
	}

	public function removePart(string $id): void
	{
		$parent = $this->parts[$this->parts[$id]->parent] ?? null;

		if ($parent) {
			unset($parent->parts[$id]);
		}

		unset($this->parts[$id]);
	}

	public function addPart(string $type, ?string $content, array $options = []): stdClass
	{
		$part = (object) array_merge($options, compact('type', 'content'));
		$part->id ??= sha1(random_bytes(10));
		$part->parent ??= null;
		$part->parts ??= [];

		if (isset($part->parent)) {
			$parent = $this->getPart($part->parent);
		}
		elseif (isset($part->multipart_parent)) {
			$parent = $this->findPartByType('multipart/' . $part->multipart_parent);

			// Enclose multipart/alternative inside relative inside mixed
			// https://stackoverflow.com/a/66551704/1224777
			if (!$parent && $part->multipart_parent === 'alternative') {
				$parent = $this->addPart('multipart/alternative', null, ['multipart_parent' => 'related']);
			}
			elseif (!$parent && $part->multipart_parent === 'related') {
				$parent = $this->addPart('multipart/related', null, ['multipart_parent' => 'mixed']);
			}
			elseif (!$parent) {
				$parent = $this->addPart('multipart/' . $part->multipart_parent, null);
			}
		}
		else {
			$parent = null;
		}

		if ($part->parent && !$parent) {
			throw new \InvalidArgumentException('Parent option does not match an existing part: ' . $part->parent);
		}

		$part->parent = $parent->id ?? null;

		if ($parent) {
			$parent->parts[] = $part->id;
		}

		$this->parts[$part->id] = $part;

		if ($parent && $parent->type === 'multipart/alternative') {
			if ($type === 'text/html' && !$this->html_part_id) {
				$this->html_part_id = $part->id;
			}
			elseif ($type === 'text/plain' && !$this->text_part_id) {
				$this->text_part_id = $part->id;
			}
		}

		return $part;
	}

	public function attachMessage($content): string
	{
		if (is_object($content) && $content instanceof self) {
			$content = $content->output();
		}

		return $this->addPart('message/rfc822', $content);
	}

	public function HTMLToText(string $str): string
	{
		$str = preg_replace('!<br\s*/?>\n!i', '<br />', $str);
		$str = preg_replace('!</?(?:b|strong)(?:\s+[^>]*)?>!i', '**', $str);
		$str = preg_replace('!</?(?:i|em)(?:\s+[^>]*)?>!i', '*', $str);
		$str = preg_replace('!</?(?:u|ins)(?:\s+[^>]*)?>!i', '__', $str);
		$str = preg_replace('!</?(?:s|del)(?:\s+[^>]*)?>!i', '~~', $str);

		$str = preg_replace_callback('!<h(\d)(?:\s+[^>]*)?>!i', function ($match) {
			return str_repeat('#', (int)$match[1]) . ' ';
		}, $str);
		$str = preg_replace_callback('!</h(\d)>!i', function ($match) {
			return ' ' . str_repeat('#', (int)$match[1]);
		}, $str);

		$str = str_replace("\r", "\n", $str);
		$str = preg_replace("!</p>\n*!i", "\n\n", $str);
		$str = preg_replace("!<br[^>]*>\n*!i", "\n", $str);

		//$str = preg_replace('!<img[^>]*src=([\'"])([^\1]*?)\1[^>]*>!i', '![]($2)', $str);

		preg_match_all('!<a[^>]href=([\'"])([^\1]*?)\1[^>]*>(.*?)</a>!i', $str, $match, PREG_SET_ORDER);

		foreach ($match as $found) {
			if ($found[3] == $found[2] || trim($found[3]) === '') {
				$link = '&lt;' . $found[2] . '&gt;';
			}
			else {
				$link = sprintf('%s &lt;%s&gt;', $found[3], $found[2]);
			}
		}

		/*
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
		*/

		$str = preg_replace_callback('!<blockquote[^>]*>(.*)</blockquote>!is', function ($match) {
			return preg_replace('!^!m', '> ', trim($match[1]));
		}, $str);

		$str = preg_replace('!<(script|style).*</\1>!is', '', $str);
		$str = strip_tags($str);

		$str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
		$str = preg_replace('/^\h*/m', '', $str);
		$str = preg_replace("!\n{3,}!", "\n\n", $str);

		return trim($str);
	}

	public function getSignature(string $str): ?string
	{
		// From http://www.cs.cmu.edu/~vitor/papers/sigFilePaper_finalversion.pdf
		if (preg_match('/^(?:--[ ]?\n|\s*[*#+^$\/=%:&~!_-]{10,}).*?\n/m', $str, $match, PREG_OFFSET_CAPTURE)) {
			$str = substr($str, $match[0][1] + strlen($match[0][0]));
			return trim($str);
		}

		return null;
	}

	public function removeSignature(string $str): string
	{
		if (preg_match('/^--[ ]*$/m', $str, $match, PREG_OFFSET_CAPTURE)) {
			return trim(substr($str, 0, $match[0][1]));
		}

		return $str;
	}

	public function removeTrailingQuote(string $str): string
	{
		$str = explode("\n", rtrim($str));

		for ($i = count($str) - 1; $i >= 0; $i--) {
			$f = substr(ltrim($str[i]), 0, 1);

			if ($f !== '>' && $f === '|') {
				break;
			}
		}

		$str = array_slice($str, 0, $i);
		return implode("\n", $str);
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

		$this->text_part_id = null;
		$this->html_part_id = null;
		$this->parts = [];

		$this->body = "This is an encrypted message.\r\nPlease use a PGP/GPG enabled email client to read it.";

		$this->addPart(
			'application/pgp-encrypted',
			"Version: 1\r\n",
			['encoding' => 'raw', 'multipart_parent' => "encrypted; protocol=\"application/pgp-encrypted\""],
		);

		$this->addPart(
			'application/octet-stream',
			$enclosed,
			['encoding' => 'raw', 'multipart_parent' => "encrypted; protocol=\"application/pgp-encrypted\""],
		);

		return $this;
	}

	public function getRaw()
	{
		return $this->raw_headers . self::HEADER_BODY_SEPARATOR . $this->raw_body;
	}

	public function outputHeaders(array $headers = null, bool $for_sending = false): string
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

		if (count($this->parts) <= 1) {
			$part = current($this->parts);

			// Force text/plain if part has not type
			$part->type ??= 'text/plain';

			// Force CTE to quoted-printable if nothing else is present
			$part->encoding ??= 'quoted-printable';

			// use part type/encoding as content-type/encoding
			$headers['content-type'] = $part->type;
			$headers['content-transfer-encoding'] = $part->encoding;

			unset($headers['mime-version']);

			// Force UTF-8
			if (substr($part->type, 0, 5) === 'text/') {
				$headers['content-type'] .= '; charset=utf-8';
			}
		}
		else {
			$parent = $this->getRootPart();
			$headers['content-type'] = $parent->type . ";\r\n boundary=\"" . $parent->id . '"';
			$headers['content-transfer-encoding'] = '8bit';
			$headers['mime-version'] = '1.0';
		}

		foreach ($headers as $key => $value) {
			if (is_array($value)) {
				foreach ($value as $line) {
					$out .= $this->_encodeHeader($key, $line) . "\n";
				}
			}
			else {
				$out .= $this->_encodeHeader($key, $value) . "\n";
			}
		}

		return rtrim($out);
	}

	public function outputBody(): string
	{
		if (count($this->parts) <= 1) {
			$body = $this->_outputPartBody(current($this->parts));
		}
		else {
			$body = $this->body;

			if (empty($body)) {
				$body = "This is a message in multipart MIME format.\r\n"
					. "Your mail client should not be displaying this.\r\n"
					. "Consider upgrading your mail client to view this message correctly.";
			}

			$body .= "\r\n\r\n";

			$body .= $this->outputMultipart($this->getRootPart());
		}

		return $body;
	}

	public function outputMultipart(stdClass $parent, int $level = 2)
	{
		$body = '';

		foreach ($parent->parts as $id) {
			$part = $this->getPart($id);
			$body .= '--' . $parent->id . "\r\n";

			if (!empty($part->parts)) {
				$body .= 'Content-Type: '
					. $part->type
					. ";\r\n boundary=\""
					. $part->id
					. "\"\r\n\r\n";

				$body .= $this->outputMultipart($part, $level + 1);
			}
			else {
				$body .= $this->outputPart($part) . "\r\n";
			}
		}

		$body .= '--' . $parent->id . "--\r\n";

		return $body;
	}

	public function outputPart(stdClass $part): string
	{
		$out = 'Content-Type: ' . $part->type . '; charset=utf-8';
		$name = !empty($part->name) ? str_replace(['"', "\r", "\n"], '', $part->name) : null;

		if ($name) {
			$out .= '; name="' . $name . '"';
		}

		$out .= "\r\n";

		if ($name && (!empty($part->cid) || !empty($part->location))) {
			$out .= 'Content-Disposition: inline; filename="' . $name . "\"\r\n";
		}
		elseif (!empty($name)) {
			$out .= 'Content-Disposition: attachment; filename="' . $name . "\"\r\n";
		}

		if (!empty($part->cid)) {
			$out .= 'Content-ID: <' . $part->cid . ">\r\n";
		}

		if (!empty($part->location)) {
			$out .= 'Content-Location: ' . $part->location . "\r\n";
		}

		if ($part->type === 'message/rfc822' || ($part->encoding ?? null) === 'raw') {
			$out .= "Content-Disposition: inline\r\n";
			$part->encoding = null;
		}
		elseif (stripos($part->type, 'text/') === 0) {
			$out .= "Content-Transfer-Encoding: quoted-printable\r\n";
			$part->encoding = 'quoted-printable';
		}
		else {
			$out .= "Content-Transfer-Encoding: base64\r\n";
			$part->encoding = 'base64';
		}

		$out .= "\r\n";
		$out .= $this->_outputPartBody($part) . "\r\n";

		return $out;
	}

	protected function _outputPartBody(stdClass $part): string
	{
		$encoding = $part->encoding ?? '';

		if (false !== strpos('quoted-printable', $encoding)) {
			$content = $this->_normalizeLineBreaks(rtrim($part->content));
			$content = $this->_wrapLines($content);
			return quoted_printable_encode($content);
		}
		elseif (stristr($encoding, 'base64')) {
			return chunk_split(base64_encode($part->content));
		}
		else {
			return $part->content;
		}
	}

	protected function _wrapLines(string $str, int $max = 997, string $separator = "\r\n"): string
	{
		$lines = explode("\n", $str);

		foreach ($lines as &$line) {
			if (strlen($line) > $max) {
				$line = wordwrap($line, $max, $separator, true);
			}
		}

		return implode("\n", $lines);
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
	protected function _encodeHeader(string $key, $value)
	{
		$key = strtolower($key);

		$key = preg_replace_callback('/(^\w|-\w)/i', function ($match) {
			return strtoupper($match[1]);
		}, $key);

		if (is_array($value)) {
			$value = array_map('trim', $value);
			$value = array_map(fn ($a) =>$this->_encodeHeaderValue($a, $key), $value);

			$glue = in_array($key, ['From', 'Cc', 'To', 'Bcc', 'Reply-To']) ? ', ' : '';
			$value = implode($glue, $value);
		}
		elseif (in_array($key, ['From', 'Cc', 'To', 'Bcc', 'Reply-To'])) {
			return $this->_encodeHeader($key, self::splitMultipleAddressHeaderValue($value));
		}
		else {
			$value = $this->_encodeHeaderValue($value, $key);
		}

		$value = $key . ': ' . trim($value);

		// Force-wrap long lines to respect RFC (max line length is 998)
		if (strlen($value) > 997) {
			$value = $this->_wrapLines($value, 997, "\r\n ");
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

		$value = '=?UTF-8?B?'.base64_encode($value).'?=';
		return $value;
	}

	public function _splitHeadersAndBody(string $str): array
	{
		$pos = strpos($str, self::HEADER_BODY_SEPARATOR) ?: null;

		return [
			$pos === null ? $str : substr($str, 0, $pos), // headers
			$pos === null ? '' : substr($str, $pos + 4), // body
		];
	}

	public function parse(string $str): void
	{
		$this->parts = [];
		$this->headers = [];
		$this->html_part_id = null;
		$this->text_part_id = null;

		$str = $this->_normalizeLineBreaks($str);
		list($this->raw_headers, $this->raw_body) = $this->_splitHeadersAndBody($str);

		$this->headers = $this->_parseHeaders($this->raw_headers);
		$this->body = $this->_extractParts($this->raw_body, $this->headers, null);

		// Either the message is not multipart, or decoding multipart failed, treat it as plain text
		if (!count($this->parts)) {
			if (empty($this->headers['content-type']) || stristr($this->headers['content-type'], 'multipart/')) {
				$this->headers['content-type'] = 'text/plain';
			}

			$type = $this->_parseContentHeader($this->headers['content-type'], 'type');

			$encoding = $this->headers['content-transfer-encoding'] ?? null;
			$this->body = $this->_decodeBody($this->body, $type['type'], $encoding);

			$part = $this->addPart($type['type'], $this->body);

			if ($type['type'] === 'text/html') {
				$this->html_part_id = $part->id;
			}
			elseif ($type['type'] === 'text/plain') {
				$this->text_part_id = $part->id;
			}
		}
		// We couldn't find any text/HTML part, try to get the first one
		elseif (!$this->html_part_id || !$this->text_part_id) {
			foreach ($this->parts as $part) {
				if ($part['type'] === 'text/html' && !$part['name']) {
					$this->html_part_id = $part->id;
				}
				elseif ($part['type'] === 'text/plain' && !$part['name']) {
					$this->text_part_id = $part->id;
				}
			}

			unset($part);
		}
	}

	protected function _parseContentHeader(string $str, string $first_value_name): ?array
	{
		if ($str === '') {
			return null;
		}

		$header = explode(';', trim($str));
		$header = array_map('trim', $header);

		$properties = [
			$first_value_name => array_shift($header),
		];

		foreach ($header as $h) {
			$name = strtolower(trim(strtok($h, '=')));
			$value = trim(strtok(''));

			if (substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
				$value = substr($value, 1, -1);
			}

			$properties[$name] = $value;
		}

		return $properties;
	}

	protected function _getMultipartProperties(array $headers): ?array
	{
		// No content-type supplied, or not multipart
		if (empty($headers['content-type'])
			|| false === strpos($headers['content-type'], 'multipart/')) {
			return null;
		}

		$properties = $this->_parseContentHeader($headers['content-type'], 'type');

		if (0 !== strpos($properties['type'], 'multipart/')) {
			return null;
		}

		if (empty($properties['boundary'])) {
			return null;
		}

		return $properties;
	}

	protected function _extractParts(string $body, array $headers, ?string $parent_id, int $level = 0): ?string
	{
		if ($level > 5) {
			throw new \OverflowException('Too many levels of multipart decoding: stopped at 5');
		}

		if (null !== $parent_id) {
			$parent = $this->getPart($parent_id);
		}
		else {
			$properties = $this->_getMultipartProperties($headers);

			if (!$properties) {
				return $body;
			}

			$parent = $this->addPart($properties['type'], null, ['id' => $properties['boundary']]);
		}

		$part = null;
		$prefix = '';
		$boundary = '--' . $parent->id;
		$lines = explode("\r\n", $body);

		foreach ($lines as $line) {
			if ($line === $boundary) {
				if ($part) {
					$this->_extractPart($part, $parent->id, $level);
				}


				$part = '';
				continue;
			}
			elseif ($line === $boundary . '--') {
				break;
			}
			elseif ($part !== null) {
				$part .= $line . "\r\n";
			}
			else {
				$prefix = $line . "\r\n";
			}
		}

		if ($part) {
			$this->_extractPart($part, $parent->id, $level);
		}

		// We didn't find any part belonging to the boundary from the headers.
		// Maybe the boundary name is invalid?
		// Let's try to auto-detect the first boundary name instead
		if (!count($parent->parts)) {
			$old_id = $parent->id;
			$this->removePart($old_id);
			$parent->id = null;

			foreach ($lines as $line) {
				if (substr($line, 0, 2) === '--') {
					$parent->id = substr($line, 2);
					break;
				}
			}

			if ($parent->id) {
				$this->addPart($parent->type, null, ['id' => $parent->id]);
				$prefix = $this->_extractParts($body, $headers, $parent->id, $level + 1);
			}
		}

		return $prefix;
	}

	protected function _extractPart(string $str, ?string $parent_id, int $level): void
	{
		list($headers, $body) = $this->_splitHeadersAndBody($str);
		$headers = $this->_parseHeaders($headers);
		$headers['content-type'] ??= 'text/plain';
		$headers['content-disposition'] ??= 'inline';

		// Sub-multipart
		if (false !== strpos($headers['content-type'], 'multipart/')) {
			$this->_extractParts($body, $headers, $parent_id, $level + 1);
			return;
		}

		$type = $this->_parseContentHeader($headers['content-type'], 'type');
		$disposition = $this->_parseContentHeader($headers['content-disposition'], 'disposition');

		$this->addPart(
			$type['type'],
			$this->_decodeBody($body, $type['type'], $headers['content-transfer-encoding'] ?? null),
			[
				'name'     => $type['name'] ?? ($disposition['filename'] ?? null),
				'cid'      => !empty($headers['content-id']) ? trim($headers['content-id'], '<> ') : null,
				'encoding' => $headers['content-transfer-encoding'] ?? null,
				'parent'   => $parent_id,
			]
		);
	}

	protected function _normalizeLineBreaks(string $str): string
	{
		return preg_replace("#(?<!\r)\n#si", "\r\n", $str);
	}

	protected function _parseHeaders(string $raw): array
	{
		$headers = [];

		$name = null;
		$lines = explode("\r\n", $raw);

		foreach ($lines as $l => $line) {
			// Long header unfolding
			// RFC 5322 says we shouldn't remove any whitespace character, just the CRLF
			// https://datatracker.ietf.org/doc/html/rfc5322.html#section-2.2.3
			if ($name !== null && ($line[0] === ' ' || $line[0] === "\t")) {
				// Keep linebreaks for some headers
				if ($name === 'received' || $name === 'x-spam-report' || $name === 'DKIM-Signature') {
					$prefix = "\n";
				}
				else {
					$prefix = '';
				}

				if (is_array($headers[$name])) {
					$headers[$name][count($headers[$name]) - 1] .= $prefix . $line;
				}
				else {
					$headers[$name] .= $prefix . $line;
				}

				continue;
			}
			else {
				$name = strtok($line, ':');
				$value = strtok('');

				if (!$name || !$value) {
					throw new \RuntimeException(sprintf('No header name found on line %d: %s', $l, $line));
				}

				// Email headers are case insensitive
				// see https://stackoverflow.com/questions/6143549/are-email-headers-case-sensitive
				$name = trim(strtolower($name));
				$value = trim($value);

				// this is a multiple header (like Received:)
				if (array_key_exists($name, $headers)) {
					// Transform string into array
					if (!is_array($headers[$name])) {
						$headers[$name] = [$headers[$name]];
					}

					$headers[$name][] = $value;
				}
				else {
					$headers[$name] = $value;
				}
			}
		}

		// Decode headers
		foreach ($headers as &$value) {
			if (is_array($value)) {
				foreach ($value as &$subvalue) {
					$subvalue = $this->_decodeHeaderValue($subvalue);
				}
			}
			else {
				$value = $this->_decodeHeaderValue($value);
			}
		}

		unset($value);
		return $headers;
	}

	protected function _decodeBody(string $body, string $type, ?string $encoding): string
	{
		if (null !== $encoding && trim($encoding) === '') {
			$encoding = null;
		}

		if (null !== $encoding && stristr('quoted-printable', $encoding)) {
			$body = quoted_printable_decode($body);
		}
		elseif (null !== $encoding && stristr('base64', $encoding)) {
			$body = base64_decode($body);
		}

		if (stristr($type, 'text/')) {
			$body = $this->utf8_encode(rtrim($body));
		}

		return $body;
	}

	protected function _decodeHeaderValue(string $value): string
	{
		$value = rtrim($value);

		if (strpos($value, '=?') === false) {
			return $this->utf8_encode($value);
		}

		if (function_exists('iconv_mime_decode')) {
			$value = $this->utf8_encode(iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR));
		}
		elseif (function_exists('mb_decode_mimeheader')) {
			$value = $this->utf8_encode(mb_decode_mimeheader($value));
		}
		elseif (function_exists('imap_mime_header_decode')) {
			$_value = '';

			// subject can span into several lines
			foreach (imap_mime_header_decode($value) as $h) {
				$charset = ($h->charset == 'default') ? 'US-ASCII' : $h->charset;
				$_value .= iconv($charset, "UTF-8//TRANSLIT", $h->text);
			}

			$value = $_value;
		}

		return $value;
	}

	public function utf8_encode($str)
	{
		// Check if string is already UTF-8 encoded or not
		if (!preg_match('//u', $str)) {
			return self::iso8859_1_to_utf8($str);
		}

		return $str;
	}

    /**
     * Poly-fill to encode a ISO-8859-1 string to UTF-8 for PHP >= 9.0
     * @see https://php.watch/versions/8.2/utf8_encode-utf8_decode-deprecated
     */
    static public function iso8859_1_to_utf8(string $s): string
    {
        if (PHP_VERSION_ID < 90000) {
            return @utf8_encode($s);
        }

        $s .= $s;
        $len = strlen($s);

        for ($i = $len >> 1, $j = 0; $i < $len; ++$i, ++$j) {
            switch (true) {
                case $s[$i] < "\x80":
                    $s[$j] = $s[$i];
                    break;
                case $s[$i] < "\xC0":
                    $s[$j] = "\xC2";
                    $s[++$j] = $s[$i];
                    break;
                default:
                    $s[$j] = "\xC3";
                    $s[++$j] = chr(ord($s[$i]) - 64);
                    break;
            }
        }

        return substr($s, 0, $j);
    }

	/**
	 * @see https://www.php.net/manual/en/function.mb-detect-encoding.php#68607
	 */
	public function is_utf8(string $str): bool
	{
		return (bool) preg_match('%(?:
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
	 * Send email using either SMTP class, or native PHP function mail()
	 */
	public function send(?SMTP $smtp = null): bool
	{
		if ($smtp) {
			$smtp->send($this);
			return true;
		}

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
			$success += mail($address, $subject, $this->outputBody(), $this->outputHeaders($headers, true));
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
		if (preg_match('/(?:user|mailbox)\s+(?:is\s+)?unavailable|doesn\'t\s*have|does\s*not\s*exist|invalid|Unrouteable|unknown|illegal|no\s*such\s*user|disabled|Relay\s*access\s*denied|not\s*found|Amazon SES did not send the message/i', $error_message))
		{
			return true;
		}
		elseif (preg_match('/rejete|rejected|spam\s*detected|Service\s*refus|greylist|expired|service\s*unavailable|retry\s*timeout|quota|too\s*many|spam\s*policy/i', $error_message))
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
			$part_id = $this->findPartByType('message/delivery-status');

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

			$s = new self;
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
			$part_id = $this->findPartByType('message/rfc822');

			if ($part_id === false)
			{
				throw new \RuntimeException('The complaint does not contain a sub-message?!');
			}

			$orig = new self;
			$orig->parse(ltrim($this->getPartContent($part_id)));
			list($recipient) = $orig->getTo();
			$recipient = self::extractAddressFromHeader($recipient);

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
