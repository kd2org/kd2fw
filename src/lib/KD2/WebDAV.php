<?php
/*
	This file is part of KD2FW -- <https://kd2.org/>

	Copyright (c) 2001-2022+ BohwaZ <https://bohwaz.net/>
	All rights reserved.

	KD2FW is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	KD2FW is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with KD2FW.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2;

class WebDAV_Exception extends \RuntimeException {}

/**
 * This is a minimal, lightweight, and self-supported WebDAV server
 * it does not require anything out of standard PHP, not even an XML library.
 * This makes it more secure by design, and also faster and lighter.
 *
 * You have to extend this class and implement all the abstract methods to
 * get a class-1 compliant server. Implement also the lock, unlock and getLock
 * methods to get a class-2 server (also set LOCK constant to true).
 *
 * This also supports HTTP ranges for GET.
 *
 * Differences with SabreDAV and RFC:
 * - PROPPATCH is not implemented by default
 * - If-Match, If-Range are not implemented
 *
 * @author BohwaZ <https://bohwaz.net/>
 */
abstract class WebDAV
{
	/**
	 * Set this content to TRUE in extended class if you support locking
	 * via lock, unlock and getLock methods
	 */
	const LOCK = false;

	/**
	 * Parse PROPFIND XML properties
	 * By default it's disabled as it is not necessary for core WebDAV features
	 * Also this requires having simpleXML
	 */
	protected bool $parse_propfind = false;

	/**
	 * Return the requested resource
	 *
	 * @param  string $uri Path to resource
	 * @return null|array An array containing one of those keys:
	 * path => Full filesystem path to a local file, it will be streamed directly to the client
	 * resource => a PHP resource (eg. returned by fopen) that will be streamed directly to the client
	 * content => a string that will be returned
	 * or NULL if the resource can not be returned (404)
	 *
	 * It is recommended to use X-SendFile inside this method to make things faster.
	 * @see https://tn123.org/mod_xsendfile/
	 */
	abstract protected function get(string $uri): ?array;

	/**
	 * Return TRUE if the requested resource exists, or FALSE
	 *
	 * @param  string $uri
	 * @return bool
	 */
	abstract protected function exists(string $uri): bool;

	/**
	 * Return the requested resource properties
	 *
	 * This method is used for HEAD requests
	 *
	 * @param string $uri Path to resource
	 * @param null|array $requested_properties Properties requested by the client, NULL if all available properties are requested,
	 * each item as a key like 'namespace_url:property_name', eg. 'DAV::getcontentlength' or 'http://owncloud.org/ns:size'
	 * @param int $depth Depth, can be 0 or 1
	 * @return null|array An array containing the requested properties, each item must have a key
	 * of the same form as the requested properties.
	 *
	 * This method MUST return NULL if the resource does not exist
	 */
	abstract protected function properties(string $uri, ?array $requested_properties, int $depth);

	const BASIC_PROPERTIES = [
		'DAV::resourcetype', // should be empty for files, and 'collection' for directories
		'DAV::getcontenttype', // MIME type
		'DAV::getlastmodified', // File modification date (must be \DateTimeInterface)
		'DAV::getcontentlength', // file size
		'DAV::displayname', // File name for display
	];

	const EXTENDED_PROPERTIES = [
		'DAV::getetag',
		'DAV::creationdate',
		'DAV::lastaccessed',
		'DAV::ishidden', // Microsoft thingy
	];

	// Custom property
	const PROP_THUMB_URL = 'urn:karadav:thumb_url'; // Thumbnail URL for file preview in directory view

	/**
	 * Create or replace a resource
	 * @param  string $uri     Path to resource
	 * @param  resource $pointer A PHP file resource containing the sent data (note that this might not always be seekable)
	 * @return bool Return TRUE if the resource has been created, or FALSE it has just been updated.
	 */
	abstract protected function put(string $uri, $pointer): bool;

	/**
	 * Delete a resource
	 * @param  string $uri
	 * @return void
	 */
	abstract protected function delete(string $uri): void;

	/**
	 * Copy a resource from $uri to $destination
	 * @param  string $uri
	 * @param  string $destination
	 * @return bool TRUE if the destination has been overwritten
	 */
	abstract protected function copy(string $uri, string $destination): bool;

	/**
	 * Move (rename) a resource from $uri to $destination
	 * @param  string $uri
	 * @param  string $destination
	 * @return bool TRUE if the destination has been overwritten
	 */
	abstract protected function move(string $uri, string $destination): bool;

	/**
	 * Create collection of resources (eg. a directory)
	 * @param  string $uri
	 * @return void
	 */
	abstract protected function mkcol(string $uri): void;

