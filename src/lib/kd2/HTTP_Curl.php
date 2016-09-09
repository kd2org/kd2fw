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

class HTTP_Curl extends HTTP
{
	public function __construct()
	{
		parent::__construct();

		if (!function_exists('curl_init'))
		{
			throw new \Exception('curl library is not loaded.');
		}
	}

	protected function httpClientRequest($method, $url, $data, $headers)
	{
		// Sets headers in the right format
		foreach ($headers as $key=>&$header)
		{
			$header = $key . ': ' . $header;
		}

		// Concatenates cookies
		$cookies = [];

		foreach ($this->cookies as $key=>$value)
		{
			$cookies[] = $key . '=' . $value;
		}

		$cookies = implode('; ', $cookies);

		$r = new HTTP_Response;

		$c = curl_init();

		curl_setopt_array($c, [
			CURLOPT_URL            =>	$url,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_COOKIESESSION  => true,
			CURLOPT_FOLLOWLOCATION => !empty($this->http_options['max_redirects']),
			CURLOPT_MAXREDIRS      => !empty($this->http_options['max_redirects']) ? (int) $this->http_options['max_redirects'] : 0,
			CURLOPT_SSL_VERIFYPEER => !empty($this->ssl_options['verify_peer']),
			CURLOPT_SSL_VERIFYHOST => !empty($this->ssl_options['verify_peer_name']) ? 2 : 0,
			CURLOPT_CUSTOMREQUEST  => $method,
			CURLOPT_COOKIE         => $cookies,
			CURLOPT_TIMEOUT        => !empty($this->http_options['timeout']) ? (int) $this->http_options['timeout'] : 30,
			CURLOPT_USERAGENT      => $this->user_agent,
			CURLOPT_POST           => $method == 'POST' ? true : false,
			CURLOPT_SAFE_UPLOAD    => true, // Disable file upload with values beginning with @
			CURLOPT_POSTFIELDS     => $data,
			CURLINFO_HEADER_OUT    => true,
		]);

		curl_setopt($c, CURLOPT_HEADERFUNCTION, function ($c, $header) use (&$r) {
			$r->raw_headers .= $header;

			$name = trim(strtok(trim($header), ':'));
			$value = strtok('');

			if ($name === '')
			{
				return strlen($header);
			}
			elseif ($value === false)
			{
				$r->headers[] = $name;
			}
			else
			{
				$name = strtolower($name);
				$value = trim($value);

				if ($name == 'set-cookie')
				{
					$cookie_key = strtok($value, '=');
					$cookie_value = strtok(';');
					$r->cookies[$cookie_key] = $cookie_value;
				}

				// Multiple headers with the same name
				if (array_key_exists($name, $r->headers))
				{
					if (!is_array($r->headers[$name]))
					{
						$r->headers[$name] = [$r->headers[$name]];
					}

					$r->headers[$name][] = $value;
				}
				else
				{
					$r->headers[$name] = $value;
				}
			}

			return strlen($header);
		});

		$request = curl_getinfo($c, CURLINFO_HEADER_OUT) . $data;

		$r->url = $url;
		$r->request = $request;

		$r->body = curl_exec($c);

		if ($error = curl_error($c))
		{
			if (!empty($this->http_options['ignore_errors']))
			{
				$r->body = $error;
				return $r;
			}

			throw new \RuntimeException('cURL error: ' . $error);
		}

		if ($r->body === false)
		{
			return $r;
		}

		$r->fail = false;
		$r->size = strlen($r->body);
		$r->status = curl_getinfo($c, CURLINFO_HTTP_CODE);

		curl_close($c);

		return $r;
	}
}