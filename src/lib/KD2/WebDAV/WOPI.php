<?php

namespace KD2\WebDAV;

/**
 * KD2/WebDAV/WOPI
 * This class implements a basic WOPI server on top of a WebDAV storage.
 * It allows to edit documents with Collabora, OnlyOffice, MS Office Online etc.
 *
 * Note that OnlyOffice is not enabling WOPI by default:
 * docker run -i -t -p 9980:80 --network=host -e WOPI_ENABLED=true onlyoffice/documentserver
 *
 * @author BohwaZ
 * @license GNU AGPL v3
 * @see https://api.onlyoffice.com/editors/wopi/
 * @see https://interoperability.blob.core.windows.net/files/MS-WOPI/[MS-WOPI].pdf
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/rest/concepts#access-token
 * @see https://learn.microsoft.com/en-us/microsoft-365/cloud-storage-partner-program/online/wopi-requirements
 * @see https://dzone.com/articles/implementing-wopi-protocol-for-office-integration
 */
class WOPI
{
	const NS = 'https://interoperability.blob.core.windows.net/files/MS-WOPI/';
	const PROP_FILE_ID = self::NS . ':file-id';
	const PROP_FILE_URL = self::NS . ':file-url';
	const PROP_TOKEN = self::NS . ':token';
	const PROP_TOKEN_TTL = self::NS . ':token-ttl';
	const PROP_READ_ONLY = self::NS . ':ReadOnly';
	const PROP_CAN_WRITE = self::NS . ':UserCanWrite';
	const PROP_CAN_RENAME = self::NS . ':UserCanRename';

	protected AbstractStorage $storage;

	public function setStorage(AbstractStorage $storage)
	{
		$this->storage = $storage;
	}

	public function route(?string $uri = null): bool
	{
		if (!method_exists($this->storage, 'getWopiURI')) {
			throw new \LogicException('Storage class does not implement getWopiURI method');
		}

		if (null === $uri) {
			$uri = $_SERVER['REQUEST_URI'] ?? '/';
		}

		$uri = trim($uri, '/');

		if (0 !== strpos($uri, 'wopi/files/')) {
			return false;
		}

		$uri = substr($uri, strlen('wopi/files/'));

		if (!empty($_SERVER['HTTP_AUTHORIZATION']) && 0 === stripos($_SERVER['HTTP_AUTHORIZATION'], 'Bearer ')) {
			$auth_token = trim(substr($_SERVER['HTTP_AUTHORIZATION'], strlen('Bearer ')));
		}
		elseif (!empty($_GET['access_token'])) {
			$auth_token = trim($_GET['access_token']);
		}
		else {
			$auth_token = null;
		}

		if (!$auth_token) {
			throw new Exception('No access_token was provided', 401);
		}

		$method = $_SERVER['REQUEST_METHOD'];
		$id = rawurldecode(strtok($uri, '/'));
		$action = trim(strtok(false), '/');

		$uri = $this->storage->getWopiURI($id, $auth_token);

		if (!$uri) {
			throw new Exception('Invalid file ID or invalid token', 404);
		}

		if ($action == 'contents' && $method == 'GET') {
			http_response_code(200);
			$this->storage->get($uri);
		}
		elseif ($action == 'contents' && $method == 'POST') {
			http_response_code(200);
			$this->storage->put($uri, fopen('php://input', 'rb'));
		}
		elseif (!$action && $method == 'GET') {
			$this->getInfo($uri);
		}
		else {
			throw new Exception('Invalid URI', 404);
		}

		return true;
	}

	protected function getInfo(string $uri): bool
	{
		$props = $this->storage->properties($uri, [
			'DAV::getcontentlength',
			'DAV::getlastmodified',
			'DAV::getetag',
			self::PROP_READ_ONLY,
		], 0);

		$modified = !empty($props['DAV::getlastmodified']) ? $props['DAV::getlastmodified']->format(DATE_ISO8601) : null;
		$size = $props['DAV::getcontentlength'] ?? null;

		$data = [
			'BaseFileName' => basename($uri),
			'OwnerId' => 1,
			'UserId' => 1,
			'Size' => $size,
			'Version' => $props['DAV::getetag'] ?? md5($uri . $size . $modified),
		];

		if ($modified) {
			$data['LastModifiedTime'] = $modified;
		}

		if (!empty($props['self::PROP_READ_ONLY'])) {
			$data['ReadOnly'] = $props['self::PROP_READ_ONLY'];
			$data['UserCanWrite'] = !$props['self::PROP_READ_ONLY'];
			$data['UserCanRename'] = !$props['self::PROP_READ_ONLY'];
		}

		http_response_code(200);
		echo json_encode($data, JSON_PRETTY_PRINT);
		return true;
	}

