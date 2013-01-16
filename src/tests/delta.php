<?php

namespace KD2;

require __DIR__ . '/_assert.php';
require KD2FW_ROOT . '/Delta.php';

$delta = new Delta;

test($delta instanceOf Delta, '$delta must be an instance of Delta');

$orig = file_get_contents(DATA_DIR . '/delta/small.orig');
$target = file_get_contents(DATA_DIR . '/delta/small.target');
$d = file_get_contents(DATA_DIR . '/delta/small.delta');

test($d == $delta->create($orig, $target), 'small: delta created differs from reference sample');
test($target == $delta->apply($orig, $d), 'small: target created from delta differs from target sample file');

$orig = file_get_contents(DATA_DIR . '/delta/code.orig');
$target = file_get_contents(DATA_DIR . '/delta/code.target');
$d = file_get_contents(DATA_DIR . '/delta/code.delta');

test($d == $delta->create($orig, $target), 'code: delta created differs from reference sample');
test($target == $delta->apply($orig, $d), 'code: target created from delta differs from target sample file');

$orig = file_get_contents(DATA_DIR . '/delta/binary.orig');
$target = file_get_contents(DATA_DIR . '/delta/binary.target');
$d = file_get_contents(DATA_DIR . '/delta/binary.delta');

test($d == $delta->create($orig, $target), 'binary: delta created differs from reference sample');
test($target == $delta->apply($orig, $d), 'binary: target created from delta differs from target sample file');

