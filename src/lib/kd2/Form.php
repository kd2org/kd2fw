<?php

namespace KD2;

class Form
{
	/**
	 * Secret used for tokens
	 * @var null
	 */
	static protected $token_secret = null;

	/**
	 * Sets the secret key used to hash and check the CSRF tokens
	 * @param  string $secret Whatever secret you may like, must be the same for all the user session
	 * @return boolean true
	 */
	static public function tokenSetSecret($secret)
	{
		self::$token_secret = $secret;
		return true;
	}

	/**
	 * Generate a single use token and return the value
	 * The token will be HMAC signed and you can use it directly in a HTML form
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @param  integer $expire Number of hours before the hash will expire
	 * @return string         HMAC signed token
	 */
	static public function tokenGenerate($action = null, $expire = 5)
	{
		if (is_null(self::$token_secret))
		{
			throw new \RuntimeException('No CSRF token secret has been set.');
		}

		$action = self::tokenAction($action);

		$random = self::random_int();
		$expire = floor(time() / 3600) + $expire;
		$value = $expire . $random . $action;

		$hash = hash_hmac('sha256', $expire . $random . $action, self::$token_secret);

		return $hash . '/' . dechex($expire) . '/' . dechex($random);
	}

	/**
	 * Checks a CSRF token
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @param  string $value  User supplied value, if NULL then $_POST[automatic name] will be used
	 * @return boolean
	 */
	static public function tokenCheck($action = null, $value = null)
	{
		$action = self::tokenAction($action);

		if (is_null($value))
		{
			$name = self::tokenFieldName($action);
			
			if (empty($_POST[$name]))
			{
				return false;
			}

			$value = $_POST[$name];
		}

		$value = explode('/', $value, 3);

		if (count($value) != 3)
		{
			return false;
		}

		$user_hash = $value[0];
		$expire = hexdec($value[1]);
		$random = hexdec($value[2]);

		// Expired token
		if ($expire < ceil(time() / 3600))
		{
			return false;
		}

		$hash = hash_hmac('sha256', $expire . $random . $action, self::$token_secret);

		return self::hash_equals($hash, $user_hash);
	}

	/**
	 * Generates a random field name for the current token action
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @return string
	 */
	static public function tokenFieldName($action = null)
	{
		$action = self::tokenAction($action);
		return 'ct_' . sha1($action . $_SERVER['DOCUMENT_ROOT'] . $_SERVER['SERVER_NAME'] . $action);
	}

	/**
	 * Returns the supplied action name or if it is NULL, then the REQUEST_URI
	 * @param  string $action
	 * @return string
	 */
	static protected function tokenAction($action = null)
	{
		// Default action, will work as long as the check is on the same URI as the generation
		if (is_null($action) && !empty($_SERVER['REQUEST_URI']))
		{
			$url = parse_url($_SERVER['REQUEST_URI']);

			if (!empty($url['path']))
			{
				$action = $url['path'];
			}
		}

		return $action;
	}

	/**
	 * Returns HTML code to embed a CSRF token in a form
	 * @param  string $action An action description, if NULL then REQUEST_URI will be used
	 * @return string HTML <input type="hidden" /> element
	 */
	static public function tokenHTML($action = null)
	{
		return '<input type="hidden" name="' . self::tokenFieldName($action) . '" value="' . self::tokenGenerate($action) . '" />';
	}

	static protected $custom_validation_rules = [];

	static public function has($key)
	{
		return isset($_POST[$key]) || isset($_FILES[$key]);
	}

	static public function get($key)
	{
		if (isset($_POST[$key])) 
		{
			return $_POST[$key];
		}
		elseif (isset($_FILES[$key]))
		{
			return $_FILES[$key];
		}

		return null;
	}

	static public function registerValidationRule($name, callable $callback)
	{
		self::$custom_validation_rules[$name] = $callback;
	}