	/**
	 * Return a list of resources for target $uri
	 *
	 * @param  string $uri
	 * @param  array $properties List of properties requested by client (see ::properties)
	 * @return iterable An array or other iterable (eg. a generator)
	 * where each item has a key string containing the name of the resource (eg. file name),
	 * and the value being an array of properties, or NULL
	 */
	abstract protected function list(string $uri, array $properties): iterable;

	/**
	 * Lock the requested resource
	 * @param  string $uri   Requested resource
	 * @param  string $token Unique token given to the client for this resource
	 * @param  string $scope Locking scope, either ::SHARED_LOCK or ::EXCLUSIVE_LOCK constant
	 * @return void
	 */
	protected function lock(string $uri, string $token, string $scope): void {}

	/**
	 * Unlock the requested resource
	 * @param  string $uri   Requested resource
	 * @param  string $token Unique token sent by the client
	 * @return void
	 */
	protected function unlock(string $uri, string $token): void {}

	/**
	 * If $token is supplied, this method MUST return ::SHARED_LOCK or ::EXCLUSIVE_LOCK
	 * if the resource is locked with this token. If the resource is unlocked, or if it is
	 * locked with another token, it MUST return NULL.
	 *
	 * If $token is left NULL, then this method must return ::EXCLUSIVE_LOCK if there is any
	 * exclusive lock on the resource. If there are no exclusive locks, but one or more
	 * shared locks, it MUST return ::SHARED_LOCK. If the resource has no lock, it MUST
	 * return NULL.
	 *
	 * @param  string      $uri
	 * @param  string|null $token
	 * @return string|null
	 */
	protected function getLock(string $uri, ?string $token = null): ?string {}

	/**
	 * List of language strings used in the web UI
	 */
	const LANGUAGE_STRINGS = [
		'title'  => 'Files',
		'back'   => 'Parent',
		'empty'  => 'There are no files in this directory.',
		'bytes_unit' => 'B', // B for Bytes
	];

	// You have reached the end of the abstract methods :)

	const SHARED_LOCK = 'shared';
	const EXCLUSIVE_LOCK = 'exclusive';

	/**
	 * Base server URI (eg. "/index.php/webdav/")
	 */
	protected string $base_uri;

	/**
	 * Original URI passed to route() before trim
	 */
	protected string $original_uri;

	public function setBaseURI(string $uri): void
	{
		$this->base_uri = $uri;
	}

	protected function http_delete(string $uri): ?string
	{
		// check RFC 2518 Section 9.2, last paragraph
		if (isset($_SERVER['HTTP_DEPTH']) && $_SERVER['HTTP_DEPTH'] != 'infinity') {
			throw new WebDAV_Exception('We can only delete to infinity', 400);
		}

		$this->checkLock($uri);

		$this->delete($uri);

		if (static::LOCK && ($token = $this->getLockToken())) {
			$this->unlock($uri, $token);
		}

		http_response_code(204);
		header('Content-Length: 0', true);
		return null;
	}

	protected function http_put(string $uri): ?string
	{
		if (!empty($_SERVER['HTTP_CONTENT_TYPE']) && !strncmp($_SERVER['HTTP_CONTENT_TYPE'], 'multipart/', 10)) {
			throw new WebDAV_Exception('Multipart PUT requests are not supported', 501);
		}

		if (!empty($_SERVER['HTTP_CONTENT_ENCODING'])) {
			throw new WebDAV_Exception('Content Encoding is not supported', 501);
		}

		if (!empty($_SERVER['HTTP_CONTENT_RANGE'])) {
			throw new WebDAV_Exception('Content Range is not supported', 400);
		}

		// See SabreDAV CorePlugin for reason why OS/X Finder is buggy
		if (isset($_SERVER['HTTP_X_EXPECTED_ENTITY_LENGTH'])) {
			throw new WebDAV_Exception('This server is not compatible with OS/X finder. Consider using a different WebDAV client or webserver.', 403);
		}

		$this->checkLock($uri);

		if (!empty($_SERVER['HTTP_IF_MATCH'])) {
			$etag = trim($_SERVER['HTTP_IF_MATCH'], '" ');
			$prop = $this->properties($uri, ['DAV::getetag'], 0);

			if (!empty(['DAV::getetag']) && $prop['DAV::getetag'] != $etag) {
				throw new WebDAV_Exception('ETag did not match condition', 412);
			}
		}

		$created = $this->put($uri, fopen('php://input', 'r'));

		$prop = $this->properties($uri, ['DAV::getetag'], 0);

		if (!empty($prop['DAV::getetag'])) {
			$value = $prop['DAV::getetag'];

			if (substr($value, 0, 1) != '"') {
				$value = '"' . $value . '"';
			}

			header(sprintf('ETag: %s', $value));
		}

		http_response_code($created ? 201 : 204);
		return null;
	}

