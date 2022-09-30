<?php

namespace KD2\WebDAV;

abstract class NextCloud
{
	/**
	 * File permissions for NextCloud clients
	 * from lib/private/Files/Storage/DAV.php
	 * and apps/dav/lib/Connector/Sabre/Node.php
	 * in NextCloud
	 *
	 * R = Shareable
	 * S = Shared
	 * M = Mounted
	 * D = Delete
	 * G = Readable
	 * NV = Renameable/moveable
	 * Files only:
	 * W = Write (Update)
	 * CK = Create/Update
	 */
	const PERM_READ = 'G';
	const PERM_SHARE = 'R';
	const PERM_SHARED = 'S';
	const PERM_MOUNTED = 'M';
	const PERM_DELETE = 'D';
	const PERM_RENAME_MOVE = 'NV';
	const PERM_WRITE = 'W';
	const PERM_CREATE = 'CK';

	const NC_NAMESPACE = 'http://nextcloud.org/ns';
	const OC_NAMESPACE = 'http://owncloud.org/ns';

	const PROP_OC_ID = self::OC_NAMESPACE . ':id';
	const PROP_OC_SIZE = self::OC_NAMESPACE . ':size';
	const PROP_OC_DOWNLOADURL = self::OC_NAMESPACE . ':downloadURL';
	const PROP_OC_PERMISSIONS = self::OC_NAMESPACE . ':permissions';
	const PROP_OC_SHARETYPES = self::OC_NAMESPACE . ':share-types';

	const NC_PROPERTIES = [
		self::PROP_OC_ID,
		self::PROP_OC_SIZE,
		self::PROP_OC_DOWNLOADURL,
		self::PROP_OC_PERMISSIONS,
		self::PROP_OC_SHARETYPES,
	];

	protected string $root_url;

	/**
	 * Handle your authentication
	 */
	abstract public function auth(?string $login, ?string $password): bool;
	/*  This is a simple example:
		session_start();

		if (!empty($_SESSION['user'])) {
			return true;
		}

		if ($login != 'admin' && $password != 'abcd') {
			return false;
		}

		$_SESSION['user'] = 'admin';
		return true;
	*/

	/**
	 * Return username (a-z_0-9) of currently logged-in user
	 */
	abstract public function getUserName(): ?string;
	/*
		return $_SESSION['user'] ?? null;
	 */

	/**
	 * Set username of currently logged-in user
	 * Return FALSE if user is invalid
	 */
	abstract public function setUserName(string $login): bool;
	/*
		$_SESSION['user'] = $login;
		return true;
	 */

	/**
	 * Return quota for currently loggged-in user
	 * @return array ['free' => 123, 'used' => 123, 'total' => 246]
	 */
	abstract public function getUserQuota(): array;
	/*
		return ['free' => 123, 'used' => 123, 'total' => 246];
	 */

	/**
	 * Return a unique token for v2 login flow
	 */
	abstract public function generateToken(): string;
	/*
		return sha1(random_bytes(16));
	*/

	/**
	 * Validate the provided token to get a session, returns either NULL or a user login and app password
	 * @return array ['login' => ..., 'password' => ...]
	 */
	abstract public function validateToken(string $token): ?array;
	/*
		$session = $db->get('SELECT login, password FROM sessions WHERE token = ?;', $token);

		if (!$session) {
			return null;
		}

		// Make sure to have a single-use token
		$db->query('UPDATE sessions SET token = NULL WHERE token = ?;', $token);

		return (array)$session;
	*/

	abstract public function getLoginURL(?string $token): string;
	/*
		if ($token) {
			return $this->root_url . '/admin/login.php?nc_token=' . $token;
		}
		else {
			return $this->root_url . '/admin/login.php?nc_redirect=true';
		}
	 */

	/**
	 * Direct download API
	 * Return a unique secret to authentify a direct URL request (for direct API)
	 * meaning a third party (eg. local user app) can access the file without auth
	 * @param  string $uri
	 * @param  string $login User name
	 * @return string a secret string (eg. a hash)
	 */
	abstract public function getDirectDownloadSecret(string $uri, string $login): string;

	/**
	 * Chunked upload API.
	 * You should manage automatic removal of incomplete uploads after around 24 hours.
	 * @param  string $login Current user name
	 * @return string Path to temporary storage for user
	 */
	abstract public function storeChunk(string $login, string $name, string $part, $pointer): void;

