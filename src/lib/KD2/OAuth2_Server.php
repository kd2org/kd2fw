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

	/**
	 * Forces clients with passwords to use a secure (TLS/SSL) connection
	 * OAuth 2.0 RFC section 2.3.1 "The authorization server MUST require the use of TLS"
	 *
	 * You can disable that restriction for test purposes though.
	 *
	 * Please note that this is using $_SERVER['HTTPS'] to detect if the
	 * connection is secure or not and it might not be set on some configurations.
	 * 
	 * @var boolean
	 */
	protected $force_secure_client_connection = true;

	public function __construct()
	{
		$this->setExpiry();
		$this->callbacks = (object)[];
	}

	public function toggleReturnMode($enable)
	{
		$this->return_mode = (bool) $enable;
	}

	public function toggleForceSecureClient($enable)
	{
		$this->force_secure_client_connection = (bool) $enable;
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
		static $types = ['store_token', 'check_access_token', 'check_refresh_token',
			'auth_client', 'auth_password'];

		if (!in_array($type, $types))
		{
			throw new \InvalidArgumentException('Invalid callback type, expected one of: ' . implode(', ', $types));
		}

		$this->callbacks->$type = $callback;
	}

	public function handleRequest()
	{
		if (!isset($this->callbacks->store_token) || !isset($this->callbacks->check_access_token))
		{
			throw new \LogicException('Undefined callback. Both of these are required: store_token, check_access_token');
		}

		if (!isset($this->callbacks->auth_client) && !isset($this->callbacks->auth_password))
		{
			throw new \LogicException('No auth callback defined. One of these is required: auth_client, auth_password');
		}

		if (isset($_POST['grant_type']))
		{
			return $this->handleGrantRequest($_POST['grant_type']);
		}

		if (isset($_POST['response_type']))
		{
			return $this->error('unsupported_grant_type', 'This grant type is not supported.');
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
		$this->authorized->user = call_user_func($this->callbacks->check_access_token, $token);

		if (false === $this->authorized->user)
		{
			$this->authorized = false;
			return $this->error('invalid_token', 'Invalid or expired Authorization token.', 401);
		}

		return true;
	}

	protected function handleGrantRequest($type)
	{
		if ($type == 'password' && isset($this->callbacks->auth_password))
		{
			if (empty($_POST['username']) || empty($_POST['password']))
			{
				return $this->error('invalid_client', 'Missing username or password for password grant type.');
			}

			$response = call_user_func($this->callbacks->auth_password, $_POST['username'], $_POST['password']);

			if (!$response)
			{
				return $this->error('invalid_grant', 'Invalid username or password.');
			}

			return $this->tokenResponse($type, $response);
		}
		elseif ($type == 'client_credentials' && isset($this->callbacks->auth_client))
		{
			if ($this->force_secure_client_connection && empty($_SERVER['HTTPS']))
			{
				return $this->error('unsupported_over_http', 'Grant type client_credentials must be used with HTTPS only.');
			}

			// Support for HTTP Basic authentication
			if (!empty($_SERVER['PHP_AUTH_USER']) && !empty($_SERVER['PHP_AUTH_PW']))
			{
				$client_id = $_SERVER['PHP_AUTH_USER'];
				$client_secret = $_SERVER['PHP_AUTH_PW'];
			}
			// Support sending of id/password in body
			elseif (!empty($_POST['client_id']) && !empty($_POST['client_secret']))
			{
				$client_id = $_POST['client_id'];
				$client_secret = $_POST['client_secret'];
			}
			else
			{
				return $this->error('invalid_client', 'Missing client ID or client secret.');
			}

			$response = call_user_func($this->callbacks->auth_client, $client_id, $client_secret);

			if (!$response)
			{
				return $this->error('invalid_client', 'Invalid or expired client ID or client secret.');
			}

			return $this->tokenResponse($type, $response);
		}
		elseif ($type == 'refresh_token' && isset($this->callbacks->check_refresh_token))
		{
			if (empty($_POST['refresh_token']))
			{
				return $this->error('invalid_token', 'Missing refresh token.');
			}

			$token = $_POST['refresh_token'];

			$response = call_user_func($this->callbacks->check_refresh_token, $token);

			if (!$response)
			{
				return $this->error('invalid_token', 'Invalid or expired refresh token.', 401);
			}

			return $this->tokenResponse($type, $response);
		}

		return $this->error('unsupported_grant_type', 'Unsupported grant type.');
	}

	protected function tokenResponse($type, $auth_response)
	{
		// Generate new tokens
		$access_token = $this->generateToken(microtime(true));
		$tokens = [
			'access_token'  => $access_token,
			'access_expiry' => (new \DateTime)->modify(sprintf('+%d seconds', $this->expiry->access)),
		];

		$response = [
			'token_type'    => 'bearer',
			'access_token'  => $access_token,
			'expires_in'    => $this->expiry->access,
		];

		// Only generate a refresh token if we have enabled that feature
		if (isset($this->callbacks->check_refresh_token))
		{
			$refresh_token = $this->generateToken(microtime(true));
			$response['refresh_token'] = $refresh_token;
			$tokens['refresh_token'] = $refresh_token;
			$tokens['refresh_expiry'] = (new \DateTime)->modify(sprintf('+%d seconds', $this->expiry->refresh));
		}

		call_user_func($this->callbacks->store_token, $type, $auth_response, (object) $tokens);

		return $this->response($response);
	}

	protected function response($data, $code = 200)
	{
		if ($this->return_mode)
		{
			return (object) $data;
		}

		static $messages = [
			200 => 'OK',
			400 => 'Bad Request',
			401 => 'Unauthorized',
		];

		$protocol = !empty($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';

		if (!headers_sent())
		{
			header(sprintf('%s %d %s', $protocol, $code, $messages[$code]), true, (int)$code);
			header('Content-Type: application/json', true);
		}

		$data = json_encode($data, JSON_PRETTY_PRINT);

		echo $data;
	}

	protected function error($error, $description, $code = 400)
	{
		return $this->response([
			'error'             => $error,
			'error_description' => $description,
		], $code);
	}

	protected function randomBytes($length)
	{
		if (function_exists('random_bytes'))
		{
			return random_bytes($length);
		}
        elseif (function_exists('mcrypt_create_iv') && version_compare(PHP_VERSION, '5.3.7') >= 0)
        {
            return mcrypt_create_iv($length, MCRYPT_DEV_URANDOM);
        }
		elseif (file_exists('/dev/urandom') && is_readable('/dev/urandom'))
		{
			return file_get_contents('/dev/urandom', false, null, 0, $length);
		}
		elseif (function_exists('openssl_random_pseudo_bytes'))
		{
			return openssl_random_pseudo_bytes($length);
		}
		else
		{
			throw new \LogicException('Cannot generate random bytes: no random source found.');
		}
	}

	protected function generateToken($ref)
	{
		// 16 random bytes
		$bytes = self::randomBytes(16);

		// hash it
		$token = hash('sha256', $bytes . $ref, true);

		// then encode withURI-safe Base64
		$token = rtrim(strtr(base64_encode($token), '+/', '-_'), '=');

		return $token;
	}
}