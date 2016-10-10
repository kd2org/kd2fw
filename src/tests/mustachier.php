<?php

use KD2\Test;
use KD2\Mustachier;

require __DIR__ . '/_assert.php';

test_php();
test_tag();
test_loop();

function test_php()
{
	$m = new Mustachier;
	Test::equals('<?=\'<?=\'?>', $m->compile('<?='));
	Test::equals('<?=\'<?php\'?>', $m->compile('<?php'));
	Test::equals('<?=\'?>\'?>', $m->compile('?>'));
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
	Test::equals('..', $m->run('{{#test}}..{{/test}}', ['test' => 'ok'], true));
	Test::equals('.ok.', $m->run('{{#test}}.{{test}}.{{/test}}', ['test' => 'ok'], true));

	// Negative condition
	Test::equals('..', $m->run('{{^test}}..{{/test}}', [], true));
	Test::equals('..', $m->run('{{^test}}..{{/test}}', ['test' => false], true));

	// loop with sub-tags
	Test::equals('.#.', $m->run('{{#test}}.{{name}}.{{/test}}', ['test' => ['name' => '#']], true));

	// Nested loop
	Test::equals('.#.', $m->run('{{#test}}.{{#bla}}#{{/bla}}.{{/test}}', ['test' => ['bla' => ['ok' => true]]], true));
}