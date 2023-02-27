<?php

namespace KD2;

use KD2\Translate;

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

	public $_stack = [];

	protected $_sections = [];
	// Escape is the only mandatory modifier
	protected $_modifiers = ['escape' => 'htmlspecialchars'];
	protected $_functions = [];
	protected $_blocks = [];

	protected $_variables = [0 => []];

	public function registerDefaults()
	{
		$this->registerFunction('assign', [self::class, '__assign']);

		// This is because PHP 8.1 sucks (string functions no longer accept NULL)
		// so we need to force NULLs as strings
		$this->registerModifier('escape', function ($str) {
			if (is_scalar($str) || is_null($str)) {
				return htmlspecialchars((string)$str);
			}
			else {
				return '<span style="color: #000; background: yellow; padding: 5px; white-space: pre-wrap; display: inline-block; font-family: monospace;">Error: cannot escape this value!<br />'
					. htmlspecialchars(print_r($str, true)) . '</span>';
			}
		});

		$this->registerModifier('args', 'sprintf');
		$this->registerModifier('nl2br', 'nl2br');
		$this->registerModifier('strip_tags', 'strip_tags');
		$this->registerModifier('count', function ($var) {
			if (is_countable($var)) {
				return count($var);
			}

			return null;
		});
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

		$this->registerSection('foreach', [self::class, '__foreach']);
	}

	public function assign(string $key, $value, ?int $level = null, bool $throw_on_invalid_name = true): void
	{
		if (!preg_match('/^[\w\d_]*$/', $key)) {
			if ($throw_on_invalid_name) {
				throw new \InvalidArgumentException('Invalid variable name: ' . $key);
			}

			// For assign from a section, don't throw an error, just ignore

			return;
		}

		if (!count($this->_variables)) {
			$this->_variables = [0 => []];
		}

		if (null === $level) {
			$level = count($this->_variables)-1;
		}

		$this->_variables[$level][$key] = $value;
	}

	public function assignArray(array $array, ?int $level = null, bool $throw_on_invalid_name = true): void
	{
		foreach ($array as $key => $value) {
			$this->assign($key, $value, $level, $throw_on_invalid_name);
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

	public function registerCompileBlock(string $name, callable $callback): void
	{
		$this->_blocks[$name] = $callback;
	}

	public function render(string $tpl_code): string
	{
		$code = $this->compile($tpl_code);

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
			'<?' => '<?=\'<?\'?>',
			'?>' => '<?=\'?>\'?>'
		]);

		// Remove comments, but do not affect the number of lines
		$code = preg_replace_callback('/\{\{\*(?:(?!\*\}\}).*?)\*\}\}/s', function ($match) {
			return '<?php /* ' . str_repeat("\n", substr_count($match[0], "\n")) . '*/ ?>';
		}, $code);

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

		// Remove comments altogether
		$return = preg_replace('!<\?php /\*.*?\*/ \?>!s', '', $return);

		// Remove whitespaces between PHP logic blocks (not echo blocks)
		// this is to avoid sending data to browser in logic code, eg. redirects
		$return = preg_replace('!\s\?>(\s+)<\?php\s!', ' $1 ', $return);

		return $return;
	}

	public function get(string $name)
	{
		$array =& $this->_variables;

		for ($vars = end($array); key($array) !== null; $vars = prev($array)) {
			// Dots at the start of a variable name mean: go back X levels in variable stack
			if (substr($name, 0, 1) == '.') {
				$name = substr($name, 1);
				continue;
			}

			if (array_key_exists($name, $vars)) {
				return $vars[$name];
			}

			$found = false;

			if (strstr($name, '.')) {
				$return = $this->_magic($name, $vars, $found);

				if ($found) {
					return $return;
				}
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

		while (null !== ($key = array_shift($keys)))
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

		$params = trim((string) $params);

		// Variable
		if ($start == '$') {
			return sprintf('<?=%s?>', $this->_variable('$' . $name . $params, true, $line));
		}

		if ($start == '"' || $start == '\'') {
			return sprintf('<?=%s?>', $this->_variable($start . $name . $params, true, $line));
		}

		if ($start == '#' && array_key_exists($name, $this->_sections)) {
			return $this->_section($name, $params, $line);
		}
		elseif ($start == 'if') {
			$this->_push(self::IF, 'if');
			return $this->_if($name, $params, 'if', $line);
		}
		elseif ($start == 'elseif') {
			if ($this->_lastType() != self::IF) {
				throw new Brindille_Exception('"elseif" block is not following a "if" block');
			}

			$this->_pop();
			$this->_push(self::IF, 'if');
			return $this->_if($name, $params, 'elseif', $line);
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
		elseif (array_key_exists($start . $name, $this->_blocks)) {
			return $this->_block($start . $name, $params, $line);
		}
		elseif ($start == '/') {
			return $this->_close($name, $all);
		}
		elseif ($start == ':' && array_key_exists($name, $this->_functions)) {
			return $this->_function($name, $params, $line);
		}

		throw new Brindille_Exception('Unknown block: ' . $all);
	}

	public function _callModifier(string $name, int $line, ... $params) {
		try {
			return $this->_modifiers[$name](...$params);
		}
		catch (\Exception $e) {
			throw new Brindille_Exception(sprintf("line %d: modifier '%s' has returned an error: %s\nParameters: %s", $line, $name, $e->getMessage(), json_encode($params)));
		}
	}

	public function _function(string $name, string $params, int $line): string {
		if (!isset($this->_functions[$name])) {
			throw new Brindille_Exception(sprintf('line %d: unknown function "%s"', $line, $name));
		}

		$params = $this->_parseArguments($params, $line);
		$params = $this->_exportArguments($params);

		return sprintf('<?=$this->_callFunction(%s, %s, %d)?>',
			var_export($name, true),
			$params,
			$line
		);
	}

	public function _callFunction(string $name, array $params, int $line) {
		try {
			return call_user_func($this->_functions[$name], $params, $this, $line);
		}
		catch (\Exception $e) {
			throw new Brindille_Exception(sprintf("line %d: function '%s' has returned an error: %s\nParameters: %s", $line, $name, $e->getMessage(), json_encode($params)));
		}
	}

	public function _section(string $name, string $params, int $line): string
	{
		$this->_push(self::SECTION, $name);

		if (!isset($this->_sections[$name])) {
			throw new Brindille_Exception(sprintf('line %d: unknown section "%s"', $line, $name));
		}

		$params = $this->_parseArguments($params, $line);
		$params = $this->_exportArguments($params);

		return sprintf('<?php unset($last); foreach (call_user_func($this->_sections[%s], %s, $this, %d) as $key => $value): $this->_variables[] = []; $this->assignArray(array_merge($value, [\'__\' => $value, \'_\' => $key]), null, false); ?>',
			var_export($name, true),
			$params,
			$line
		);
	}

	public function _block(string $name, string $params, int $line): string
	{
		if (!isset($this->_blocks[$name])) {
			throw new Brindille_Exception(sprintf('unknown section "%s"', $name));
		}

		return call_user_func($this->_blocks[$name], $name, $params, $this, $line);
	}

	public function _if(string $name, string $params, string $tag_name, int $line)
	{
		try {
			$tokens = self::tokenize($params, self::TOK_IF_BLOCK);
		}
		catch (\InvalidArgumentException $e) {
			throw new Brindille_Exception(sprintf('line %d: error in "if" block (%s)', $line, $e->getMessage()));
		}

		$code = '';

		foreach ($tokens as $token) {
			if ($token->type === self::T_VAR) {
				$code .= $this->_variable($token->value, false, $line);
			}
			else {
				$code .= $token->value;
			}
		}

		return sprintf('<?php %s (%s): ?>', $tag_name, $code);
	}

	public function _close(string $name, string $block)
	{
		if ($this->_lastName() != $name) {
			throw new Brindille_Exception(sprintf('"%s": block closing does not match last block "%s" opened', $block, $this->_lastName()));
		}

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
	public function _variable(string $raw, bool $escape, int $line): string
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
				$pre .= '$this->_callModifier(' . var_export($mod_name, true) . ', ' . $line . ', ';
			}
		}

		$search = false;

		$var = $this->_exportArgument($var);

		$var = $pre . $var . $post;

		unset($pre, $post, $arguments, $mod_name, $modifier, $modifiers, $pos, $_post);

		// auto escape
		if ($escape)
		{
			$var = '$this->_callModifier(\'escape\', ' . $line . ', ' . $var . ')';
		}

		return $var;
	}

	/**
	 * Parse block arguments, this is similar to parsing HTML arguments
	 * @param  string $str List of arguments
	 * @param  integer $line Source code line
	 * @return array
	 */
	protected function _parseArguments(string $str, int $line)
	{
		$args = [];
		$name = null;
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

				$args[$name] = $this->_variable($value, false, $line);
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
		if (substr($raw_arg, 0, 1) == '$') {
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
			$out .= var_export($key, true) . ' => ' . $value . ', ';
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
		static $replace = [
			'\\"'  => '"',
			'\\\'' => '\'',
			'\\n'  => "\n",
			'\\t'  => "\t",
			'\\\\' => '\\',
		];

		if (strlen($arg) && ($arg[0] == '"' || $arg[0] == "'"))
		{
			return strtr(substr($arg, 1, -1), $replace);
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

	static public function __foreach(array $params, $tpl, $line): \Generator
	{
		if (!array_key_exists('from', $params)) {
			throw new Brindille_Exception(sprintf('line %d: missing parameter: "from"', $line));
		}

		if (null == $params['from']) {
			return null;
		}

		if (empty($params['item']) || !is_string($params['item'])) {
			throw new Brindille_Exception(sprintf('line %d: missing parameter: "item"', $line));
		}

		if (!is_iterable($params['from'])) {
			throw new Brindille_Exception('"from" parameter is not an iterable value');
		}

		foreach ($params['from'] as $key => $value) {
			$array = [$params['item'] => $value];

			if (isset($params['key']) && is_string($params['key'])) {
				$array[$params['key']] = $key;
			}

			yield $array;
		}
	}

	/**
	 * Default '{{:assign' function
	 *
	 * This *always* assigns variables to level 0 so that the variables are kept in all contexts
	 *
	 * This allows these syntaxes:
	 * {{:assign name="Mr Lonely"}} => {{$name}}
	 * {{:assign var="people" age=42 name="Mr Lonely"}} => {{$people.age}} {{$people.name}}
	 * {{:assign .="user"}} => {{$user.name}} (within a section)
	 * {{:assign var="people[address]" value="42 street"}}
	 */
	static public function __assign(array $params, Brindille $tpl, int $line)
	{
		$unset = [];

		// Special case: {{:assign .="user" ..="loop"}}
		foreach ($params as $key => $value) {
			if (!preg_match('/^\.+$/', $key)) {
				continue;
			}

			$level = count($tpl->_variables) - strlen($key);

			self::__assign(array_merge($tpl->_variables[$level], ['var' => $value]), $tpl, $line);
			unset($params[$key]);
		}

		if (isset($params['var'])) {
			$var = $params['var'];
			unset($params['var']);

			if (strstr($var, '[')) {
				$separator = '[';
			}
			else {
				$separator = '.';
			}

			$parts = explode($separator, $var);

			$var_name = array_shift($parts);
			$unset[] = $var_name;

			if (!isset($tpl->_variables[0][$var_name]) || !is_array($tpl->_variables[0][$var_name])) {
				$tpl->_variables[0][$var_name] = [];
			}

			$prev =& $tpl->_variables[0][$var_name];

			// To assign to arrays, eg. {{:assign var="rows[0][label]"}}
			// or {{:assign var="rows.0.label"}}
			foreach ($parts as $sub) {
				$sub = trim($sub, '\'" ' . ($separator == '[' ? '[]' : '.'));

				// Empty key: just increment
				if (!strlen($sub)) {
					$sub = count($prev);
				}

				if (!array_key_exists($sub, $prev)) {
					$prev[$sub] = [];
				}

				$prev =& $prev[$sub];
			}

			// If value is supplied, and nothing else is supplied, then use this value
			if (array_key_exists('value', $params) && count($params) == 1) {
				$prev = $params['value'];
			}
			// Same for 'from', but use it as a variable name
			// {{:assign var="test" from="types.%s"|args:$type}}
			elseif (isset($params['from']) && count($params) == 1) {
				$prev = $tpl->get($params['from']);
			}
			// Or else assign all params
			else {
				$prev = $params;
			}

			unset($prev);
		}
		// {{:assign bla="blou" address="42 street"}}
		else {
			$unset = array_keys($params);

			try {
				$tpl->assignArray($params, 0);
			}
			catch (\InvalidArgumentException $e) {
				throw new Brindille_Exception(sprintf('line %d: %s', $line, $e->getMessage()));
			}
		}

		// Unset all variables of the same name in children contexts,
		// as we expect the assigned variable to be accessible right away
		// If we don't do that, calling {{:assign}} in a section with a variable
		// named like an existing one, and then {{$variable}} in the same section,
		//  the variable from the section will be used instead of the one just assigned
		foreach ($unset as $name) {
			for ($i = count($tpl->_variables) - 1; $i > 0; $i--) {
				unset($tpl->_variables[$i][$name]);
			}
		}
	}
}

class Brindille_Exception extends \RuntimeException
{

}