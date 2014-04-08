<?php

namespace KD2;

class Mail_Message
{
	protected $headers = [];
	protected $raw = '';
	protected $parts = [];
	protected $boundaries = [];

	public function __construct()
	{
	}

	public function getHeaders()
	{
		return $this->headers;
	}

	public function getBody()
	{
		foreach ($this->parts as $part)
		{
			if ($part['type'] == 'text/plain')
				return $part['content'];
		}

		foreach ($this->parts as $part)
		{
			if ($part['type'] == 'text/html')
				return $part;
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
			$out[$id] = ['type' => $p['type'], 'name' => $p['name'], 'id' => $p['id']];
		}

		return $out;
	}

	public function getPart($id)
	{
		return $this->parts[$id];
	}

	public function getPartContent($id)
	{
		return $this->parts[$id]['content'];
	}

	public function parse($raw)
	{
		$this->raw = $raw;
		$this->parts = [];
		$this->headers = [];

		list($headers, $body) = $this->_parseHeadersAndBody($raw);

		if (!empty($headers['content-type']) && stristr($headers['content-type'], 'multipart/')
			&& preg_match('/boundary=(?:"(.*?)"|([^\s]*?))/mi', $headers['content-type'], $match))
		{
			$this->boundaries[] = !empty($match[2]) ? $match[2] : $match[1];

			// Multipart handling
			$this->_decodeMultipart($body);
		}
		else
		{
			if (empty($headers['content-type']))
				$headers['content-type'] = 'text/plain';

			$encoding = isset($headers['content-transfer-encoding']) ? $headers['content-transfer-encoding'] : '';

			$body = implode("\n", $body);
			$body = $this->_decodePart($body, $headers['content-type'], $encoding);

			$type = preg_replace('/;.*$/', '', $headers['content-type']);
			$type = trim($type);

			$this->parts[] = [
				'name'      =>  null,
				'content'   =>  $body,
				'type'      =>  $type,
				'id'        =>  null,
			];
		}

		$this->headers = $headers;

		return true;
	}

	protected function _parseHeadersAndBody($raw)
	{
		$lines = is_array($raw) ? $raw : preg_split("/(\r?\n|\r)/", $raw);
		$body = '';
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
			if (preg_match('/^([a-z][^:]*): ?(.*)$/i', $line, $matches))
			{
				$header = strtolower($matches[1]);
				$value = $this->_decodeHeader($matches[2]);

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
				if ($current_header && $line[0] == " ")
				{
					$current_header .= "\n" . substr($line, 1);
				}
			}

			$i++;
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
			$body = base64_decode(str_replace(["\r", "\n", " "], '', $body));
		}

		if (stristr($type, 'text/'))
		{
			$body = self::utf8_encode($body);
		}

		return trim($body);
	}

	protected function _decodeHeader($value)
	{
		$value = trim($value);

		if (strpos($value, '=?') === false)
		{
			return self::utf8_encode($value);
		}

		if (function_exists('imap_mime_header_decode'))
		{
			$_value = '';

			// subject can span into several lines
			foreach (imap_mime_header_decode($value) as $h)
			{
				$charset = ($h->charset == 'default') ? 'US-ASCII' : $h->charset;
				$_value .= iconv($charset, "UTF-8//TRANSLIT", $h->text);
			}

			$value = $_value;
			unset($_value);
		}
		elseif (function_exists('iconv_mime_decode'))
		{
			$value = self::utf8_encode(iconv_mime_decode($value));
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
			if (preg_match('!boundary=(?:"(.*?)"|([^\s]*?))!si', $line, $match))
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

		list($headers, $body) = $this->_parseHeadersAndBody(array_slice($lines, $start, $end));

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

		$name = $id = null;

		$type = preg_replace('/;.*$/', '', $headers['content-type']);
		$type = trim($type);

		if (preg_match('/name=(?:"(.*?)"|([^\s]*))/mi', $headers['content-type'], $match))
		{
			$name = !empty($match[2]) ? $match[2] : $match[1];
		}
		elseif (!empty($headers['content-disposition']) && preg_match('/filename=(?:"(.*?)"|([^\s]+)/mi', $headers['content-disposition'], $match))
		{
			$name = !empty($match[2]) ? $match[2] : $match[1];
		}

		if (!empty($headers['content-id']) && preg_match('/<(.*?)>/m', $headers['content-id'], $match))
		{
			$id = $match[1];
		}

		$part = [
			'type'  =>  $type,
			'name'  =>  $name,
			'id'    =>  $id,
			'content'=> $body,
		];

		$part['content'] = implode("\n", $part['content']);
		$part['content'] = $this->_decodePart($part['content'], $headers['content-type'], $encoding);

		$this->parts[] = $part;

		return $this->_decodeMultipart(array_slice($lines, $end));
	}

	protected function utf8_encode($str)
	{
		if (!preg_match('%(?:
			[\xC2-\xDF][\x80-\xBF]        # non-overlong 2-byte
			|\xE0[\xA0-\xBF][\x80-\xBF]               # excluding overlongs
			|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}      # straight 3-byte
			|\xED[\x80-\x9F][\x80-\xBF]               # excluding surrogates
			|\xF0[\x90-\xBF][\x80-\xBF]{2}    # planes 1-3
			|[\xF1-\xF3][\x80-\xBF]{3}                  # planes 4-15
			|\xF4[\x80-\x8F][\x80-\xBF]{2}    # plane 16
			)+%xs', $str))
		{
			return utf8_encode($str);
		}

		return $str;
	}
}