<?php

require __DIR__ . '/_assert.php';

use KD2\HTML\Markdown;
use KD2\Test;

$md = new Markdown;

$strings = [
	'<iframe src="File:///">'
	=> '<p>&lt;iframe src="File:///"&gt;</p>',
	'<iframe src="https://youtube.com/embed/bla" />'
	=> '<figure class="video" style="padding-top: 56.000000%;"><iframe width="100%" height="100%" src="https://youtube.com/embed/bla" loading="lazy" referrerpolicy="no-referrer" sandbox="allow-same-origin allow-scripts allow-popups allow-forms allow-modals" frameborder="0" allowfullscreen="" allowtransparency="" style="position: absolute; inset: 0px;"></iframe></figure>',
];

foreach ($strings as $src => $expected) {
	Test::strictlyEquals($expected, $md->text($src));
}