	protected function http_head(string $uri, array &$props = []): ?string
	{
		$props = $this->properties($uri, array_merge(self::BASIC_PROPERTIES, ['DAV::getetag']), 0);

		if (!$props) {
			throw new WebDAV_Exception('Resource Not Found', 404);
		}

		http_response_code(200);

		if (isset($props['DAV::getlastmodified'])
			&& $props['DAV::getlastmodified'] instanceof \DateTimeInterface) {
			header(sprintf('Last-Modified: %s', $props['DAV::getlastmodified']->format(\DATE_RFC7231)));
		}

		if (!empty($props['DAV::getetag'])) {
			$value = $props['DAV::getetag'];

			if (substr($value, 0, 1) != '"') {
				$value = '"' . $value . '"';
			}

			header(sprintf('ETag: %s', $value));
		}

		if (empty($props['DAV::resourcetype']) || $props['DAV::resourcetype'] != 'collection') {
			if (!empty($props['DAV::getcontenttype'])) {
				header(sprintf('Content-Type: %s', $props['DAV::getcontenttype']));
			}

			if (!empty($props['DAV::getcontentlength'])) {
				header(sprintf('Content-Length: %d', $props['DAV::getcontentlength']));
				header('Accept-Ranges: bytes');
			}
		}

		return null;
	}

