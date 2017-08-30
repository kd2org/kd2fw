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

namespace KD2;

/**
 * A session management class
 * See http://www.held.org.il/blog/2008/02/php-session-locks/
 * and https://00f.net/2011/01/19/thoughts-on-php-sessions/
 */
class Session extends Singleton
{
	protected $blocking = true;
	protected $open = false;

	public function __construct()
	{
	}

	/**
	 * Sets if session should be blocking or non-blocking
	 * @param boolean $blocking TRUE to set a blocking session or FALSE to have a non-blocking session
	 */
	public function setBlocking($blocking = true)
	{
		$this->blocking = (bool) $blocking;
	}

	/**
	 * Starts a session
	 *
	 * If write mode is disabled then a session will only be started if it already exists
	 * (either there's a session cookie or a session transid variable in the URL). 
	 * If non-blocking mode is enabled: Then the session will be immediately closed. Data will 
	 * be available to read via get(), but a cal to set() will call back the start() function 
	 * in write mode, until the session is written using save().
	 *
	 * If write mode is enabled, then the session will be started no matter if it already exists,
	 * and you'll have to call save() when finished with the writing to unblock the session.
	 * 
	 * @param  boolean $write Enable write mode
	 * @return boolean TRUE
	 */
	protected function start($write = false)
	{
        if (($write && !$this->open) || (!isset($_SESSION) && $this->open
        	&& ((!ini_get('session.use_cookies') && array_key_exists(session_name(), $_GET)) 
        		|| array_key_exists(session_name(), $_COOKIE))
        	))
        {
        	if (headers_sent())
        	{
        		throw new \RuntimeException('Headers already sent: session can\'t started.');
        	}

            session_start();
            $this->open = true;
        }
        else
        {
        	$_SESSION = array();
        }

        if (!isset($_SESSION))
        {
        	throw new \RuntimeException('Unable to start session.');
        }

        // If not writing and non-blocking, free the session now
        if (!$write && !$this->blocking)
        {
        	$this->save();
        }

        return true;
	}

	public function keepAlive()
	{
		return session_start();
	}

	/**
	 * Will save the session data upon object destruction
	 * @return void
	 */
	public function __destroy()
	{
		$this->save();
	}

	/**
	 * Saves the session data
	 * It is advised to call this every time you ended a couple of session storage
	 * @return void
	 */
	public function save()
	{
		if ($this->open)
		{
			session_write_close();
		}

		$this->open = false;
	}

	/**
	 * Get session stored data
	 * @param  mixed 	$key 	Session key
	 * @return mixed 	Value of the key passed as an argument, or NULL if the key doesn't exists
	 */
	public function get($key)
	{
		$this->start();

		if (array_key_exists($key, $_SESSION))
			return $_SESSION[$key];
		else
			return null;
	}

	/**
	 * Sets session stored data
	 * @param mixed 	$key 	Session key
	 * @param boolean	TRUE
	 */
	public function set($key, $value)
	{
		$this->start(true);

		$_SESSION[$key] = $value;

		return true;
	}

	/**
	 * Destroys current session, removes the session cookie (if any) and ereases all session data
	 * @return boolean TRUE on success or FALSE on failure
	 */
	public function destroy()
	{
		$_SESSION = array();

		if (ini_get('session.use_cookies')) {
			$params = session_get_cookie_params();
			setcookie(session_name(), '', time() - 42000,
				$params['path'], $params['domain'],
				$params['secure'], $params['httponly']
			);
		}

		$this->open = false;
		return session_destroy();
	}
}