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

/**
 * Route: routing HTTP/CLI requests
 *
 * @author  bohwaz  http://bohwaz.net/
 * @license BSD
 * @version 0.1
 */

namespace KD2;

class RouteException extends \RuntimeException {}

class Route
{
	/**
	 * Known HTTP request methods
	 * @var array
	 */
	static protected $http_methods = [
		// RFC 2616
		'GET', 'POST', 'HEAD', 'PUT', 'DELETE', 'TRACE', 'OPTIONS', 'CONNECT',
		'PATCH',
		// RFC 2518
		'PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK',
		// RFC 3253
		'VERSION-CONTROL', 'REPORT', 'CHECKOUT', 'CHECKIN', 'UNCHECKOUT',
		'MKWORKSPACE', 'UPDATE', 'LABEL', 'MERGE', 'BASELINE-CONTROL',
		'MKACTIVITY',
		// RFC 3648
		'ORDERPATCH',
		// RFC 3744
		'ACL',
	];

	/**
	 * Returns HTTP request method
	 * @return string HTTP request method (eg. GET, POST...)
	 */
	static public function requestMethod()
	{
		if (empty($_SERVER['REQUEST_METHOD']))
			return null;

		return strtoupper($_SERVER['REQUEST_METHOD']);
	}

	/**
	 * Returns the URI requested by the HTTP requests
	 * @param  boolean $relative If TRUE will send the request URI relative
	 * to the document root (useful if your app is not in the root directory
	 * of the virtual host).
	 * If FALSE will just return the raw request URI.
	 * @return string            Request URI
	 */
	static public function requestURI($relative = false)
	{
		if (empty($_SERVER['REQUEST_URI']))
		{
			return null;
		}

		$url = parse_url($_SERVER['REQUEST_URI']);

		if (empty($url['path']))
		{
			return null;
		}

		$uri = $url['path'];

		if ($relative)
		{
			// Relative, for when your app is in http://server.tld/sub/directory/
			$prefix = substr(__DIR__, strlen($_SERVER['DOCUMENT_ROOT']));
			$uri = substr($uri, strlen($prefix));
		}

		return $uri;
	}

	/**
	 * Magic call for HTTP methods, eg. GET(...) post(...)
	 * @param  string $name      method name
	 * @param  array $arguments  Method arguments
	 * @return mixed
	 */
	static public function __callStatic($name, $arguments)
	{
		$method = strtoupper($name);
		$method = str_replace('_', '-', $method);

		if (in_array($method, self::$http_methods))
		{
			if (count($arguments) != 2)
			{
				throw new \BadMethodCallException($method . ' requires 2 parameters.');
			}

			return self::HTTP($arguments[0], $arguments[1], $method);
		}

		throw new \BadMethodCallException('Unknown method name: ' . $name);
	}

	/**
	 * HTTP router, with optional method
	 * @param string   $pattern  Regexp pattern for matching the request URI
	 * @param Callable $callback Callback to use when the regexp match (any matching pattern will be passed as argument)
	 * @param string   $method   HTTP request method
	 */
	static public function HTTP($pattern, Callable $callback, $method = null)
	{
		if (!is_null($method) && $method != self::requestMethod())
		{
			throw new RouteException('Method Not Allowed', 405);
		}

		return self::route(self::requestURI(true), $pattern, $callback);
	}

	/**
	 * Simple command line router
	 * @param string   $pattern  Regexp pattern to match in the arguments
	 * @param Callable $callback Callack to use if regexp is matching
	 */
	static public function CLI($pattern, Callable $callback)
	{
		$args = $_SERVER['argv'];
		array_shift($args);
		$args = implode(' ', $args);
		
		return self::route($args, $pattern, $callback);
	}

	/**
	 * Main routing logic
	 * @param  string   $path     	Path to match/route against
	 * @param  string   $pattern  	Regular expression to match to execute this route
	 * @param  Callable $callback 	Route callback, called if $pattern is matching $path
	 * Any capturing pattern will be passed to callback: $callback(capture1, capture2...)
	 * @return mixed				TRUE if the route matched, FALSE if not
	 */
	static public function route($path, $pattern, Callable $callback)
	{
		// Allow for {id}, {id_bis?}, {id:\d+}, {login?:(?i:\w+\.\w+\d+)}
		$name_pattern = '(\w+(?:_\w+)*)(\?)?(?:\:(.+?))?';
		$replace_pattern = sprintf('#\{%s\}#', $name_pattern);

		// Make a real regexp
		$pattern = preg_replace_callback($replace_pattern, function($match) {
			$pattern = empty($match[3]) ? '.*?' : $match[3];
			$opt = empty($match[2]) ? '' : '?';
			return '(' . $pattern . ')' . $opt;
		}, $pattern);

		$pattern = '#^' . $pattern . '$#';

		if (preg_match($pattern, self::requestURI(true), $match))
		{
			unset($match[0]);
			call_user_func_array($callback, $match);
			return true;
		}

		return false;
	}
}