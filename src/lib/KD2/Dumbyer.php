<?php

namespace KD2;

class Dumbyer
{
	const NONE = 0;
	const LITERAL = 1;
	const SECTION = 10;
	const IF = 11;
	const ELSE = 12;

	protected $_stack = [];

	protected $_sections = [];
	protected $_modifiers = [];
	protected $_default_modifier = null;

	protected $_variables = [0 => []];

	public function assign(string $key, $value): void
	{
		if (!count($this->_variables)) {
			$this->_variables = [0 => []];
		}


		$this->_variables[count($this->_variables)-1][$key] = $value;
	}

	public function assignArray(array $array): void
	{
		foreach ($array as $key => $value) {
			$this->assign($key, $value);
		}
	}

	public function registerModifier(string $name, callable $callback): void
	{
		$this->_modifiers[$name] = $callback;
	}

	public function registerSection(string $name, callable $callback): void
	{
		$this->_sections[$name] = $callback;
	}

	public function __construct()
	{
		$this->registerDefaultModifier([$this, 'defaultModifier']);
	}

	public function defaultModifier(string $name, $var1, array $params): bool
	{
		$var2 = reset($params);

		switch ($name) {
			case '==': return $var1 == $var2;
			case '!=': return $var1 != $var2;
			case '>=': return $var1 >= $var2;
			case '<=': return $var1 <= $var2;
			case '>': return $var1 > $var2;
			case '<': return $var1 < $var2;
			case '===': return $var1 === $var2;
			case '!==': return $var1 !== $var2;
			default:
				throw new Dumbyer_Exception(sprintf('unknown function "%s"', $name));
		}
	}

	public function registerDefaultModifier(callable $callback): void
	{
		$this->_default_modifier = $callback;
	}

	public function render(string $code): string
	{
		$code = $this->compile($code);
		var_dump($code);

		try {
			ob_start();

			eval('?>' . $code);

			return ob_get_clean();
		}
		catch (\Exception $e) {
			throw new Dumbyer_Exception('Syntax error', 0, $e);
		}
	}

	public function compile(string $code): string
	{
		// Remove comments
		$code = preg_replace('!\{\{\*.*\}\}!', '', $code);

		// Remove PHP tags
		$code = strtr($code, [
			'<?php' => '<?=\'<?php\'?>',
			'<?' => '<?=\'<?\'?>',
			'?>' => '<?=\'?>\'?>'
		]);

		return preg_replace_callback('!\{\{\s*(if|else\s?if|include|else|endif|literal|[$#/])(?:\s+(.+))?\s*(.*)\s*\}\}!mUs', function ($match) use ($code) {
			try {
				$all = $match[0][0];
				$start = $match[1][0];
				$name = $match[2][0] ?? null;
				$params = $match[3][0] ?? null;

				return $this->_walk($all, $start, $name, $params);
			}
			catch (Dumbyer_Exception $e) {
				$offset = $match[0][1];
				$line = substr_count($code, "\n", 0, $offset);
				throw new Dumbyer_Exception(sprintf('Line %d: %s', $line, $e->getMessage()));
			}
		}, $code, -1, $count, PREG_OFFSET_CAPTURE);
	}

	public function get(string $name)
	{
		$array =& $this->_variables;

		for ($vars = end($array); key($array) !== null; $vars = prev($array)) {
			if (array_key_exists($name, $vars)) {
				return $vars[$name];
			}

			$found = false;

			if (strstr($name, '.') && ($return = $this->_magic($name, $vars, $found)) && $found) {
				return $return;
			}
		}

		return null;
	}

	protected function _magic(string $expr, $var, &$found)
	{
		$i = 0;
		$keys = explode('.', $expr);

		while ($key = array_shift($keys))
		{
			if ($i++ > 20)
			{
				// Limit the amount of recusivity we can go through
				$found = false;
				return null;
			}

			if (is_object($var))
			{
				// Test for constants
				if (defined(get_class($var) . '::' . $key))
				{
					$found = true;
					return constant(get_class($var) . '::' . $key);
				}

				if (!property_exists($var, $key))
				{
					$found = false;
					return null;
				}

				$var = $var->$key;
			}
			elseif (is_array($var))
			{
				if (!array_key_exists($key, $var))
				{
					$found = false;
					return null;
				}

				$var = $var[$key];
			}
		}

		$found = true;
		return $var;
	}