	/**
	 * Return list of available editors
	 * @param  string $url WOPI client discovery URL (eg. http://localhost:8080/hosting/discovery for OnlyOffice)
	 * @return an array containing a list of extensions and (eventually) a list of mimetypes
	 * that can be handled by the editor server:
	 * ['extensions' => [
	 *   'odt' => ['edit' => 'http://...', 'embedview' => 'http://'],
	 *   'ods' => ...
	 * ], 'mimetypes' => [
	 *   'application/vnd.oasis.opendocument.presentation' => ['edit' => 'http://'...],
	 * ]]
	 */
	public function discover(string $url): array
	{
		if (function_exists('curl_init')) {
			$c = curl_init($url);
			curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
			$r = curl_exec($c);
			$code = curl_getinfo(CURLINFO_HTTP_CODE);

			if ($code != 200) {
				throw new \RuntimeException(sprintf("Discovery URL returned an error: %d\n%s", $code, $r));
			}

			curl_close($c);
		}
		else {
			$r = file_get_contents($url);
			$ok = false;

			foreach ($http_response_header as $h) {
				if (0 === strpos($h, 'HTTP/') && false !== strpos($h, '200')) {
					$ok = true;
					break;
				}
			}

			if (!$ok || empty($r)) {
				throw new \RuntimeException(sprintf("Discovery URL returned an error:\n%s", $r));
			}
		}

		$xml = simplexml_load_string($r);

		if (!is_object($xml)) {
			throw new \RuntimeException('Invalid XML returned by discovery');
		}

		$extensions = [];
		$mimetypes = [];

		foreach ($xml->xpath('/wopi-discovery/net-zone/app') as $app) {
			$name = (string) $app['name'];
			$mime = null;

			if (strpos($name, '/')) {
				$mime = $name;

				if (!isset($mimetypes[$mime])) {
					$mimetypes[$mime] = [];
				}
			}

			foreach ($app->children() as $child) {
				if ($child->getName() != 'action') {
					continue;
				}

				$ext = (string) $child['ext'];
				$action = (string) $child['name'];
				$url = (string) $child['urlsrc'];

				if ($mime) {
					$mimetypes[$mime][$action] = $url;
				}
				else {
					if (!isset($extensions[$ext])) {
						$extensions[$ext] = [];
					}

					$extensions[$ext][$action] = $url;
				}
			}
		}

		unset($xml, $app, $child);

		return compact('extensions', 'mimetypes');
	}

	/**
	 * Return list of available options for editor URL
	 * This is called "Discovery query parameters" by OnlyOffice:
	 * https://api.onlyoffice.com/editors/wopi/discovery#wopi-standart
	 */
	public function getEditorAvailableOptions(string $url): array
	{
		$query = parse_url($url, PHP_URL_QUERY);

		preg_match_all('/<(\w+)=(\w+)&>/i', $query, $match, PREG_SET_ORDER);
		$options = [];

		foreach ($match as $m) {
			$options[$m[1]] = $m[2];
		}

		return $options;
	}

	/**
	 * Set query parameters for editor URL
	 */
	public function setEditorOptions(string $url, array $options = []): string
	{
		$url = parse_url($url);

		// Remove available options from URL
		$url['query'] = preg_replace('/<(\w+)=(\w+)&>/i', '', $url['query']);

		// Set options
		parse_str($url['query'], $params);
		$params = array_merge($params, $options);

		$host = $url['host'] . (!empty($url['port']) ? ':' . $url['port'] : '');
		$query = count($params) ? '?' . http_build_query($params) : '';
		$url = sprintf('%s://%s%s%s', $url['scheme'], $host, $url['path'], $query);
		return $url;
	}

	public function getEditorHTML(string $editor_url, string $document_uri, string $title = 'Document')
	{
		// You need to extend this method by creating a token for the document_uri first!
		// Store the token in the document properties using ::PROP_TOKEN

		$props = $this->storage->properties($document_uri, [self::PROP_TOKEN, self::PROP_TOKEN_TTL], 0);
		$src = $props[self::PROP_FILE_URL] ?? null;

		if (!$src) {
			throw new Exception('Storage did not provide a file URL for WOPI src', 500);
		}

		$token = $props[self::PROP_TOKEN] ?? null;
		// access_token_TTL: A 64-bit integer containing the number of milliseconds since January 1, 1970 UTC and representing the expiration date and time stamp of the access_token.
		$token_ttl = $props[self::PROP_TOKEN_TTL] ?? (time() + 3600) * 1000;


		// Append WOPI host URL
		$url = $this->setEditorOptions($editor_url, ['wopisrc' => $src]);

		if (!$token) {
			throw new Exception('Access forbidden: no token was created', 403);
		}

		return <<<EOF
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="utf-8" />
			<meta http-equiv="X-UA-Compatible" content="IE=edge" />
			<meta name="viewport" content="width=device-width" />
			<title>{$title}</title>
			<style type="text/css">
				body {
					margin: 0;
					padding: 0;
					overflow: hidden;
					-ms-content-zooming: none;
				}
				#frame {
					width: 100%;
					height: 100%;
					position: absolute;
					top: 0;
					left: 0;
					right: 0;
					bottom: 0;
					margin: 0;
					border: none;
					display: block;
				}
			</style>
		</head>

		<body>
			<form target="frame" action="{$url}" method="post">
				<input name="access_token" value="{$token}" type="hidden" />
				<input name="access_token_ttl" value="{$token_ttl}" type="hidden" />
			</form>

			<iframe id="frame" name="frame" sandbox="allow-scripts allow-same-origin allow-forms allow-popups allow-top-navigation allow-popups-to-escape-sandbox allow-downloads allow-modals" allow="autoplay camera microphone display-capture" allowfullscreen="true"></iframe>

			<script type="text/javascript">
			document.forms[0].submit();
			</script>
		</body>
		</html>
EOF;
	}

	/**
	 * Returns a base64 string safe for URLs
	 * @param  string $str
	 * @return string
	 */
	static public function base64_encode_url_safe($str)
	{
		return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
	}

	/**
	 * Decodes a URL safe base64 string
	 * @param  string $str
	 * @return string
	 */
	static public function base64_decode_url_safe($str)
	{
		return base64_decode(str_pad(strtr($str, '-_', '+/'), strlen($str) % 4, '=', STR_PAD_RIGHT));
	}
}
