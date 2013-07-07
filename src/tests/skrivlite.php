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

$orig = '{{image|http://lol.png}}';
$target = '<p><img src="http://lol.png" alt="image" /></p>';

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
echo $skriv->render($orig);

