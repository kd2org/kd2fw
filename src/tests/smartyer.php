<?php

use KD2\Smartyer;

$start = microtime(true);
$memory = memory_get_usage();

require __DIR__ . '/_assert.php';

class TestClass
{
	const TEST_CONSTANT = 42;
}

Smartyer::setCompileDir('/tmp');
Smartyer::setTemplateDir(__DIR__ . '/data/smartyer');

$tpl = new Smartyer('all.tpl');

$tpl->assign('loop', [2 => 'a', 'b', 'c']);
$tpl->assign('empty_loop', []);

$tpl->assign('testclass', new TestClass);

$tpl->assign('simple', 'Hello world!');
$tpl->assign('html', '<big>Hello</big> world!');

$tpl->assign('object', (object)['array' => ['key1' => 'OK']]);

$tpl->register_modifier('rot13', 'str_rot13');
$tpl->register_modifier('substr', 'substr');
$tpl->register_modifier('link', function ($str, $url) {
	return '<a href="' . $url . '">' . $str . '</a>';
});

$tpl->register_function('lorem', function ($args) {
	return str_repeat('lorem ipsum ' . $args['bis'] . $args['ter'], $args['ipsum']);
});

$tpl->register_block('rot13', function ($content, $params) {
	return str_rot13($content);
});

$tpl->display();


Smartyer::fromString('{$simple}')->assign('simple', 'Hello')->display();

echo PHP_EOL . PHP_EOL;
echo "Compile results:" . PHP_EOL;
echo "Time:\t\t" . ((microtime(true) - $start)*1000) . ' ms' . PHP_EOL;
echo "Memory:\t\t" . ((memory_get_usage() - $memory)/1000) . ' KB' . PHP_EOL;

$size = exec('du -bc /tmp/*.phptpl | awk \'{print $1}\'');
echo "Template size:\t" . ($size / 1000) . ' KB' . PHP_EOL;

echo "Included files:\t" . count(get_included_files()) . PHP_EOL;

$start = microtime(true);
$memory = memory_get_usage();

echo "Second fetch results:" . PHP_EOL;

$tpl->fetch();

echo "Time:\t\t" . ((microtime(true) - $start)*1000) . ' ms' . PHP_EOL;
echo "Memory:\t\t" . ((memory_get_usage() - $memory)/1000) . ' KB' . PHP_EOL;

$start = microtime(true);
$memory = memory_get_usage();

echo "Third fetch results:" . PHP_EOL;

$tpl->fetch();

echo "Time:\t\t" . ((microtime(true) - $start)*1000) . ' ms' . PHP_EOL;
echo "Memory:\t\t" . ((memory_get_usage() - $memory)/1000) . ' KB' . PHP_EOL;
