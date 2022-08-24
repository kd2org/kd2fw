<?php

namespace KD2;

class WebDAV_Exception extends \RuntimeException {}

abstract class WebDAV
{
	const LOCK = false;

	protected string $base_uri;

	/**
	 * Return the requested file
	 * @param  string $uri
	 * @return array containing those keys:
	 * path, resource or content
	 * or NULL if the file can not be returned (404)
	 */
	abstract protected function get(string $uri): ?array;

	/**
	 * Return the requested file metadata
	 * @param string $uri
	 * @param bool $all Set to TRUE if created, accessed and hidden properties should be returned as well
	 * @return array containing those keys:
	 * modified: modification timestamp
	 * size: file size
	 * type: mimetype
	 * is_dir: true if it's a directory
	 * or NULL if the file can not be returned (404)
	 */
	abstract protected function metadata(string $uri, bool $all = false);

	abstract protected function put(string $uri, $pointer): bool;

	abstract protected function delete(string $uri): void;

	abstract protected function copy(string $uri, string $destination): bool;

	abstract protected function move(string $uri, string $destination): bool;

	abstract protected function mkdir(string $uri): void;

	abstract protected function lock(string $uri): void;
	abstract protected function unlock(string $uri): void;

	protected function http_delete(string $uri): void
	{
		// check RFC 2518 Section 9.2, last paragraph
		if (isset($_SERVER['HTTP_DEPTH']) && $_SERVER['HTTP_DEPTH'] != 'infinity') {
			throw new WebDAV_Exception('We can only delete to infinity', 400);
		}

		$this->delete($uri);

		http_response_code(204);
		header('Content-Length: 0', true);
	}

