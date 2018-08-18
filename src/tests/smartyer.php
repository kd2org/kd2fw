<?php

use KD2\Test;
use KD2\Smartyer;

require __DIR__ . '/_assert.php';

$root_smartyer = new Smartyer;
$root_smartyer->setCompiledDir(sys_get_temp_dir());

test_variables($root_smartyer);
test_literals($root_smartyer);
test_foreach($root_smartyer);
test_functions($root_smartyer);
test_blocks($root_smartyer);
test_compile_blocks($root_smartyer);
test_namespace($root_smartyer);

function test_variables(Smartyer $smartyer)
{
	// Basic simple variable replacement
	$code = '{$str}';
	$string = 'Hello';
	$expected = $string;
	$tpl = Smartyer::fromString($code, $smartyer)->assign('str', $string);

	Test::equals($expected, $tpl->fetch(), 'Simple string');
	Test::equals($expected, $tpl->fetch(), 'Simple string, second fetch call');

	// PHP code
	$code = '<?=$str?>';
	$string = 'Hello';
	$expected = $string;
	$output = Smartyer::fromString($code, $smartyer)->assign('str', $string)->fetch();

	Test::equals($expected, $output, 'PHP code');

	// Comments
	$code = 'ab{*{Comment}*}c';
	$expected = 'abc';
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'Comment');

	// HTML escaping
	$code = '{$str}';
	$string = '<b>Hello ¿é!Æ</b>';
	$expected = htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
	$output = Smartyer::fromString($code, $smartyer)->assign('str', $string)->fetch();

	Test::equals($expected, $output, 'HTML auto escaping');

	// HTML escaping
	$code = '{$str|raw}';
	$string = '<b>Hello ¿é!Æ</b>';
	$expected = $string;
	$output = Smartyer::fromString($code, $smartyer)->assign('str', $string)->fetch();

	Test::equals($expected, $output, 'HTML auto escaping disabled');

	// HTML entities
	$code = '{$str|escape:entities}';
	$string = '<b>Hello ¿é!Æ</b>';
	$expected = htmlentities($string, ENT_QUOTES, 'UTF-8');
	$output = Smartyer::fromString($code, $smartyer)->assign('str', $string)->fetch();

	Test::equals($expected, $output, 'HTML entities escaping');

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

function test_literals(Smartyer $smartyer)
{
	$code = '{ldelim} / {rdelim}';
	$expected = '{ / }';
	$output = Smartyer::fromString($code, $smartyer)->fetch();
	Test::equals($expected, $output, 'Delimiters');

	$js = 'function () {	do.something({}); }';
	$code = '{literal}' . $js . '{/literal}';
	$expected = $js;
	$output = Smartyer::fromString($code, $smartyer)->fetch();
	Test::equals($expected, $output, 'Javascript literal');

	$tpl = Smartyer::fromString($js, $smartyer);
	$tpl->error_on_invalid_block = false;
	$expected = $js;
	$output = $tpl->fetch();
	Test::equals($expected, $output, 'Javascript literal');

	$code = "{literal}{not\n a block{{/literal}te\n\nst{literal}}still}not}a}block{/literal}";
	$expected = "{not\n a block{te\n\nst}still}not}a}block";
	$output = Smartyer::fromString($code, $smartyer)->fetch();
	Test::equals($expected, $output, 'Stuff that looks like a block but is not');
}

