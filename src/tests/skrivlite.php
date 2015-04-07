<?php

namespace KD2;

require __DIR__ . '/_assert.php';
require KD2FW_ROOT . '/SkrivLite.php';

$skriv = new SkrivLite;
$skriv->setCallback(SkrivLite::CALLBACK_CODE_HIGHLIGHT, false); // Disable code highlighting
$skriv->footnotes_prefix = 'test';

test($skriv instanceOf SkrivLite, '$skriv must be an instance of SkrivLite');

$orig = '**strong word** --strike-through-- not-__underlined__';
$target = '<p><strong>strong word</strong> <s>strike-through</s> not-__underlined__</p>';

test($skriv->render($orig) == $target, 'inline rendering error');

$orig = '
line
break

new paragraph';

$target = '<p>line
<br />break
</p>
<p>new paragraph</p>';

test($skriv->render($orig) == $target, 'paragraph or line-break rendering error');

$orig = '
useless text

== title level 2

some text
=== title level 3 ===
= title level 1 = with ID
= title level 1 \= without ID';

$target = '<p>useless text
</p>
<h2 id="title-level-2">title level 2</h2>

<p>some text
</p><h3 id="title-level-3">title level 3</h3>
<h1 id="with-ID">title level 1</h1>
<h1 id="title-level-1-without-ID">title level 1 = without ID</h1>';

test($skriv->render($orig) == $target, 'title rendering error');

$orig = '
some text

> "great quote"
> (he said)
>
>but on a new paragraph

some text

>>> sub-sub-reply
>> sub-reply
> reply
';

$target = '<p>some text
</p>
<blockquote><p>&quot;great quote&quot;
<br />(he said)
</p>
<p>but on a new paragraph
</p></blockquote>
<p>some text
</p>
<blockquote><blockquote><blockquote><p>sub-sub-reply
</p></blockquote><p>sub-reply
</p></blockquote><p>reply</p></blockquote>';

test($skriv->render($orig) == $target, 'blockquote rendering error');

$orig = 'What is ??KD2FW|KD2 micro framework???';
$target = '<p>What is <abbr title="KD2 micro framework">KD2FW</abbr>?</p>';

test($skriv->render($orig) == $target, 'abbreviation rendering error');

$orig = '
Here is an example:
 At least one space at the beginning of each
 line is enough to create a preformatted paragraph.
 
 Skriv syntax **works**.';

$target = '<p>Here is an example:
</p><pre>At least one space at the beginning of each
line is enough to create a preformatted paragraph.

Skriv syntax <strong>works</strong>.</pre>';

test($skriv->render($orig) == $target, 'preformatted text rendering error');

$orig = '
[[[
verbatim block
**not rendered**
]]]
**yes rendered**';

$target = '<pre>
verbatim block
**not rendered**
</pre>
<p><strong>yes rendered</strong></p>';

test($skriv->render($orig) == $target, 'verbatim rendering error');

$orig = '
[[[ javascript
(function () {
	console.log("lol");
}());
]]]';

$target = '<pre><code class="language-javascript">
(function () {
	console.log(&quot;lol&quot;);
}());
</code></pre>';

test($skriv->render($orig) == $target, 'code block rendering error');

$orig = '{{image|http://lol.png}} {{image.jpg}}';
$target = '<p><img src="http://lol.png" alt="image" /> <img src="image.jpg" alt="image.jpg" /></p>';

test($skriv->render($orig) == $target, 'image rendering error');

$orig = '
some text