	abstract public function deleteChunks(string $login, string $name): void;

	abstract public function assembleChunks(string $login, string $name, string $target): bool;

	// END OF ABSTRACT METHODS

	/**
	 * List of routes
	 * Order of array elements is important!
	 */
	const ROUTES = [
		// Main routes
		'remote.php/webdav/' => 'webdav', // desktop client
		'remote.php/dav' => 'webdav', // android client

		// Chunked API
		// https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/chunking.html
		'remote.php/webdav/uploads/' => 'chunked',
		'remote.php/dav/uploads/' => 'chunked',

		// Login v1, for Android app
		'index.php/login/flow' => 'login_v1',
		// Login v2, for desktop app
		'index.php/login/v2/poll' => 'poll',
		'index.php/login/v2' => 'login_v2',

		// Random API endpoints
		'status.php' => 'status',
		'ocs/v1.php/cloud/capabilities' => 'capabilities',
		'ocs/v2.php/cloud/capabilities' => 'capabilities',
		'ocs/v2.php/cloud/user' => 'user',
		'ocs/v1.php/cloud/user' => 'user',
		'ocs/v1.php/config' => 'config',
		'ocs/v2.php/apps/files_sharing/api/v1/shares' => 'shares',
		'ocs/v2.php/apps/user_status/api/v1/predefined_statuses' => 'empty',
		'ocs/v2.php/core/navigation/apps' => 'empty',
		'ocs/v2.php/apps/dav/api/v1/direct' => 'direct_url',
		'remote.php/direct/' => 'direct',
	];

	const AUTH_REDIRECT_URL = 'nc://login/server:%s&user:%s&password:%s';

	/**
	 * Handle NextCloud specific routes
	 *
	 * @param null|string If left NULL, then REQUEST_URI will be used
	 * @return null|array Will return NULL if no NextCloud route was requested.
	 * If a route was requested, the route name will be returned in the array:
	 * ['route' => 'config'] for example.
	 * For 'webdav' route, a 'base_uri' key will be supplied in the array,
	 * this should be passed to the WebDAV server as the base URL.
	 * For 'direct' route, a 'uri' key will be supplied in the array,
	 * this should be used to return the requested resource, without auth (as the
	 * NextCloud class already handled auth), eg. $dav->http_get($uri);
	 */
	public function route(?string $uri = null): ?array
	{
		if (null === $uri) {
			$uri = $_SERVER['REQUEST_URI'] ?? '/';
		}

		$uri = ltrim($uri, '/');
		$uri = rawurldecode($uri);

		$route = array_filter(self::ROUTES, fn($k) => 0 === strpos($uri, $k), ARRAY_FILTER_USE_KEY);

		if (count($route) < 1) {
			return null;
		}

		$route = current($route);

		header('Access-Control-Allow-Origin: *', true);

		try {
			$v = $this->{'nc_' . $route}($uri);
		}
		catch (Exception $e) {
			http_response_code($e->getCode());
			echo json_encode(['error' => $e->getMessage()]);
			$route = 'error';
		}

		// This route is XML only
		if ($route == 'shares') {
			http_response_code(200);
			header('Content-Type: text/xml; charset=utf-8', true);
			echo '<?xml version="1.0"?>' . $this->xml($v);
		}
		elseif (is_array($v)) {
			http_response_code(200);
			header('Content-Type: application/json', true);
			echo json_encode($v, JSON_PRETTY_PRINT);
		}
		elseif ($route == 'webdav') {
			return ['route' => $route, 'base_uri' => $v];
		}
		elseif ($route == 'direct') {
			return ['route' => $route, 'uri' => $v];
		}

		return compact('route');
	}

	protected function xml(array $array): string
	{
		$out = '';

		foreach ($array as $key => $v) {
			$out .= '<' . $key .'>';

			if (is_array($v)) {
				$out .= $this->xml($v);
			}
			else {
				$out .= htmlspecialchars((string) $v, ENT_XML1);
			}

			$out .= '</' . $key .'>';

		}

		return $out;
	}

	protected function requireAuth(): void
	{
		if (!$this->auth($_SERVER['PHP_AUTH_USER'] ?? null, $_SERVER['PHP_AUTH_PW'] ?? null)) {
			header('WWW-Authenticate: Basic realm="Please login"');
			throw new Exception('Please login to access this resource', 401);
		}
	}

