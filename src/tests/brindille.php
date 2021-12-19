<?php

use KD2\Test;
use KD2\Brindille;
use KD2\Brindille_Exception;

require __DIR__ . '/_assert.php';

test_tokenizer();
test_variables();
test_php_tags();
test_comments();
test_if();
test_modifiers();
test_loop();
test_assign();
test_modifiers_parameters();

function test_tokenizer()
{
	Test::exception(\InvalidArgumentException::class, function () {
		Brindille::tokenize('config.email_asso', Brindille::TOK_IF_BLOCK);
	});
}

function test_php_tags()
{
	$b = new Brindille;
	$b->assign('php', '<?php');

	Test::equals('&lt;?php', $b->render('{{$php}}'));
	Test::equals('<?php exit; ?>', $b->render('<?php exit; ?>'));

	/*Test::equals('<?php if ($this->_magic(\'"\\\'"\', $this->get(\'ob"\\\'ject->test()\')) ): ?>--<?php endif; ?>', $b->compile('{{if $ob"\'ject->test()."\'"}}--{{/if}}'));*/
}

function test_comments()
{
	$b = new Brindille;
	Test::equals('abd', $b->render('ab{{*c*}}d'));
	Test::equals("abd", $b->render("ab{{*c sdf s\n\nezeze*}}d"));
}

function test_variables()
{
	$b = new Brindille;

	$b->assign('ok', '42');
	$b->assignArray(['plop' => 'plip']);

	// test overwrite
	$b->assignArray(['plop' => 'plap']);
	$b->assignArray(['bla' => ['plap' => 'plop']]);

	Test::equals('42', $b->render('{{$ok}}'));
	Test::equals('plap', $b->render('{{$plop}}'));
	Test::equals('plop', $b->render('{{$bla.plap}}'));

	Test::equals('', $b->render('{{$.plap}}'));

	$b->assign('html', '<html>');
	Test::equals('&lt;html&gt;', $b->render('{{$html}}'));
	Test::equals('<html>', $b->render('{{$html|raw}}'));

	$b->registerDefaults();
	Test::equals('<html>abc42', $b->render('{{$html|raw|cat:"a"|cat:"b":"c"|cat:42}}'));
	Test::equals('-<html>-', $b->render('{{"-%s-"|args:$html|raw}}'));
	Test::equals('-&quot;__&quot;-32', $b->render('{{"-%s-"|args:\'"__"\'|cat:32}}'));
	Test::equals('-"__"-32', $b->render('{{"-%s-"|raw|args:\'"__"\'|cat:32}}'));
	Test::equals('<?=\'-"\\\'__"-\'?>', $b->compile('{{"-\\"\'__\\"-"|raw}}'));
}

function test_variables2(Smartyer $smartyer)
{
	// Truncate + auto escape
	$code = '{$str|truncate:3:"":true}';
	$string = '<b>Hello ¿é!Æ</b>';
	$expected = htmlspecialchars(substr($string, 0, 3));
	$output = Smartyer::fromString($code, $smartyer)->assign('str', $string)->fetch();

	Test::equals($expected, $output, 'HTML auto escaping after truncate');

	class TestClass
	{
		const TEST_CONSTANT = 42;
		static $test_var = 42;
	}

	// Class constant
	$code = '{$class::TEST_CONSTANT}';
	$expected = 42;
	$output = Smartyer::fromString($code, $smartyer)->assign('class', new TestClass)->fetch();

	Test::equals($expected, $output, 'Class constant as a variable');

	// Static variable
	$code = '{$class::$test_var}';
	$expected = 42;
	$output = Smartyer::fromString($code, $smartyer)->assign('class', new TestClass)->fetch();

	Test::equals($expected, $output, 'Static variable');

	// System constant
	$code = '{"PHP_VERSION"|const}';
	$expected = PHP_VERSION;
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'System constant');

	// Custom modifier
	$code = '{$str|rot13}';
	$str = 'Hello!';
	$expected = str_rot13($str);
	$output = Smartyer::fromString($code, $smartyer)->assign('str', $str)->register_modifier('rot13', 'str_rot13')->fetch();

	Test::equals($expected, $output, 'Custom rot13 modifier');

	// Magic variable
	$code = '{$object.array.key1}';
	$expected = 'OK';
	$obj = (object)['array' => ['key1' => 'OK']];
	$output = Smartyer::fromString($code, $smartyer)->assign('object', $obj)->fetch();

	Test::equals($expected, $output, 'Magic variable');

	// Magic variable in modifier arguments
	$code = '{$str|replace:"world":$object.array.key1}';
	$expected = 'Hello OK!';
	$str = 'Hello world!';
	$obj = (object)['array' => ['key1' => 'OK']];
	$output = Smartyer::fromString($code, $smartyer)->assign('str', $str)->assign('object', $obj)->fetch();

	Test::equals($expected, $output, 'Magic variable in modifier arguments');

	// quotes in quoted arguments
	$code = '{"Hello world!"|replace:"world":"\"\'world\'\"!"|raw}';
	$expected = 'Hello "\'world\'"!!';
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'Quotes in quoted arguments');

	// Current object variable
	$code = '{$this->delimiter_start}';
	$expected = '{';
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'Current object variable');

	// Current object function call
	$code = '{$this->dateFormat(time(), "%Y|")|truncate:1:"":true}';
	$expected = substr(strftime('%Y', time()), 0, 1);
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'Current object function call + modifier');
}

