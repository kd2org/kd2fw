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
test_modifiers_parameters();
test_loop();
test_assign();

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
	$b->registerDefaults();
	$b->registerFunction('test', fn() => '');

	Test::equals('&lt;?php', $b->render('{{$php}}'));
	Test::equals('<?php echo 42; ?>', $b->render('<?php echo 42; ?>'));
	Test::equals('<?php echo 42; ?>', $b->render('<{{literal}}?php echo 42; ?{{/literal}}>'));
	Test::equals('<?php echo 42; ?>', $b->render('<{{**test**}}?php echo 42; ?{{**test**}}>'));
	Test::equals('<?php echo 42; ?>', $b->render('<{{$test}}?php echo 42; ?{{$test}}>'));
	Test::equals('<?php echo 42; ?>', $b->render("<{{:test \nlol=1}}?php echo 42; ?{{:test lol=1}}>"));

	// With spaces and new lines
	Test::equals('<?php echo 42; ?>', $b->render("<{{**lol\n\n\n\n\t\n**}}?php echo 42; ?{{**lol\n\n\n\n\t\n**}}>"));
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
	$b->setEscapeType(null);

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
	Test::equals('<html>', $b->render('{{$html}}'));
	Test::equals('<html>', $b->render('{{$html|raw}}'));

	$b->registerDefaults();
	$b->setEscapeType('html');

	Test::equals('&lt;html&gt;abc42', $b->render('{{$html|cat:"a"|cat:"b":"c"|cat:42}}'));
	Test::equals('<html>abc42', $b->render('{{$html|raw|cat:"a"|cat:"b":"c"|cat:42}}'));
	Test::equals('-<html>-', $b->render('{{"-%s-"|args:$html|raw}}'));
	Test::equals('-&quot;__&quot;-32', $b->render('{{"-%s-"|args:\'"__"\'|cat:32}}'));
	Test::equals('-"__"-32', $b->render('{{"-%s-"|raw|args:\'"__"\'|cat:32}}'));
	Test::equals('<?=\'-"\\\'__"-\'?>', $b->compile('{{"-\\"\'__\\"-"|raw}}'));

	Test::equals('%2F%3F', $b->render('{{"/?"|escape:"url"}}'));
	Test::equals('%2F%3F', $b->render('{{"/?"|rawurlencode}}'));
	Test::equals('éé&quot;é&quot;&#039;', $b->render('{{"éé\\"é\\"\'"|escape:"html"}}'));
	Test::equals('éé&quot;é&quot;&apos;', $b->render('{{"éé\\"é\\"\'"|escape:"xml"}}'));

	$e = null;

	try {
		$r = $b->render('{{"/?"|rawurlencode:true}}');
	}
	catch (Brindille_Exception $e) {
	}

	Test::assert($e instanceof Brindille_Exception);
	Test::assert(preg_match('/Wrong argument count/', $e->getMessage()));
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
	Test::equals('0a1b2c', $b->render('{{#foreach from=$test key="key" item="value"}}{{$key}}{{$value}}{{/foreach}}'));
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


	$code = '
	{{:assign var="rows[]" label="Toto1"}}
	{{:assign var="rows[]" label="Toto2"}}
	{{$rows.0.label}}.{{$rows.1.label}}';

	Test::equals('Toto1.Toto2', trim($b->render($code)));

	$code = '
	{{:assign var="toto[a][0][b]" label="Toto2"}}
	{{:assign var="toto[a][]" label="Toto3"}}
	{{:assign var="toto[a][]" label="Toto4"}}
	{{$toto.a.0.b.label}}.{{$toto.a.1.label}}.{{$toto.a.2.label}}';

	Test::equals('Toto2.Toto3.Toto4', trim($b->render($code)));
}

function test_modifiers_parameters()
{
	$b = new Brindille;

	$b->registerModifier('reverse', 'strrev');
	$b->registerFunction('json', function (array $params) {
		return json_encode($params);
	});

	Test::equals('{"var1":"acab"}', $b->render('{{:json var1="baca"|reverse}}'));

	$b->registerFunction('echo', function (array $params) {
		return implode(' ', $params);
	});

	Test::equals('bla "lol lol" bla bla \'lol lol\' bla', $b->render('{{:echo var1="bla \\"lol lol\\" bla" var2=\'bla \\\'lol lol\\\' bla\'}}'));
}
