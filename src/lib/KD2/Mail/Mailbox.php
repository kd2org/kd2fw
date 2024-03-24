<?php

namespace KD2\Mail;

/*
$m = new Mailbox('localhost', Mailbox::IMAP);
$m->setLogin('test', 'test');
//var_dump($m->listMessages());
//var_dump($m->append(file_get_contents('/tmp/a.eml'), 'INBOX'));
//var_dump($m->listMessages());
//var_dump($m->fetchHeaders('INBOX', 1));
var_dump($m->move('INBOX', 2, 'Sent'));
*/

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

	public function setLogin(string $username, string $password): void
	{
		$this->init();
		curl_setopt($this->curl, CURLOPT_USERNAME, $username);
		curl_setopt($this->curl, CURLOPT_PASSWORD, $password);
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

	protected function parseFlags(string $flags): array
	{
		$flags = explode(' ', $flags);
		$flags = array_map(fn($a) => substr($a, 1), $flags);
		return $flags;
	}

	protected function parseImapAddresses(string $str): array
	{
		// personal name, [SMTP] at-domain-list (source route), mailbox name, and host name
		preg_match_all('/\((".*?"|NIL) (".*?"|NIL) (".*?"|NIL) (".*?"|NIL)\)/', $str, $list, PREG_SET_ORDER);

		$out = [];

		foreach ($list as $item) {
			array_walk($item, fn (&$a) => $a = ($a === 'NIL') ? null : substr($a, 1, -1));

			$address = (object) [
				'name' => $item[1],
				'address' => $item[3] . '@' . $item[4],
				'domain' => $item[4],
				'user' => $item[3],
			];

			if ($address->name) {
				$address->full = sprintf('"%s" <%s>', $address->name, $address->address);
			}
			else {
				$address->full = $address->address;
			}

			$out[] = $address;
		}

		return $out;
	}

	protected function parseImapEnvelope(string $str): array
	{
		static $keys = ['date', 'subject', 'from', 'sender', 'reply_to', 'to', 'cc', 'bcc', 'in_reply_to', 'message_id'];

		preg_match_all('/(?<=\s|^)(?:".*?"|NIL|\(.*?\))(?=\s|$)/', $str, $envelope, PREG_PATTERN_ORDER);

		$envelope = $envelope[0];
		$envelope = array_combine($keys, $envelope);

		array_walk($envelope, function (&$a) {
			if ($a === 'NIL') {
				$a = null;
			}
			elseif (substr($a, 0, 1) == '"') {
				$a = substr($a, 1, -1);
			}
			else {
				$a = $this->parseImapAddresses(substr($a, 1, -1));
			}
		});

		$envelope['message_id'] = trim($envelope['message_id'], '<>');
		$envelope['from'] = $envelope['from'][0] ?? null;
		$envelope['sender'] = $envelope['sender'][0] ?? null;
		$envelope['date'] = \DateTime::createFromFormat(\DateTime::RFC2822, $envelope['date']);

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
				'name' => mb_convert_encoding($f, 'UTF7-IMAP', 'UTF-8'),
				'flags' => $this->parseFlags($match[1]),
				'separator' => $match[2],
			];
		}

		return $folders;
	}

	public function countUnread(string $folder = 'INBOX'): int
	{
		$r = $this->request(null, sprintf('STATUS %s (UNSEEN)', $folder));

		if (!preg_match('/\(UNSEEN (\d+)\)$/', trim($r), $match)) {
			throw new \RuntimeException('Invalid response from IMAP server: ' . $r);
		}

		return (int) $match[1];
	}

	public function listUIDs(string $folder = 'INBOX'): array
	{
		return $this->search($folder, 'ALL');
	}

	public function listUnseenUIDs(string $folder = 'INBOX'): array
	{
		$out = [];

		foreach ($this->search($folder, 'UNSEEN') as $uid) {
			$out[$uid] = $this->fetch($folder, $uid);
		}

		return $out;
	}

	public function listMessages(string $folder = 'INBOX'): array
	{
		$out = [];
		$r = $this->request($folder, 'FETCH 1:* FULL');
		$r = trim($r);

		if (!$r) {
			return [];
		}

		$r = explode("\n", $r);

		foreach ($r as $line) {
			$line = trim($line);

			if (!preg_match('/^\* (\d+) FETCH \(FLAGS \((.*?)\) INTERNALDATE "(.*?)" RFC822.SIZE (\d+) ENVELOPE \((.*?)\) BODY \((.*?)\)\)$/', $line, $match)) {
				continue;
			}

			$envelope = $this->parseImapEnvelope($match[5]);

			$msg = (object) array_merge([
				'uid' => (int) $match[1],
				'flags' => $this->parseFlags($match[2]),
				'internal_date' => \DateTime::createFromFormat(\DateTime::RFC822, $match[3]),
				'size' => (int) $match[4]
			], $envelope);

			$out[] = $msg;
		}

		return $out;
	}

	public function iterateMessages(string $folder = 'INBOX'): \Generator
	{
		foreach ($this->listUIDs($folder) as $uid) {
			yield $uid => $this->fetch($folder, $uid);
		}
	}

	public function iterateNewMessages(string $folder = 'INBOX'): \Generator
	{
		foreach ($this->listUnseenUIDs($folder) as $uid) {
			yield $uid => $this->fetch($folder, $uid);
		}
	}

	public function search(string $folder, string $query): array
	{
		$r = $this->request($folder . '?' . $query);
		$r = trim($r);

		if (0 !== strpos($r, '* SEARCH')) {
			throw new \RuntimeException('Invalid SEARCH');
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

	public function fetch(string $folder, int $uid): string
	{
		return $this->request(sprintf('%s/;UID=%d', $folder, $uid));
	}

	public function delete(string $folder, int $uid): void
	{
		$this->setFlag($folder, $uid, 'Deleted');
		$this->expunge = true;
	}

	public function addFlag(string $folder, int $uid, string $flag): void
	{
		$this->request($folder, sprintf('UID STORE %d +FLAGS (\\%s)', $uid, $flag));
	}

	public function removeFlag(string $folder, int $uid, string $flag): void
	{
		$this->request($folder, sprintf('UID STORE %d -FLAGS (\\%s)', $uid, $flag));
	}

	public function move(string $folder, int $uid, string $target_folder): void
	{
		$this->request($folder, sprintf('UID MOVE %d %s', $uid, $target_folder));
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
		$dh = fopen('php://memory', 'w');

		try {
			$this->opt(CURLOPT_VERBOSE, true);
			$this->opt(CURLOPT_STDERR, $dh);
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

			$this->request($folder);

			$this->opt(CURLOPT_STDERR, null);

			// Curl doesn't have an option to fetch new UID, so we use its verbose output
			rewind($dh);
			$debug = stream_get_contents($dh);
			fclose($dh);

			if (preg_match('/OK \[APPENDUID \d+ (\d+)\]/', $debug, $match)) {
				return (int)$match[1];
			}

			return null;
		}
		finally {
			$this->opt(CURLOPT_VERBOSE, false);
			$this->opt(CURLOPT_BUFFERSIZE, null);
			$this->opt(CURLOPT_UPLOAD, false);
			$this->opt(CURLOPT_INFILESIZE, -1);
			$this->opt(CURLOPT_READFUNCTION, null);
		}
	}
}
