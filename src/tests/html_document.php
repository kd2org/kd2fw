<?php

use \KD2\Test;

require __DIR__ . '/_assert.php';
/*
Test::equals('descendant-or-self::*', HTML_Document::cssSelectorToXPath(''));
Test::equals('descendant-or-self::h1', HTML_Document::cssSelectorToXPath('h1'));
Test::equals("descendant-or-self::h1[@id = 'foo']", HTML_Document::cssSelectorToXPath('h1#foo'));
Test::equals("descendant-or-self::h1[@class and contains(concat(' ', normalize-space(@class), ' '), ' foo ')]", HTML_Document::cssSelectorToXPath('h1.foo'));
Test::equals('descendant-or-self::foo:h1', HTML_Document::cssSelectorToXPath('foo|h1'));
Test::equals('descendant-or-self::h1', HTML_Document::cssSelectorToXPath('H1'));*/
//Test::equals(HTML_Document::cssSelectorToXPath('h1:'));

$tests = [
	array('h1', '//h1'),
	array('h1, h2, h3', '//h1 | //h2 | //h3'),
	array('h1 > p', '//h1/p'),
	array('h1#foo', '//h1[@id="foo"]'),
	array('h1.foo', '//h1[@class and contains(concat(" ", normalize-space(@class), " "), " foo ")]'),
	array('h1[class*="foo bar"]', '//h1[@class and contains(@class, "foo bar")]'),
	//array('h1[foo|class*="foo bar"]', "h1[@foo:class and contains(@foo:class, 'foo bar')]"),
	array('h1[class]', '//h1[@class]'),

	['#foo', '//*[@id="foo"]'],
	['.foo', '//*[@class and contains(concat(" ", normalize-space(@class), " "), " foo ")]'],
	['div#foo>span', '//div[@id="foo"]/span']
];

foreach ($tests as $test)
{
	Test::equals($test[1], \KD2\HTML_Document::cssSelectorToXPath($test[0]));
}