	protected function html_directory(string $uri, iterable $list, array $strings = self::LANGUAGE_STRINGS): ?string
	{
		// Not a file: let's serve a directory listing if you are browsing with a web browser
		if (substr($this->original_uri, -1) != '/') {
			http_response_code(301);
			header(sprintf('Location: /%s/', trim($this->base_uri . $uri, '/')), true);
			return null;
		}

		$out = '<!DOCTYPE html><html><head><style>
			body { font-size: 1.1em; font-family: Arial, Helvetica, sans-serif; }
			table { border-collapse: collapse; }
			th, td { padding: .5em; text-align: left; border: 2px solid #ccc; }
			span { font-size: 40px; line-height: 40px; }
			td img { max-width: 100px; max-height: 100px; }
			b { font-size: 1.4em; }
			td:nth-child(1) { text-align: center; }
			</style>';

		$out .= sprintf('<title>%s</title></head><body><h1>%1$s</h1><table>', htmlspecialchars($uri ? str_replace('/', ' / ', $uri) . ' - ' . $strings['title'] : $strings['title']));

		if (trim($uri)) {
			$out .= sprintf('<tr><td><span>&#x21B2;</span></td><th colspan=3><a href="../"><b>%s</b></a></th></tr>', $strings['back']);
		}

		$props = null;

		foreach ($list as $file => $props) {
			if (null === $props) {
				$props = $this->properties(trim($uri . '/' . $file, '/'), self::BASIC_PROPERTIES, 0);
			}

			$collection = !empty($props['DAV::resourcetype']) && $props['DAV::resourcetype'] == 'collection';

			if ($collection) {
				$out .= sprintf('<tr><td><span>&#x1F4C1;</span></td><th colspan=3><a href="%s/"><b>%s</b></a></th></tr>', rawurlencode($file), htmlspecialchars($file));
			}
			else {
				if (!empty($props[self::PROP_THUMB_URL])) {
					$icon = sprintf('<a href="%s"><img src="%s" /></a>', rawurlencode($file), htmlspecialchars($props[self::PROP_THUMB_URL]));
				}
				else {
					$icon = '<span>&#x1F5CE;</span>';
				}

				$out .= sprintf('<tr><td>%s</td><th><a href="%s">%s</a></th><td>%s</td><td style="text-align: right">%s</td></tr>',
					$icon,
					rawurlencode($file),
					htmlspecialchars($file),
					$props['DAV::getcontenttype'] ?? null,
					isset($props['DAV::getcontentlength']) ? $this->format_bytes($props['DAV::getcontentlength']) : null
				);
			}
		}

		$out .= '</table>';

		if (null === $props) {
			$out .= sprintf('<p>%s</p>', $strings['empty']);
		}

		$out .= '</body></html>';

		return $out;
	}

	public function format_bytes(int $bytes, string $unit = self::LANGUAGE_STRINGS['bytes_unit']): string
	{
		if ($bytes >= 1024*1024*1024) {
			return round($bytes / (1024*1024*1024), 1) . ' G' . $unit;
		}
		elseif ($bytes >= 1024*1024) {
			return round($bytes / (1024*1024), 1) . ' M' . $unit;
		}
		elseif ($bytes >= 1024) {
			return round($bytes / 1024, 1) . ' K' . $unit;
		}
		else {
			return $bytes . ' ' . $unit;
		}
	}

	protected function http_get(string $uri): ?string
	{
		$props = [];
		$this->http_head($uri, $props);

		$is_collection = !empty($props['DAV::resourcetype']) && $props['DAV::resourcetype'] == 'collection';
		$out = '';

		if ($is_collection) {
			$list = $this->list($uri, self::BASIC_PROPERTIES + [self::PROP_THUMB_URL]);

			if (!isset($_SERVER['HTTP_ACCEPT']) || false === strpos($_SERVER['HTTP_ACCEPT'], 'html')) {
				$list = is_array($list) ? $list : iterator_to_array($list);

				if (!count($list)) {
					return "Nothing in this collection\n";
				}

				return implode("\n", array_keys($list));
			}

			header('Content-Type: text/html; charset=utf-8', true);

			return $this->html_directory($uri, $list);
		}

		$file = $this->get($uri);

		if (!$file) {
			throw new WebDAV_Exception('File Not Found', 404);
		}

		if (!isset($file['content']) && !isset($file['resource']) && !isset($file['path'])) {
			throw new \RuntimeException('Invalid file array returned by ::get()');
		}

		$length = $start = $end = null;

		if (isset($_SERVER['HTTP_RANGE'])
			&& preg_match('/^bytes=(\d*)-(\d*)$/i', $_SERVER['HTTP_RANGE'], $match)
			&& $match[1] . $match[2] !== '') {
			$start = $match[1] === '' ? null : (int) $match[1];
			$end   = $match[2] === '' ? null : (int) $match[2];

			if (null !== $start && $start < 0) {
				throw new WebDAV_Exception('Start range cannot be satisfied', 416);
			}

			$this->log('HTTP Range requested: %s-%s', $start, $end);
		}

		if (isset($file['content'])) {
			$length = strlen($file['content']);

			if ($start || $end) {
				if (null !== $end && $end > $length) {
					header('Content-Range: bytes */' . $length, true);
					throw new WebDAV_Exception('End range cannot be satisfied', 416);
				}

				if ($start === null) {
					$start = $length - $end;
					$end = $start + $end;
				}
				elseif ($end === null) {
					$end = $length;
				}


				http_response_code(206);
				header(sprintf('Content-Range: bytes %s-%s/%s', $start, $end - 1, $length));
				$file['content'] = substr($file['content'], $start, $end - $start);
				$length = $end - $start;
			}

			header('Content-Length: ' . $length, true);
			echo $file['content'];
			return null;
		}

		if (isset($file['path'])) {
			$file['resource'] = fopen($file['path'], 'rb');
		}

		$seek = fseek($file['resource'], 0, SEEK_END);

		if ($seek === 0) {
			$length = ftell($file['resource']);
			fseek($file['resource'], 0, SEEK_SET);
		}

		if (($start || $end) && $seek === 0) {
			if (null !== $end && $end > $length) {
				header('Content-Range: bytes */' . $length, true);
				throw new WebDAV_Exception('End range cannot be satisfied', 416);
			}

			if ($start === null) {
				$start = $length - $end;
				$end = $start + $end;
			}
			elseif ($end === null) {
				$end = $length;
			}

			fseek($file['resource'], $start, SEEK_SET);

			http_response_code(206);
			header(sprintf('Content-Range: bytes %s-%s/%s', $start, $end - 1, $length), true);

			$length = $end - $start;
			$end -= $start;
		}
		elseif (null === $length && isset($file['path'])) {
			$end = $length = filesize($file['path']);
		}

		if (null !== $length) {
			header('Content-Length: ' . $length, true);
			$this->log('Length: %s', $length);
		}

		while (!feof($file['resource']) && ($end === null || $end > 0)) {
			$l = $end !== null ? min(8192, $end) : 8192;
			echo fread($file['resource'], $l);

			if (null !== $end) {
				$end -= 8192;
			}
		}

		fclose($file['resource']);

		return null;
	}

	protected function http_copy(string $uri): ?string
	{
		return $this->_http_copymove($uri, 'copy');
	}

	protected function http_move(string $uri): ?string
	{
		return $this->_http_copymove($uri, 'move');
	}

	protected function _http_copymove(string $uri, string $method): ?string
	{
		$destination = $_SERVER['HTTP_DESTINATION'] ?? null;

		if (!$destination) {
			throw new WebDAV_Exception('Destination not supplied', 400);
		}

		$destination = $this->getURI($destination);
		$overwrite = ($_SERVER['HTTP_OVERWRITE'] ?? null) == 'T';

		// Dolphin is removing the file name when moving to root directory
		if (empty($destination)) {
			$destination = basename($uri);
		}

		$this->log('<= Destination: %s', $destination);
		$this->log('<= Overwrite: %s (%s)', $overwrite ? 'Yes' : 'No', $_SERVER['HTTP_OVERWRITE'] ?? null);

		if (!$overwrite && $this->exists($destination)) {
			throw new WebDAV_Exception('File already exists and overwriting is disabled', 412);
		}

		if ($method == 'move') {
			$this->checkLock($uri);
		}

		$this->checkLock($destination);

		$overwritten = $this->$method($uri, $destination);

		if (static::LOCK && $method == 'move' && ($token = $this->getLockToken())) {
			$this->unlock($uri, $token);
		}

		http_response_code($overwritten ? 204 : 201);
		return null;
	}

	protected function http_mkcol(string $uri): ?string
	{
		if (!empty($_SERVER['CONTENT_LENGTH'])) {
			throw new WebDAV_Exception('Unsupported body for MKCOL', 415);
		}

		$this->mkcol($uri);

		http_response_code(201);
		return null;
	}

	/**
	 * Return a list of requested properties, if any.
	 * We are using regexp as we don't want to depend on a XML module here.
	 * Your are free to re-implement this using a XML parser if you wish
	 */
	protected function getRequestedProperties(string $body): ?array
	{
		// We only care about properties if the client asked for it
		// If not, we consider that the client just requested to get everything
		if (!preg_match('!<(?:\w+:)?propfind!', $body)) {
			return null;
		}

		$ns = [];
		$dav_ns = null;

		preg_match_all('!xmlns:(\w+)\s*=\s*"([^"]+)"!', $body, $match, PREG_SET_ORDER);

		// Find all aliased xmlns
		foreach ($match as $found) {
			$ns[$found[2]] = $found[1];
		}

		if (isset($ns['DAV:'])) {
			$dav_ns = $ns['DAV:'] . ':';
		}

		$regexp = '/<(' . $dav_ns . 'prop(?!find))[^>]*?>(.*?)<\/\1\s*>/s';
		if (!preg_match($regexp, $body, $match)) {
			return null;
		}

		// Find all properties
		preg_match_all('!<(\w+):(\w+)|<(\w+)[^>]*xmlns="([^"]+)"!', $match[2], $match, PREG_SET_ORDER);

		$properties = [];

		foreach ($match as $found) {
			$url = $found[4] ?? array_search($found[1], $ns);
			$name = $found[2] ?? $found[3];

			$properties[$url . ':' . $name] = [
				'name' => $name,
				'ns_alias' => $found[1],
				'ns_url' => $url,
			];
		}

		return $properties;
	}

	protected function http_propfind(string $uri): ?string
	{
		// We only support depth of 0 and 1
		$depth = isset($_SERVER['HTTP_DEPTH']) && empty($_SERVER['HTTP_DEPTH']) ? 0 : 1;

		$this->log('Depth: %s', $depth);

		$body = file_get_contents('php://input');

		// We don't really care about having a correct XML string,
		// but we can get better WebDAV compliance if we do
		if (isset($_SERVER['HTTP_X_LITMUS'])) {
			$xml = @simplexml_load_string($body);

			if ($e = libxml_get_last_error()) {
				throw new WebDAV_Exception('Invalid XML', 400);
			}
		}

		$requested = $this->getRequestedProperties($body);
		$requested_keys = $requested ? array_keys($requested) : null;

		// Find root element properties
		$properties = $this->properties($uri, $requested_keys, $depth);

		if (null === $properties) {
			throw new WebDAV_Exception('This does not exist', 404);
		}

		$items = [$uri => $properties];

		if ($depth) {
			foreach ($this->list($uri, $requested) as $file => $properties) {
				$path = trim($uri . '/' . $file, '/');
				$properties = $properties ?? $this->properties($path, $requested_keys, 0);

				if (!$properties) {
					$this->log('!!! Cannot find "%s"', $path);
					continue;
				}

				$items[$path] = $properties;
			}
		}

		// http_response_code doesn't know the 207 status code
		header('HTTP/1.1 207 Multi-Status', true);
		$this->dav_header();
		header('Content-Type: application/xml; charset=utf-8');

		$root_namespaces = [
			'DAV:' => 'd',
			// Microsoft Clients need this special namespace for date and time values (from PEAR/WebDAV)
			'urn:uuid:c2f41010-65b3-11d1-a29f-00aa00c14882/' => 'ns0',
		];

		$i = 0;

		foreach (($requested ?? []) as $prop) {
			if ($prop['ns_url'] == 'DAV:') {
				continue;
			}

			$root_namespaces[$prop['ns_url']] = $prop['ns_alias'] ?? 'rns' . $i++;
		}

		foreach ($items as $properties) {
			foreach ($properties as $name => $value) {
				$pos = strrpos($name, ':');
				$ns = substr($name, 0, strrpos($name, ':'));

				if (!array_key_exists($ns, $root_namespaces)) {
					$root_namespaces[$ns] = 'rns' . $i++;
				}
			}
		}

		$out = '<?xml version="1.0" encoding="utf-8"?>';
		$out .= '<d:multistatus';

		foreach ($root_namespaces as $url => $alias) {
			$out .= sprintf(' xmlns:%s="%s"', $alias, $url);
		}

		$out .= '>';

		foreach ($items as $uri => $item) {
			$e = '<d:response>';

			$path = '/' . str_replace('%2F', '/', rawurlencode(ltrim($this->base_uri . $uri, '/')));
			$e .= sprintf('<d:href>%s</d:href>', htmlspecialchars($path, ENT_XML1));
			$e .= '<d:propstat><d:prop>';

			foreach ($item as $name => $value) {
				if (null === $value) {
					continue;
				}

				$pos = strrpos($name, ':');
				$ns = substr($name, 0, strrpos($name, ':'));
				$name = substr($name, strrpos($name, ':') + 1);

				$alias = $root_namespaces[$ns];
				$attributes = '';

				if ($name == 'DAV::resourcetype' && $value == 'collection') {
					$value = '<d:collection />';
				}
				elseif ($name == 'DAV::getetag' && strlen($value) && $value[0] != '"') {
					$value = '"' . $value . '"';
				}
				elseif ($value instanceof \DateTimeInterface) {
					if ($ns == 'DAV:' && $name == 'creationdate') {
						$attributes = 'ns0:dt="dateTime.tz"';
						$value = $value->format(DATE_RFC3339);
					}
					else {
						//maybe should be only? elseif ($ns == 'DAV:') {
						$value = $value->format(DATE_RFC1123);
					}
				}
				elseif (is_array($value)) {
					$attributes = $value['attributes'] ?? '';
					$value = $value['xml'] ?? '';
				}
				else {
					$value = htmlspecialchars($value, ENT_XML1);
				}

				$e .= sprintf('<%s:%s%s>%s</%1$s:%2$s>', $alias, $name, $attributes ? ' ' . $attributes : '', $value);
			}

			$e .= '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>' . "\n";

			// Append missing properties
			if (!empty($requested)) {
				$missing_properties = array_diff($requested_keys, array_keys($item));

				if (count($missing_properties)) {
					$e .= '<d:propstat><d:prop>';

					foreach ($missing_properties as $name) {
						$pos = strrpos($name, ':');
						$ns = substr($name, 0, strrpos($name, ':'));
						$name = substr($name, strrpos($name, ':') + 1);
						$alias = $root_namespaces[$ns];

						$e .= sprintf('<%s:%s />', $alias, $name);
					}

					$e .= '</d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>';
				}
			}

			$e .= '</d:response>' . "\n";
			$out .= $e;
		}

		$out .= '</d:multistatus>';

		return $out;
	}

	// You should extend this to store the properties
	// Parsing the body is up to you
	protected function setProperties(string $uri, string $body)
	{
	}

	protected function http_proppatch(string $uri): ?string
	{
		$this->checkLock($uri);

		$body = file_get_contents('php://input');

		$this->setProperties($uri, $body);

		// http_response_code doesn't know the 207 status code
		header('HTTP/1.1 207 Multi-Status', true);
		header('Content-Type: application/xml; charset=utf-8');

		$out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
		$out .= '<d:multistatus xmlns:d="DAV:">';
		$out .= '</d:multistatus>';

		return $out;
	}

	protected function http_lock(string $uri): ?string
	{
		if (!static::LOCK) {
			throw new WebDAV_Exception('LOCK is not supported', 405);
		}

		// We don't use this currently, but maybe later?
		//$depth = !empty($this->_SERVER['HTTP_DEPTH']) ? 1 : 0;
		//$timeout = isset($_SERVER['HTTP_TIMEOUT']) ? explode(',', $_SERVER['HTTP_TIMEOUT']) : [];
		//$timeout = array_map('trim', $timeout);

		if (empty($_SERVER['CONTENT_LENGTH']) && !empty($_SERVER['HTTP_IF'])) {
			$token = $this->getLockToken();

			if (!$token) {
				throw new WebDAV_Exception('Invalid If header', 400);
			}

			$info = null;
			$ns = 'D';
			$scope = self::EXCLUSIVE_LOCK;

			$this->checkLock($uri, $token);
			$this->log('Requesting LOCK refresh: %s = %s', $uri, $scope);
		}
		else {
			$xml = file_get_contents('php://input');

			if (!preg_match('!<((?:(\w+):)?lockinfo)[^>]*>(.*?)</\1>!is', $xml, $match)) {
				throw new WebDAV_Exception('Invalid XML', 400);
			}

			// We don't care if the lock is shared or exclusive, or about anything else
			// we just store what the client sent us and will send that back
			$ns = $match[2];
			$info = $match[3];

			// Quick and dirty UUID
			$uuid = random_bytes(16);
			$uuid[6] = chr(ord($uuid[6]) & 0x0f | 0x40); // set version to 0100
			$uuid[8] = chr(ord($uuid[8]) & 0x3f | 0x80); // set bits 6-7 to 10
			$uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuid), 4));