{{{ class1 class2
enclosed **text**

text1
text2

{{{ {{{ class3

text3

}}}

}}}';
// Note that we don't close the second styled block properly

$target = '<p>some text
</p>
<div class="class1 class2">
<p>enclosed <strong>text</strong>
</p>
<p>text1
<br />text2
</p>
<div class="class1 class2 class3">

<p>text3
</p>
</div></div>

';

test($skriv->render($orig) == $target, 'styled block rendering error');

$orig = 'text [[http://kd2.org/]] and [[LQDN|http://lqdn.net/]]';
$target = '<p>text <a href="http://kd2.org/">http://kd2.org/</a> and <a href="http://lqdn.net/">LQDN</a></p>';

test($skriv->render($orig) == $target, 'link rendering error');

$orig = '
!! one !! two
|| un || deux||
|| 1 || 2 ||one column too much
|| missing column';

$target = '<table><tr><th>one</th><th>two</th></tr>
<tr><td>un</td><td>deux||</td></tr>
<tr><td>1</td><td>2 ||one column too much</td></tr>
<tr><td>missing column</td><td></td></tr></table>';

test($skriv->render($orig) == $target, 'table rendering error');

$orig = '
Maybe((sure)).

SkrivML is powerful ((Skriv|[[http://markup.skriv.org/]]))

Last try((ok))';

$target = '<p>Maybe<sup class="footnote-ref"><a href="#cite_note-test1" id="cite_ref-test1">1</a></sup>.
</p>
<p>SkrivML is powerful <sup class="footnote-ref"><a href="#cite_note-test2" id="cite_ref-test2">Skriv</a></sup>
</p>
<p>Last try<sup class="footnote-ref"><a href="#cite_note-test3" id="cite_ref-test3">2</a></sup></p>
<div class="footnotes"><p class="footnote"><a href="#cite_ref-test1" id="cite_note-test1">1</a>. sure</p><p class="footnote"><a href="#cite_ref-test2" id="cite_note-test2">Skriv</a>. <a href="http://markup.skriv.org/">http://markup.skriv.org/</a></p><p class="footnote"><a href="#cite_ref-test3" id="cite_note-test3">2</a>. ok</p></div>';

test($skriv->render($orig) == $target, 'footnote rendering error');

// https://github.com/Amaury/SkrivMarkup/issues/3
$orig = 'aa ##--bbb## ccc';
$target = '<p>aa <tt>--bbb</tt> ccc</p>';

test($skriv->render($orig) == $target, 'inline rendering error');

$orig = '
* list 1
*list 2

# list 1
# list 2
not in list

* level 1
** level 2
* level 1
** level 2
** level 2
*** level 3
* back to level 1

== now a mix ==

* level 1
*# level 2
*#* level 3
*#* level 3
*# back to level 2
** level 2 but unordered
## level 2 ordered

**bold bold**
';

$target = '<ul><li>list 1
</li><li>list 2
</li></ul>
<ol><li>list 1
</li><li>list 2
</li></ol><p>not in list
</p>
<ul><li>level 1
<ul><li>level 2
</li></ul></li><li>level 1
<ul><li>level 2
</li><li>level 2
<ul><li>level 3
</li></ul></li></ul></li><li>back to level 1
</li></ul>
<h2 id="now-a-mix">now a mix</h2>

<ul><li>level 1
<ol><li>level 2
<ul><li>level 3
</li><li>level 3
</li></ul></li><li>back to level 2
</li></ol><ul><li>level 2 but unordered
</li></ul></li></ul><ol><ol><li>level 2 ordered
</li></ol></ol>
<p><strong>bold bold</strong></p>';

test($skriv->render($orig) == $target, 'list rendering error');

// https://github.com/Amaury/SkrivMarkup/issues/15
$orig = '[[##__invoke## | http://www.php.net/manual/fr/language.oop5.magic.php#object.invoke]]';
$target = '<p><a href="http://www.php.net/manual/fr/language.oop5.magic.php#object.invoke"><tt>__invoke</tt></a></p>';

test($skriv->render($orig) == $target, 'issue 15 rendering error');

$skriv->registerExtension('lipsum', function($args, $content = null) 
{
	if (isset($args['length']))
		$length = (int) $args['length'];
	elseif (isset($args[0]))
		$length = (int)$args[0];
	else
		$length = null;
	
	$text = 'Lorem ipsum dolor sit amet, consectetur adipisicing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.';

	if ($length)
		$text = substr($text, 0, $length);
	
	if (!is_null($content))
	{
		$text = '<p>' . $text . '</p>';
	}

	return $text;
});

$target = '<p><s>text</s> Lorem ipsum dolor si <strong>bold</strong> normal</p>';
$orig = '--text-- <<lipsum|20>> **bold** normal';

test($skriv->render($orig) == $target, 'inline extension rendering error');

$orig = '--text-- <<lipsum length=20>> **bold** normal';

test($skriv->render($orig) == $target, 'inline extension with named argument rendering error');

$orig = '<<lipsum length="20"
>>';

$target = '
<p>Lorem ipsum dolor si</p>';

test($skriv->render($orig) == $target, 'block extension with named argument rendering error');

$skriv->registerExtension('html', function($args, $content = null)  { return $content; });

$orig = '
<b>Escaped html</b>
<<html
<p><b>Not escaped</b></p>
>>';

$target = '<p>&lt;b&gt;Escaped html&lt;/b&gt;
</p>

<p><b>Not escaped</b></p>
';

test($skriv->render($orig) == $target, 'block html extension rendering error');

$orig = 'Multiple extensions: <<lipsum|5>> <<lipsum|5>> **some text** <<lipsum|5>> --other text--';
$target = '<p>Multiple extensions: Lorem Lorem <strong>some text</strong> Lorem <s>other text</s></p>';

test($skriv->render($orig) == $target, 'multiple inline extension rendering error');


$orig = '<<lipsum|5>> <<lipsum|5>> **some text** <<lipsum|5>> --other text--';
$target = '<p>Lorem Lorem <strong>some text</strong> Lorem <s>other text</s></p>';

test($skriv->render($orig) == $target, 'multiple inline extension 2 rendering error');
