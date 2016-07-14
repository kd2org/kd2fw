<?php

use KD2\Smartyer;

require __DIR__ . '/_assert.php';

Smartyer::setCachePath('/tmp');

$tpl = new Smartyer(__DIR__ . '/data/smartyer/if-elseif.tpl');
$tpl->display();

$tpl = new Smartyer(__DIR__ . '/data/smartyer/foreach.tpl');
$tpl->assign('loop', [2 => 'a', 'b', 'c']);
$tpl->assign('empty_loop', []);
$tpl->display();

$tpl = new Smartyer(__DIR__ . '/data/smartyer/variables.tpl');
$tpl->assign('simple', 'Hello world!');
$tpl->assign('html', '<big>Hello</big> world!');

$tpl->assign('object', (object)['array' => ['key1' => 'OK']]);

$tpl->register_modifier('rot13', 'str_rot13');
$tpl->register_modifier('substr', 'substr');
$tpl->register_modifier('replace', function ($str, $a, $b) {
	return str_replace($a, $b, $str);
});
$tpl->register_modifier('link', function ($str, $url) {
	return '<a href="' . $url . '">' . $str . '</a>';
});

$tpl->display();

$tpl = new Smartyer(__DIR__ . '/data/smartyer/functions.tpl');
$tpl->register_function('lorem', function ($args) {
	return str_repeat('lorem ipsum', $args['ipsum']);
});
$tpl->display();

$tpl = new Smartyer(__DIR__ . '/data/smartyer/blocks.tpl');
$tpl->register_block('rot13', function ($content, $params) {
	return str_rot13($content);
});
$tpl->display();
