<?php

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
	http_response_code(405);
	exit;
}

$len = $_SERVER['HTTP_CONTENT_LENGTH'] ?? null;

if (!$len) {
	http_response_code(400);
	exit;
}

http_response_code(201);

printf('Received %d bytes', $len);
echo "\n";
readfile('php://input');
