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
	 * User agent
	 * @var string
	 */
	public $user_agent = null;
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
	 * Be warned that by default we allow self signed certificates
	 * See http://php.net/manual/en/context.ssl.php
	 * @var array
	 */
	public $ssl_options = [
		'verify_peer'		=>	true,
		'verify_peer_name'	=>	true,
		'allow_self_signed'	=>	true,
		'SNI_enabled'		=>	true,
	];

	/**
	 * Options for the HTTP stream wrapper
	 * See http://php.net/manual/en/context.http.php
	 * @var array
	 */
	public $http_options = [
		'max_redirects'		=>	10,
		'timeout'			=>	10,
		'ignore_errors'		=>	true,
	];

	/**
	 * List of cookies sent to the server, will contain the cookies
	 * set by the server after a request.
	 * @var array
	 */
	public $cookies = [];

	/**
	 * Prepend this string to every request URL
	 * (helpful for API calls)
	 * @var string
	 */
	public $url_prefix = '';

	/**
	 * Class construct
	 */
	public function __construct()
	{
		// Random user agent
		$this->user_agent = $this->uas[array_rand($this->uas)];
	}

	/**
	 * Enable or disable SSL security,
	 * this includes disabling or enabling self signed certificates
	 * which are allowed by default
	 * @param boolean $enable TRUE to enable certificate check, FALSE to disable
	 */
	public function setSecure($enable = true)
	{
		$this->ssl_options['verify_peer'] = $enable;
		$this->ssl_options['verify_peer_name'] = $enable;
		$this->ssl_options['allow_self_signed'] = !$enable;
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
			$additional_headers['Content-Length'] = strlen($data);
			$additional_headers['Content-Type'] = 'application/x-www-form-urlencoded';
		}
		elseif ($type == 'json')
		{
			$data = json_encode($data);
			$additional_headers['Content-Length'] = strlen($data);
			$additional_headers['Content-Type'] = 'application/json; charset=UTF-8';
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
		$url = $this->url_prefix . $url;

		$headers = $this->headers;

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
			'method' 	=> 	$method,
			'header'	=>	$request,
			'content'	=>	$data,
			'user_agent'=> 	$this->user_agent,
		];

		$http_options = array_merge($this->http_options, $http_options);

		$context = stream_context_create([
			'http'  =>  $http_options,
			'ssl'	=>	$this->ssl_options,
		]);

		$request = $method . ' ' . $url . "\r\n" . $request . "\r\n" . $data;

		$r = new \stdClass;
		$r->url = $url;
		$r->headers = [];
		$r->body = null;
		$r->fail = true;
		$r->cookies = [];
		$r->status = null;
		$r->request = $request;
		$r->size = 0;

		$r->body = file_get_contents($url, false, $context);

		if ($r->body === false && empty($http_response_header))
			return $r;

		$r->fail = false;
		$r->size = strlen($r->body);

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