	public function nc_webdav(string $uri): string
	{
		$this->requireAuth();

		// Find out which route we are using and replace URI
		foreach (self::NC_ROUTES as $route => $method) {
			if ($method != 'nc_webdav') {
				continue;
			}

			if (0 === strpos($uri, $route)) {
				$base_uri = rtrim($route, '/') . '/';
				break;
			}
		}

		// Android app is using "/remote.php/dav/files/user//" as root
		// so let's alias that as well
		if (preg_match('!^' . preg_quote($base_uri, '!') . 'files/[a-z]+/*!', $uri, $match)) {
			$base_uri = $match[0];
		}

		return $base_uri;
	}

	public function nc_status(): array
	{
		return [
			'installed'       => true,
			'maintenance'     => false,
			'needsDbUpgrade'  => false,
			'version'         => '24.0.4.1',
			'versionstring'   => '24.0.4',
			'edition'         => '',
			'productname'     => 'NextCloud',
			'extendedSupport' => false,
		];
	}

	public function nc_login_v2(): array
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? null;

		if ($method != 'POST') {
			throw new Exception('Invalid request method', 405);
		}

		$token = $this->generateToken();
		$endpoint = sprintf('%s%s', $this->root_url, array_search('poll', self::NC_ROUTES));

		return [
			'poll' => compact('token', 'endpoint'),
			'login' => $this->getLoginURL($token),
		];
	}

	public function nc_poll(): array
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? null;

		if ($method != 'POST') {
			throw new Exception('Invalid request method', 405);
		}

		if (empty($_POST['token']) || !ctype_alnum($_POST['token'])) {
			throw new Exception('Invalid token', 400);
		}

		$session = $this->validateToken($_POST['token']);

		if (!$session) {
			throw new Exception('No token yet', 404);
		}

		return [
			'server'      => $this->root_url,
			'loginName'   => $session['login'],
			'appPassword' => $session['password'],
		];
	}

	public function nc_capabilities()
	{
		return $this->nc_ocs([
			'version' => [
				'major' => 24,
				'minor' => 0,
				'micro' => 4,
				'string' => '24.0.4',
				'edition' => '',
				'extendedSupport' => false,
			],
			'capabilities' => [
				'core' => [
					'webdav-root' => array_search('webdav', self::NC_ROUTES),
					'pollinterval' => 60,
					'bruteforce' => ['delay' => 0],
				],
			],
			'dav' => [
				"chunking" => "1.0",
			],
			'files' => [
				'bigfilechunking' => false,
				'comments' => false,
				'undelete' => false,
				'versioning' => false,
			],
			'files_sharing' => [
				'api_enabled' => false,
				'group_sharing' => false,
				'resharing' => false,
				'sharebymail' => ['enabled' => false],
			],
			'user' => [
				'expire_date' => ['enabled' => false],
				'send_mail' => false,
			],
			'public' => [
				'enabled' => false,
				'expire_date' => ['enabled' => false],
				'multiple_links' => false,
				'send_mail' => false,
				'upload' => false,
				'upload_files_drop' => false,
			],
		]);
	}

	public function nc_login_v1(): void
	{
		http_response_code(303);
		header('Location: ' . $this->getLoginURL(null));
	}

	public function nc_user(): array
	{
		$this->requireAuth();

		$quota = $this->getUserQuota();
		$user = $this->getUserName() ?? 'null';

		return $this->nc_ocs([
			'id' => $user,
			'enabled' => true,
			'email' => null,
			'storageLocation' => '/secret/whoknows/' . $user,
			'role' => '',
			'quota' => [
				'quota' => -3, // fixed value
				'relative' => 0, // fixed value
				'free' => $quota['free'] ?? 200000000,
				'total' => $quota['total'] ?? 200000000,
				'used' => $quota['used'] ?? 0,
			],
		]);
	}

	public function nc_shares(): array
	{
		return $this->nc_ocs([]);
	}

	protected function nc_empty(): array
	{
		return $this->nc_ocs([]);
	}

	protected function nc_config(): array
	{
		return $this->nc_ocs([
			'contact' => '',
			'host' => $_SERVER['SERVER_NAME'] ?? '',
			'ssl' => !empty($_SERVER['HTTPS']) || $_SERVER['SERVER_PORT'] == 443,
			'version' => '1.7',
			'website' => 'Nextcloud',
		]);
	}

	protected function nc_direct_url(): array
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? null;

		if ($method != 'POST') {
			throw new Exception('Invalid request method', 405);
		}

		$this->requireAuth();

		if (empty($_POST['fileId'])) {
			throw new Exception('Missing fileId', 400);
		}

		$uri = gzuncompress(base64_decode($_POST['fileId']));

		if (!$uri) {
			throw new Exception('Invalid fileId', 404);
		}

		$user = strtok($uri, ':');
		$uri = strtok('');

		if (!$this->exists($uri)) {
			throw new Exception('Invalid fileId', 404);
		}

		$expire = intval((time() - strtotime('2022-09-01'))/3600) + 8; // 8 hours
		$hash = $expire . ':' . sha1($user . $uri . $expire . $this->getDirectDownloadSecret($uri, $user));

		$uri = rawurlencode($uri);
		$uri = str_replace('%2F', '/', $uri);

		$url = sprintf('%s%s/%s/%s?h=%s', $this->root_url, array_search('direct', self::NC_ROUTES), $user, $uri, $hash);

		return self::nc_ocs(compact('url'));
	}

	protected function nc_direct(string $uri): string
	{
		$method = $_SERVER['REQUEST_METHOD'] ?? null;

		if ($method != 'GET') {
			throw new Exception('Invalid request method', 405);
		}

		if (empty($_GET['h'])) {
			throw new Exception('Missing hash', 400);
		}

		$uri = substr(trim($uri, '/'), strlen(trim(array_search('direct', self::NC_ROUTES), '/')));

		$user = strtok($uri, '/');
		$uri = strtok('');

		if (!$user || !$uri) {
			throw new Exception('Invalid URI', 400);
		}

		$expire = strtok($_GET['h'], ':');
		$hash = strtok('');
		$expire_seconds = $expire * 3600 + strtotime('2022-09-01');

		// Link has expired
		if ($expire_seconds < time()) {
			throw new Exception('Link has expired', 401);
		}

		$verify = sha1($user . $uri . $expire . $this->getDirectDownloadSecret($uri, $user));

		// Check if the provided hash is correct
		if (!hash_equals($verify, $hash)) {
			throw new Exception('Link hash is invalid', 401);
		}

		if (!$this->setUserName($user)) {
			throw new Exception('Invalid user', 404);
		}

		return $uri;
	}

	static public function getDirectID(string $username, string $uri): string
	{
		// trick to avoid having to store a file ID, just send the file name
		return rtrim(base64_encode(gzcompress($username . ':' . $uri)), '=');
	}

	protected function nc_ocs(array $data = []): array
	{
		return ['ocs' => [
			'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
			'data' => $data,
		]];
	}

	protected function nc_chunked(string $uri): void
	{
		$this->requireAuth();
		$user = $this->getUserName();

		$r = '!^/remote\.php/(?:web)?dav/uploads/([^/]+)/([\w\d_-]+)(?:/([\w\d_-]+))$!';

		if (!preg_match($r, $uri, $match)) {
			throw new Exception('Invalid URL for chunk upload', 400);
		}

		$method = $_SERVER['REQUEST_METHOD'] ?? null;
		$login = $match[1] ?? null;
		$dir = $match[2] ?? null;
		$part = $match[3] ?? null;

		if ($method == 'MKCOL') {
			http_response_code(201);
		}
		elseif ($method == 'PUT') {
			$this->storeChunk($login, $dir, $part, fopen('php://input', 'rb'));
			http_response_code(201);
		}
		elseif ($method == 'MOVE') {
			$dest = $_SERVER['HTTP_DESTINATION'];
			$dest = preg_replace('!^.*/remote.php/(?:web)?dav/(?:files/)?[^/]*/!', '', $dest);

			if ($this->assembleChunks($login, $dir, $dest)) {
				http_response_code(201);
			}
			else {
				http_response_code(204);
			}
		}
		elseif ($method == 'DELETE' && !$part) {
			$this->deleteChunks($login, $dir);
		}
		else {
			throw new Exception('Invalid method for chunked upload', 400);
		}
	}
}
