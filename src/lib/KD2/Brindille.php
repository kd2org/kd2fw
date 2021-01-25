<?php

namespace KD2;

class Brindille
{
	const NONE = 0;
	const LITERAL = 1;
	const SECTION = 10;
	const IF = 11;
	const ELSE = 12;

	const T_VAR = 'var';
	const T_PARAMS = 'params';

	// $var.subvar , "quoted string even with \" escape quotes", 'even single quotes'
	const RE_LITERAL = '\$[\w.]+|"(.*?(?<!\\\\))"|\'(.*?(?<!\\\\))\'';

	const RE_SCALAR = 'null|true|false|\d+|\d+\.\d+';

	// Modifier argument: :"string", :$variable.subvar, :42, :false, :null
	const RE_MODIFIER_ARGUMENTS = '(?::(?:' . self::RE_LITERAL . '|' . self::RE_SCALAR . '))*';

	// Modifier: |mod_name:arg1:arg2
	const RE_MODIFIER = '\|\w+' . self::RE_MODIFIER_ARGUMENTS;

	// Variable: $var_name|modifier, "string literal"|modifier:arg1,arg2
	const RE_VARIABLE = '(?:' . self::RE_LITERAL . ')(?:' . self::RE_MODIFIER . ')*';

	// block parameters
	const RE_PARAMETERS = '[:\w]+=(?:' . self::RE_VARIABLE . '|' . self::RE_SCALAR . ')';

	// Tokens allowed in an if statement
	const TOK_IF_BLOCK = [
		'>=', '<=', '===', '!==', '==', '!=', '>', '<', '!',
		'&&', '\|\|', '\(', '\)',
		self::T_VAR => self::RE_VARIABLE,
		self::RE_SCALAR,
		'\s+',
	];

	const TOK_VAR_BLOCK = [
		self::T_VAR => self::RE_VARIABLE,
		self::T_PARAMS => self::RE_PARAMETERS,
		'\s+',
	];

	const PARSE_PATTERN = '%
		# start of block
		\{\{
		# ignore spaces at start of block
		\s*
		# capture block type/name
		(if|else\s?if|else|endif|literal|
		# comments
		\*|
		# sections, variables, functions, MUST have a valid name
		[:$#/]([\w._]+)|
		# quoted strings can be chained to modifiers as well
		[\'"]
		# end of capture group
		)
		# Arguments etc.
		((?!\}\}).*?)?
		# end of block
		\}\}
		# regexp modifiers
		%sx';

	protected $_stack = [];

	protected $_sections = [];
	// Escape is the only mandatory modifier
	protected $_modifiers = ['escape' => 'htmlspecialchars'];
	protected $_functions = [];

	protected $_variables = [0 => []];

	public function registerDefaults()
	{
		$this->registerFunction('assign', function(array $params, Brindille $tpl) {
			$tpl->assignArray($params);
		});

		$this->registerModifier('args', 'sprintf');
		$this->registerModifier('nl2br', 'nl2br');
		$this->registerModifier('strip_tags', 'strip_tags');
		$this->registerModifier('count', 'count');
		$this->registerModifier('cat', function() { return implode('', func_get_args()); });

		$this->registerModifier('date_format', function ($date, $format = '%d/%m/%Y %H:%M') {
			$tz = null;

			if (is_object($date)) {
				$date = $date->getTimestamp();
				$tz = $date->getTimezone();
			}
			elseif (!ctype_digit($date)) {
				$date = strtotime($date);
			}

			return Translate::strftime($format, $date, $tz);
		});
	}

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

	public function registerFunction(string $name, callable $callback): void
	{
		$this->_functions[$name] = $callback;
	}

