<?php

$url = 'https://github.com/mledoze/countries/raw/refs/heads/master/countries.json';

$l = json_decode(file_get_contents($url));
$countries = [
	'fr' => [],
	'en' => [],
];

foreach ($l as $c) {
	if (empty($c->cca2)) {
		continue;
	}

	foreach ($countries as $lang => &$cc) {
		$l = $lang === 'fr' ? 'fra' : 'eng';
		$name = $c->translations->$l->common ?? ($c->name->common ?? null);

		if (!$name) {
			echo "Skip: " . $c->cca2 . " ($l)\n";
			continue(2);
		}

		$cc[$c->cca2] = $name;
	}

	unset($cc);
}

foreach ($countries as $lang => $list) {
	natcasesort($list);
	$file = __DIR__ . '/../lib/KD2/data/countries.' . $lang . '.json';
	$old = json_decode(file_get_contents($file), true);

	foreach ($old as $key => $value) {
		if ($list[$key] === $value) {
			continue;
		}

		echo "$value != $list[$key]\n";
	}
	//var_dump(array_diff($old, $list));
	//file_put_contents($file, json_encode($list, JSON_PRETTY_PRINT));
}