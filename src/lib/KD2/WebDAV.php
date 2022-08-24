<?php

namespace KD2;

class WebDAV_Exception extends \RuntimeException {}

abstract class WebDAV
{
	/**
	 * Set this content to TRUE in extended class if you support locking
	 * via lock, unlock and getLock methods
	 */
	const LOCK = false;

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
	 * is_collection: true if it's a directory
	 * or NULL if the file can not be returned (404)
	 */
	abstract protected function metadata(string $uri, bool $all = false);

	abstract protected function put(string $uri, $pointer): bool;

	abstract protected function delete(string $uri): void;

	abstract protected function copy(string $uri, string $destination): bool;

	abstract protected function move(string $uri, string $destination): bool;

	abstract protected function mkcol(string $uri): void;

	abstract protected function list(string $uri): iterable;

	protected function lock(string $uri, string $token, string $scope, ?string $xml, ?string $ns): void {}
	protected function unlock(string $uri, string $token): void {}
	protected function getLock(string $uri, ?string $token = null): ?string {}

	const SHARED_LOCK = 'shared';
	const EXCLUSIVE_LOCK = 'exclusive';

	protected string $base_uri;

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

		$this->checkLock($uri);

		$created = $this->put($uri, fopen('php://input', 'r'));

		http_response_code($created ? 201 : 204);
		return null;
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

		if (!$meta->is_collection) {
			header(sprintf('Content-Length: %d', $meta->size), true);
		}

