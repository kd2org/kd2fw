<?php

use KD2\Test;
use KD2\HTML\CSSParser;

require __DIR__ . '/_assert.php';

$parser = new \KD2\HTML\CSSParser;

test_matching();

function test_matching()
{
	$css = 'table {
		type: number;
	}
	td {
		type: string;
	}
	td.date {
		type: date;
	}';

	$html = '<table><tr><td></td><td class="date"></td></tr></table>';
	$doc = new DOMDocument;
	$doc->loadHTML($html);

	$p = new CSSParser;
	$p->import($css);

	$tag = $p->xpath($doc, './/td', 0);
	Test::equals('string', $p->get($tag)['type']);
	$tag = $p->xpath($doc, './/td', 1);
	Test::equals('date', $p->get($tag)['type']);
}

/*
$css = <<<EOF
@media spreadsheet {
	html body h2 {
		font-weight: bold;
	}
}

body, div, dl, dt, dd, ul, ol, li, h1, h2, h3, h4, h5, h6, 
pre, form, fieldset, input, textarea, p, blockquote, th, td { 
	padding: 0;
	margin: 0;
}

header.main.small h1 b.old, a#id, p.small {
	display: inline-block;
	background: url("logo_texte.png") no-repeat 0px 0px;
	width: 500px;
	height: 92px;
	text-indent: -70em;
	overflow: hidden;
}

@media handheld, screen and (max-width:480px) {

	.home_features article {
		padding-top: 200px;
		padding-left: .5em;
		background-position: center 10px;
		text-align: center;
	}

	.home_features article ul {
		justify-content: center;
	}

	.home_features article ul li {
		font-size: 1em;
	}

	.offers, section.page > article {
		display: flex;
		flex-direction: column;
	}

	section.page nav.categories li {
		text-align: center;
	}

	article.payment .methods {
		display: block
	}
}
EOF;

$parser->import($css);


$html = '<!DOCTYPE html>
<html>
	<meta http-equiv="Content-Type" content="charset=utf-8" />
	<body>
		<h2>LOL</h2>
		<header class="main">
			<h1>Maiééén <b class="old">OLLD</b></h1>
		</header>
		<header class="main small">
			<h1>Main</h1>
		</header>
		<pre>lol</pre>
		<a id="id">lol</a>
	</body>
</html>
';

libxml_use_internal_errors(true);
$dom = new DOMDocument;
$dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

$parser->debug($dom->documentElement);
$parser->style($dom->documentElement);

var_dump($dom->saveHTML());
*/