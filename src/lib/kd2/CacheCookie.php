<?php

namespace KD2;

/**
 * Cache Cookie
 * (C) 2011-2014 BohwaZ
 * Inspired by Frank Denis (C) 2011 Public domain
 * https://00f.net/2011/01/19/thoughts-on-php-sessions/
 */

class CacheCookie
{
    protected $name = 'cache';
    protected $secret_key = null;
    protected $digest_method = 'md5';
    protected $path = '/';
    protected $domain = null;
    protected $duration = 0;
    protected $secure = false;

    protected $content = null;

    public function __construct($name = null, $secret = null, $duration = null, $path = null, $domain = null, $secure = null)
    {
        if (!is_null($name))
        {
            $this->setName($name);
        }

        if (!is_null($secret))
        {
            $this->setSecret($secret);
        }
        else
        {
            // Default secret key
            $this->setSecret(md5($_SERVER['DOCUMENT_ROOT']));
        }

        if (!is_null($duration))
        {
            $this->setDuration($duration);
        }

        if (!is_null($path))
        {
            $this->setPath($path);
        }

        if (!is_null($domain))
        {
            $this->setDomain($domain);
        }
        else
        {
            $this->setDomain(!empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME']);
        }

        if (!is_null($secure))
        {
            $this->setSecure($secure);
        }
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function setSecret($secret)
    {
        $this->secret = $secret;
    }

    public function setDuration($duration)
    {
        $this->duration = (int) $duration;
    }

    public function setPath($path)
    {
        $this->path = $path;
    }

    public function setDomain($domain)
    {
        $this->domain = $domain;
    }

    public function setSecure($secure)
    {
        $this->secure = (bool)$secure;
    }

    protected function _getCookie()
    {
        if (!is_null($this->content))
        {
            return $this->content;
        }

        $cookie = null;
        $this->content = [];

        if (!empty($_COOKIE[$this->name]))
        {
            $cookie = $_COOKIE[$this->name];
        }

        if (!empty($cookie) && (strpos($cookie, '|') !== false))
        {
            list($digest, $data) = explode('|', $cookie, 2);

            if (!empty($digest) && !empty($data) &&
                ($digest === hash_hmac($this->digest_method, $data, $this->secret)))
            {
                if (substr($data, 0, 1) == '{')
                {
                    $this->content = json_decode($data, true);
                }
                elseif (function_exists('msgpack_unpack'))
                {
                    $this->content = msgpack_unpack($data);
                }
            }
        }

        return $this->content;
    }

    /**
     * Sends the cookie content to the user-agent
     * @return boolean TRUE for success, 
     * or RuntimeException if the HTTP headers have already been sent
     */
    public function save()
    {
        if (headers_sent())
        {
            throw new \RuntimeException('Cache cookie can not be saved as headers have '
                . 'already been sent to the user agent.');
        }

        $headers = headers_list(); // List all headers
        header_remove(); // remove all headers
        $regexp = '/^Set-Cookie\\s*:\\s*' . preg_quote($this->name) . '=/';

        foreach ($headers as $header)
        {
            // Re-add every header except the one for this cookie
            if (!preg_match($regexp, $header))
            {
                header($header, true);
            }
        }

        if (!empty($this->content) && count($this->content) > 0)
        {
            if (function_exists('msgpack_pack'))
            {
                $data = msgpack_pack($this->content);
            }
            else
            {
                $data = json_encode($this->content);
            }

            $cookie = hash_hmac($this->digest_method, $data, $this->secret) . '|' . $data;
            $duration = $this->duration ? time() + $this->duration : 0;

            if (strlen($cookie . $this->path . $this->duration . $this->domain . $this->name) > 4080)
            {
                throw new \OverflowException('Cache cookie can not be saved as its size exceeds 4KB.');
            }

            setcookie($this->name, $cookie, $duration, $this->path, $this->domain, $this->secure, true);

            $_COOKIE[$this->name] = $cookie;
        }
        else
        {
            setcookie($this->name, '', 1, $this->path, $this->domain, $this->secure, true);
            unset($_COOKIE[$this->name]);
        }

        return true;
    }

    /**
     * Set a key/value pair in the cache cookie
     * @param mixed  $key   Key (integer or string)
     * @param mixed  $value Value (integer, string, boolean, array, float...)
     */
    public function set($key, $value)
    {
        $this->_getCookie();

        if (is_null($value))
        {
            unset($this->content[$key]);
        }
        else
        {
            $this->content[$key] = $value;
        }

        return true;
    }

    /**
     * Get data from the cache cookie, if $key is NULL then all the keys will be returned
     * @param  mixed    $key Data key
     * @return mixed    NULL if the key is not found, or content of the requested key
     */
    public function get($key = null)
    {
        $content = $this->_getCookie();

        if (is_null($key))
        {
            return $content;
        }

        if (!array_key_exists($key, $content))
        {
            return null;
        }
        else
        {
            return $content[$key];
        }
    }

    /**
     * Delete the cookie and all its data
     * @return boolean TRUE
     */
    public function delete()
    {
        $content = $this->get();

        foreach ($content as $key=>$value)
        {
            $this->set($key, null);
        }

        return $this->save();
    }
}