			$token = 'opaquelocktoken:' . $uuid;
			$scope = false !== stripos($info, sprintf('<%sexclusive', $ns ? $ns . ':' : '')) ? self::EXCLUSIVE_LOCK : self::SHARED_LOCK;

			$this->log('Requesting LOCK: %s = %s', $uri, $scope);
			$locked_scope = $this->getLock($uri);

			if ($locked_scope == self::EXCLUSIVE_LOCK || ($locked_scope && $scope == self::EXCLUSIVE_LOCK)) {
				throw new WebDAV_Exception('Cannot acquire another lock, resource is locked for exclusive use', 423);
			}
		}

		$this->lock($uri, $token, $scope);

		if (null === $info) {
			$info = sprintf('
				<d:lockscope><d:%s /></d:lockscope>
				<d:locktype><d:write /></d:locktype>
				<d:owner>unknown</d:owner>', $scope);
		}

		$timeout = 60*5;
		$append = sprintf('
			<d:depth>%d</d:depth>
			<d:timeout>Second-%d</d:timeout>
			<d:locktoken><d:href>%s</d:href></d:locktoken>
		', 1, $timeout, $token);

		$info .= $append;

		http_response_code(200);
		header('Content-Type: application/xml; charset=utf-8');
		header(sprintf('Lock-Token: <%s>', $token));

		$out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
		$out .= '<d:prop xmlns:d="DAV:">';
		$out .= '<d:lockdiscovery><d:activelock>';

		$out .= $info;

		$out .= '</d:activelock></d:lockdiscovery></d:prop>';

		if ($ns != 'D') {
			$out = str_replace('D:', $ns ? $ns . ':' : '', $out);
			$out = str_replace('xmlns:D', $ns ? 'xmlns:' . $ns : 'xmlns', $out);
		}

		return $out;
	}

	protected function http_unlock(string $uri): ?string
	{
		if (!static::LOCK) {
			throw new WebDAV_Exception('LOCK is not supported', 405);
		}

		$token = $this->getLockToken();

		if (!$token) {
			throw new WebDAV_Exception('Invalid Lock-Token header', 400);
		}

		$this->log('<= Lock Token: %s', $token);

		$this->checkLock($uri, $token);

		$this->unlock($uri, $token);

		http_response_code(204);
		return null;
	}

	/**
	 * Return current lock token supplied by client
	 */
	protected function getLockToken(): ?string
	{
		if (isset($_SERVER['HTTP_LOCK_TOKEN'])
			&& preg_match('/<(.*?)>/', trim($_SERVER['HTTP_LOCK_TOKEN']), $match)) {
			return $match[1];
		}
		elseif (isset($_SERVER['HTTP_IF'])
			&& preg_match('/\(<(.*?)>\)/', trim($_SERVER['HTTP_IF']), $match)) {
			return $match[1];
		}
		else {
			return null;
		}
	}

	/**
	 * Check if the resource is protected
	 * @throws WebDAV_Exception if the resource is locked
	 */
	protected function checkLock(string $uri, ?string $token = null): void
	{
		if (!static::LOCK) {
			return;
		}

		if ($token === null) {
			$token = $this->getLockToken();
		}

		if ($token == 'DAV:no-lock') {
			throw new WebDAV_Exception('Resource is locked', 412);
		}

		// Trying to access using a parent directory
		if (isset($_SERVER['HTTP_IF'])
			&& preg_match('/<([^>]+)>\s*\(<[^>]*>\)/', $_SERVER['HTTP_IF'], $match)) {
			$root = $this->getURI($match[1]);

			if (0 !== strpos($uri, $root)) {
				throw new WebDAV_Exception('Invalid "If" header path: ' . $root, 400);
			}

			$uri = $root;
		}

		// Token is valid
		if ($token && $this->getLock($uri, $token)) {
			return;
		}
		elseif ($token) {
			throw new WebDAV_Exception('Invalid token', 423);
		}
		// Resource is locked
		elseif ($this->getLock($uri)) {
			throw new WebDAV_Exception('Resource is locked', 423);
		}
	}

	protected function dav_header()
	{
		if (static::LOCK) {
			header('DAV: 1, 2, 3');
		}
		else {
			header('DAV: 1, 3');
		}
	}

	protected function http_options(): void
	{
		http_response_code(200);
		$methods = 'GET HEAD PUT DELETE COPY MOVE PROPFIND MKCOL';

		$this->dav_header();

		if (static::LOCK) {
			$methods .= ' LOCK UNLOCK';
		}

		header('Allow: ' . $methods);
		header('Content-length: 0');
		header('Accept-Ranges: bytes');
		header('MS-Author-Via: DAV');
	}

	protected function log(string $message, ...$params)
	{
		if (PHP_SAPI == 'cli-server') {
			file_put_contents('php://stderr', vsprintf($message, $params));
		}
	}

	protected function getURI(string $source): string
	{
		$uri = parse_url($source, PHP_URL_PATH);
		$uri = rawurldecode($uri);
		$uri = trim($uri, '/');

		if ($uri . '/' == $this->base_uri) {
			$uri .= '/';
		}

		if (strpos($uri, $this->base_uri) !== 0) {
			throw new WebDAV_Exception(sprintf('Invalid URI, "%s" is outside of scope "%s"', $uri, $this->base_uri), 400);
		}

		$uri = preg_replace('!/{2,}!', '/', $uri);

		if (false !== strpos($uri, '..')) {
			throw new WebDAV_Exception(sprintf('Invalid URI: "%s"', $uri), 400);
		}

		$uri = substr($uri, strlen($this->base_uri));
		return $uri;
	}

	public function route(?string $uri = null): bool
	{
		if (null === $uri) {
			$uri = $_SERVER['REQUEST_URI'] ?? '/';
		}

		$this->original_uri = $uri;

		if ($uri . '/' == $this->base_uri) {
			$uri .= '/';
		}

		if (0 === strpos($uri, $this->base_uri)) {
			$uri = substr($uri, strlen($this->base_uri));
		}
		else {
			return false;
		}

		// Add some extra-logging for Litmus tests
		if (isset($_SERVER['HTTP_X_LITMUS']) || isset($_SERVER['HTTP_X_LITMUS_SECOND'])) {
			$this->log('X-Litmus: %s', $_SERVER['HTTP_X_LITMUS'] ?? $_SERVER['HTTP_X_LITMUS_SECOND']);
		}

		$method = $_SERVER['REDIRECT_REQUEST_METHOD'] ?? ($_SERVER['REQUEST_METHOD'] ?? null);

		// Stop and send reply to OPTIONS before anything else
		if ($method == 'OPTIONS') {
			$this->log('<= OPTIONS');
			$this->http_options();
			return true;
		}

		$uri = rawurldecode($uri);
		$uri = trim($uri, '/');
		$uri = preg_replace('!/{2,}!', '/', $uri);

		$this->log('<= %s /%s', $method, $uri);

		if (false !== strpos($uri, '..')) {
			throw new WebDAV_Exception(sprintf('Invalid URI: "%s"', $uri), 400);
		}

		try {
			// Call 'http_method' class method
			$method = 'http_' . strtolower($method);

			if (!method_exists($this, $method)) {
				throw new WebDAV_Exception('Invalid request method', 405);
			}

			$out = $this->$method($uri);

			$this->log('=> %d', http_response_code());

			if (null !== $out) {
				$this->log('=> %s', $out);
			}

			echo $out;
		}
		catch (WebDAV_Exception $e) {
			$this->log('=> %d - %s', $e->getCode(), $e->getMessage());

			if ($e->getCode() == 423) {
				// http_response_code doesn't know about 423 Locked
				header('HTTP/1.1 423 Locked');
			}
			else {
				http_response_code($e->getCode());
			}

			header('application/xml; charset=utf-8', true);

			printf('<?xml version="1.0" encoding="utf-8"?><d:error xmlns:d="DAV:" xmlns:s="http://sabredav.org/ns"><s:message>%s</s:message></d:error>', htmlspecialchars($e->getMessage(), ENT_XML1));
		}

		return true;
	}
}
