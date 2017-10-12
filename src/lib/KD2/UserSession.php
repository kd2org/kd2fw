<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/
  
  Copyright (c) 2001-2016 BohwaZ <http://bohwaz.net/>
  All rights reserved.
  
  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:
  1. Redistributions of source code must retain the above copyright notice,
  this list of conditions and the following disclaimer.
  2. Redistributions in binary form must reproduce the above copyright notice,
  this list of conditions and the following disclaimer in the documentation
  and/or other materials provided with the distribution.
  
  THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
  AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
  IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
  ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
  LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
  CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF
  THE POSSIBILITY OF SUCH DAMAGE.
*/

/**
 * UserSession
 *
 * @author  bohwaz  http://bohwaz.net/
 * @license BSD
 * @version 0.3
 */

namespace KD2;

use KD2\DB;
use KD2\QRCode;
use KD2\Security_OTP;

class UserSession
{
	////////////////////////////////////////////////////////////////////////////
	// Methods and properties that should be reimplemented in the child class
	// You should implement those methods in the class that is extending this
	// one to suit your setup.

	/**
	 * Cookie name for the current, short-lived session (PHP session)
	 * @var string
	 */
	protected $cookie_name = 'session';

	/**
	 * Cookie name for "remember me" session
	 * @var string
	 */
	protected $remember_me_cookie_name = 'rememberme';

	/**
	 * Domain name used for cookies
	 * @var string
	 */
	protected $cookie_domain = null;

	/**
	 * URI path used for cookies
	 * @var string
	 */
	protected $cookie_path = '/';

	/**
	 * Secure bit for cookies, set to TRUE to have the cookie only returned
	 * on HTTPS.
	 * @var boolean
	 */
	protected $cookie_secure = false;

	/**
	 * Expiry of "remember me" sessions
	 * Any string supported by strtotime() is supported here
	 * @var string
	 */
	protected $remember_me_expiry = '+3 months';

	/**
	 * Checks a password supplied at login ($supplied_password) against a stored
	 * password ($stored_password)
	 * @param  string $supplied_password
	 * @param  string $stored_password
	 * @return boolean TRUE if password is matching, FALSE if it's not
	 */
	public function checkPassword($supplied_password, $stored_password)
	{
		// Remove NUL bytes
		// see http://blog.ircmaxell.com/2015/03/security-issue-combining-bcrypt-with.html
		$supplied_password = str_replace("\0", '', $supplied_password);

		return password_verify($supplied_password, $stored_password);
	}

	/**
	 * Returns user details for login
	 * @param  string $login
	 * @return object|boolean An object with at least 3 public properties: login, password and otp_secret. Or FALSE if
	 * there is no user found for this login (or the user doesn't have the right to login).
	 */
	protected function getUserForLogin($login)
	{
		return $this->db->first('SELECT login, password, otp_secret, id FROM users WHERE login = ? LIMIT 1;', $login);
	}

	/**
	 * Returns user details to populate session data
	 * @param  mixed  $id User ID
	 * @return object An object that will be stored in session
	 */
	protected function getUserDataForSession($id)
	{
		return $this->db->first('SELECT * FROM users WHERE id = ? LIMIT 1;', $id);
	}

	/**
	 * Stores "remember me" selector and details
	 * @param  string $selector
	 * @param  string $hash
	 * @param  integer $expiry
	 * @param  string $user_id
	 * @return boolean
	 */
	protected function storeRememberMeSelector($selector, $hash, $expiry, $user_id)
	{
		return $this->db->insert('remember_me_selectors', [
			'selector' => $selector,
			'hash'     => $hash,
			'expiry'   => $expiry,
			'user_id'  => $user_id,
		]);
	}

	/**
	 * Deletes expired selectors
	 * @return boolean
	 */
	protected function expireRememberMeSelectors()
	{
		return $this->db->delete('remember_me_selectors', $this->db->where('expiry', '<', time()));
	}