	public function render(string $code): string
	{
		$code = $this->compile($code);

		try {
			ob_start();

			eval('?>' . $code);

			return ob_get_clean();
		}
		catch (\Throwable $e) {
			$lines = explode("\n", $code);
			$code = $lines[$e->getLine()-1] ?? $code;
			throw new Brindille_Exception(sprintf("[%s] Line %d: %s\n%s", get_class($e), $e->getLine(), $e->getMessage(), $code), 0, $e);
		}
	}

	public function compile(string $code): string
	{
		$this->_stack = [];

		// Remove PHP tags
		$code = strtr($code, [
			'<?php' => '<?=\'<?php\'?>',
			'<?' => '<?=\'<?\'?>',
			'?>' => '<?=\'?>\'?>'
		]);

		$return = preg_replace_callback(self::PARSE_PATTERN, function ($match) use ($code) {
			$offset = $match[0][1];
			$line = 1 + substr_count($code, "\n", 0, $offset);

			try {
				$all = $match[0][0];
				$start = !empty($match[2][0]) ? substr($match[1][0], 0, 1) : $match[1][0];
				$name = $match[2][0] ?? $match[1][0];
				$params = $match[3][0] ?? null;

				return $this->_walk($all, $start, $name, $params, $line);
			}
			catch (Brindille_Exception $e) {
				throw new Brindille_Exception(sprintf('Line %d: %s', $line, $e->getMessage()), 0, $e);
			}
		}, $code, -1, $count, PREG_OFFSET_CAPTURE);

		if (count($this->_stack)) {
			$line = 1 + substr_count($code, "\n");
			throw new Brindille_Exception(sprintf('Line %d: missing closing tag "%s"', $line, $this->_lastName()));
		}

		return $return;
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

	public function getAllVariables(): array
	{
		$out = [];

		foreach ($this->_variables as $vars) {
			$out = array_merge($out, $vars);
		}

		return $out;
	}

	protected function _magic(string $expr, $var, &$found = null)
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

	protected function _walk(string $all, ?string $start, string $name, ?string $params, int $line): string
	{
		if (!$start && $name == 'literal') {
			$this->_push(self::LITERAL, $name);
			return '';
		}
		elseif ($start == '/' && $name == 'literal') {
			if ($this->_lastType() != self::LITERAL) {
				throw new Brindille_Exception('closing of a literal block that wasn\'t opened');
			}

			$this->_pop();
			return '';
		}
		elseif ($this->_lastType() == self::LITERAL) {
			return $all;
		}

		// Comments
		if ($start == '*') {
			return '';
		}

		$params = trim($params);

		// Variable
		if ($start == '$') {
			return sprintf('<?=%s?>', $this->_variable('$' . $name . $params, true));
		}

		if ($start == '"' || $start == '\'') {
			return sprintf('<?=%s?>', $this->_variable($start . $name . $params, true));
		}

		if ($start == '#') {
			$this->_push(self::SECTION, $name);
			return $this->_section($name, $params, $line);
		}
		elseif ($start == 'if') {
			$this->_push(self::IF, 'if');
			return $this->_if($name, $params);
		}
		elseif ($start == 'elseif') {
			if ($this->_lastType() != self::IF) {
				throw new Brindille_Exception('"elseif" block is not following a "if" block');
			}

			$this->_pop();
			$this->_push(self::IF, 'if');
			return $this->_if($name, $params, 'elseif');
		}
		elseif ($start == 'else') {
			$type = $this->_lastType();

			if ($type != self::IF && $type != self::SECTION) {
				throw new Brindille_Exception('"else" block is not following a "if" or section block');
			}

			$name = $this->_lastName();
			$this->_pop();
			$this->_push(self::ELSE, $name);

			if ($type == self::SECTION) {
				return '<?php $last = array_pop($this->_variables); endforeach; if (!isset($last) || !count($last)): ?>';
			}
			else {
				return '<?php else: ?>';
			}
		}
		elseif ($start == '/') {
			if (($start == 'if' && $this->_lastName() != $start) || ($name && $this->_lastName() != $name)) {
				throw new Brindille_Exception(sprintf('"%s": block closing does not match last block "%s" opened', $all, $this->_lastName()));
			}

			return $this->_close($name);
		}
		elseif ($start == ':') {
			return $this->_function($name, $params, $line);
		}

		throw new Brindille_Exception('Unknown block: ' . $all);
	}

	protected function _function(string $name, string $params, int $line) {
		if (!isset($this->_functions[$name])) {
			throw new Brindille_Exception(sprintf('unknown function "%s"', $name));
		}

		$params = $this->_parseArguments($params);
		$params = $this->_exportArguments($params);

		return sprintf('<?=call_user_func($this->_functions[%s], %s, $this, %d)?>',
			var_export($name, true),
			$params,
			$line
		);
	}

	protected function _section(string $name, string $params, int $line): string
	{
		$params = $this->_parseArguments($params);
		$params = $this->_exportArguments($params);

		return sprintf('<?php unset($last); foreach (call_user_func($this->_sections[%s], %s, $this, %d) as $key => $value): $this->_variables[] = []; $this->assignArray($value + [\'__\' => $value, \'_\' => $key]); ?>',
			var_export($name, true),
			$params,
			$line
		);
	}

	protected function _if(string $name, string $params, string $tag_name = 'if')
	{
		$tokens = self::tokenize($params, self::TOK_IF_BLOCK);

		$code = '';

		foreach ($tokens as $token) {
			if ($token->type == self::T_VAR) {
				$code .= $this->_variable($token->value, false);
			}
			else {
				$code .= $token->value;
			}
		}

		return sprintf('<?php %s (%s): ?>', $tag_name, $code);
	}

	protected function _close(string $name)
	{
		$type = $this->_lastType();
		$this->_pop();

		if ($type == self::IF || $type == self::ELSE) {
			return '<?php endif; ?>';
		}
		else {
			return '<?php array_pop($this->_variables); endforeach; ?>';
		}
	}

	/**
	 * Parse a variable, either from a {$block} or from an argument: {block arg=$bla|rot13}
	 */
	protected function _variable(string $raw, bool $escape = true): string
	{
		// Split by pipe (|) except if enclosed in quotes
		$modifiers = preg_split('/\|(?=(([^\'"]*["\']){2})*[^\'"]*$)/', $raw);
		$var = array_shift($modifiers);

		$pre = $post = '';

		if (count($modifiers))
		{
			$modifiers = array_reverse($modifiers);

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
					$arguments = array_map([$this, '_exportArgument'], $arguments);

					$_post .= ', ' . implode(', ', $arguments);
				}
				else
				{
					$mod_name = trim($modifier);
				}

				// Disable autoescaping
				if ($mod_name == 'raw') {
					$escape = false;
					continue;
				}
				else if ($mod_name == 'escape') {
					$escape = false;
				}

				// Modifiers MUST be registered at compile time
				if (!array_key_exists($mod_name, $this->_modifiers)) {
					throw new Brindille_Exception('Unknown modifier name: ' . $mod_name);
				}

				$post = $_post . ')' . $post;
				$pre .= '$this->_modifiers[' . var_export($mod_name, true) . '](';
			}
		}

