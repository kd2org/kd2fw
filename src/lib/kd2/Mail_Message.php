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

	public function getHeader($key)
	{
		if (array_key_exists($key, $this->headers))
			return $this->headers[$key];
		return null;
	}

	public function getMessageId()
	{
		foreach ($this->headers as $key=>$value)
		{
			if ($key == 'message-id')
			{
				if (is_array($value))
				{
					$value = current($value);
				}

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
		}

		return false;
	}

	public function setMessageId($id = null)
	{
		if (is_null($id))
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
		}

		$this->headers['message-id'] = '<' . $id . '>';
		return $id;
	}

	public function getInReplyTo()
	{
		foreach ($this->headers as $key=>$value)
		{
			if ($key == 'in-reply-to')
			{
				if (is_array($value))
				{
					$value = current($value);
				}

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
		}

		return false;
	}

	public function getReferences()
	{
		foreach ($this->headers as $key=>$value)
		{
			if ($key == 'references')
			{
				if (is_array($value))
				{
					$value = current($value);
				}

				if (preg_match_all('!<(.*?)>!', $value, $match, PREG_PATTERN_ORDER))
				{
					return $match[1];
				}

				if (filter_var(trim($value), FILTER_VALIDATE_EMAIL))
				{
					return [$value];
				}

				return false;
			}
		}

		return false;
	}


	public function setHeader($key, $value)
	{
		$this->headers[$key] = $value;
		return true;
	}

	public function setHeaders($headers)
	{
		$this->headers = $headers;
	}

	public function appendHeaders($headers)
	{
		foreach ($headers as $key=>$value)
		{
			$this->headers[$key] = $value;
		}
		return true;
	}

	public function setBody($content)
	{
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
			unset($p['content']);
			$out[$id] = $p;
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

	public function HTMLToText($str)
	{
        $str = preg_replace('!<br\s*/?>\n!i', '<br />', $str);
        $str = preg_replace('!</?(?:b|strong)(?:\s+[^>]*)?>!i', '*', $str);
        $str = preg_replace('!</?(?:i|em)(?:\s+[^>]*)?>!i', '/', $str);
        $str = preg_replace('!</?(?:u|ins)(?:\s+[^>]*)?>!i', '_', $str);
        $str = preg_replace('!<h(\d)(?:\s+[^>]*)?>!i', function ($match) {
        	return str_repeat('=', (int)$match[1]) . ' ';
        }, $str);
        $str = preg_replace('!</h(\d)>!i', function ($match) {
        	return ' ' . str_repeat('=', (int)$match[1]);
        }, $str);

        $str = str_replace("\r", "\n", $str);
        $str = preg_replace("!</p>\n*!i", "\n\n", $str);
        $str = preg_replace("!<br[^>]*>\n*!i", "\n", $str);

        $str = preg_replace('!<img[^>]*src=([\'"])([^\1]*?)\1[^>]*>!i', 'Image : $2', $str);

        preg_match_all('!<a[^>]href=([\'"])([^\1]*?)\1[^>]*>(.*?)</a>!i', $str, $match, PREG_SET_ORDER);

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
        $str = preg_replace("!\n{3,}!", "\n\n", $str);
        return $str;
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
        // From http://www.cs.cmu.edu/~vitor/papers/sigFilePaper_finalversion.pdf
        if (preg_match('/^(?:--[ ]?\n|\s*[*#+^$\/=%:&~!_-]{10,})/m', $str, $match, PREG_OFFSET_CAPTURE))
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

	public function addPart($type, $content, $name = null, $cid = null)
	{
		$this->parts[] = [
			'type'		=>	$type,
			'content'	=>	$content,
			'name'		=>	$name,
			'cid'		=>	$cid,
		];

		return true;
	}

	public function getOrigRaw()
	{
		return $this->raw;
	}

	public function getRawHeaders($headers = null)
	{
		$headers = is_null($headers) ? $this->headers : $headers;
		$out = '';

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

		return $out;
	}

	public function getRaw()
	{
		$body = '';
		$out = '';

		$headers = $this->headers;

		$parts = array_values($this->parts);

		if (count($parts) == 1 && $parts[0]['type'] == 'text/plain')
		{
			$headers['content-type'] = 'text/plain; charset=utf-8';
			$headers['content-transfer-encoding'] = 'quoted-printable';
			$body = quoted_printable_encode($parts[0]['content']);
		}
		else
		{
			// FIXME
			// https://en.wikipedia.org/wiki/MIME
			foreach ($parts as $part)
			{
			}
		}

		$out .= $this->getRawHeaders($headers);
		$out .= "\n" . $body;

		$out = preg_replace("#(?<!\r)\n#si", "\r\n", $out);

		return $out;
	}

	protected function _encodeHeader($key, $value)
	{
		$key = strtolower($key);

		$key = preg_replace_callback('/(^\w|-\w)/i', function ($match) {
			return strtoupper($match[1]);
		}, $key);

		if ($this->is_utf8($value))
		{
			$value = '=?UTF-8?B?'.base64_encode($value).'?=';
		}

		$value = preg_replace("/^[ ]*/m", ' ', $value);
		$value = trim($value);

		return $key . ': ' . $value;
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
				'cid'       =>  null,
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
			if (preg_match('/^(\w[^:]*): ?(.*)$/i', $line, $matches))
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
				if ($current_header && preg_match('/^\h/', $line))
				{
					$current_header .= "\n" . ltrim($line);
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

		$name = $cid = null;

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
			$cid = $match[1];
		}

		$part = [
			'type'  =>  $type,
			'name'  =>  $name,
			'cid'   =>  $cid,
			'content'=> $body,
		];

		$part['content'] = implode("\n", $part['content']);
		$part['content'] = $this->_decodePart($part['content'], $headers['content-type'], $encoding);

		$this->parts[] = $part;

		return $this->_decodeMultipart(array_slice($lines, $end));
	}

	protected function is_utf8($str)
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

	protected function utf8_encode($str)
	{
		if (!$this->is_utf8($str))
		{
			return utf8_encode($str);
		}

		return $str;
	}
}