<?php

use KD2\Test;
use KD2\Dumbyer;
use KD2\Dumbyer_Exception;

require __DIR__ . '/_assert.php';

test_variables();
test_if();
//test_tag();
//test_loop();
//test_templates();
//test_specs();

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