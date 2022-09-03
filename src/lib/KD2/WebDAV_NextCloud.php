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

	protected bool $parse_propfind = true;

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

	/**
	 * Return username (a-z_0-9) of currently logged-in user
	 */
	abstract public function nc_get_user(): ?string;
	/*
		return $_SESSION['user'] ?? null;
	 */

	/**
	 * Return quota for currently loggged-in user
	 * @return array ['free' => 123, 'used' => 123, 'total' => 246]
	 */
	abstract public function nc_get_quota(): array;
	/*
		return ['free' => 123, 'used' => 123, 'total' => 246];
	 */

	/**
	 * Return a unique token for v2 login flow
	 */
	abstract public function nc_generate_token(): string;
	/*
		return sha1(random_bytes(16));
	*/

	/**
	 * Validate the provided token to get a session, returns either NULL or a user login and app password
	 * @return array ['login' => ..., 'password' => ...]
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
		'ocs/v1.php/config' => 'config',
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

		$uri = ltrim($uri, '/');

		$route = array_filter(self::NC_ROUTES, fn($k) => 0 === strpos($uri, $k), ARRAY_FILTER_USE_KEY);

		if (count($route) < 1) {
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
				http_response_code(200);
				header('Content-Type: text/xml; charset=utf-8', true);
				echo '<?xml version="1.0"?>';
				echo self::nc_xml($v);
			}
			else {
				http_response_code(200);
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

	protected function nc_require_auth(): void
	{
		if (!$this->nc_auth($_SERVER['PHP_AUTH_USER'] ?? null, $_SERVER['PHP_AUTH_PW'] ?? null)) {
			header('WWW-Authenticate: Basic realm="Please login"');
			throw new WebDAV_NextCloud_Exception('Please login to access this resource', 401);
		}
	}

	public function nc_webdav(string $uri): bool
	{
		$this->nc_require_auth();

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
		if (preg_match('!^' . preg_quote($base_uri, '!') . 'files/[a-z]+/*!', $uri, $match)) {
			$base_uri = $match[0];
		}

		$this->setBaseURI($base_uri);

		return parent::route($uri);
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
		$method = $_SERVER['REDIRECT_REQUEST_METHOD'] ?? ($_SERVER['REQUEST_METHOD'] ?? null);

		if ($method != 'POST') {
			throw new WebDAV_NextCloud_Exception('Invalid request method', 405);
		}

		$token = $this->nc_generate_token();
		$endpoint = sprintf('%s%s', $this->root_url, array_search('poll', self::NC_ROUTES));

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
				//"chunking": "1.0"
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
		header('Location: ' . $this->nc_login_url(null));
	}

	public function nc_user()
	{
		$this->nc_require_auth();

		$quota = $this->nc_get_quota();
		$user = $this->nc_get_user() ?? 'null';

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

	public function nc_shares()
	{
		return $this->nc_ocs([]);
	}

	protected function nc_empty()
	{
		return $this->nc_ocs([]);
	}

	protected function nc_config()
	{
		return $this->nc_ocs([
			'contact' => '',
			'host' => $_SERVER['SERVER_NAME'] ?? '',
			'ssl' => !empty($_SERVER['HTTPS']) || $_SERVER['SERVER_PORT'] == 443,
			'version' => '1.7',
			'website' => 'Nextcloud',
		]);
	}

	protected function nc_ocs(array $data = []): array
	{
		return ['ocs' => [
			'meta' => ['status' => 'ok', 'statuscode' => 200, 'message' => 'OK'],
			'data' => $data,
		]];
	}
}
