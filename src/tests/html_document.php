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

$html = '<!DOCTYPE html>
<html>
<body>
	<header>
		<h1 class="main">Title</h1>
		<h2 id="subtitle">Subtitle</h2>
	</header>
	<div>
		<b class="main-bold-text">Bold</b>
		<i class="main italic text">Italic</i>
		<u></u>
		<b class="none">None</b>
	</div>
</body>
</html>';

$tests = [
	'h1' => 'Title',
	'header h1' => 'Title',
	'body > header > h1' => 'Title',
	'b ~ u' => '',
	'h1 + h2' => 'Subtitle',

	'h1.main' => 'Title',
	'.main' => 'Title',
	'h2#subtitle' => 'Subtitle',
	'#subtitle' => 'Subtitle',

	'header *:nth-child(odd)' => 'Title',
	'header *:nth-child(even)' => 'Subtitle',
	'header *:nth-child(1)' => 'Title',
	'header *:nth-child(2)' => 'Subtitle',

	'h1[class]' => 'Title',
	'h1[class="main"]' => 'Title',
	'i[class~="italic"]' => 'Italic',
	'b[class^="main"]' => 'Bold',
	'*[class$="-text"]' => 'Bold',
	'b[class*="-bold-"]' => 'Bold',

	'h1:only-of-type' => 'Title',
	'b:first-of-type' => 'Bold',
	'b:last-of-type' => 'None',
	'u:empty' => '',
	'b:not([class^="main"])' => 'None',
	'b:not(.main-bold-text)' => 'None',
];

$doc = new \KD2\HTML_Document();
$doc->loadHTML($html);

// Test that querySelector returns an extended node that can also call querySelector
Test::equals('Title', $doc->querySelector('body')->querySelector('h1')->textContent);

foreach ($tests as $selector => $expected)
{
	Test::equals($expected, $doc->querySelector($selector)->textContent);
}
