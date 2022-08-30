<?php

namespace KD2;

class WebDAV_NextCloud_Exception extends \RuntimeException {}

abstract class WebDAV_NextCloud extends WebDAV
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

	protected string $root_url;

	/**
	 * Handle your authentication
	 */
	abstract public function nc_auth(?string $login, ?string $password): bool;
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

	abstract public function nc_generate_token(): string;
	/*
		return sha1(random_bytes(16));
	*/

	abstract public function nc_validate_token(string $token): ?array;
	/*
		$session = $db->get('SELECT login, password FROM sessions WHERE token = ?;', $token);

		if (!$session) {
			return null;
		}

		// Make sure to have a single-use token
		$db->query('UPDATE sessions SET token = NULL WHERE token = ?;', $token);

		return (array)$session;
	*/

	abstract public function nc_login_url(?string $token): string;
	/*
		if ($token) {
			return $this->root_url . '/admin/login.php?nc_token=' . $token;
		}
		else {
			return $this->root_url . '/admin/login.php?nc_redirect=true';
		}
	 */


	// Order of array elements is important!
	const NC_ROUTES = [
		// Main routes
		'remote.php/webdav/' => 'webdav', // desktop client
		'remote.php/dav' => 'webdav', // android client

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
		'ocs/v2.php/apps/files_sharing/api/v1/shares' => 'shares',
		'ocs/v2.php/apps/user_status/api/v1/predefined_statuses' => 'empty',
		'ocs/v2.php/core/navigation/apps' => 'empty',
	];

	const NC_AUTH_REDIRECT_URL = 'nc://login/server:%s&user:%s&password:%s';

	/**
	 * Handle NextCloud specific routes, will return TRUE if it has returned any content
	 */
	public function route(?string $uri = null): bool
	{
		if (null === $uri) {
			$uri = $_SERVER['REQUEST_URI'] ?? '/';
		}

		$route = array_filter(self::NC_ROUTES, fn($k) => 0 === strpos($uri, $k), ARRAY_FILTER_USE_KEY);

		if (count($route) != 1) {
			return false;
		}

		$route = current($route);

		header('Access-Control-Allow-Origin: *', true);

		try {
			$v = $this->{'nc_' . $route}($uri);

			if (is_bool($v)) {
				return $v;
			}
			// This route is XML only
			elseif ($route == 'shares') {
				header('Content-Type: text/xml; charset=utf-8', true);
				echo '<?xml version="1.0"?>';
				echo self::nc_xml($v);
			}
			else {
				header('Content-Type: application/json', true);
				echo json_encode($v, JSON_PRETTY_PRINT);
			}
		}
		catch (WebDAV_NextCloud_Exception $e) {
			http_response_code($e->getCode());
			echo $e->getMessage();
		}

		return true;
	}

	static protected function nc_xml(array $array): string
	{
		$out = '';

		foreach ($array as $key => $v) {
			$out .= '<' . $key .'>';

			if (is_array($v)) {
				$out .= self::nc_xml($v);
			}
			else {
				$out .= htmlspecialchars((string) $v, ENT_XML1);
			}

			$out .= '</' . $key .'>';

		}

		return $out;
	}

	public function nc_webdav(string $uri): bool
	{
		if (!$this->nc_auth($_SERVER['PHP_AUTH_USER'] ?? null, $_SERVER['PHP_AUTH_PW'] ?? null)) {
			header('WWW-Authenticate: Basic realm="Please login"');
			throw new WebDAV_NextCloud_Exception('Please login to access this resource', 401);
		}

		foreach (self::NC_ROUTES as $route => $method) {
			if ($method != 'webdav') {
				continue;
			}

			if (0 === strpos($uri, $route)) {
				$base_uri = rtrim($route, '/') . '/';
				break;
			}
		}

		// Android app is using "/remote.php/dav/files/user//" as root
		// so let's alias that as well
		if (preg_match('!^' . preg_quote($base_uri, '!') . 'files/[a-z]+/+!', $uri, $match)) {
			$base_uri = $match[0];
		}

		$this->setBaseURI($base_uri);

		return parent::route($uri);
	}

	static public function nc_status(): array
	{
		return [
			'installed'       => true,
			'maintenance'     => false,
			'needsDbUpgrade'  => false,
			'version'         => '2022.0.0.1',
			'versionstring'   => '2022.0.0',
			'edition'         => '',
			'productname'     => 'WebDAV',
			'extendedSupport' => false,
		];
	}

	static public function nc_login_v2(): array
	{
		$method = $_SERVER['REDIRECT_REQUEST_METHOD'] ?? ($_SERVER['REQUEST_METHOD'] ?? null);

		if ($method != 'POST') {
			throw new WebDAV_NextCloud_Exception('Invalid request method', 405);
		}

		$token = $this->nc_generate_token();
		$endpoint = sprintf('%s%s', $this->root_url, array_search(self::NC_ROUTES, 'poll'));

		return [
			'poll' => compact('token', 'endpoint'),
			'login' => $this->nc_login_url($token),
		];
	}

	public function nc_poll()
	{
		$method = $_SERVER['REDIRECT_REQUEST_METHOD'] ?? ($_SERVER['REQUEST_METHOD'] ?? null);

		if ($method != 'POST') {
			throw new WebDAV_NextCloud_Exception('Invalid request method', 405);
		}

		if (empty($_POST['token']) || !ctype_alnum($_POST['token'])) {
			throw new WebDAV_NextCloud_Exception('Invalid token', 400);
		}

		$session = $this->nc_validate_token($_POST['token']);

		if (!$session) {
			throw new WebDAV_NextCloud_Exception('No token yet', 404);
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
				'major' => 2022,
				'minor' => 0,
				'micro' => 0,
				'string' => '2022.0.0',
				'edition' => '',
				'extendedSupport' => false,
			],
			'capabilities' => [
				'core' => ['webdav-root' => array_search('webdav', self::NC_ROUTES), 'pollinterval' => 60],
			],
		]);
	}

	public function nc_login_v1(): void
	{
		http_response_code(303);
		header('Location: ' . $this->nc_login_url());
	}

	public function nc_user()
	{
		$quota = $this->nc_quota();
		return $this->nc_ocs([
			'id' => 'null',
			'enabled' => true,
			'email' => null,
			'storageLocation' => '/tmp/whoknows',
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

	public function nc_shares()
	{
		return $this->nc_ocs([]);
	}

	protected function nc_empty()
	{
		return $this->nc_ocs([]);
	}

	protected function nc_ocs(array $data = []): array
	{
		return ['ocs' => [
			'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
			'data' => $data,
		]];
	}

	protected function get_extra_ns(string $uri): string
	{
		return 'xmlns:oc="http://owncloud.org/ns" xmlns:nc="http://nextcloud.org/ns"';
	}

	protected function get_extra_propfind(string $uri, string $file, array $meta): string
	{
		if (!isset($meta['nc_permissions'])) {
			throw new \InvalidArgumentException('Metadata array is missing the nc_permissions key');
		}

		if (!isset($meta['collection'])) {
			throw new \InvalidArgumentException('Missing "collection" key in metadata array');
		}

		// oc:size is to return the size of a folder (when depth = 0)
		return sprintf('
			<oc:id>%s</oc:id>
			<oc:size>%s</oc:size>
			<oc:downloadURL></oc:downloadURL>
			<oc:permissions>%s</oc:permissions>
			<oc:share-types/>',
			md5($uri . $file),
			$meta['collection'] && null !== $meta['size'] ? $meta['size'] : '',
			$meta['nc_permissions']
		);
	}
}
