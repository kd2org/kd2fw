<?php

use KD2\Smartyer;

require __DIR__ . '/_assert.php';

Smartyer::setCachePath('/tmp');
Smartyer::setTemplatesPath(__DIR__ . '/data/smartyer');

$tpl = new Smartyer('all.tpl');

$tpl->assign('loop', [2 => 'a', 'b', 'c']);
$tpl->assign('empty_loop', []);

$tpl->assign('simple', 'Hello world!');
$tpl->assign('html', '<big>Hello</big> world!');

$tpl->assign('object', (object)['array' => ['key1' => 'OK']]);

$tpl->register_modifier('rot13', 'str_rot13');
$tpl->register_modifier('substr', 'substr');
$tpl->register_modifier('link', function ($str, $url) {
	return '<a href="' . $url . '">' . $str . '</a>';
});

$tpl->register_function('lorem', function ($args) {
	return str_repeat('lorem ipsum ' . $args['bis'], $args['ipsum']);
});

$tpl->register_block('rot13', function ($content, $params) {
	return str_rot13($content);
});

$tpl->display();
