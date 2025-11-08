<?php

namespace KD2\Mail;

use stdClass;

/**
 * IMAP library using curl
 * Parsing the message is left up to your implementation.
 *
 * Curl doc:
 * https://everything.curl.dev/usingcurl/reademail
 * https://curl.se/mail/lib-2013-03/0104.html
 * https://gist.github.com/akpoff/53ac391037ae2f2d376214eac4a23634
 * https://curl.se/libcurl/c/imap-examine.html (and others)
 *
 * IMAP:
 * https://irp.nain-t.net/doku.php/190imap:030_commandes
 * https://www.rfc-editor.org/rfc/rfc3501#section-7.4.2
 * https://explained-from-first-principles.com/email/#internet-message-access-protocol
 * https://www.atmail.com/blog/advanced-imap/
 * https://irp.nain-t.net/doku.php/190imap:030_commandes
 * https://nickb.dev/blog/introduction-to-imap/
 */
class Mailbox
{
	const IMAP = 'imap';
	const IMAPS = 'imaps';
	//const POP3 = 'pop3';
	//const POP3S = 'pop3s';

	protected string $server;
	protected string $protocol;
	protected string $username;
	protected string $password;
	protected $curl;
	protected bool $expunge = false;
	protected $log_pointer = null;

	const DEFAULT_FETCH_QUERY = [
		'flags',
		'date',
		'size',
		'envelope',
	];

	const SEARCH_UNSEEN = ['UNSEEN'];

	public function __construct(string $server, string $protocol = self::IMAPS)
	{
		$this->server = $server;
		$this->protocol = $protocol;
	}

	public function __destruct()
	{
		if ($this->curl) {
			if ($this->expunge) {
				$this->request(null, 'EXPUNGE');
			}

			curl_close($this->curl);
		}
	}

	public function setLogin(
		string $username,
		#[\SensitiveParameter]
		string $password
	): void
	{
		$this->init();
		curl_setopt($this->curl, CURLOPT_USERNAME, $username);
		curl_setopt($this->curl, CURLOPT_PASSWORD, $password);
	}

	public function setLogFilePointer($p)
	{
		if (null === $p) {
			$this->opt(CURLOPT_VERBOSE, false);
			$this->opt(CURLOPT_STDERR, null);
			$this->log_pointer = null;
		}
		else {
			if (!is_resource($p)) {
				throw new \InvalidArgumentException('Pointer argument is not a valid resource');
			}

			$this->opt(CURLOPT_VERBOSE, true);
			$this->opt(CURLOPT_STDERR, $p);
			$this->log_pointer = $p;
		}
	}

	protected function init(): void
	{
		if (isset($this->curl)) {
			return;
		}

		$this->curl = curl_init();
		curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($this->curl, CURLOPT_HEADER, 1);
		// Make sure we only allow IMAP/IMAPS protocols
		curl_setopt($this->curl, CURLOPT_PROTOCOLS, CURLPROTO_IMAP | CURLPROTO_IMAPS);
	}

	protected function request(?string $uri = null, ?string $request = null): string
	{
		$this->init();
		curl_setopt($this->curl, CURLOPT_URL, sprintf('%s://%s/%s', $this->protocol, $this->server, $uri));
		curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $request ?? null);