	/**
	 * Returns a remember me selector and the user password
	 * @param  string $selector
	 * @return object An object with public properties: selector, hash, user_id and user_password
	 */
	protected function getRememberMeSelector($selector)
	{
		return $this->db->first('SELECT r.*, u.password AS user_password
			FROM remember_me_selectors AS r
			LEFT JOIN users AS u ON u.id = r.user_id
			WHERE r.selector = ? LIMIT 1;', $selector);
	}

	/**
	 * Deletes a specific selector
	 * @param  string $selector
	 * @return boolean
	 */
	protected function deleteRememberMeSelector($selector)
	{
		return $this->db->delete('remember_me_selectors', $this->db->where('selector', $selector));
	}

	/**
	 * Deletes all selectors for a user
	 * @param  string $user_id
	 * @return boolean
	 */
	protected function deleteAllRememberMeSelectors($user_id)
	{
		return $this->db->delete('remember_me_selectors', $this->db->where('user_id', $user_id));
	}

	////////////////////////////////////////////////////////////////////////////
	// Actual code of UserSession

	const HASH_ALGO = 'sha256';
	const REQUIRE_OTP = 'otp';

	protected $cookie;
	protected $user;

	protected $db;

	public function __construct(DB $db, $config = [])
	{
		$this->db = $db;

		foreach ($config as $key=>$value)
		{
			$this->$key = $value;
		}

		if (null === $this->cookie_domain && isset($_SERVER['SERVER_NAME']))
		{
			$this->cookie_domain = $_SERVER['SERVER_NAME'];
		}
	}

	protected function getSessionOptions()
	{
		return [
			'name'            => $this->cookie_name,
			'cookie_path'     => $this->cookie_path,
			'cookie_domain'   => $this->cookie_domain,
			'cookie_secure'   => $this->cookie_secure,
			'cookie_httponly' => true,
		];
	}

	public function start($write = false)
	{
		// Don't start session if it has been already started
		if (isset($_SESSION))
		{
			return true;
		}

		// Only start session if it exists
		if ($write || isset($_COOKIE[$this->cookie_name]))
		{
			session_set_cookie_params(0, $this->cookie_path, $this->cookie_domain, $this->cookie_secure, true);
			session_name($this->cookie_name);
			return session_start($this->getSessionOptions());
		}

		return false;
	}

	public function keepAlive()
	{
		return $this->start(true);
	}

	public function refresh()
	{
		if (!$this->isLogged())
		{
			throw new \LogicException('User is not logged in.');
		}

		return $this->create($this->user->id);
	}

	public function isLogged()
	{
		if (null !== $this->user)
		{
			return true;
		}

		// Démarrage session
		$this->start();

		if (empty($_SESSION['userSession']))
		{
			$this->rememberMeAutoLogin();
		}

		if (empty($_SESSION['userSession']))
		{
			return false;
		}

		$this->user =& $_SESSION['userSession'];
		return true;
	}

	public function getUser()
	{
		if (!$this->isLogged())
		{
			throw new \LogicException('User is not logged in.');
		}

		return $this->user;
	}

	public function set($key, $value)
	{
		if (!isset($_SESSION['userSessionData']))
		{
			$_SESSION['UserSessionData'] = [];
		}

		$_SESSION['userSessionData'][$key] = $value;
	}

	public function get($key)
	{
		if (isset($_SESSION['userSessionData'][$key]))
		{
			return $_SESSION['userSessionData'][$key];
		}

		return null;
	}

	public function login($login, $password, $remember_me = false)
	{
		assert(is_bool($remember_me));
		assert(is_string($login));
		assert(is_string($password));

		$user = $this->getUserForLogin(trim($login));

		if (!$user)
		{
			return false;
		}

		if (!$this->checkPassword(trim($password), $user->password))
		{
			return false;
		}

		if (!empty($user->otp_secret))
		{
			$this->start(true);

			$_SESSION = [];

			$_SESSION['userSessionRequireOTP'] = (object) [
				'user'        => $user,
				'remember_me' => $remember_me,
			];

			return $this::REQUIRE_OTP;
		}
		else
		{
			$this->create($user->id);

			if ($remember_me)
			{
				$this->createRememberMeSelector($user->id, $user->password);
			}

			return true;
		}
	}

	protected function create($user_id)
	{
		$user = $this->getUserDataForSession($user_id);

		if (!$user)
		{
			throw new \LogicException('Cannot create a session for a user that does not exists.');
		}

		$this->start(true);
		$this->user = $_SESSION['userSession'] = $user;
		return true;
	}

	public function logout()
	{
		if ($cookie = $this->getRememberMeCookie())
		{
			$this->deleteRememberMeSelector($cookie->selector);

			setcookie($this->remember_me_cookie_name, null, -1, $this->cookie_path,
				$this->cookie_domain, $this->cookie_secure, true);
			unset($_COOKIE[$this->remember_me_cookie_name]);
		}

		$this->start(true);
		$_SESSION = [];

		setcookie($this->cookie_name, null, -1, $this->cookie_path,
			$this->cookie_domain, $this->cookie_secure, true);

		unset($_COOKIE[$this->cookie_name]);
	
		return true;
	}

	//////////////////////////////////////////////////////////////////////
	// "Remember me" feature

	/**
	 * Creates a permanent "remember me" session
	 * @link   https://www.databasesandlife.com/persistent-login/
	 * @link   https://paragonie.com/blog/2015/04/secure-authentication-php-with-long-term-persistence
	 * @link   https://paragonie.com/blog/2017/02/split-tokens-token-based-authentication-protocols-without-side-channels
	 * @link   http://jaspan.com/improved_persistent_login_cookie_best_practice
	 * @param  object $user
	 * @return boolean
	 */
	protected function createRememberMeSelector($user_id, $user_password)
	{
		$selector = hash($this::HASH_ALGO, Security::random_bytes(10));
		$verifier = hash($this::HASH_ALGO, Security::random_bytes(10));
		$expiry = (new \DateTime)->modify($this->remember_me_expiry);
		$expiry = $expiry->getTimestamp();

		$hash = hash($this::HASH_ALGO, $selector . $verifier . $user_password . $expiry);

		$this->storeRememberMeSelector($selector, $hash, $expiry, $user_id);

		$cookie = $selector . '|' . $verifier;

		setcookie($this->remember_me_cookie_name, $cookie, $expiry,
			$this->cookie_path, $this->cookie_domain, $this->cookie_secure, true);

		return true;
	}

	/**
	 * Connexion automatique en utilisant un cookie permanent
	 * (fonction "remember me")
	 *
	 * @link   https://www.databasesandlife.com/persistent-login/
	 * @link   https://paragonie.com/blog/2015/04/secure-authentication-php-with-long-term-persistence
	 * @link   https://paragonie.com/blog/2017/02/split-tokens-token-based-authentication-protocols-without-side-channels
	 * @link   http://jaspan.com/improved_persistent_login_cookie_best_practice
	 * @return boolean
	 */
	protected function rememberMeAutoLogin()
	{
		$cookie = $this->getRememberMeCookie();

		if (!$cookie)
		{
			return false;
		}

		// Delete expired selectors first thing
		$this->expireRememberMeSelectors();

		// Fetch the selector
		$selector = $this->getRememberMeSelector($cookie->selector);

		// Invalid selector: delete any cookie (clean up)
		if (!$selector)
		{
			return $this->logout();
		}

		// The selector is useless now, delete it so that it can't be reused
		$this->deleteRememberMeSelector($cookie->selector);

		// Here we are using the user password. If the user changes his password,
		// any previously opened session will be invalid.
		$hash = hash($this::HASH_ALGO, $cookie->selector . $cookie->verifier . $selector->user_password . $selector->expiry);

		// Check the token hash
		if (!hash_equals($selector->hash, $hash))
		{
			// If we get there it means that the selector is valid, but not its verifier token hash
			// Either the cookie has been stolen, then the attacker has obtained a
			// new token, and the user is coming back with an old token that is now
			// invalid. In that case let's delete all remember me selectors to force
			// the user to re-login

			$this->deleteAllRememberMeSelectors($selector->user_id);
			return $this->logout();
		}

		// Create short lived session
		$this->create($selector->user_id);

		// Re-generate a new verifier/selector and update the cookie
		// as each selector is single use
		$this->createRememberMeSelector($selector->user_id, $selector->user_password);

		return true;
	}


	protected function getRememberMeCookie()
	{
		if (empty($_COOKIE[$this->remember_me_cookie_name]))
		{
			return false;
		}

		$cookie = $_COOKIE[$this->remember_me_cookie_name];

		$data = explode('|', $cookie);

		if (count($data) !== 2)
		{
			return false;
		}

		return (object) [
			'selector' => $data[0],
			'verifier' => $data[1],
		];
	}

	//////////////////////////////////////////////////////////////////////
	// Second factor OTP feature

	public function isOTPRequired()
	{
		$this->start();

		return !empty($_SESSION['userSessionRequireOTP']);
	}

	public function loginOTP($code)
	{
		$this->start();

		if (empty($_SESSION['userSessionRequireOTP']))
		{
			return false;
		}

		$user = $_SESSION['userSessionRequireOTP']->user;

		if (empty($user->otp_secret) || empty($user->id))
		{
			return false;
		}

		if (!$this->checkOTP($user->otp_secret, $code))
		{
			return false;
		}

		if (!empty($_SESSION['userSessionRequireOTP']->remember_me))
		{
			$this->createRememberMeSelector($user->id, $user->password);
		}

		$this->create($user->id);
		return true;
	}

	public function checkOTP($secret, $code)
	{
		return Security_OTP::TOTP($secret, $code);
	}

	public function getPGPFingerprint($key, $display = false)
	{
		if (!Security::canUseEncryption())
		{
			return false;
		}

		$fingerprint = Security::getEncryptionKeyFingerprint($key);

		if ($display && $fingerprint)
		{
			$fingerprint = str_split($fingerprint, 4);
			$fingerprint = implode(' ', $fingerprint);
		}

		return $fingerprint;
	}
}