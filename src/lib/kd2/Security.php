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

class Security
{
	/**
	 * Allowed schemes/protocols in URLs
	 * @var array
	 */
	static protected $whitelist_url_schemes = [
		'http'  =>  '://',
		'https' =>  '://',
		'ftp'   =>  '://',
		'mailto'=>  ':',
		'xmpp'  =>  ':',
		'news'  =>  ':',
		'nntp'  =>  '://',
		'tel'   =>  ':',
		'callto'=>  ':',
		'ed2k'  =>  '://',
		'irc'   =>  '://',
		'magnet'=>  ':',
		'mms'   =>  '://',
		'rtsp'  =>  '://',
		'sip'   =>  ':',
	];

	static public function tokenSetSecret($secret)
	{
		
	}

	/**
	 * Generate a single use token and return the value
	 * The token will be HMAC signed and you can use it directly in a HTML form
	 * @param  string $action An action description
	 * @return string         HMAC signed token
	 */
	static public function tokenGenerate($action = null)
	{

	}

	static public function tokenCheck($action = null, $value = null)
	{

	}

	static public function tokenHTML($action = null)
	{

	}

	static public function checkEmailAddress($email)
	{
		return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
	}

	static public function checkURL($url)
	{
		return (bool) filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED);
	}


	/**
	 * Protects a URL/URI given as an image/link target against XSS attacks
	 * (at least it tries)
	 * @param  string 	$value 	Original URL
	 * @return string 	Filtered URL but should still be escaped, like with htmlspecialchars for HTML documents
	 */
	static public function protectURL($value)
	{
		// Decode entities and encoded URIs
		$value = rawurldecode($value);
		$value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

		// Convert unicode entities back to ASCII
		// unicode entities don't always have a semicolon ending the entity
		$value = preg_replace_callback('~&#x0*([0-9a-f]+);?~i', 
			function($match) { return chr(hexdec($match[1])); }, 
			$value);
		$value = preg_replace_callback('~&#0*([0-9]+);?~', 
			function ($match) { return chr($match[1]); },
			$value);

		// parse_url already helps against some XSS malformed URLs
		$url = parse_url($value);

		// This should not happen as parse_url can usually deal with most malformed URLs
		if (!$url)
		{
			return false;
		}

		$value = '';

		if (!empty($url['scheme']))
		{
			$url['scheme'] = strtolower($url['scheme']);

			if (!array_key_exists($url['scheme'], self::$whitelist_url_schemes))
			{
				return '';
			}

			$value .= $url['scheme'] . self::$whitelist_url_schemes[$url['scheme']];
		}

		if (!empty($url['user']))
		{
			$value .= rawurlencode($url['user']);

			if (!empty($url['pass']))
			{
				$value .= ':' . rawurlencode($url['pass']);
			}

			$value .= '@';
		}

		if (!empty($url['host']))
		{
			$value .= $url['host'];
		}

		if (!empty($url['port']) && !($url['scheme'] == 'http' && $url['port'] == 80) 
			&& !($url['scheme'] == 'https' && $url['port'] == 443))
		{
			$value .= ':' . (int) $url['port'];
		}

		if (!empty($url['path']))
		{
			// Split and re-encode path
			$url['path'] = explode('/', $url['path']);
			$url['path'] = array_map('rawurldecode', $url['path']);
			$url['path'] = array_map('rawurlencode', $url['path']);
			$url['path'] = implode('/', $url['path']);

			// Keep leading /~ un-encoded for compatibility with user accounts on some web servers
			$url['path'] = preg_replace('!^/%7E!', '/~', $url['path']);

			$value .= $url['path'];
		}

		if (!empty($url['query']))
		{
			// We can't use parse_str and build_http_string to sanitize url here
			// Or else we'll get things like ?param1&param2 transformed in ?param1=&param2=
			$query = explode('&', $url['query'], 2);

			foreach ($query as &$item)
			{
				$item = explode('=', $item);

				if (isset($item[1]))
				{
					$item = rawurlencode(rawurldecode($item[0])) . '=' . rawurlencode(rawurldecode($item[1]));
				}
				else
				{
					$item = rawurlencode(rawurldecode($item[0]));
				}
			}

			$value .= '?' . implode('&', $query);
		}

		if (!empty($url['fragment']))
		{
			$value .= '#' . rawurlencode(rawurldecode($url['fragment']));
		}
		
		return $value;
	}

}