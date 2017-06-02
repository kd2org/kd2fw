<?php

namespace KD2;

/**
 * A simple OAuth2 server
 */
class OAuth2_Server
{
	/**
	 * 1 hour
	 */
	const DEFAULT_ACCESS_TOKEN_EXPIRY = 3600;

	/**
	 * 14 days
	 */
	const DEFAULT_REFRESH_TOKEN_EXPIRY = 1209600;

	/**
	 * Callbacks for checking tokens etc. and storing stuff
	 * @var stdClass
	 */
	protected $callbacks;

	/**
	 * Expiry times
	 * @var stdClass
	 */
	protected $expiry;

	/**
	 * Stores the currently authorized user
	 * @var null|boolean|object
	 */
	protected $authorized;

	/**
	 * Return mode. true is return string instead of printing and exiting.
	 * Default is to print the response and exit.
	 * @var boolean
	 */
	protected $return_mode = false;

	public function __construct()
	{
		$this->setExpiry();
		$this->callbacks = (object)[];
	}

	public function toggleReturnMode($enable = true)
	{
		$this->return_mode = (bool) $enable;
	}

	public function setExpiry($access = self::DEFAULT_ACCESS_TOKEN_EXPIRY, $refresh = 7776000)
	{
		$this->expiry = (object) [
			'access'  => (int) $access,
			'refresh' => (int) $refresh,
		];
	}

	public function setCallback($type, callable $callback)
	{
		static $types = ['store_token', 'check_client', 'check_token'];

		if (!in_array($type, $types))
		{
			throw new \InvalidArgumentException('Invalid callback type, expected one of: ' . implode(', ', $types));
		}

		$this->callbacks->$type = $callback;
	}

	public function handleRequest()
	{
		if (count((array) $this->callbacks) !== 3)
		{
			throw new \LogicException('No callbacks registered.');
		}

		if (isset($_POST['grant_type']))
		{
			return $this->handleGrantRequest($_POST['grant_type']);
		}

		return false;
	}

	public function isAuthorized()
	{
		$this->handleAuthorization();
		return $this->authorized ? true : false;
	}

	public function getAuthorizedUser()
	{
		$this->handleAuthorization();
		return $this->authorized ? $this->authorized->user : false;
	}

	public function unauthorize()
	{
		$this->authorized = null;
	}

	protected function handleAuthorization()
	{
		if (!is_null($this->authorized))
		{
			return false;
		}

		if (empty($_SERVER['HTTP_AUTHORIZATION']))
		{
			return false;
		}

		list($type, $token) = sscanf($_SERVER['HTTP_AUTHORIZATION'], '%s %s');

		if (!$type || !$token)
		{
			return $this->error('invalid_request', 'Invalid Authorization header. Expected: Bearer [token]');
		}

		if (strcasecmp($type, 'Bearer') !== 0)
		{
			return $this->error('invalid_request', 'Invalid Authorization type. Expected: Bearer');
		}

		$this->authorized = new \stdClass;
		$this->authorized->user = call_user_func($this->callbacks->check_token, 'access_token', $token);

		if (!$user)
		{
			return $this->error('invalid_token', 'Invalid or expired Authorization token.', 401);
		}

		return true;
	}

	protected function handleGrantRequest($type)
	{
		if ($type == 'client_credentials')
		{
			if (empty($_POST['client_id']) || empty($_POST['client_secret']))
			{
				return $this->error('invalid_client', 'Missing client ID or client secret.');
			}

			$client_id = trim($_POST['client_id']);

			$ok = call_user_func($this->callbacks->check_client, $client_id, $_POST['client_secret']);

			if (!$ok)
			{
				return $this->error('invalid_client', 'Invalid or expired client ID or client secret.');
			}

			$refresh_token = $this->generateToken($client_id);
			$access_token = $this->generateToken($refresh_token);

			call_user_func($this->callbacks->store_token, [
				'client_id'      => $client_id,
				'access_token'   => $access_token,
				'access_expiry'  => (new \DateTime)->modify(sprintf('+%d seconds', $this->expiry->access)),
				'refresh_token'  => $refresh_token,
				'refresh_expiry' => (new \DateTime)->modify(sprintf('+%d seconds', $this->expiry->refresh)),
			]);

			return $this->response([
				'token_type'    => 'bearer',
				'access_token'  => $access_token,
				'expires_in'    => $this->expiry->access,
				'refresh_token' => $refresh_token,
			]);
		}
		elseif ($type == 'refresh_token')
		{
			if (empty($_POST['refresh_token']))
			{
				return $this->error('invalid_token', 'Missing refresh token.');
			}

			$token = $_POST['refresh_token'];

			$ok = call_user_func($this->callbacks->check_token, 'refresh_token', $token);

			if (!$ok)
			{
				return $this->error('invalid_token', 'Invalid or expired refresh token.', 401);
			}

			// Generate new tokens
			$refresh_token = $this->generateToken($token);
			$access_token = $this->generateToken($refresh_token);

			call_user_func($this->callbacks->store_token, [
				'old_refresh_token' => $token,
				'access_token'      => $access_token,
				'access_expiry'     => (new \DateTime)->modify(sprintf('+%d seconds', $this->expiry->access)),
				'refresh_token'     => $refresh_token,
				'refresh_expiry'    => (new \DateTime)->modify(sprintf('+%d seconds', $this->expiry->refresh)),
			]);

			return $this->response([
				'token_type'    => 'bearer',
				'access_token'  => $access_token,
				'expires_in'    => $this->expiry->access,
				'refresh_token' => $refresh_token,
			]);
		}
		else
		{
			return $this->error('unsupported_grant_type', 'Unsupported grant type.');
		}
	}

	protected function response($data, $code = 200)
	{
		static $messages = [
			200 => 'OK',
			400 => 'Bad Request',
			401 => 'Unauthorized',
		];

		$protocol = !empty($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';

		if (!$this->return_mode && !headers_sent())
		{
			header(sprintf('%s %d %s', $protocol, $code, $messages[$code]), true, (int)$code);
			header('Content-Type: application/json', true);
		}

		$data = json_encode($data, JSON_PRETTY_PRINT);

		if (!$this->return_mode)
		{
			echo $data;
			exit;
		}

		return $data;
	}

	protected function error($error, $description, $code = 400)
	{
		return $this->response([
			'error'             => $error,
			'error_description' => $description,
		], $code);
	}

	protected function generateToken($ref)
	{
		// 16 random bytes
		$bytes = random_bytes(16);

		// hash it
		$token = hash('sha256', $bytes . $ref, true);

		// then encode withURI-safe Base64
		$token = rtrim(strtr(base64_encode($token), '+/', '-_'), '=');

		return $token;
	}
}