<?php

namespace KD2\Mail;

class DKIM
{
	// headers to sign (minimal: From, To, Subject, Date)
	const DEFAULT_SIGNED_HEADERS = ['from', 'to', 'subject', 'date', 'message-id', 'x-mailer'];

	protected function canonicalizeHeaders(string $headers, string $canonicalization)
	{
		if ($canonicalization === 'simple') {
			return $headers;
		}

		// relaxed
		$lines = explode("\r\n", $headers);
		$new_headers = [];
		$current = null;

		foreach ($lines as &$line) {
			if (substr($line, 0, 1) === ' ') {
				$current .= ' ' . ltrim($line);
				// Unfold all header field continuation lines as described in
				// [RFC5322]; in particular, lines with terminators embedded in
				// continued header field values (that is, CRLF sequences followed by
				// WSP) MUST be interpreted without the CRLF.  Implementations MUST
				// NOT remove the CRLF at the end of the header field value.
				continue;
			}

			// Convert all header field names (not the header field values) to
			// lowercase.  For example, convert "SUBJect: AbC" to "subject: AbC".
			$name = strtolower(strtok($line, ':'));
			$value = strtok('');

			// Delete any WSP characters remaining before and after the colon
			// separating the header field name from the header field value.  The
			// colon separator MUST be retained.
			$line = $name . ':' . ltrim($value);
		}

		unset($line);

		foreach ($lines as &$line) {
			// Convert all sequences of one or more WSP characters to a single SP
			// character.  WSP characters here include those before and after a
			// line folding boundary.
			$line = preg_replace('/[ \t]+/', ' ', $line);

			// Delete all WSP characters at the end of each unfolded header field
			// value.
			$line = preg_replace('/[ \t]+$/m', '', $line);
		}

		unset($line);

		$headers = implode("\r\n", $lines);
		return $headers;
	}

	protected function canonicalizeBody(string $body, string $canonicalization)
	{
		if ($canonicalization === 'relaxed') {
			// Ignore all whitespace at the end of lines.  Implementations
			// MUST NOT remove the CRLF at the end of the line.
			$body = preg_replace('/[ \t]+$/m', '', $body);

			// Reduce all sequences of WSP within a line to a single SP character.
			$body = preg_replace('/[ \t]+/', ' ', $body);
		}

		// Reduce multiple trailing line breaks to a single one
		$body = rtrim($body, "\r\n");

		// If body is empty, it should only consist of a single CRLF
		// relaxed canonicalization should just keep an empty body
		if ($canonicalization === 'simple'
			|| $body !== '') {
			$body .= "\r\n";
		}

		return $body;
	}

	protected function normalizeMessage(string $message): array
	{
		// Normalize line breaks to CRLF
		$message = str_replace(["\r\n", "\r"], "\n", $message);
		$message = str_replace("\n", "\r\n", $message);

		// Split headers and body
		return preg_split("/\r\n\r\n/", $message, 2);
	}

	protected function parseHeaders(string $headers, array $required): array
	{
		$headers = explode("\r\n", $headers);
		$out = [];
		$current = null;

		foreach ($headers as $line) {
			// Handle multi-lines headers
			if (null !== $current
				&& substr($line, 0, 1) === ' ') {
				$current .= "\r\n" . $line;
				continue;
			}

			$name = strtolower(strtok($line, ':'));
			strtok('');

			// Not interested
			if (!in_array($name, $required, true)) {
				unset($current);
				$current = null;
				continue;
			}

			$out[$name] = $line;
			$current =& $out[$name];
		}

		unset($current, $headers);
		return $out;
	}

	protected function getSigningValue(array $headers): string
	{
		$sign_str = '';

		foreach ($headers as $value) {
			$sign_str = rtrim($value, "\r\n") . "\r\n";
		}

		return $sign_str;
	}

	protected function getDKIMHeader(string $headers_canonicalization, string $body_canonicalization, string $domain, string $selector, array $signed_headers, string $body_hash): string
	{
		// Build DKIM-Signature header (without signature value)
		return sprintf(
			'DKIM-Signature: v=1; a=rsa-sha256; c=%s/%s; d=%s; s=%s; h=%s; bh=%s; b=',
			$headers_canonicalization,
			$body_canonicalization,
			$domain,
			$selector,
			implode(':', $signed_headers),
			base64_encode($body_hash),
		);
	}

	public function createSignature(
		string $headers,
		string $body,
		string $selector,
		string $domain,
		string $private_key,
		array $signed_headers = self::DEFAULT_SIGNED_HEADERS,
		string $headers_canonicalization = 'relaxed',
		string $body_canonicalization = 'relaxed'
	): array
	{
		// Load private key
		$keyid = openssl_pkey_get_private($private_key);

		if (!$keyid) {
			throw new \LogicException('Unable to load DKIM private key');
		}

		$headers = $this->canonicalizeHeaders($headers, $headers_canonicalization);
		$body = $this->canonicalizeBody($body, $body_canonicalization);

		$body_hash = hash('sha256', $body, true);

		$headers_to_sign = $this->parseHeaders($headers, $signed_headers);

		$dkim_header = $this->getDKIMHeader($headers_canonicalization, $body_canonicalization, $domain, $selector, $signed_headers, $body_hash);

		// The string to sign is all signed headers, then DKIM header (without b= part)
		$sign_str = $this->getSigningValue($headers_to_sign) . $dkim_header;

		// Sign
		$signature = '';

		if (!openssl_sign($sign_str, $signature, $keyid, OPENSSL_ALGO_SHA256)) {
			throw new \LogicException('Unable to sign DKIM header');
		}

		// Final DKIM header
		$dkim_header .= base64_encode($signature);
		$dkim_header = wordwrap($dkim_header, 73, "\r\n ", true);

		return [
			'header'          => $dkim_header,
			'signature'       => $signature,
			'body_hash'       => $body_hash,
			'headers_to_sign' => $headers_to_sign,
		];
	}