		return curl_exec($this->curl);
	}

	protected function opt($key, $value)
	{
		$this->init();
		curl_setopt($this->curl, $key, $value);
	}

	public function parseImapString(string $str, int &$pos = 0)
	{
		$idx = 0;
		$out = [];
		$in_quotes = false;

		for ($i = $pos; $i < strlen($str); $i++) {
			$c = $str[$i];

			if ($c === '"') {
				if ($in_quotes) {
					$in_quotes = false;
					$idx++;
				}
				else {
					$in_quotes = true;
				}
				continue;
			}
			elseif (!$in_quotes) {
				if ($c === '(') {
					$i++;
					$out[$idx++] = $this->parseImapString($str, $i);
					continue;
				}
				elseif ($c === ')') {
					break;
				}
				// Parse bytes
				elseif ($c === '{') {
					$closing_pos = strpos($str, '}', $i + 1);
					$length = substr($str, $i + 1, $closing_pos - ($i + 1));
					$i += strlen($length) + 2 + 2;
					$out[$idx++] = substr($str, $i, $length);
					$i += $length;
					continue;
				}
				elseif (trim($c) === '') {
					$idx++;
					continue;
				}
			}
			$out[$idx] ??= '';
			$out[$idx] .= $c;
		}

		$pos = $i;

		// Replace NIL with NULL
		array_walk($out, fn (&$a) => $a = $a === 'NIL' ? null : $a);

		// use array_values, as we might increment $idx a bit too often
		// with empty characters, but not an issue
		return array_values($out);
	}

	public function parseImapFetch(string $str): stdClass
	{
		$tuples = $this->parseImapString($str);
		$out = [];

		for ($i = 0; $i < count($tuples); $i++) {
			$name = $tuples[$i];
			$i++; // Skip to value
			$value = $tuples[$i];

			if ($name === 'INTERNALDATE') {
				$out['date'] = new \DateTime($value);
			}
			elseif ($name === 'RFC822.SIZE') {
				$out['size'] = (int)$value;
			}
			elseif ($name === 'UID') {
				$out['uid'] = (int)$value;
			}
			elseif ($name === 'BODYSTRUCTURE' || $name === 'BODY') {
				$out['body'] = $this->parseImapBodyStructure($value);
			}
			elseif ($name === 'ENVELOPE') {
				$out['envelope'] = $this->parseImapEnvelope($value);
			}
			elseif ($name === 'FLAGS') {
				$out['flags'] = $value;
			}
			elseif ($name === 'BODY[HEADER.FIELDS') {
				// Skip closing bracket and list of headers
				$i += 2;
				$value = $tuples[$i];
				$value = str_replace("\r", '', $value);
				$value = preg_replace("/\n[ \t]/", '', $value);
				$out['headers'] = trim($value);
			}
		}

		if (isset($out['envelope'])) {
			$out = array_merge($out, $out['envelope']);
		}

		return (object) $out;
	}

	protected function parseImapAddresses(array $item): array
	{
		foreach ($item as &$address) {
			// personal name, [SMTP] at-domain-list (source route), mailbox name, and host name
			$address = (object) [
				'name'    => $address[0],
				'address' => $address[2] . '@' . $address[3],
				'user'    => $address[2],
				'domain'  => $address[3],
			];

			if ($address->name) {
				$address->full = sprintf('"%s" <%s>', $address->name, $address->address);
			}
			else {
				$address->full = $address->address;
			}
		}

		unset($address);

		return $item;
	}

	protected function parseImapBodyStructure(array $struct): stdClass
	{
		$body = (object) [
			'html'        => false,
			'text'        => false,
			'attachments' => [],
			'inlines'     => [],
			'structure'   => null,
		];

		$body->structure = $this->simplifyImapBodyStructure($struct, $body);

		return $body;
	}

	protected function simplifyImapBodyStructure(array $struct, stdClass &$body): stdClass
	{
		$part = new stdClass;

		// First item is a string: we are in a part
		// If it's a list, then we are in a multipart
		if (is_array($struct[0])) {
			$part->parts = [];

			for ($i = 0; $i < count($struct); $i++) {
				if (!is_array($struct[$i])) {
					break;
				}

				$part->parts[] = $struct[$i];
			}

			$part->multipart = strtolower($struct[$i]);
			$part->boundary = $struct[$i + 1][1];

			foreach ($part->parts as &$subpart) {
				$subpart = $this->simplifyImapBodyStructure($subpart, $body);

				if (isset($subpart->type)
					&& ($part->multipart === 'alternative' || $part->multipart === 'related')) {
					if ($subpart->type === 'text/html') {
						$body->html = true;
					}
					elseif ($subpart->type === 'text/plain') {
						$body->text = true;
					}
				}

				if (!empty($subpart->attachment)) {
					$body->attachments[] = $subpart;
				}
				elseif (!empty($subpart->inline)) {
					$body->inlines[] = $subpart;
				}
			}

			unset($subpart);
		}
		else {
			$part->type = strtolower($struct[0] . '/' . $struct[1]);
			$part->content_id = $struct[3] ?? null;
			$part->encoding = $struct[5] ?? null;
			$part->size = isset($struct[6]) ? (int) $struct[6] : null;
			$part->filename = null;

			$atype = strtolower($struct[8][0] ?? '') ?: null;
			$tuples = $struct[8][1] ?? null;
			$part->attachment = $atype === 'attachment';
			$part->inline = $atype === 'inline';

			// This might be charset or name
			if (($atype === 'inline' || $atype === 'attachment')
				&& is_array($tuples)) {

				for ($i = 0; $i < count($tuples); $i++) {
					if ($tuples[$i] !== 'filename') {
						continue;
					}

					$part->{$tuples[$i]} = $tuples[++$i];
				}
			}
		}

		return $part;
	}

	protected function parseImapEnvelope(array $item): array
	{
		static $keys = ['date', 'subject', 'from', 'sender', 'reply_to', 'to', 'cc', 'bcc', 'in_reply_to', 'message_id'];

		$envelope = array_combine($keys, $item);

		foreach ($envelope as $key => &$value) {
			if (is_array($value)) {
				$value = $this->parseImapAddresses($value);

				if ($key === 'from' || $key === 'sender') {
					$value = $value[0] ?? null;
				}
			}
			elseif ($key === 'message_id') {
				$value = trim((string) $value, '<>') ?: null;
			}
			elseif ($key === 'date') {
				$value = new \DateTime($value);
			}
		}

		unset($value);

		return $envelope;
	}