	static public function validateRule($key, $rule_name, Array $params = [])
	{
		$value = self::get($key);

		switch ($rule_name)
		{
			case 'required':
				if (isset($_FILES[$key]))
				{
					return self::validateRule($key, 'file');
				}
				elseif (is_array($value) || $value instanceof \Countable)
				{
					return count($value) < 1;
				}
				elseif (is_string($value))
				{
					return trim($value) !== '';
				}
				return is_null($value);
			case 'file':
				return isset($_FILES[$key]) && !empty($value['size']) && !empty($value['tmp_name']) && empty($value['error']);
			case 'active_url':
				$url = parse_url($value);
				return isset($url['host']) && strlen($url['host']) && (checkdnsrr($url['host'], 'A') || checkdnsrr($url['host'], 'AAAA'));
			case 'alpha':
				return preg_match('/^[\pL\pM]+$/u', $value);
			case 'alpha_dash':
				return preg_match('/^[\pL\pM\pN_-]+$/u', $value);
			case 'alpha_num':
				return preg_match('/^[\pL\pM\pN]+$/u', $value);
			case 'array':
				return is_array($value);
			case 'between':
				return isset($params[0]) && isset($params[1]) && $value >= $params[0] && $value <= $params[1];
			case 'boolean':
				return ($value == 0 || $value == 1);
			case 'confirmed':
				return $value === form_get($key . '_confirmed');
			case 'date':
				return (bool) strtotime($value);
			case 'date_format':
				$date = date_parse_from_format($params[0], $value);
				return $date['warning_count'] === 0 && $date['error_count'] === 0;
			case 'different':
				return isset($params[0]) && $value !== form_get($params[0]);
			case 'digits':
				return is_numeric($value) && strlen((string) $value) == $params[0];
			case 'digits_between':
				$len = strlen((string) $value);
				return is_numeric($value) && $len >= $params[0] && $len <= $params[0];
			case 'email':
				return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
			case 'in':
				return in_array($value, $params);
			case 'in_array':
				return isset($params[0]) && ($field = form_get($params[0])) && is_array($field) && in_array($value, $field);
			case 'integer':
				return is_int($value);
			case 'ip':
				return filter_var($value, FILTER_VALIDATE_IP) !== false;
			case 'json':
				return json_decode($value) !== null;
			case 'max':
				$size = is_array($value) ? count($value) : (is_numeric($value) ? $value : strlen($value));
				return isset($params[0]) && $size <= $params[0];
			case 'min':
				$size = is_array($value) ? count($value) : (is_numeric($value) ? $value : strlen($value));
				return isset($params[0]) && $size >= $params[0];
			case 'not_in':
				return !in_array($value, $params);
			case 'numeric':
				return is_numeric($value);
			case 'present':
				return form_has($key);
			case 'regex':
				return isset($params[0]) && preg_match($params[0], $value);
			case 'same':
				return isset($params[0]) && form_get($params[0]) == $value;
			case 'size':
				$size = is_array($value) ? count($value) : (is_numeric($value) ? $value : strlen($value));
				return isset($params[0]) && $size == (int) $params[0];
			case 'string':
				return is_string($value);
			case 'timezone':
				try {
					new DateTimeZone($value);
					return true;
				}
				catch (\Exception $e) {
					return false;
				}
			case 'url':
				return filter_var($value, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED) !== false;
			default:
				if (isset(self::$custom_validation_rules[$rule_name]))
				{
					return call_user_func(self::$custom_validation_rules[$rule_name], [$key, $params]);
				}

				throw new \UnexpectedValueException('Invalid rule name: ' . $rule_name);
		}
	}

	static public function check($token_action, Array $all_rules, Array &$errors = [])
	{
		if (!self::tokenCheck($token_action))
		{
			$errors[] = ['rule' => 'csrf'];
			return false;
		}

		return self::validate($all_rules, $errors);
	}

	static public function validate(Array $all_rules, Array &$errors = [])
	{
		foreach ($all_rules as $key=>$rules)
		{
			$rules = explode('|', $rules);

			foreach ($rules as $rule)
			{
				$params = explode(':', $rule);
				$name = $params[0];
				unset($params[0]);

				if (!form_validate_rule($key, $params[0], array_slice($params, 1)))
				{
					$errors[] = ['name' => $key, 'rule' => $name];
				}
			}
		}

		return count($errors) == 0 ? true : false;
	}
}
