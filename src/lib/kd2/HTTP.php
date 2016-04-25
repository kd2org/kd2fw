<?php

namespace KD2;

class HTTP
{
	/**
	 * A list of common User-Agent strings, one of them is used
	 * randomly every time an object has a new instance.
	 * @var array
	 */
	public $uas = [
		'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Ubuntu Chromium/48.0.2564.116 Chrome/48.0.2564.116 Safari/537.36',
		'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:45.0) Gecko/20100101 Firefox/45.0',
		'Mozilla/5.0 (X11; Linux x86_64; rv:38.9) Gecko/20100101 Goanna/2.0 Firefox/38.9 PaleMoon/26.1.1',
	];

	/**
	 * Default HTTP headers sent with every request
	 * @var array
	 */
	public $headers = [
		'Accept-Language' => 'fr,en',
		'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
	];

	/**
	 * Options for the SSL stream wrapper
	 * See http://php.net/manual/en/context.ssl.php
	 * @var array
	 */
	public $ssl_options = [
		'verify_peer'		=>	false,
		'allow_self_signed'	=>	true,
		'SNI_enabled'		=>	true,
	];

	/**
	 * Options for the HTTP stream wrapper
	 * See http://php.net/manual/en/context.http.php
	 * @var array
	 */
	public $htt_options = [
		'max_redirects'		=>	10,
		'timeout'			=>	10,
		'ignore_errors'		=>	false,
	];

	/**
	 * List of cookies sent to the server, will contain the cookies
	 * set by the server after a request.
	 * @var array
	 */
	public $cookies = [];

	/**
	 * Class construct
	 */
	public function __construct()
	{
		// Random user agent
		$this->headers['User-Agent'] = $this->uas[array_rand($this->uas)];
	}

	/**
	 * Make a GET request
	 * @param  string $url                URL to request
	 * @param  array  $additional_headers Optional headers to send with request
	 * @return object                     a stdClass object containing 'headers' and 'body'
	 */
	public function GET($url, $additional_headers = null)
	{
		return $this->request('GET', $url, null, $additional_headers);
	}

	/**
	 * Make a GET request
	 * @param  string $url                URL to request
	 * @param  array  $data 			  Data to send with POST request
	 * @param  string $type 			  Type of data: 'form' for HTML form or 'json' to encode array in JSON
	 * @param  array  $additional_headers Optional headers to send with request
	 * @return object                     a stdClass object containing 'headers' and 'body'
	 */
	public function POST($url, $data = [], $type = 'form', $additional_headers = null)
	{
		if ($type == 'form')
		{
			$data = http_build_query($data, null, '&');
			$headers['Content-Length'] = strlen($data);
			$headers['Content-Type'] = 'application/x-www-form-urlencoded';
		}
		elseif ($type == 'json')
		{
			$data = json_encode($data);
			$headers['Content-Length'] = strlen($data);
			$headers['Content-Type'] = 'application/json; charset=UTF-8';
		}

		return $this->request('POST', $url, $data, $additional_headers);
	}

	/**
	 * Make a custom request
	 * @param  string $method             HTTP verb (GET, POST, PUT, etc.)
	 * @param  string $url                URL to request
	 * @param  string $content            Data to send with request
	 * @param  [type] $additional_headers [description]
	 * @return [type]                     [description]
	 */
	public function request($method, $url, $data = null, $additional_headers = null)
	{
		$headers = $this->headers;
		$data = '';

		if (!is_null($additional_headers))
		{
			$headers = array_merge($headers, $additional_headers);
		}

		if (!empty($this->cookies))
		{
			$headers['Cookie'] = '';

			foreach ($this->cookies as $key=>$value)
			{
				if (!empty($headers['Cookie'])) $headers['Cookie'] .= '; ';
				$headers['Cookie'] .= $key . '=' . $value;
			}
		}

		$request = '';

		foreach ($headers as $key=>$value)
		{
			$request .= $key . ': ' . $value . "\r\n";
		}

		$http_options = [
			'header'=> $request,
			'data'	=>	$data,
		];

		$http_options = array_merge($this->http_options, $http_options);

		$context = stream_context_create([
			'http'  =>  $http_options,
			'ssl'	=>	$this->ssl_options,
		]);

		$r = new stdObj;
		$r->url = $url;
		$r->headers = [];
		$r->body = null;
		$r->fail = true;
		$r->cookies = [];
		$r->status = null;
		$r->sent_request = $request;

		$r->body = file_get_contents($url, false, $context);

		if ($r->body === false && empty($http_response_header))
			return $r;

		foreach ($http_response_header as $line)
		{
			if (preg_match('!^([a-z0-9_-]+): (.*)$!i', $line, $match))
			{
				$key = $match[1];

				if (strtolower($key) == 'set-cookie' && preg_match('!^([^=]+)=([^;]*)!', $match[2], $c_match))
				{
					$r->cookies[$c_match[1]] = urldecode($c_match[2]);
				}

				if (array_key_exists($key, $r->headers))
				{
					if (!is_array($r->headers[$key]))
					{
						$r->headers[$key] = array($r->headers[$key]);
					}

					$r->headers[$key][] = $match[2];
				}
				else
				{
					$r->headers[$key] = $match[2];
				}
			}
			else
			{
				if (preg_match('!^HTTP/1\.[01] ([0-9]{3}) !', $line, $match))
				{
					$r->status = $match[1];
				}

				$r->headers[] = $line;
			}
		}

		$this->cookies = array_merge($this->cookies, $r->cookies);

		return $r;
	}
}