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
	'h1' => '//h1',
	'h1 h2' => '//h1//h2',
	'h1, h2, h3' => '//h1 | //h2 | //h3',
	'h1 > p' => '//h1/p',
	'h1#foo' => '//h1[@id="foo"]',
	'h1.foo' => '//h1[@class and contains(concat(" ", normalize-space(@class), " "), " foo ")]',
	'h1[class*="foo bar"]' => '//h1[@class and contains(@class, "foo bar")]',
	//array('h1[foo|class*="foo bar"]', "h1[@foo:class and contains(@foo:class, 'foo bar')]"),
	'h1[class]' => '//h1[@class]',

	'#foo' => '//*[@id="foo"]',
	'.foo' => '//*[@class and contains(concat(" ", normalize-space(@class), " "), " foo ")]',
	'div#foo>span' => '//div[@id="foo"]/span',
	'*' => '//*',
	'h3 strong a, #fbPhotoPageAuthorName, title' => '//h3//strong//a | //*[@id="fbPhotoPageAuthorName"] | //title',
	'p#foo.bar' => '//p[@id="foo"][@class and contains(concat(" ", normalize-space(@class), " "), " bar ")]',
	'div#main p > a p > div' => '//div[@id="main"]//p/a//p/div',
	'h1 ~ p' => '//h1/following-sibling::p',
	'div[class="foo"]' => '//div[@class="foo"]',
	'div[class|="foo"]' => '//div[@class="foo" or starts-with(@class, "foo-")]',
	'div[class*="foo"]' => '//div[@class and contains(@class, "foo")]',
	'div[class~="foo"]' => '//div[contains(concat(" ", @class, " "), " foo ")]',

	'div:empty' => '//div[not(node())]',
	'dl dt:first-of-type' => '//dl//dt[position() = 1]',
	'tr > td:last-of-type' => '//tr/td[position() = last()]',
	'p:only-of-type' => '//p[last() = 1]',

	'tr:nth-child(odd)'  => '//tr[(position() >= 1) and (((position()-1) mod 2) = 0)]',
	'tr:nth-child(even)' => '//tr[(position() mod 2) = 0]',
	'tr:nth-child(3)' => '//tr[position() = 3]',
	/*
	// Not supported yet
	'tr:nth-child(2n+1)' => '//tr[(position() >= 1) and (((position()-1) mod 2) = 0)]',
	'tr:nth-child(2n+0)' => '//tr[(position() mod 2) = 0]',
	'tr:nth-child(10n+9)'=> '//tr[(position() >= 9) and (((position()-9) mod 10) = 0)]',
	'tr:nth-child(10n-1)'=> '//tr[(position() >= 9) and (((position()-9) mod 10) = 0)]',
	'tr:nth-child(n-2)'  => '//tr[((last()-position()+1) >= 1) and ((((last()-position()+1)-1) mod 2) = 0)]',
	*/
];

foreach ($tests as $css=>$xpath)
{
	Test::equals($xpath, \KD2\HTML_Document::cssSelectorToXPath($css));
}