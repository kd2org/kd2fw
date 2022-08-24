<?php

require __DIR__ . '/../lib/KD2/ErrorManager.php';
require __DIR__ . '/../lib/KD2/WebDAV.php';
require __DIR__ . '/../lib/KD2/WebDAV_FS.php';

KD2\ErrorManager::enable();
ini_set('log_errors', true);

$fs = new KD2\WebDAV_FS(__DIR__ . '/webdav', '/tmp/lockdb.sqlite');
$fs->route('/files/');