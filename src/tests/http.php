<?php

use KD2\Test;
use KD2\HTTP;
use KD2\HTTP_Response;
use KD2\ErrorManager as EM;

require __DIR__ . '/_assert.php';

test_http();
test_http(HTTP::CLIENT_CURL);

function test_http($client = HTTP::CLIENT_DEFAULT)
{
	$http = new HTTP;
	$http->client = $client;

	$time = time();

	$response = $http->GET('http://kd2.org/ip/', ['Test' => 'OK' . $time]);

	Test::isInstanceOf('KD2\HTTP_Response', $response);

	Test::hasProperty('status', $response);
	Test::hasProperty('body', $response);
	Test::hasProperty('url', $response);
	Test::hasProperty('headers', $response);
	Test::hasProperty('request', $response);
	Test::hasProperty('fail', $response);
	Test::hasProperty('cookies', $response);
	Test::hasProperty('size', $response);
	Test::hasProperty('raw_headers', $response);
	Test::hasProperty('error', $response);
	
	Test::assert($response->fail === false, 'Request failed: ' . $response->body);

	Test::equals(200, $response->status);

	Test::assert(preg_match('/Test:\s*OK' . $time . '/i', $response->body));

	$response = $http->GET('http://kd2.org/404.not.found');

	Test::equals(404, $response->status);

	// Test POST
	$http->http_options['max_redirects'] = 0;
	$response = $http->POST('http://polyamour.info/adult_warning.php', 
		['majeur_et_vaccine' => 'oui', 'from' => '/']);

	Test::equals(302, $response->status);
	Test::hasKey('majeur_et_vaccine', $response->cookies);

	// Test cookies, we should now have access to this page
	$response = $http->GET('http://polyamour.info/discussion/-bgv-/Polyamour-libertinage-et-matrice-heteronormative/');

	Test::equals(200, $response->status);

	Test::assert(preg_match('/id="msg-79884"/i', $response->body));

	// Test request failed (not that 404, 500 etc. are not fail)
	$http->http_options['timeout'] = 1;
	$response = $http->GET('http://random.domain.' . $time . '.invalid/');

	Test::equals(true, $response->fail);

	// Check that the error message is there
	Test::assert(strlen($response->error) > 1);
}