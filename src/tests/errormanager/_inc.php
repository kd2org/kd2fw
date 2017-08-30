<?php

require __DIR__ . '/../_assert.php';

use KD2\ErrorManager as EM;

EM::enable();
EM::setLogFile(__DIR__ . '/error.log');
