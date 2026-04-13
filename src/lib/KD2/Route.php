<?php
/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
	All rights reserved.

	KD2FW is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Foobar is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

/**
 * Route: routing HTTP/CLI requests
 *
 * @author  bohwaz  http://bohwaz.net/
 */

namespace KD2;

class RouteException extends \RuntimeException {}

/**
 * @deprecated
 */
class Route
{
	/**
	 * Set to true when one of the routes returns true
	 * @var null
	 */
	static protected $routed;

	/**
	 * Stores request URI
	 * @var string
	 */
	static protected $request_uri;

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
	 * List of HTTP codes and messages
	 * @var array
	 */
	static protected $http_codes = [
		100 => 'Continue',
		101 => 'Switching Protocols',
		102 => 'Processing',
		200 => 'OK',
		201 => 'Created',
		202 => 'Accepted',
		203 => 'Non-Authoritative Information',
		204 => 'No Content',
		205 => 'Reset Content',
		206 => 'Partial Content',
		207 => 'Multi-Status',
		300 => 'Multiple Choices',
		301 => 'Moved Permanently',
		302 => 'Found',
		303 => 'See Other',
		304 => 'Not Modified',
		305 => 'Use Proxy',
		306 => 'Switch Proxy',
		307 => 'Temporary Redirect',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Request Entity Too Large',
		414 => 'Request-URI Too Long',
		415 => 'Unsupported Media Type',
		416 => 'Requested Range Not Satisfiable',
		417 => 'Expectation Failed',
		418 => 'I\'m a teapot',
		422 => 'Unprocessable Entity',
		423 => 'Locked',
		424 => 'Failed Dependency',
		425 => 'Unordered Collection',
		426 => 'Upgrade Required',
		449 => 'Retry With',
		450 => 'Blocked by Windows Parental Controls',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
		505 => 'HTTP Version Not Supported',
		506 => 'Variant Also Negotiates',
		507 => 'Insufficient Storage',
		509 => 'Bandwidth Limit Exceeded',
		510 => 'Not Extended'
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
	 * @return string            Request URI
	 */
	static public function requestURI($relative = false)
	{
		if (is_null(self::$request_uri))
		{
			self::setRequestURI();
		}

		return self::$request_uri;
	}

	static public function setRequestURI($uri = null)
	{
		if ($uri === null)
		{
			$url = parse_url($_SERVER['REQUEST_URI']);

			if (empty($url['path']))
			{
				return null;
			}

			$uri = rawurldecode($url['path']);
		}

		self::$request_uri = $uri;
		return true;
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

		return self::route(self::requestURI(), $pattern, $callback);
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
	 * @return boolean				TRUE if the route matched, FALSE if not
	 */
	static public function route($path, $pattern, Callable $callback)
	{
		if (self::$routed)
		{
			return false;
		}

		// Allow for {id}, {id_bis?}, {id:\d+}, {login?:(?i:\w{2}\.\w+\d+)}
		$replace_pattern = '#(?<!\\\\)\{(\w+(?:_\w+)*)(\?)?(?:\:((?:[^{}]|(?R))*?))?\}#i';

		// Make a real regexp
		$pattern = preg_replace_callback($replace_pattern, function($match) {
			$pattern = empty($match[3]) ? '.*?' : $match[3];
			$opt = empty($match[2]) ? '' : '?';
			return '(' . $pattern . ')' . $opt;
		}, $pattern);

		$pattern = '#^' . $pattern . '$#u';

		if (preg_match($pattern, self::requestURI(), $match))
		{
			unset($match[0]);
			self::$routed = call_user_func_array($callback, $match);
			return self::$routed;
		}

		return false;
	}

	/**
	 * Will be called if no route has been successful
	 * @param  Callable $callback Route callback
	 * @return boolean
	 */
	static public function fallback(Callable $callback)
	{
		if (!self::$routed)
		{
			return call_user_func($callback, self::requestURI());
		}

		return false;
	}

	/**
	 * Sets the HTTP header status
	 * @param integer $status  Status code
	 */
	static public function setStatus($status)
	{
		$status = (int)$status;

		if (!array_key_exists($status, self::$http_codes))
		{
			throw new \InvalidArgumentException('Invalid HTTP code: ' . $status);
		}

		$message = self::$http_codes[$status];

	    return header($_SERVER['SERVER_PROTOCOL'] . ' ' . $status . ' ' . $message, true, $status);
	}

	static public function isExistingFile($path)
	{
		$file_path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . ltrim(self::requestURI(), '/');
		return is_file($file_path);
	}

	static public function isExistingDir($path)
	{
		$file_path = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . ltrim(self::requestURI(), '/');
		return is_dir($file_path);
	}

	static public function isExistingURI()
	{
		$uri = self::requestURI();
		$path = $_SERVER['DOCUMENT_ROOT'] . $uri;

		if (is_file($path))
		{
			return true;
		}

		return false;
	}
}