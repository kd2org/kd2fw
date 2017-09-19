<?php

use KD2\Test;
use KD2\HTTP;
use KD2\HTTP_Response;
use KD2\ErrorManager as EM;

require __DIR__ . '/_assert.php';

test_urls();
test_uri_templates();
test_http();
test_http(HTTP::CLIENT_CURL);

function test_urls()
{
	Test::equals('/w/Wiki', HTTP::glueURL(['path' => '/w/Wiki']));
	Test::equals('//wikipedia.org/w/Wiki', HTTP::glueURL(['host' => 'wikipedia.org', 'path' => '/w/Wiki']));
	Test::equals('https://wikipedia.org/w/Wiki', HTTP::glueURL(['host' => 'wikipedia.org', 'scheme' => 'https', 'path' => '/w/Wiki']));

	// Merge
	Test::equals('https://wikipedia.org:80/w/Wiki/Pedia', HTTP::mergeURLs('https://wikipedia.org:80/admin/', '../w/Wiki/Pedia'));
	Test::equals('/w/Wiki', HTTP::mergeURLs('/w/', '../w/Wiki'));
	Test::equals('https://wikipedia.org:80/w/Wiki', HTTP::mergeURLs('https://wikipedia.org:80/w/Pedia', './Wiki'));
}

function test_uri_templates()
{
	Test::equals('/w/Wiki', HTTP::URITemplate('/w/{page}', ['page' => 'Wiki']));
	Test::equals('/w/Hello%20world%21', HTTP::URITemplate('/w/{page}', ['page' => 'Hello world!']));
	Test::equals('/w/Hello%20world!', HTTP::URITemplate('/w/{+page}', ['page' => 'Hello world!']));
	Test::equals('/w/Wiki#Beginning,end', HTTP::URITemplate('/w/{page}{#section}', ['page' => 'Wiki', 'section' => 'Beginning,end']));
}

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
	Test::hasProperty('error', $response);

	Test::isObject($response->headers);
	Test::isInstanceOf('\KD2\HTTP_Headers', $response->headers);
	
	Test::assert($response->fail === false, 'Request failed: ' . $response->body);

	Test::equals(200, $response->status);

	// Check if header has been received by server
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
	$http->http_options['max_redirects'] = 10;
	$response = $http->GET('http://polyamour.info/discussion/-bgv-/Polyamour-libertinage-et-matrice-heteronormative/');

	Test::equals(200, $response->status);

	Test::assert(preg_match('/id="msg-79884"/i', $response->body));

	// Test request failed (not that 404, 500 etc. are not fail)
	$http->http_options['timeout'] = 1;
	$response = $http->GET('http://random.domain.' . $time . '.invalid/');

	Test::equals(true, $response->fail);

	// Check that the error message is there
	Test::assert(strlen($response->error) > 1);

	// Test redirect
	$http->http_options['max_redirects'] = 0;
	$response = $http->GET('http://kd2.org/ip');

	Test::equals(301, $response->status);
	Test::equals(null, $response->previous);

	$http->http_options['max_redirects'] = 1;
	$response = $http->GET('http://kd2.org/ip');

	Test::equals(200, $response->status);
	Test::equals('http://kd2.org/ip/', $response->url);
	Test::equals('http://kd2.org/ip/', $response->previous->headers['location']);
	Test::isInstanceOf('KD2\HTTP_Response', $response->previous);
	Test::equals(301, $response->previous->status);
}