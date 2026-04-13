<?php

use KD2\Delta;
use KD2\Test;

require __DIR__ . '/_assert.php';

$delta = new Delta;

Test::assert($delta instanceOf Delta, '$delta must be an instance of Delta');

$orig = file_get_contents(DATA_DIR . '/delta/small.orig');
$target = file_get_contents(DATA_DIR . '/delta/small.target');
$d = file_get_contents(DATA_DIR . '/delta/small.delta');

Test::assert($d == $delta->create($orig, $target), 'small: delta created differs from reference sample');
Test::assert($target == $delta->apply($orig, $d), 'small: target created from delta differs from target sample file');

$orig = file_get_contents(DATA_DIR . '/delta/code.orig');
$target = file_get_contents(DATA_DIR . '/delta/code.target');
$d = file_get_contents(DATA_DIR . '/delta/code.delta');

Test::assert($d == $delta->create($orig, $target), 'code: delta created differs from reference sample');
Test::assert($target == $delta->apply($orig, $d), 'code: target created from delta differs from target sample file');

$orig = file_get_contents(DATA_DIR . '/delta/binary.orig');
$target = file_get_contents(DATA_DIR . '/delta/binary.target');
$d = file_get_contents(DATA_DIR . '/delta/binary.delta');

Test::assert($d == $delta->create($orig, $target), 'binary: delta created differs from reference sample');
Test::assert($target == $delta->apply($orig, $d), 'binary: target created from delta differs from target sample file');