	protected function _push(int $type, ?string $name = null, ?array $params = []): void
	{
		$this->_stack[] = func_get_args();
	}

	protected function _pop(): ?array
	{
		return array_pop($this->_stack);
	}

	protected function _lastType(): int
	{
		return count($this->_stack) ? end($this->_stack)[0] : self::NONE;
	}

	protected function _lastName(): ?string
	{
		if ($this->_stack) {
			return end($this->_stack)[1];
		}

		return null;
	}

	protected function _walk(string $all, ?string $start, string $name, ?string $params): string
	{
		if (!$start && $name == 'literal') {
			$this->_push(self::LITERAL, $name);
			return '';
		}
		elseif ($start == '/' && $name == 'literal') {
			if ($this->_lastType() != self::LITERAL) {
				throw new Dumbyer_Exception('closing of a literal block that wasn\'t opened');
			}

			$this->_pop();
			return '';
		}
		elseif ($this->_lastType() == self::LITERAL) {
			return $all;
		}

		// Variable
		if ($start == '$') {
			return sprintf('<?=%s?>', $this->_variable($name, $params, true));
		}

		// Include
		if ($start == 'include') {
			return sprintf('<?=$this->fetch(%s);?>', var_export(trim($params), true));
		}

		if ($start == '#') {
			$this->_push(self::SECTION, $name);
			return $this->_section($name, $params);
		}
		elseif ($start == 'if') {
			$this->_push(self::IF, 'if');
			return $this->_if($name, $params);
		}
		elseif ($start == 'elseif') {
			if ($this->_lastType() != self::IF) {
				throw new Dumbyer_Exception('"elseif" block is not following a "if" block');
			}

			$this->_pop();
			$this->_push(self::IF, 'if');
			return $this->_if($name, $params, 'elseif');
		}
		elseif ($start == 'else') {
			$type = $this->_lastType();

			if ($type != self::IF && $type != self::SECTION) {
				throw new Dumbyer_Exception('"elseif" block is not following a "if" or section block');
			}

			$this->_pop();
			$this->_push(self::ELSE);

			if ($type == self::SECTION) {
				return '<?php endforeach; else: ?>';
			}
			else {
				return '<?php else: ?>';
			}
		}
		elseif ($start == '/') {
			if (($start == 'if' && $this->_lastName() != $start) || ($name && $this->_lastName() != $name)) {
				throw new Dumbyer_Exception(sprintf('"%s": block closing does not match last block "%s" opened', $all, $this->_lastName()));
			}

			return $this->_close($name, $params);
		}

		throw new Dumbyer_Exception('Unknown block: ' . $all);
	}

	protected function _section(string $name, string $params): string
	{
		$params = $this->_parseParams($params);
		return sprintf('<?php foreach (call_user_func_array(%s, %s) as $key => $value): $this->_variables[] = []; $this->assignArray($value); ?>',
			var_export($this->_sections[$name], true), var_export($params, true));
	}

	protected function _if(string $name, string $params, string $tag_name = 'if')
	{
		preg_match_all('$(\|\||&&|[()]|(?:>=|<=|==|===|>|<|!=|!==|null|\d+|false|true|!)|\$\w+(?:\.\w+)*)$', $name . $params, $match);

		$conditions = [];

		foreach ($match[1] as $condition) {
			$condition = trim($condition);

			if (substr($condition, 0, 1) == '$') {
				$conditions[] = $this->_variable($condition, '', false);
			}
			else {
				$conditions[] = $condition;
			}
		}

		return sprintf('<?php %s (%s): ?>', $tag_name, implode(' ', $conditions));
	}

	protected function _close(string $name, string $params)
	{
		$type = $this->_lastType();
		$this->_pop();

		if ($type == self::IF || $type == self::ELSE) {
			return '<?php endif; ?>';
		}
		else {
			return '<?php array_pop($this->_variables); endforeach; endif; ?>';
		}
	}

