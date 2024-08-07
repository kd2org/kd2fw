<?php

use \KD2\Test;

require __DIR__ . '/_assert.php';


$ods = new \KD2\HTML\TableToODS;

$ods->import(file_get_contents(__DIR__ . '/data/html/table1.html'));

$ods->save('/tmp/test.ods');

$ods->XML();