function test_foreach(Smartyer $smartyer)
{
	$loop = ['a', 'b', 'c'];

	// Simple loop, PHP style
	$code = '{foreach ($loop as $k=>$v)}{$k} = {$v} ({$iteration}) {/foreach}';
	$expected = '0 = a (1) 1 = b (2) 2 = c (3) ';
	$output = Smartyer::fromString($code, $smartyer)->assign('loop', $loop)->fetch();

	Test::equals($expected, $output, 'Simple loop, PHP style');
	
	// Simple loop, Smarty style
	$code = '{foreach from=$loop item="v" key="k"}{$k} = {$v} ({$iteration}) {/foreach}';
	$expected = '0 = a (1) 1 = b (2) 2 = c (3) ';
	$output = Smartyer::fromString($code, $smartyer)->assign('loop', $loop)->fetch();

	Test::equals($expected, $output, 'Simple loop, Smarty style');
	
	// Simple loop, Smarty style, no key
	$code = '{foreach from=$loop item="v"}{$v} ({$iteration}) {/foreach}';
	$expected = 'a (1) b (2) c (3) ';
	$output = Smartyer::fromString($code, $smartyer)->assign('loop', $loop)->fetch();

	Test::equals($expected, $output, 'Simple loop, Smarty style, no key');

	// Empty loop with foreachelse
	$code = '{foreach from=$loop item="v"}{$v} ({$iteration}) {foreachelse}Empty{/foreach}';
	$expected = 'Empty';
	$output = Smartyer::fromString($code, $smartyer)->assign('loop', [])->fetch();

	Test::equals($expected, $output, 'Empty loop with foreachelse');
}

function test_functions(Smartyer $smartyer)
{
	// if else
	$code = '{if true}TRUE{else}FALSE{/if}';
	$expected = 'TRUE';
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'Simple if / else');

	// if / else if / else
	$code = '{if false}FALSE{else if true}TRUE{else}FALSE{/if}';
	$expected = 'TRUE';
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'Simple if / else if / else');

	// if using function
	$code = '{if (version_compare(phpversion(), 3, ">="))}3+{else}FAIL{/if}';
	$expected = '3+';
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'function call in if condition');

	// for () loop
	$code = '{for $i = 1; $i < 4; $i++}{$i}.{$iteration}|{/for}';
	$expected = '1.1|2.2|3.3|';
	$output = Smartyer::fromString($code, $smartyer)->fetch();

	Test::equals($expected, $output, 'for loop');

	// while () loop
	$code = '{while $a = array_shift($array)}{$a}.{$iteration}|{/while}';
	$expected = 'a.1|b.2|c.3|';
	$output = Smartyer::fromString($code, $smartyer)->assign('array', ['a', 'b', 'c'])->fetch();

	Test::equals($expected, $output, 'while loop');

	// custom function
	$code = '{repeat length="1" source=$object.array.key1|rot13|cat:"Embedded variables $object.array.key2 $simple `$simple` {$simple}"}';
	$expected = 'BXEmbedded variables $object.array.key2 $simple `$simple` {$simple}';

	$tpl = Smartyer::fromString($code, $smartyer);
	$tpl->assign('object', (object)['array' => ['key1' => 'OK']]);
	$tpl->register_modifier('rot13', 'str_rot13');
	
	$tpl->register_function('repeat', function ($args) {
		return str_repeat($args['source'], $args['length']);
	});

	$output = $tpl->fetch();
	Test::equals($expected, $output, 'function with parameters');
}

function test_blocks(Smartyer $smartyer)
{
	$code = '{rot13}Hello world!{/rot13}';
	$expected = 'Uryyb jbeyq!';
	$output = Smartyer::fromString($code, $smartyer)->register_block('rot13', function ($content, $params) {
		return str_rot13($content);
	})->fetch();

	Test::equals($expected, $output, 'block');
}

function test_compile_blocks(Smartyer $smartyer)
{
	$code = '{
	#PHP_VERSION
	}';
	$expected = PHP_VERSION;

	$tpl = Smartyer::fromString($code, $smartyer);
	$tpl->register_compile_function('constants', function($pos, $block, $name, $raw_args) {
		if (substr(trim($block), 0, 1) == '#')
		{
			return 'echo constant(' . $this->exportArgument(substr(trim($block), 1)) . ');';
		}

		return false;
	});

	$output = $tpl->fetch();

	Test::equals($expected, $output, 'compile function');
}

function test_namespace(Smartyer $smartyer)
{
	$code = '/<?=__NAMESPACE__?>/';
	$tpl = Smartyer::fromString($code, $smartyer);
	$expected = '//';
	Test::equals($expected, $tpl->fetch(), 'default namespace');

	$tpl->setNamespace('KD2');
	$expected = '/KD2/';
	Test::equals($expected, $tpl->fetch(), 'custom namespace');	
}