/*
	public function examine(string $folder): \stdClass
	{
		$r = $this->request(null, 'EXAMINE $folder');
		$r = explode("\n", $r);
		$flags = null;
		$total = null;
		$uidvalidity = null;
		$uidnext = null;

		foreach ($r as $line) {
			$line = trim($line);

			if (preg_match())
		}
	}
*/

	public function listFolders(): array
	{
		$r = $this->request('');
		$r = explode("\n", trim($r));
		$folders = [];

		foreach ($r as $line) {
			$line = trim($line);

			if (!preg_match('/^\* LIST \((.*?)\) "(.*?)" (".*?"|[^"]+?)$/', $line, $match)) {
				throw new \LogicException('Invalid LIST line: ' . $line);
			}

			$f = trim($match[3], '"');

			$folders[$f] = (object) [
				'name'      => mb_convert_encoding($f, 'UTF7-IMAP', 'UTF-8'),
				'flags'     => explode(' ', $match[1]),
				'separator' => $match[2],
			];
		}

		return $folders;
	}

	public function countUnseen(string $folder = 'INBOX'): int
	{
		$r = $this->request(null, sprintf('STATUS %s (UNSEEN)', $folder));

		if (!preg_match('/\(UNSEEN (\d+)\)$/', trim($r), $match)) {
			throw new \RuntimeException('Invalid response from IMAP server: ' . $r);
		}

		return (int) $match[1];
	}

	public function listUIDs(string $folder = 'INBOX', ?array $query = null): array
	{
		$query ??= ['ALL'];
		return $this->search($folder, $this->buildSearchQuery($query));
	}

	public function listMessages(string $folder = 'INBOX', ?array $search = null, array $headers = []): array
	{
		if (null === $search) {
			$uids = '1:*';
		}
		else {
			$uids = $this->listUIDs($folder, $search);

			if (!count($uids)) {
				return [];
			}

			$uids = implode(',', $uids);
		}

		// FULL: Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODY)
		$request = 'FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODYSTRUCTURE';

		if (count($headers)) {
			$request .= sprintf(' BODY.PEEK[HEADER.FIELDS (%s)]', implode(' ', $headers));
		}

		$request = sprintf('UID FETCH %s (%s)', $uids, $request);
		$r = $this->requestVerbose($folder, $request);

		if (!$r) {
			return [];
		}

		$split = preg_split('/(?:\)$)?^\* \d+ FETCH \(/m', substr(trim($r), 0, -1), -1, PREG_SPLIT_NO_EMPTY);

		// Remove last split which is just the AOK message
		if (count($split)) {
			$last = $split[count($split) - 1] ;
			$split[count($split) - 1] = substr($last, 0, strrpos($last, ")\r\n"));
		}

		$split = array_filter($split, 'trim');

		$out = [];

		foreach ($split as $str) {
			$out[] = $this->parseImapFetch($str);
		}

		return $out;
	}

	public function buildSearchQuery(array $params)
	{
		$out = [];

		foreach ($params as $key => $value) {
			if ($key === 'since') {
				$out[] = sprintf('SINCE %s', $value->format('d-M-Y'));
			}
			elseif (is_int($key)) {
				$out[] = $value;
			}
		}

		return implode(' ', $out);
	}

	public function iterateMessages(string $folder = 'INBOX', ?array $search = null): \Generator
	{
		foreach ($this->listUIDs($folder, $search) as $uid) {
			yield $this->fetchMessage($folder, $uid);
		}
	}

	public function iterateMessagesHeaders(string $folder = 'INBOX', ?array $search = null): \Generator
	{
		foreach ($this->listUIDs($folder, $search) as $uid) {
			yield $this->fetchHeaders($folder, $uid);
		}
	}

	public function search(string $folder, string $query): array
	{
		$query = 'UID SEARCH ' . $query;
		$r = $this->request($folder, $query);
		$r = trim($r);

		if (0 !== strpos($r, '* SEARCH')) {
			throw new \RuntimeException(sprintf('Invalid SEARCH: %s (%s)', $query, $r));
		}

		$r = substr($r, strlen('* SEARCH'));
		$r = trim($r);

		if (!$r) {
			return [];
		}

		return explode(' ', $r);
	}

	public function fetchHeaders(string $folder, int $uid): string
	{
		return $this->request(sprintf('%s/;UID=%d/;SECTION=HEADER', $folder, $uid));
	}

	public function fetchBody(string $folder, int $uid): string
	{
		return $this->request(sprintf('%s/;UID=%d/;SECTION=TEXT', $folder, $uid));
	}

	public function fetchMessage(string $folder, int $uid): string
	{
		return $this->request(sprintf('%s/;UID=%d', $folder, $uid));
	}

