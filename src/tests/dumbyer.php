<?php

use KD2\Test;
use KD2\Dumbyer;
use KD2\Dumbyer_Exception;

require __DIR__ . '/_assert.php';

test_variables();
test_php_tags();
test_comments();
test_if();
test_modifiers();
//test_loop();
//test_templates();
//test_specs();
//

function test_php_tags()
{
	$d = new Dumbyer;
	$d->assign('php', '<?php');

	Test::equals('&lt;?php', $d->render('{{$php}}'));
	Test::equals('<?php', $d->render('<?php'));
}

function test_comments()
{
	$d = new Dumbyer;
	Test::equals('abd', $d->render('ab{{*c*}}d'));
	Test::equals("abd", $d->render("ab{{*c sdf s\n\nezeze*}}d"));
}

function test_variables()
{
	$d = new Dumbyer;

	$d->assign('ok', '42');
	$d->assignArray(['plop' => 'plip']);

	// test overwrite
	$d->assignArray(['plop' => 'plap']);
	$d->assignArray(['bla' => ['plap' => 'plop']]);

	Test::equals('42', $d->render('{{$ok}}'));
	Test::equals('plap', $d->render('{{$plop}}'));
	Test::equals('plop', $d->render('{{$bla.plap}}'));

	$d->assign('html', '<html>');
	Test::equals('&lt;html&gt;', $d->render('{{$html}}'));
	//Test::equals('<html>', $d->render('{{$html|raw}}'));
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
	$d = new Dumbyer;

	$d->assign('ok', '42');
	$d->assign('nope', false);

	Test::equals('yep', $d->render('{{if $ok > 41 }}yep{{/if}}'));
	Test::equals('', $d->render('{{if $ok == 41 }}yep{{/if}}'));
	Test::equals('yep', $d->render('{{if $ok < 43 && (!$nope || $ok > 40) }}yep{{/if}}'));
	Test::equals('yep', $d->render('{{if $ok < 42}}nope{{elseif $ok < 44}}yep{{/if}}'));
	Test::equals('yup', $d->render('{{if $ok < 42}}nope{{elseif $ok > 43}}nope2{{else}}yup{{/if}}'));
}

function test_modifiers()
{
	$d = new Dumbyer;

	$d->registerModifier('reverse', 'strrev');

	Test::equals('acab', $d->render('{{"baca"|reverse}}'));

	$d->registerModifier('truncate', function (string $str, ?int $start = null, ?int $length = null, ?string $end = null) {
		return substr($str, $start ?? 0, $length ?? strlen($str))
			. ($end ?? '');
	});

	$d->assign('menu', 'pizza');
	Test::equals('izz…', $d->render('{{ $menu | truncate : 1 : 3 : "…" }}'));
}

function test_tag()
{
	$m = new Mustachier;
	Test::equals('', $d->render('{{test}}', [], true));
	Test::equals('ok', $d->render('{{test}}', ['test' => 'ok'], true));
	Test::equals('0', $d->render('{{test}}', ['test' => 0], true));
	Test::equals('', $d->render('{{test}}', ['test' => false], true));
	Test::equals('', $d->render('{{test}}', ['test' => null], true));

	// Escaped
	Test::equals('&lt;HTML&gt;', $d->render('{{test}}', ['test' => '<HTML>'], true));

	// Unescaped + multiline tag
	Test::equals('<HTML>', $d->render('{{& test }}', ['test' => '<HTML>'], true));
	Test::equals('<HTML>', $d->render('{{{ test
		}}}', ['test' => '<HTML>'], true));

	// Comments
	Test::equals('', $d->render('{{!test}}', ['test' => '<HTML>'], true));
}

function test_loop()
{
	$m = new Mustachier;

	// Positive condition with empty loop
	Test::equals('', $d->render('{{#test}}.{{/test}}', [], true));

	// Positive conditions
	Test::equals('..', $d->render('{{#test}}..{{/test}}', ['test' => 'Chewing gum'], true));
	Test::equals('.ok.', $d->render('{{#test}}.{{test}}.{{/test}}', ['test' => 'ok'], true));

	// Negative condition
	Test::equals('..', $d->render('{{^test}}..{{/test}}', [], true));
	Test::equals('..', $d->render('{{^test}}..{{/test}}', ['test' => false], true));

	// loop with sub-tags
	Test::equals('.#.', $d->render('{{#test}}.{{name}}.{{/test}}', ['test' => ['name' => '#']], true));

	// Nested loop
	Test::equals('.#.', $d->render('{{#test}}.{{#bla}}#{{/bla}}.{{/test}}', ['test' => ['bla' => ['ok' => true]]], true));

	// Nested nested with sub-tags
	Test::equals('.#!!#.', $d->render('{{#test}}.{{#bla}}#{{#ok}}{{t42}}{{/ok}}#{{/bla}}.{{/test}}', ['test' => ['bla' => ['ok' => ['t42' => '!!']]]], true));

	// Invalid loop
	try {
		Test::equals('', $d->render('{{#test}}.{{/plop}}', [], true));
	}
	catch (MustachierException $e)
	{
		Test::equals('Unexpected closing tag for section: \'plop\'', $e->getMessage());
	}

	// Invalid non closed loop
	try {
		Test::equals('', $d->render('{{#test}}.', [], true));
	}
	catch (MustachierException $e)
	{
		Test::equals('Missing closing tag for section: \'test\'', $e->getMessage());
	}
}

function test_templates()
{
	$m = new Mustachier(__DIR__ . '/data/mustache', '/tmp');

	Test::equals('ok', $d->fetch('simple.mustache', ['ok' => 'ok']));
	Test::equals('ok', $d->fetch('include.mustache', ['ok' => 'ok']));
}

function test_specs()
{
	$m = new Mustachier;
	$path = __DIR__ . '/data/mustache/spec';
	$dir = dir($path);

	while ($file = $dir->read())
	{
		if ($file[0] == '.')
		{
			continue;
		}

		if ($file == 'sections.json') continue; // temp

		$json = json_decode(file_get_contents($path . '/' . $file), true);

		foreach ($json['tests'] as $test)
		{
			if (!isset($test['partials']))
			{
				$test['partials'] = [];
			}

			$d->setPartials($test['partials']);

			$result = $d->render($test['template'], $test['data'], true);

			Test::equals(trim($test['expected']), trim($result),
				sprintf('%s: %s (%s) %s', $file, $test['name'], $test['desc'], $d->compile($test['template'])));
		}
	}
}