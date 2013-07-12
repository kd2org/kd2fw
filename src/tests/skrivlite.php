<?php

namespace KD2;

require __DIR__ . '/_assert.php';
require KD2FW_ROOT . '/SkrivLite.php';

$skriv = new SkrivLite;
$skriv->setCallback(SkrivLite::CALLBACK_CODE_HIGHLIGHT, false); // Disable code highlighting

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
<blockquote><p>"great quote"
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
	console.log("lol");
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
|| un || deux
|| 1 || 2 ||one column too much
|| missing column';

$target = '<table><tr><th>one</th><th>two</th></tr>
<tr><td>un</td><td>deux</td></tr>
<tr><td>1</td><td>2 ||one column too much</td></tr>
<tr><td>missing column</td><td></td></tr></table>';

test($skriv->render($orig) == $target, 'table rendering error');

$orig = '
Maybe((sure)).

SkrivML is powerful ((Skriv|[[http://markup.skriv.org/]]))

Last try((ok))';

$target = '
<p>Maybe<sup class="footnote-ref"><a href="#cite_note-1" id="cite_ref-1">1</a></sup>.
</p>
<p>SkrivML is powerful <sup class="footnote-ref"><a href="#cite_note-2" id="cite_ref-2">Skriv</a></sup>
</p>
<p>Last try<sup class="footnote-ref"><a href="#cite_note-3" id="cite_ref-3">2</a></sup></p>
<div class="footnotes"><p class="footnote"><a href="#cite_ref-skriv-notes-62q-1" id="cite_note-skriv-notes-62q-1">1</a>. sure</p><p class="footnote"><a href="#cite_ref-skriv-notes-62q-2" id="cite_note-skriv-notes-62q-2">Skriv</a>. <a href="http://markup.skriv.org/">http://markup.skriv.org/</a></p><p class="footnote"><a href="#cite_ref-skriv-notes-62q-3" id="cite_note-skriv-notes-62q-3">2</a>. ok</p></div>';

test($skriv->render($orig) == $target, 'footnote rendering error');

echo $skriv->render($orig);