	public function sign(string $message, string $selector, string $domain, string $private_key, array $signed_headers = self::DEFAULT_SIGNED_HEADERS, $canonicalization = 'relaxed/relaxed'): string
	{
		$canonicalization = explode('/', $canonicalization);
		$canonicalization[1] ??= $canonicalization[0];
		list($headers_canonicalization, $body_canonicalization) = $canonicalization;

		list($headers, $body) = $this->normalizeMessage($message);

		$sig = $this->createSignature($headers, $body, $selector, $domain, $private_key, $signed_headers, $headers_canonicalization, $body_canonicalization);
		return rtrim($headers) . "\r\n" . $sig['header'] . "\r\n\r\n" . $body;
	}

	public function verify(string $message): ?string
	{
		list($headers, $body) = $this->normalizeMessage($message);
		$found = $this->parseHeaders($headers, ['dkim-signature']);

		if (!count($found)) {
			return 'No DKIM signature found.';
		}

		$dkim = substr($found['dkim-signature'], strlen('dkim-signature: '));
		$dkim = preg_split('/;\s+/', trim($dkim));
		$dkim = parse_ini_string($dkim, false, INI_SCANNER_RAW);

		static $required = ['v', 'a', 'b', 'bh', 'd', 'h', 's'];

		foreach ($required as $tag) {
			if (empty($dkim[$tag])) {
				return sprintf('Missing "%s" tag', $tag);
			}
		}

		$dkim['v'] ??= null;

		if ($dkim['v'] !== '1') {
			return 'Invalid version (v=) tag value: ' . $dkim['v'];
		}

		$algo = $dkim['a'] ?? null;

		if (!in_array($algo, ['rsa-sha1', 'rsa-sha256'])) {
			return 'Invalid algorithm (a=) tag value: ' . $algo;
		}

		$canonicalization = explode('/', $dkim['c'] ?? 'simple/simple');
		$canonicalization[1] ??= $canonicalization[0];
		list($headers_canonicalization, $body_canonicalization) = $canonicalization;

		if (!in_array($headers_canonicalization, ['simple', 'relaxed'])) {
			return 'Invalid headers canonicalization tag (c=): ' . $headers_canonicalization;
		}

		if (!in_array($body_canonicalization, ['simple', 'relaxed'])) {
			return 'Invalid body canonicalization tag (c=): ' . $body_canonicalization;
		}

		$headers = $this->canonicalizeHeaders($headers, $headers_canonicalization);
		$body = $this->canonicalizeBody($body, $body_canonicalization);

		$body_hash = base64_encode(hash('sha256', $body, true));

		if ($body_hash !== $dkim['bh']) {
			return sprintf('Invalid body hash (got: %s, but calculated: %s)', $dkim['bh'], $body_hash);
		}

		$domain = $dkim['d'];
		$selector = $dkim['s'];

		$host = sprintf('%s._domainkey.%s', $selector, $domain);
		$dns = dns_get_record($host, DNS_TXT);
		$raw_record = $dns[0]['txt'] ?? null;

		if (empty($raw_record)) {
			return sprintf('Cannot find TXT record for %s', $host);
		}

		$record = parse_ini_string(str_replace(';', "\n", $raw_record), INI_SCANNER_RAW);

		if (($record['v'] ?? null) !== 'DKIM1') {
			return sprintf('TXT record for %s is not DKIM: %s', $host, $raw_record);
		}

		if (empty($record['p'])) {
			return sprintf('TXT record for %s is missing "p" tag: %s', $host, $raw_record);
		}

		$pubkey = $record['p'];

		$signed_headers = explode(':', $dkim['h']);
		$headers_to_sign = $this->parseHeaders($headers, $signed_headers);

		$dkim_header = $this->getDKIMHeader($headers_canonicalization, $body_canonicalization, $domain, $selector, $signed_headers, $body_hash);

		// The string to sign is all signed headers, then DKIM header (without b= part)
		$sign_str = $this->getSigningValue($headers_to_sign) . $dkim_header;

		// Convert key back into PEM format
		$pubkey = sprintf(
			"-----BEGIN PUBLIC KEY-----\n%s\n-----END PUBLIC KEY-----",
			trim(chunk_split($pubkey, 64, "\n"))
		);

		list($alg, $hash) = explode($dkim['a']);

		$verified = openssl_verify($signed_string, base64_decode($dkim['b']), $pubkey, $hash);

		if ($verified === 0) {
			return 'Invalid signature';
		}
		elseif ($verified === -1) {
			$message = '';

			while ($error = openssl_error_string() !== false) {
				$message .= $error . "\n";
			}

			return 'OpenSSL verify error: ' . $message;
		}

		// Signature is valid!
		return null;
	}

	public function isValid(string $message)
	{
		return $this->verify($message) === null;
	}
}