<?php

use KD2\Test;
use KD2\HTTP;
use KD2\HTTP_Response;
use KD2\ErrorManager as EM;

require __DIR__ . '/_assert.php';

test_url_build();
test_1and1();
test_urls();
test_uri_templates();
test_http();
test_http(HTTP::CLIENT_CURL);

test_http_put();
test_http_put(HTTP::CLIENT_CURL);

function test_url_build()
{
	$_SERVER['HTTP_HOST'] = '<script>FAIL</script>';
	Test::strictlyEquals('host.invalid', HTTP::getHost());

	$_SERVER['HTTP_HOST'] = str_repeat('k', 300);
	Test::strictlyEquals('host.invalid', HTTP::getHost());

	$_SERVER['HTTP_HOST'] = '1.2.3.4:443';
	Test::strictlyEquals('1.2.3.4:443', HTTP::getHost());

	$_SERVER['HTTP_HOST'] = '[2001:0db8:85a3:0000:0000:8a2e:0370:7334]:80';
	Test::strictlyEquals('[2001:0db8:85a3:0000:0000:8a2e:0370:7334]:80', HTTP::getHost());

	$_SERVER['HTTP_HOST'] = 'domain.local.name';
	Test::strictlyEquals('domain.local.name', HTTP::getHost());

	$_SERVER['HTTPS'] = 'on';
	Test::strictlyEquals('https', HTTP::getScheme());

	$_SERVER['HTTPS'] = 'off';
	Test::strictlyEquals('http', HTTP::getScheme());

	$_SERVER['HTTPS'] = '';
	Test::strictlyEquals('http', HTTP::getScheme());

	unset($_SERVER['HTTPS']);
	Test::strictlyEquals('http', HTTP::getScheme());

	$_SERVER['DOCUMENT_ROOT'] = '/home/user/www/app/www';
	Test::strictlyEquals('/', HTTP::getRootURI('/home/user/www/app/www'));

	// If the document root is not in the www/public directory
	$_SERVER['DOCUMENT_ROOT'] = '/home/user/www/app';
	Test::strictlyEquals('/', HTTP::getRootURI('/home/user/www/app'));

	$_SERVER['DOCUMENT_ROOT'] = '/home/user/www/app/www';
	Test::strictlyEquals('/www/', HTTP::getRootURI('/home/user/www/app'));

	$_SERVER['DOCUMENT_ROOT'] = '/home/user/www';
	Test::strictlyEquals('/app/www/', HTTP::getRootURI('/home/user/www/app/www'));

	// Test in case of misconfiguration
	Test::exception('UnexpectedValueException', function () {
		$_SERVER['DOCUMENT_ROOT'] = '/home/user/www/app2';
		Test::strictlyEquals('/www/', HTTP::getRootURI('/home/user/www/app/www'));
	});

	// If the document root is on a subdirectory of the app
	$_SERVER['DOCUMENT_ROOT'] = '/home/user/www/app/www/admin';
	Test::strictlyEquals('/admin/', HTTP::getRootURI('/home/user/www/app/www/'));

	$_SERVER['DOCUMENT_ROOT'] = '/home/user/www/app/www';
	Test::strictlyEquals('http://domain.local.name/www/', HTTP::getAppURL('/home/user/www/app'));

	$_SERVER['REQUEST_URI'] = '/admin/dir/page.php?ok=yes&two=three';
	Test::strictlyEquals('http://domain.local.name/admin/dir/page.php?ok=yes&two=three', HTTP::getRequestURL());
	Test::strictlyEquals('http://domain.local.name/admin/dir/page.php', HTTP::getRequestURL(false));
}

function test_1and1()
{
	$_SERVER = [
		'SCRIPT_URL' => '/test.php',
		'SCRIPT_URI' => 'http://compta.lol.org/test.php',
		'SERVER_NAME' => 'compta.lol.org',
		'DOCUMENT_ROOT' => '/kunden/homepages/21/d42/htdocs/compta/www',
		'SCRIPT_FILENAME' => '/kunden/homepages/21/d42/htdocs/compta/www/test.php',
		'QUERY_STRING' => '',
		'REQUEST_URI' => '/test.php',
		'SCRIPT_NAME' => '/test.php',
		'STATUS' => '200',
		'ORIG_PATH_INFO' => '/test.php',
		'ORIG_PATH_TRANSLATED' => '/kunden/homepages/21/d42/htdocs/compta/www/test.php',
	];

	Test::exception('UnexpectedValueException', function () {
		HTTP::getRootURI('/homepages/21/d42/htdocs/compta');
	});
}

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

	$response = $http->GET('https://www.whatismybrowser.com/detect/what-http-headers-is-my-browser-sending', ['Test' => 'Test-OK' . $time]);

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
	Test::assert(preg_match('/Test-OK/i', $response->body));

	$response = $http->GET('http://kd2.org/404.not.found');

	Test::equals(404, $response->status);

	// Test POST
	$http->http_options['max_redirects'] = 0;
	$response = $http->POST('https://polyamour.info/adult_warning.php',
		['majeur_et_vaccine' => 'oui', 'from' => '/']);

	Test::equals(302, $response->status);
	Test::hasKey('majeur_et_vaccine', $response->cookies);

	// Test cookies, we should now have access to this page
	$http->http_options['max_redirects'] = 10;
	$response = $http->GET('https://polyamour.info/discussion/-bgv-/Polyamour-libertinage-et-matrice-heteronormative/');

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
	$response = $http->GET('https://httpd.apache.org/docs/2.4/fr');

	Test::equals(301, $response->status);
	Test::equals(null, $response->previous);

	$http->http_options['max_redirects'] = 1;
	$response = $http->GET('https://httpd.apache.org/docs/2.4/fr');

	Test::equals(200, $response->status);
	Test::equals('https://httpd.apache.org/docs/2.4/fr/', $response->url);
	Test::equals('https://httpd.apache.org/docs/2.4/fr/', $response->previous->headers['location']);
	Test::isInstanceOf('KD2\HTTP_Response', $response->previous);
	Test::equals(301, $response->previous->status);
}

function test_http_put($client = HTTP::CLIENT_DEFAULT)
{
	printf("php -S localhost:8089 %s\n", escapeshellarg(__DIR__ . '/data/http_put_server.php'));

	$http = new HTTP;
	$http->client = $client;

	$tmpfile = tempnam(sys_get_temp_dir(), 'lllll');
	file_put_contents($tmpfile, 'OK!');

	$response = $http->PUT('http://localhost:8089/test', $tmpfile);

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

	Test::equals(201, $response->status);
	Test::equals("Received 3 bytes\nOK!", $response->body);
}