	protected function http_put(string $uri): void
	{
		if (!empty($_SERVER['HTTP_CONTENT_TYPE']) && !strncmp($_SERVER['HTTP_CONTENT_TYPE'], "multipart/", 10)) {
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

		$created = $this->put($uri, fopen('php://input', 'r'));

		http_response_code($created ? 201 : 204);
	}

	protected function http_head(string $uri): bool
	{
		$meta = $this->metadata($uri);

		if (!$meta) {
			throw new WebDAV_Exception('File Not Found', 404);
		}

		$meta = (object) $meta;

		http_response_code(200);
		header(sprintf('Content-Type: %s', $meta->type), true);
		header(sprintf('Last-Modified: %s', gmdate(\DATE_RFC7231 , $meta->modified)), true);
		header(sprintf('Content-Length: %d', $meta->size), true);

		return $meta->is_dir;
	}

	protected function http_get(string $uri): void
	{
		/*
		$ranges = null;
		if (isset($_SERVER['HTTP_RANGE'])) {
			if (!preg_match('^bytes\s*=\s*(.+)$', trim($_SERVER['HTTP_RANGE']), $match)) {
				header('Content-Range: bytes *' . '/' . $this->size($uri)); // Required in 416.
				throw new WebDAV_Exception('Requested Range Not Satisfiable', 416);
			}

			$ranges = [];

			// ranges are comma separated
			foreach (explode(",", $match[1]) as $range) {
				// ranges are either from-to pairs or just end positions
				list($start, $end) = explode('-', $range);
				$ranges[] = compact('start', 'end');
			}
		}*/

		$is_dir = $this->http_head($uri);

		if ($is_dir) {
			printf('<html><head><title>Index of %s</title></head><body><h1>Index of %1$s</h1><ul>', htmlspecialchars($uri));

			foreach ($this->list($uri) as $file) {
				printf('<li><a href="%s">%s</a></li>', htmlspecialchars($file), htmlspecialchars(basename($file)));
			}

			echo '</ul></body></html>';
		}
		else {
			$file = $this->get($uri);

			if (!$file) {
				throw new WebDAV_Exception('File Not Found', 404);
			}

			if ($file['path'] ?? null) {
				readfile($file['path']);
			}
			elseif ($file['resource'] ?? null) {
				fpassthru($file['resource']);
			}
			elseif ($file['content'] ?? null) {
				echo $file['content'];
			}
		}
	}

	protected function http_copymove(string $uri, string $method): void
	{
		$destination = $_SERVER['HTTP_DESTINATION'] ?? null;

		if (!$destination) {
			throw new WebDAV_Exception('Destination not supplied', 400);
		}

		$destination = parse_url($destination, PHP_URL_PATH);

		if (strpos($destination, $this->base_uri) !== 0) {
			throw new WebDAV_Exception(sprintf('Invalid destination, "%s" is outside of scope: "%s"', $destination, $this->base_uri), 400);
		}

		$destination = substr($destination, strlen($this->base_uri));
		$overwrite = ($_SERVER['HTTP_OVERWRITE'] ?? null) == 'T';

		$this->log('<= Destination: %s', $destination);
		$this->log('<= Overwrite: %s (%s)', $overwrite ? 'Yes' : 'No', $_SERVER['HTTP_OVERWRITE'] ?? null);

		if (!$overwrite && $this->metadata($destination)) {
			throw new WebDAV_Exception('File already exists and overwriting is disabled', 412);
		}

		$overwritten = $this->$method($uri, $destination);
		http_response_code($overwritten ? 204 : 201);
	}

	protected function http_mkcol(string $uri): void
	{
		if (!empty($_SERVER['CONTENT_LENGTH'])) {
			throw new WebDAV_Exception('Unsupported body for MKCOL', 415);
		}

		$this->mkdir($uri);

		http_response_code(201);
	}

	protected function http_propfind(string $uri): void
	{
		// We only support depth of 0 and 1
		$depth = !empty($_SERVER['HTTP_DEPTH']) ? 1 : 0;

		// We don't really care about parsing the client request here (I think?)
		$meta = $this->metadata($uri, true);

		if (!$meta) {
			throw new WebDAV_Exception('This does not exist', 404);
		}

		$items = [$uri => $meta];

		if ($depth) {
			foreach ($this->list($uri) as $file) {
				$items[$file] = $this->metadata($file, true);
			}
		}

		http_response_code(207);
		header('DAV: 1');
		header('Content-Type: text/xml; charset="utf-8"');

		echo '<?xml version="1.0" encoding="utf-8"?>' . "\n";
		echo '<D:multistatus xmlns:D="DAV:">';

		foreach ($items as $file => $item) {
			// Microsoft Clients need this special namespace for date and time values
			echo '<D:response xmlns:ns0="urn:uuid:c2f41010-65b3-11d1-a29f-00aa00c14882/">' . "\n";

			printf('<D:href>%s</D:href>', $file);
			echo '<D:propstat><D:prop>';

			if ($item['is_dir']) {
				echo '<D:resourcetype><D:collection/></D:resourcetype>';
			}
			else {
				echo '<D:resourcetype/>';
			}

			printf('<D:getcontenttype>%s</D:getcontenttype>', $item['is_dir'] ? 'httpd/unix-directory' : $item['type']);
			printf('<D:creationdate ns0:dt="dateTime.tz">%s</D:creationdate>', date(DATE_RFC3339, $item['created'] ?? $item['modified']));
			printf('<D:getlastmodified ns0:dt="dateTime.rfc1123">%s</D:getlastmodified>', gmdate(DATE_RFC1123, $item['modified']));
			printf('<D:lastaccessed ns0:dt="dateTime.rfc1123">%s</D:lastaccessed>', gmdate(DATE_RFC1123, $item['accessed'] ?? $item['modified']));
			printf('<D:displayname>%s</D:displayname>', htmlspecialchars(basename($file), ENT_XML1));
			printf('<D:ishidden>%s</D:ishidden>', !empty($item['hidden']) ? 'true' : 'false');
			printf('<D:getcontentlength>%d</D:getcontentlength>', $item['size'] ?? 0);

			echo '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>' . "\n";
		}

		echo '</D:multistatus>';
	}

	protected function http_options(): void
	{
		http_response_code(200);
		header('DAV: 1');
		header('Allow: GET HEAD PUT DELETE COPY MOVE PROPFIND MKCOL');
		header('Content-length: 0');
		header('Accept-Ranges: None');
		header('MS-Author-Via: DAV');
	}

	protected function handleRequest(string $uri): void
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? null;

		$this->log('<= %s /%s', $method, $uri);

		switch ($method) {
			// OPTIONS is already handled in router
			case 'GET'      : $this->http_get($uri); return;
			case 'HEAD'     : $this->http_head($uri); return;
			case 'PUT'      : $this->http_put($uri); return;
			case 'DELETE'   : $this->http_delete($uri); return;
			case 'MKCOL'    : $this->http_mkcol($uri); return;
			case 'PROPFIND' : $this->http_propfind($uri); return;
			case 'MOVE'     : $this->http_copymove($uri, 'move'); return;
			case 'COPY'     : $this->http_copymove($uri, 'copy'); return;
			case 'PROPPATCH': throw new WebDAV_Exception('Not supported', 501);
			default:
				throw new WebDAV_Exception('Invalid request method', 405);
		}
	}

	protected function log(string $message, ...$params)
	{
	}

	public function route(string $base_uri, ?string $uri = null): bool
	{
		if (null === $uri) {
			$uri = $_SERVER['REQUEST_URI'] ?? '/';
		}

		if (0 === strpos($uri, $base_uri)) {
			$uri = substr($uri, strlen($base_uri));
		}
		else {
			return false;
		}

		if (isset($_SERVER['HTTP_X_LITMUS'])) {
			$this->log('X-Litmus: %s', $_SERVER['HTTP_X_LITMUS']);
		}

		$this->base_uri = $base_uri;

		if (($_SERVER['REQUEST_METHOD'] ?? null) == 'OPTIONS') {
			$this->log('<= OPTIONS');
			$this->http_options();
			return true;
		}

		$uri = trim($uri, '/');

		try {
			$this->handleRequest($uri);
			$this->log('=> %d', http_response_code());
		}
		catch (WebDAV_Exception $e) {
			$this->log('=> %d - %s', $e->getCode(), $e->getMessage());
			http_response_code($e->getCode());
			echo $e->getMessage();
		}

		return true;
	}
}
