<?php

namespace KD2\Mail;

use stdClass;

/**
 * Provides an easy way to discover the IMAP/SMTP/POP3 configuration of an email address
 *
 * You can use ISPDB: https://github.com/thundernest/autoconfig
 *
 * And provide the directory containing the XML files as the parameter of the constructor.
 *
 * Alternatively, a HTTP request will be performed to try to fetch the config.
 *
 * https://vadosware.io/post/thunderbird-autoconfig-for-your-self-hosted-mailserver/
 * https://hlfh.github.io/autoconfiguration/
 * https://roll.urown.net/server/mail/autoconfig.html
 * https://web.archive.org/web/20150817115525/http://moens.ch:80/2012/05/31/providing-email-client-autoconfiguration-information/
 */
class Discovery
{
	protected ?string $ispdb = null;

	public function __construct(?string $ispdb_directory = null)
	{
		$this->ispdb = rtrim($ispdb_directory, DIRECTORY_SEPARATOR);
	}

	/**
	 * If config is found, returns an object containing 3 objects: smtp, imap and pop3.
	 * See https://wiki.mozilla.org/Thunderbird:Autoconfiguration:ConfigFileFormat
	 * for format of each entry.
	 */
	public function discover(string $address): ?stdClass
	{
		$user = strtok($address, '@');
		$domain = strtok('');

		if (!$user || !$domain) {
			throw new \InvalidArgumentException('Invalid e-mail address: ' . $address);
		}

		$config = null;

		if ($this->ispdb) {
			$config = $this->discoverFromISPDB($domain);
		}

		if (!$config) {
			$config = $this->discoverManually($domain);
		}

		if ($config) {
			$replace = [
				'%EMAILADDRESS%' => $address,
				'%EMAILLOCALPART%' => $user,
				'%EMAILDOMAIN%' => $domain,
			];

			foreach ($config as &$c) {
				if (!isset($c->username)) {
					continue;
				}

				$c->username = strtr($c->username, $replace);
			}

			unset($c);

			$config = (object) $config;
		}

		return $config;
	}

	public function discoverFromISPDB(string $domain): ?stdClass
	{
		foreach (glob($this->ispdb . DIRECTORY_SEPARATOR . '*', ) as $f) {
			if (is_dir($f) || substr($f, -4) != '.xml') {
				continue;
			}

			$xml = @simplexml_load_file($f);
			$domains = (array) $xml->emailProvider->domain;
			$domains = array_map(fn ($a) => (string) $a, $domains);

			if (!in_array($domain, $domains)) {
				unset($xml);
				continue;
			}

			return $this->fromISPDB($xml, $address, $user, $domain);
		}

		return null;
	}

	protected function fromISPDB(SimpleXMLElement $xml): stdClass
	{
		$get = function ($a, $xpath) {
			$b = (array) $a->xpath($xpath)[0] ?? null;
			unset($b['@attributes']);
			return $b;
		};

		return [
			'imap' => $get($xml, './/incomingServer[@type="imap"]'),
			'pop3' => $get($xml, './/incomingServer[@type="pop3"]'),
			'smtp' => $get($xml, './/outgoingServer[@type="smtp"]'),
		];
	}

	public function http(string $url): ?string
	{
		if (function_exists('curl_init')) {
			$c = curl_init();

			curl_setopt_array($c, [
				CURLOPT_URL            => $url,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 2,
				CURLOPT_TIMEOUT        => 5,
				CURLOPT_RETURNTRANSFER => true,
			]);

			$a = curl_exec($c);
			curl_close($a);
			return $a;
		}

		$ctx = stream_context_create(['http' => [
			'timeout' => 5,
		]]);

		return @file_get_contents($url, false, $ctx);
	}

	public function discoverManually(string $domain): ?stdClass
	{
		$config = null;

		// Thunderbird method
		$r = $this->http(sprintf('https://autoconfig.%s/mail/config-v1.1.xml', $domain));

		if ($r && strstr($xml, '<clientConfig')
			&& ($xml = @simplexml_load_string($r))
			&& !empty((string)$xml->xpath('.//incomingServer[@type="imap"]')->hostname)) {
			$config = $this->fromISPDB($xml);
		}

		// Microsoft method, not currently implemented
		/*
		if (!$config) {
			$srv = dns_get_record('_autodiscover._tcp.' . $domain, DNS_SRV);
			$discover_host = null;

			if (isset($srv[0]['target'])) {
				$srv = explode(' ', $discover_host[0]['target']);

				if (!empty($srv[3])) {
					$discover_host = $srv[3];
				}

				unset($srv);
			}

			if (empty($discover_host)) {
				$discover_host = 'autodiscover' . $domain;
			}
		}
		*/

		return $config;
	}
}
