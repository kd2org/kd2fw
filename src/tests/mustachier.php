<?php

use KD2\Test;
use KD2\Mustachier;
use KD2\MustachierException;

require __DIR__ . '/_assert.php';

test_php();
test_tag();
test_assign();
test_loop();
test_templates();
test_specs();

function test_php()
{
	$m = new Mustachier;
	Test::equals('<?=\'<?=\'?>', $m->compile('<?='));
	Test::equals('<?=\'<?php\'?>', $m->compile('<?php'));
	Test::equals('<?=\'?>\'?>', $m->compile('?>'));
}

function test_assign()
{
	$m = new Mustachier;

	$m->assign('ok', '42');
	$m->assign(['plop' => 'plip']);
	
	// test overwrite
	$m->assign(['plop' => 'plap']);

	Test::equals('42', $m->run('{{ok}}', [], true));
	Test::equals('plap', $m->run('{{plop}}', [], true));
}

function test_tag()
{
	$m = new Mustachier;
	Test::equals('', $m->run('{{test}}', [], true));
	Test::equals('ok', $m->run('{{test}}', ['test' => 'ok'], true));
	Test::equals('0', $m->run('{{test}}', ['test' => 0], true));
	Test::equals('', $m->run('{{test}}', ['test' => false], true));
	Test::equals('', $m->run('{{test}}', ['test' => null], true));

	// Escaped
	Test::equals('&lt;HTML&gt;', $m->run('{{test}}', ['test' => '<HTML>'], true));

	// Unescaped + multiline tag
	Test::equals('<HTML>', $m->run('{{& test }}', ['test' => '<HTML>'], true));
	Test::equals('<HTML>', $m->run('{{{ test 
		}}}', ['test' => '<HTML>'], true));

	// Comments
	Test::equals('', $m->run('{{!test}}', ['test' => '<HTML>'], true));
}

function test_loop()
{
	$m = new Mustachier;

	// Positive condition with empty loop
	Test::equals('', $m->run('{{#test}}.{{/test}}', [], true));

	// Positive conditions
	Test::equals('..', $m->run('{{#test}}..{{/test}}', ['test' => 'Chewing gum'], true));
	Test::equals('.ok.', $m->run('{{#test}}.{{test}}.{{/test}}', ['test' => 'ok'], true));

	// Negative condition
	Test::equals('..', $m->run('{{^test}}..{{/test}}', [], true));
	Test::equals('..', $m->run('{{^test}}..{{/test}}', ['test' => false], true));

	// loop with sub-tags
	Test::equals('.#.', $m->run('{{#test}}.{{name}}.{{/test}}', ['test' => ['name' => '#']], true));

	// Nested loop
	Test::equals('.#.', $m->run('{{#test}}.{{#bla}}#{{/bla}}.{{/test}}', ['test' => ['bla' => ['ok' => true]]], true));
	
	// Nested nested with sub-tags
	Test::equals('.#!!#.', $m->run('{{#test}}.{{#bla}}#{{#ok}}{{t42}}{{/ok}}#{{/bla}}.{{/test}}', ['test' => ['bla' => ['ok' => ['t42' => '!!']]]], true));

	// Invalid loop
	try {
		Test::equals('', $m->run('{{#test}}.{{/plop}}', [], true));
	}
	catch (MustachierException $e)
	{
		Test::equals('Unexpected closing tag for section: \'plop\'', $e->getMessage());
	}

	// Invalid non closed loop
	try {
		Test::equals('', $m->run('{{#test}}.', [], true));
	}
	catch (MustachierException $e)
	{
		Test::equals('Missing closing tag for section: \'test\'', $e->getMessage());
	}
}

function test_templates()
{
	$m = new Mustachier(__DIR__ . '/data/mustache', '/tmp');

	Test::equals('ok', $m->fetch('simple.mustache', ['ok' => 'ok']));
	Test::equals('ok', $m->fetch('include.mustache', ['ok' => 'ok']));
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
			$result = $m->run($test['template'], $test['data'], true);
			Test::equals(trim($test['expected']), trim($result),
				sprintf('%s: %s (%s)', $file, $test['name'], $test['desc']));
		}
	}
}