		$search = false;

		if (substr($var, 0, 1) == '$') {
			$first_var = strtok($var, '.');
			$search = strtok('');
		}

		if ($search) {
			$var = sprintf('$this->_magic(%s, %s)', var_export((string) $search, true), $this->_exportArgument($first_var));
		}
		else {
			$var = $this->_exportArgument($var);
		}

		$var = $pre . $var . $post;

		unset($pre, $post, $arguments, $mod_name, $modifier, $modifiers, $pos, $_post);

		// auto escape
		if ($escape)
		{
			$var = '$this->_modifiers[\'escape\'](' . $var . ')';
		}

		return $var;
	}

	/**
	 * Parse block arguments, this is similar to parsing HTML arguments
	 * @param  string $str List of arguments
	 * @param  integer $line Source code line
	 * @return array
	 */
	protected function _parseArguments(string $str)
	{
		$args = [];
		$state = 0;
		$last_value = '';

		preg_match_all('/(?:"(?:\\.|[^\"])*?"|\'(?:\\.|[^\'])*?\'|(?>[^"\'=\s]+))+|[=]/i', $str, $match);

		foreach ($match[0] as $value)
		{
			if ($state == 0)
			{
				$name = $value;
			}
			elseif ($state == 1)
			{
				if ($value != '=')
				{
					throw new Brindille_Exception('Expecting \'=\' after \'' . $last_value . '\'');
				}
			}
			elseif ($state == 2)
			{
				if ($value == '=')
				{
					throw new Brindille_Exception('Unexpected \'=\' after \'' . $last_value . '\'');
				}

				$args[$name] = $value;
				$name = null;
				$state = -1;
			}

			$last_value = $value;
			$state++;
		}

		unset($state, $last_value, $name, $str, $match);

		return $args;
	}

	protected function _exportArgument(string $raw_arg): string
	{
		if ($raw_arg[0] == '$') {
			return sprintf('$this->get(%s)', var_export(substr($raw_arg, 1), true));
		}

		return var_export($this->getValueFromArgument($raw_arg), true);
	}

	/**
	 * Export an array to a string, like var_export but without escaping of strings
	 *
	 * This is used to reference variables and code in arrays
	 *
	 * @param  array   $args      Arguments to export
	 * @return string
	 */
	protected function _exportArguments(array $args): string
	{
		if (!count($args)) {
			return '[]';
		}

		$out = '[';

		foreach ($args as $key=>$value)
		{
			$out .= var_export($key, true) . ' => ' . $this->_exportArgument($value) . ', ';
		}

		$out = substr($out, 0, -2);

		$out .= ']';

		return $out;
	}

	/**
	 * Returns string value from a quoted or unquoted block argument
	 * @param  string $arg Extracted argument ({foreach from=$loop item="value"} => [from => "$loop", item => "\"value\""])
	 */
	protected function getValueFromArgument(string $arg)
	{
		if ($arg[0] == '"' || $arg[0] == "'")
		{
			return stripslashes(substr($arg, 1, -1));
		}

		switch ($arg) {
			case 'true':
				return true;
			case 'false':
				return false;
			case 'null':
				return null;
			default:
				if (ctype_digit($arg)) {
					return (int)$arg;
				}

				return $arg;
		}
	}

	/**
	 * Tokenize a string following a list of regexps
	 * @see https://github.com/nette/tokenizer
	 * @return array a list of tokens, each is an object with a value, a type (the array index of $tokens) and the offset position
	 * @throws \InvalidArgumentException if an unknown token is encountered
	 */
	static public function tokenize(string $input, array $tokens): array
	{
		$pattern = '~(' . implode(')|(', $tokens) . ')~A';
		preg_match_all($pattern, $input, $match, PREG_SET_ORDER);

		$types = array_keys($tokens);
		$count = count($types);

		$len = 0;

		foreach ($match as &$token) {
			$type = null;

			for ($i = 1; $i <= $count; $i++) {
				if (!isset($token[$i])) {
					break;
				} elseif ($token[$i] !== '') {
					$type = $types[$i - 1];
					break;
				}
			}

			$token = (object) ['value' => $token[0], 'type' => $type, 'offset' => $len];
			$len += strlen($token->value);
		}

		if ($len !== strlen($input)) {
			$text = substr($input, 0, $len);
			$line = substr_count($text, "\n") + 1;
			$col = $len - strrpos("\n" . $text, "\n") + 1;
			$token = str_replace("\n", '\n', substr($input, $len, 10));

			throw new \InvalidArgumentException("Unexpected '$token' on line $line, column $col");
		}

		return $match;
	}
}

class Brindille_Exception extends \RuntimeException
{

}