/*
	// Useless for now
	// see https://github.com/curl/curl/issues/18847
	public function buildFetchQuery(array $query): string
	{
		static $aliases = [
			//'body'      => 'BODY.PEEK[TEXT]',
			//'headers'   => 'BODY.PEEK[HEADER]',
			//'message'   => 'BODY.PEEK[]',
			//'subject'   => 'BODY.PEEK[HEADER.FIELDS (Subject)]',
			//'structure' => 'BODYSTRUCTURE.PEEK',
			'size'      => 'RFC822.SIZE',
			'date'      => 'INTERNALDATE',
			'envelope'  => 'ENVELOPE',
			'flags'     => 'FLAGS',
		];

		$headers = [];

		foreach ($query as &$value) {
			if (array_key_exists($value, $aliases)) {
				$value = $aliases[$value];
			}
			elseif (substr($value, 0, 2) === 'h:') {
				$headers[] = substr($value, 2);
				$value = null;
			}
		}

		unset($value);
		$query = array_filter($query);

		if (count($headers)) {
			$query[] = 'BODY.PEEK[HEADER.FIELDS (' . implode(' ', $headers) . ')]';
		}

		$out = implode(' ', $query);

		if (preg_match('/BODY\[|BODY\./', $out)) {
			// see https://github.com/curl/curl/issues/18847
			throw new \InvalidArgumentException('BODY arguments in FETCH don\'t work properly with curl');
		}

		return $out;
	}

	public function fetchMultiple(string $folder, array $uids): array
	{
		if (!count($uids)) {
			return [];
		}

		$cmd = sprintf('UID FETCH %s (%s)', implode(',', $uids), $this->buildFetchQuery($query));
		$r = $this->request($folder, $cmd);

		$r = explode("\n", $r);
		//TODO
		return [];
	}
*/

	public function delete(string $folder, int $uid): void
	{
		$this->setFlag($folder, $uid, 'Deleted');
		$this->expunge = true;
	}

	public function addFlag(string $folder, int $uid, string $flag): void
	{
		$this->request($folder, sprintf('UID STORE %d +FLAGS (%s)', $uid, $flag));
	}

	public function removeFlag(string $folder, int $uid, string $flag): void
	{
		$this->request($folder, sprintf('UID STORE %d -FLAGS (%s)', $uid, $flag));
	}

	public function move(string $folder, int $uid, string $target_folder): void
	{
		$this->request($folder, sprintf('UID MOVE %d %s', $uid, $target_folder));
	}

	protected function requestVerbose(?string $uri = null, ?string $request = null): string
	{
		try {
			$dh = fopen('php://memory', 'w');
			$this->opt(CURLOPT_VERBOSE, true);
			$this->opt(CURLOPT_STDERR, $dh);

			stream_get_contents($dh);
			$pos = ftell($dh);

			$this->request($uri, $request);

			fseek($dh, $pos, SEEK_SET);
			$out = '';
			$in_response = false;

			while (!feof($dh)) {
				$line = fgets($dh, 4096);

				if (!$in_response && substr($line, 0, 2) === '< ') {
					$in_response = true;
				}

				if ($in_response && substr($line, 0, 2) === '* ') {
					break;
				}
				elseif ($in_response) {
					$out .= substr($line, 2);
				}
			}

			fclose($dh);

			return $out;
		}
		finally {
			$this->opt(CURLOPT_STDERR, $this->log_pointer);

			if (!$this->log_pointer) {
				$this->opt(CURLOPT_VERBOSE, false);
			}
		}
	}

	/**
	 * Store a message on the IMAP server.
	 *
	 * If $folder is NULL, then the first folder flagged as \Sent will be used.
	 *
	 * @return null|int New message UID, if found
	 */
	public function append(string $message, ?string $folder = null): ?int
	{
		if (!$folder) {
			foreach ($this->listFolders() as $key => $folder) {
				if (in_array('Sent', $folder->flags)) {
					$folder = $key;
					break;
				}
			}

			if (!$folder) {
				throw new \LogicException('No folder was specified, and there is no folder flagged as \Sent');
			}
		}

		$i = 0;

		try {
			$this->opt(CURLOPT_BUFFERSIZE, strlen($message));
			$this->opt(CURLOPT_UPLOAD, true);
			$this->opt(CURLOPT_INFILESIZE, strlen($message));

			$this->opt(CURLOPT_READFUNCTION, function ($ch, $fh, $size) use ($message, &$i) {
				if ($i == 0 && $size >= strlen($message)) {
					return $message;
				}

				$str = substr($message, $i, $size);
				$i += strlen($str);
				return $str;
			});

			// Curl doesn't have an option to fetch new UID, so we use its verbose output
			$debug = $this->requestVerbose($folder);

			if (preg_match('/OK \[APPENDUID \d+ (\d+)\]/', $debug, $match)) {
				return (int)$match[1];
			}

			return null;
		}
		finally {
			$this->opt(CURLOPT_BUFFERSIZE, null);
			$this->opt(CURLOPT_UPLOAD, false);
			$this->opt(CURLOPT_INFILESIZE, -1);
			$this->opt(CURLOPT_READFUNCTION, null);
		}
	}
}