function test_if()
{
	$b = new Brindille;

	$b->assign('ok', '42');
	$b->assign('nope', false);
	$b->assign('date', gmmktime(0, 0, 0, 2, 2, 2021));

	$b->registerModifier('date', function ($date, $format) {
		return gmdate($format, $date);
	});

	$b->registerModifier('args', 'sprintf');

	Test::equals('yep', $b->render('{{if $ok > 41 }}yep{{/if}}'));
	Test::equals('', $b->render('{{if $ok == 41 }}yep{{/if}}'));
	Test::equals('yep', $b->render('{{if $ok < 43 && (!$nope || $ok > 40) }}yep{{/if}}'));
	Test::equals('yep', $b->render('{{if $ok < 42}}nope{{elseif $ok < 44}}yep{{/if}}'));
	Test::equals('yup', $b->render('{{if $ok < 42}}nope{{elseif $ok > 43}}nope2{{else}}yup{{/if}}'));
	Test::equals('yep', $b->render('{{if $date|date:"Y" == 2021 }}yep{{/if}}'));
	Test::equals('yep', $b->render('{{if $date|date:"YmdHis" == "20210202000000" }}yep{{/if}}'));
	Test::equals('yep', $b->render('{{if "%s"|args:"lol" == "lol" }}yep{{/if}}'));
	Test::equals('yep', $b->render('{{if "%s"|args:"lol" === "lol" }}yep{{/if}}'));
	Test::equals('yep', $b->render('{{if "%s"|args:"lol" !== "nope" }}yep{{/if}}'));
}

function test_modifiers()
{
	$b = new Brindille;

	$b->registerModifier('reverse', 'strrev');

	Test::equals('acab', $b->render('{{"baca"|reverse}}'));

	$b->registerModifier('truncate', function (string $str, ?int $start = null, ?int $length = null, ?string $end = null) {
		return substr($str, $start ?? 0, $length ?? strlen($str))
			. ($end ?? '');
	});

	$b->assign('menu', 'pizza');
	Test::equals('izz…', $b->render('{{ $menu | truncate : 1 : 3 : "…" }}'));
}

function test_loop()
{
	$b = new Brindille;
	$b->registerDefaults();

	$b->assign('test', ['a', 'b', 'c']);

	// Positive condition with empty loop
	Test::equals('0a1b2c', $b->render('{{#foreach from=$test}}{{$key}}{{$value}}{{/foreach}}', [], true));
}

function test_assign()
{
	$b = new Brindille;
	$b->registerDefaults();
	$b->registerSection('users', function () {
		yield ['name' => 'Toto'];
	});

	$code = '
	{{#users}}
		{{:assign .="user"}}
	{{/users}}
	{{$user.name}}';

	Test::equals('Toto', trim($b->render($code)));
}

function test_modifiers_parameters()
{
	$b = new Brindille;

	$b->registerModifier('reverse', 'strrev');
	$b->registerFunction('json', function (array $params) {
		return json_encode($params);
	});

	Test::equals('{"var1":"baca"}', $b->render('{{:json var1="baca"|reverse}}'));
}