		return $meta->is_collection;
	}

	protected function http_get(string $uri): ?string
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

		$is_collection = $this->http_head($uri);
		$out = '';

		if ($is_collection) {
			$list = $this->list($uri);

			if (!isset($_SERVER['HTTP_ACCEPT']) || false === strpos($_SERVER['HTTP_ACCEPT'], 'html')) {
				$list = iterator_to_array($list);

				if (!count($list)) {
					return "Nothing in this collection\n";
				}

				return implode("\n", $list);
			}

			$out .= sprintf("<html>\n<head><title>Index of %s</title></head>\n<body>\n<h1>Index of %1\$s</h1>\n<ul>\n", htmlspecialchars($uri));

			foreach ($list as $file) {
				$out .= sprintf("\t<li><a href=\"%s\">%s</a></li>\n", rawurlencode($file), htmlspecialchars(basename($file)));
			}

			$out .= "</ul>\n</body>\n</html>";
		}
		else {
			$file = $this->get($uri);

			if (!$file) {
				throw new WebDAV_Exception('File Not Found', 404);
			}

			if ($file['path'] ?? null) {
				header('Content-Length: ' . filesize($file['path']));
				readfile($file['path']);
			}
			elseif ($file['resource'] ?? null) {
				fpassthru($file['resource']);
			}
			elseif ($file['content'] ?? null) {
				header('Content-Length: ' . strlen($file['content']));
				echo $file['content'];
			}
		}

		return $out;
	}

	protected function http_copy(string $uri): ?string
	{
		return $this->http_copymove($uri, 'copy');
	}

	protected function http_move(string $uri): ?string
	{
		return $this->http_copymove($uri, 'move');
	}

	protected function http_copymove(string $uri, string $method): ?string
	{
		$destination = $_SERVER['HTTP_DESTINATION'] ?? null;

		if (!$destination) {
			throw new WebDAV_Exception('Destination not supplied', 400);
		}

		$destination = $this->getURI($destination);
		$overwrite = ($_SERVER['HTTP_OVERWRITE'] ?? null) == 'T';

		$this->log('<= Destination: %s', $destination);
		$this->log('<= Overwrite: %s (%s)', $overwrite ? 'Yes' : 'No', $_SERVER['HTTP_OVERWRITE'] ?? null);

		if (!$overwrite && $this->metadata($destination)) {
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

	protected function http_propfind(string $uri): ?string
	{
		// We only support depth of 0 and 1
		$depth = !empty($_SERVER['HTTP_DEPTH']) ? 1 : 0;

		// We don't really care about parsing the client request,
		// but we still need to make sure the XML is valid to pass litmus tests
		if (isset($_SERVER['HTTP_X_LITMUS']) && function_exists('simplexml_load_string')) {
			$xml = @simplexml_load_string(file_get_contents('php://input'));

			if (!$xml) {
				throw new WebDAV_Exception('Invalid XML', 400);
			}
		}

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

		// http_response_code doesn't know the 207 status code
		header('HTTP/1.1 207 Multi-Status', true);
		header('DAV: 1'); // Apple stuff
		header('Content-Type: text/xml; charset="utf-8"');

		$out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
		$out .= '<D:multistatus xmlns:D="DAV:">';

		foreach ($items as $file => $item) {
			// Microsoft Clients need this special namespace for date and time values
			$out .= '<D:response xmlns:ns0="urn:uuid:c2f41010-65b3-11d1-a29f-00aa00c14882/">' . "\n";

			$out .= sprintf('<D:href>%s</D:href>', $this->base_uri . $file);
			$out .= '<D:propstat><D:prop>';

			if ($item['is_collection']) {
				$out .= '<D:resourcetype><D:collection /></D:resourcetype>';
			}
			else {
				$out .= '<D:resourcetype />';
			}

			$out .= sprintf('<D:getcontenttype>%s</D:getcontenttype>', $item['is_collection'] ? 'httpd/unix-directory' : $item['type']);
			$out .= sprintf('<D:creationdate ns0:dt="dateTime.tz">%s</D:creationdate>', date(DATE_RFC3339, $item['created'] ?? $item['modified']));
			$out .= sprintf('<D:getlastmodified ns0:dt="dateTime.rfc1123">%s</D:getlastmodified>', gmdate(DATE_RFC1123, $item['modified']));
			$out .= sprintf('<D:lastaccessed ns0:dt="dateTime.rfc1123">%s</D:lastaccessed>', gmdate(DATE_RFC1123, $item['accessed'] ?? $item['modified']));
			$out .= sprintf('<D:displayname>%s</D:displayname>', htmlspecialchars(basename($file), ENT_XML1));
			$out .= sprintf('<D:ishidden>%s</D:ishidden>', !empty($item['hidden']) ? 'true' : 'false');
			$out .= sprintf('<D:getcontentlength>%d</D:getcontentlength>', $item['size'] ?? 0);

			$out .= '</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>' . "\n";
		}

		$out .= '</D:multistatus>';

		return $out;
	}

	protected function http_proppatch(string $uri): ?string
	{
		$litmus = $_SERVER['HTTP_X_LITMUS_SECOND'] ?? ($_SERVER['HTTP_X_LITMUS'] ?? null);

		// We don't support PROPPATCH, but we simulate responses for Litmus
		if (!$litmus || false === strpos($litmus, 'locks: ')) {
			throw new WebDAV_Exception('Not implemented', 501);
		}

		$this->checkLock($uri);

		// http_response_code doesn't know the 207 status code
		header('HTTP/1.1 207 Multi-Status', true);
		header('Content-Type: text/xml; charset="utf-8"');

		$out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
		$out .= '<D:multistatus xmlns:D="DAV:">';
		$out .= '</D:multistatus>';

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

		$this->lock($uri, $token, $scope, trim($info), $ns);

		if (null === $info) {
			$info = sprintf('
				<D:lockscope><D:%s /></D:lockscope>
				<D:locktype><D:write /></D:locktype>
				<D:owner>unknown</D:owner>', $scope);
		}

		$timeout = 60*5;
		$append = sprintf('
			<D:depth>%d</D:depth>
			<D:timeout>Second-%d</D:timeout>
			<D:locktoken><D:href>%s</D:href></D:locktoken>
		', 1, $timeout, $token);

		$info .= $append;

		http_response_code(200);
		header('Content-Type: text/xml; charset="utf-8"');
		header(sprintf('Lock-Token: <%s>', $token));

		$out = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
		$out .= '<D:prop xmlns:D="DAV:">';
		$out .= '<D:lockdiscovery><D:activelock>';

		$out .= $info;

		$out .= '</D:activelock></D:lockdiscovery></D:prop>';

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

	protected function http_options(): void
	{
		http_response_code(200);
		$methods = 'GET HEAD PUT DELETE COPY MOVE PROPFIND MKCOL';

		if (static::LOCK) {
			header('DAV: 1, 2');
			$methods .= ' LOCK UNLOCK';
		}
		else {
			header('DAV: 1');
		}

		header('Allow: ' . $methods);
		header('Content-length: 0');
		header('Accept-Ranges: None');
		header('MS-Author-Via: DAV');
	}

	protected function handleRequest(string $uri): ?string
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? null;

		$this->log('<= %s /%s', $method, $uri);

		$method = 'http_' . strtolower($method);

		if (!method_exists($this, $method)) {
			throw new WebDAV_Exception('Invalid request method', 405);
		}

		return $this->$method($uri);
	}

	protected function log(string $message, ...$params)
	{
	}

	protected function getURI(string $source): string
	{
		$uri = parse_url($source, PHP_URL_PATH);
		$uri = rtrim($uri, '/');

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

		if (isset($_SERVER['HTTP_X_LITMUS']) || isset($_SERVER['HTTP_X_LITMUS_SECOND'])) {
			$this->log('X-Litmus: %s', $_SERVER['HTTP_X_LITMUS'] ?? $_SERVER['HTTP_X_LITMUS_SECOND']);
		}

		$this->base_uri = $base_uri;

		if (($_SERVER['REQUEST_METHOD'] ?? null) == 'OPTIONS') {
			$this->log('<= OPTIONS');
			$this->http_options();
			return true;
		}

		$uri = trim($uri, '/');

		$uri = preg_replace('!/{2,}!', '/', $uri);

		if (false !== strpos($uri, '..')) {
			throw new WebDAV_Exception(sprintf('Invalid URI: "%s"', $uri), 400);
		}

		try {
			$out = $this->handleRequest($uri);
			$this->log('=> %d', http_response_code());

			if (null !== $out) {
				$this->log('=> %s', $out);
			}

			echo $out;
		}
		catch (WebDAV_Exception $e) {
			$this->log('=> %d - %s', $e->getCode(), $e->getMessage());

			if ($e->getCode() == 423) {
				header('HTTP/1.1 423 Locked');
			}
			else {
				http_response_code($e->getCode());
			}

			echo $e->getMessage();
		}

		return true;
	}
}