	/**
	 * Parse a variable, either from a {$block} or from an argument: {block arg=$bla|rot13}
	 */
	protected function _variable(string $name, string $params = '', bool $escape = true): string
	{
		$name = ltrim($name, '$');

		// Split by pipe (|) except if enclosed in quotes
		$modifiers = preg_split('/\|(?=(([^\'"]*["\']){2})*[^\'"]*$)/', $name . $params);
		$var = array_shift($modifiers);


		// No modifiers: easy!
		if (count($modifiers) == 0)
		{
			$str = sprintf('$this->get(%s)', var_export($var, true));

			if ($escape) {
				return '$this->escape(' . $str . ')';
			}
			else {
				return $str;
			}
		}

		$modifiers = array_reverse($modifiers);

		$pre = $post = '';

		foreach ($modifiers as &$modifier)
		{
			$_post = '';

			$pos = strpos($modifier, ':');

			// Arguments
			if ($pos !== false)
			{
				$mod_name = trim(substr($modifier, 0, $pos));
				$raw_args = substr($modifier, $pos+1);
				$arguments = [];

				// Split by two points (:) except if enclosed in quotes
				$arguments = preg_split('/\s*:\s*|("(?:\\\\.|[^"])*?"|\'(?:\\\\.|[^\'])*?\'|[^:\'"\s]+)/', trim($raw_args), 0, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
				$arguments = array_map([$this, 'exportArgument'], $arguments);

				$_post .= ', ' . implode(', ', $arguments);
			}
			else
			{
				$mod_name = trim($modifier);
			}

			// Disable autoescaping
			if ($mod_name == 'raw')
			{
				$escape = false;
				continue;
			}

			if ($mod_name == 'escape')
			{
				$escape = false;
			}

			// Modifiers MUST be registered at compile time
			if (!array_key_exists($mod_name, $this->modifiers))
			{
				$this->parseError($line, 'Unknown modifier name: ' . $mod_name);
			}

			$post = $_post . ')' . $post;
			$pre .= '$this->modifiers[' . var_export($mod_name, true) . '](';
		}

		$var = $pre . $this->parseMagicVariables($var) . $post;

		unset($pre, $post, $arguments, $mod_name, $modifier, $modifiers, $pos, $_post);

		// auto escape
		if ($escape)
		{
			$var = 'self::escape(' . $var . ', $this->escape_type)';
		}

		return $var;
	}


	/**
	 * Native default escape modifier
	 */
	static protected function escape($str, $type = 'html')
	{
		if ($type == 'json')
		{
			$str = json_encode($str);
		}

		if (is_array($str) || (is_object($str) && !method_exists($str, '__toString')))
		{
			throw new \InvalidArgumentException('Invalid parameter type for "escape" modifier: ' . gettype($str));
		}

		$str = (string) $str;

		switch ($type)
		{
			case 'html':
			case null:
				return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
			case 'xml':
				return htmlspecialchars($str, ENT_XML1, 'UTF-8');
			case 'htmlall':
			case 'entities':
				return htmlentities($str, ENT_QUOTES, 'UTF-8');
			case 'url':
				return rawurlencode($str);
			case 'quotes':
				return addslashes($str);
			case 'hex':
				return preg_replace_callback('/./', function ($match) {
					return '%' . ord($match[0]);
				}, $str);
			case 'hexentity':
				return preg_replace_callback('/./', function ($match) {
					return '&#' . ord($match[0]) . ';';
				}, $str);
			case 'mail':
				return str_replace('.', '[dot]', $str);
			case 'json':
				return $str;
			case 'js':
			case 'javascript':
				return strtr($str, [
					"\x08" => '\\b', "\x09" => '\\t', "\x0a" => '\\n', 
					"\x0b" => '\\v', "\x0c" => '\\f', "\x0d" => '\\r', 
					"\x22" => '\\"', "\x27" => '\\\'', "\x5c" => '\\'
				]);
			default:
				return $str;
		}
	}

}

class Dumbyer_Exception extends \RuntimeException
{

}