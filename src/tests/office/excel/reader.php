<?php

use KD2\Test;
use KD2\Office\Excel\Reader;

require __DIR__ . '/../../_assert.php';

function test_number_formats()
{
	$r = new Reader;

	$formats = [
		'"Shipped in "@' => [
			'1 day' => 'Shipped in 1 day',
			'2 weeks' => 'Shipped in 2 weeks',
		],
		'0#\ ##\ ##\ ##\ ##' => [
			'102030405' => '01 02 03 04 05',
			'30405' => '0  3 04 05',
		],
		'#,##0\ "€"' => [
			'42' => 42,
			'42.02' => 42,
		],
		'#,##0.00\ "€"' => [
			'42' => 42.0,
			'42.02' => 42.02,
		],
		'#,##0\ _€' => [
			'42' => 42,
			'42.02' => 42,
		],
		'#.00%' => [
			'.42' => '42.00%',
			'1.4202' => '142.02%',
		],
		'00000' => [
			'42' => 42,
			'42.02' => 42,
		],
		'#,##0_ ;[Red]\-#,##0\ ' => [
			'42' => 42,
			'-42' => -42,
			'42.02' => 42,
			'-42.02' => -42,
		],
		'_ * #,##0.00_)\ "€"_ ;_ * \(#,##0.00\)\ "€"_ ;_ * "-"??_)\ "€"_ ;_ @_ ' => [
			'42' => 42.0,
			'-42' => -42.0,
			'42.02' => 42.02,
			'test' => 'test',
		],
	];

	foreach ($formats as $raw_format => $tests) {
		$formats = $r->parseNumberFormats($raw_format);

		foreach ($tests as $number => $result) {
			Test::strictlyEquals($result, $r->formatExcelNumber($number, $formats), 'Error in format: ' . $raw_format);
		}
	}
}

function test_reader(string $str)
{
	$r = new Reader;
	$r->openString($str);

	$sheets = $r->listSheets();
	Test::equals(3, count($sheets));
	Test::assert(in_array('Feuille1', $sheets));
	Test::assert(in_array('Test <> weird sheet name', $sheets));
	Test::assert(1 === array_search('Test <> weird sheet name', $sheets, true));

	$rows = iterator_to_array($r->iterate(1));
	Test::assert(2 === count($rows));
	Test::assert(4 === count($rows[1]));
	Test::assert(1 === $rows[1][0]);
	Test::assert(2 === $rows[1][1]);
	Test::assert(3 === $rows[1][2]);
	Test::assert(4 === $rows[1][3]);
	Test::assert(4 === count($rows[0]));
	Test::assert('A' === $rows[0][0]);
	Test::assert('B' === $rows[0][1]);
	Test::assert('' === $rows[0][2]);
	Test::assert('D' === $rows[0][3]);

	$rows = iterator_to_array($r->iterate(0));
	Test::assert(8 === count($rows));

	foreach ($rows as $row) {
		Test::assert(2 === count($row));
	}

	Test::strictlyEquals('2026-02-01', $rows[0][0]);
	Test::assert(3 === $rows[0][1]);
	Test::assert(1.02 === $rows[1][0]);
	Test::assert(0.1403 === $rows[2][0]);
	Test::strictlyEquals('2026-01-01T02:02:01', $rows[3][0]);
	Test::assert(3 === $rows[4][0]);
	Test::assert(3.0004 === $rows[4][1]);
	Test::assert("Line 1\nLine 2" === $rows[5][0]);

	$rows = iterator_to_array($r->iterate(2));
	Test::assert(3 === count($rows));

	// Test number formats
	Test::strictlyEquals('01 02 03 04 05', $rows[0][1]);
}

$xlsx = <<<EOF
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
EOF;

test_number_formats();
test_reader(base64_decode($xlsx));
