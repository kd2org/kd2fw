<?php

$argv = $_SERVER['argv'];

if (empty($argv[1]) || !is_readable($argv[1])) {
	printf("Usage: %s CONFIG_FILE", $argv[0]) . PHP_EOL;
	echo "Where CONFIG_FILE is the path to the configuration file.\n\nConfiguration example:\n\n";

	echo <<<EOF
repository="/home/fossil/myrepo.fossil"
url="https://fossil.project.tld/"
last_change_file="/home/fossil/myrepo.last.monitor"
from="dev@project.tld"
to="changes@project.tld"
EOF;
	echo "\n\n";
	exit(1);
}

$config = (object) parse_ini_file($argv[1], false);

$f = new FossilMonitor($config->repository, $config->url);
$f->from_email = $config->from;
$f->to = $config->to;

$since = file_exists($config->last_change_file) ? trim(file_get_contents($config->last_change_file)) : null;

$last = $f->report($since, $since ? 100 : 10);

file_put_contents($config->last_change_file, $last);

/**
 * This tool is useful to monitor a Fossil repository for changes,
 * and sends the diffs by email.
 */
class FossilMonitor
{
	const TYPE_CHECKIN = 'ci';
	const TYPE_TICKET = 't';
	const TYPE_WIKI = 'w';
	//const TYPE_FORUM = 'f';
	//const TYPE_TECH_NOTES = 'e';

	protected string $repo;
	protected string $url;
	public string $from_email = 'fossil@localhost';
	public string $to;

	public function __construct(string $repo, string $url)
	{
		$this->repo = $repo;
		$this->url = rtrim($url, '/') . '/';
		$this->db = new \SQLite3($repo, \SQLITE3_OPEN_READONLY);
	}

	protected function html(string $title, string $content): string
	{
		$url = parse_url($this->url, PHP_URL_SCHEME) . '://' . parse_url($this->url, PHP_URL_HOST) . '/';

		$content = str_replace('href="/', 'href="' . $url, $content);
		$content = str_replace('href=\'/', 'href=\'' . $url, $content);

		return sprintf('<html><head><style type="text/css">
			ins { background: #a0e4b2; text-decoration: none; font-weight: bold; }
			del { background: #ffc0c0; text-decoration: none; font-weight: bold; }
			</style></head>
			<body><h2>%s</h2>%s</body></html>', htmlspecialchars($title), $content);
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
			curl_close($c);
			return $a;
		}

		$ctx = stream_context_create(['http' => [
			'timeout' => 5,
		]]);

		return @file_get_contents($url, false, $ctx);
	}

	public function email(string $from, string $to, string $subject, string $text, ?string $html = null, array $headers = [], array $attach = []): void
	{
		$msgid = sha1(random_bytes(10)) . '@' . gethostname();

		$header = sprintf("From: %s\r\nIn-Reply-To: <%s>\r\nMessage-Id: <%s>\r\n", $from, $msgid, $msgid);

		foreach ($headers as $key => $value) {
			$header .= sprintf("%s: %s\r\n", $key, $value);
		}

		if ($html || count($attach)) {
			$boundary = sprintf('-----=%s', md5($msgid));
			$header.= sprintf("MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"%s\"\r\n", $boundary);

			$msg = "This message contains multiple MIME parts.\r\n\r\n";
			$msg.= sprintf("--%s\r\n", $boundary);
			$msg.= "Content-Type: text/plain; charset=\"utf-8\"\r\n";
			$msg.= "Content-Transfer-Encoding: 8bit\r\n\r\n";
			$msg.= $text . "\r\n\r\n";

			foreach ($attach as $name => $content) {
				$msg.= sprintf("--%s\r\n", $boundary);
				$msg.= sprintf("Content-Type: text/plain; charset=\"utf-8\"; name=\"%s\"\r\n", $name);
				$msg.= "Content-Disposition: attachment\r\n";
				$msg.= "Content-Transfer-Encoding: 8bit\r\n\r\n";
				$msg.= $content . "\r\n\r\n";
			}

			if ($html) {
				$msg.= sprintf("--%s\r\n", $boundary);
				$msg.= "Content-Type: text/html; charset=\"utf-8\"\r\n";
				$msg.= "Content-Transfer-Encoding: 8bit\r\n\r\n";
				$msg.= $html . "\r\n\r\n";
			}

			$msg.= sprintf("--%s--", $boundary);
		}

		$header.= "\r\n";

		mail($to, $subject, $msg ?? $text, $header);
	}

	protected function exec(string $command, string $args): string
	{
		return shell_exec('fossil ' . $command . ' -R ' . escapeshellarg($this->repo) . ' ' . $args);
	}

	public function timeline(string $type, int $limit = 200): array
	{
		$tl = $this->exec('timeline', "-F '%h;%H;%a;%d;%b;%t;%p;%c' -t " . $type . " -v -n " . (int)$limit);

		$tl = explode("\n", $tl);

		$out = [];
		$current = null;

		foreach ($tl as $line) {
			if (substr($line, 0, 2) == '--') {
				break;
			}

			if (!ctype_alnum(substr($line, 0, 1))) {
				if (isset($out[$current]->files)) {
					$out[$current]->files .= trim($line) . "\n";
				}
			}
			else {
				$data = explode(";", $line, 8);
				$current = $data[0];

				$out[$current] = (object) [
					'type'       => $type,
					'short_hash' => $data[0],
					'hash'       => $data[1],
					'author'     => $data[2],
					'date'       => new \DateTime($data[3]),
					'branch'     => $data[4],
					'tags'       => $data[5],
					'phase'      => $data[6],
					'comment'    => $data[7],
					'files'      => '',
				];
			}
		}

		return $out;
	}

	public function diff(string $comment, string $hash): array
	{
		$r = $this->http($this->url . 'info/' . $hash . '?diff=1');
		$out = ['html' => $r];

		if (preg_match('!<div[^>]*sectionmenu.*?</div>(.*?)<script!is', $r, $match)) {
			$out['html'] = $this->html($comment, $match[1]);
		}

		if (preg_match('!href="/[^/]+?/(vpatch\?from=.*?)"!', $r, $match)) {
			$out['text'] = $this->http($this->url . html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401));
		}

		return $out;
	}

	public function ticketHistory(string $id): array
	{
		$tagid = $this->db->querySingle(sprintf('SELECT tagid FROM tag WHERE tagname GLOB \'tkt-%s*\';', $this->db->escapeString($id)));

		if (!$tagid) {
			throw new \LogicException('Ticket not found: ' . $id);
		}

		$sql = sprintf('
			SELECT datetime(mtime,toLocal()), objid, NULL, NULL, NULL
			  FROM event, blob
			  WHERE objid IN (SELECT rid FROM tagxref WHERE tagid=%d)
				AND blob.rid=event.objid
			  UNION
			  SELECT datetime(mtime,toLocal()), attachid, filename,
					src, user
			  FROM attachment, blob
			  WHERE target=(SELECT substr(tagname,5) FROM tag WHERE tagid=%d)
				AND blob.rid=attachid
			  ORDER BY 1 DESC', $tagid, $tagid);

		$out = [];

		while ($row = $this->db->query($sql)->fetchArray(\SQLITE3_NUM)) {
			list($date, $obj_id, $file_name, $src, $user) = $row;
		}
	}

	public function getReport(array $types = [self::TYPE_CHECKIN, self::TYPE_TICKET, self::TYPE_WIKI], string $since = null, int $limit = 100)
	{
		$timeline = [];

		foreach ($types as $type) {
			$timeline = array_merge($timeline, $this->timeline($type, $limit * 2));
		}

		// Sort by most recent to oldest
		uasort($timeline, fn ($a, $b) => $a->date == $b->date ? 0 : ($a->date > $b->date ? -1 : 1));

		$items = [];

		foreach ($timeline as $item) {
			if ($item->hash == $since || $item->short_hash == $since) {
				break;
			}

			$items[$item->short_hash] = $item;
		}

		if (!count($items)) {
			return null;
		}

		return $items;
	}

	public function sendReport(\stdClass $item)
	{
		$from = sprintf('"%s" <%s>', $item->author, $this->from_email);
		$comment = $item->comment;
		$html = null;
		$attach = [];

		// Replace wiki tags
		$comment = preg_replace_callback('/\[(.*?)(?:\|(.*?))?\]/', fn($m) => '"' . ($m[2] ?? $m[1]) . '"', $comment);

		if ($item->type == self::TYPE_CHECKIN) {
			$subject = sprintf('[%s] %s', $item->branch, $comment);
			$diff = $this->diff($comment, $item->hash);

			$msg = $comment;
			$msg .= "\n\n";
			$msg .= $this->url . 'info/' . $item->short_hash;
			$msg .= "\n\n";
			$msg .= str_repeat("-", 70);
			$msg .= "\n\n";
			$msg .= $diff['text'];

			$html = $diff['html'];
			$attach[$item->short_hash . '.patch'] = $diff['text'];
		}
		elseif ($item->type == self::TYPE_WIKI) {
			$t = substr($comment, 0, 1);
			$n = substr($comment, 1);

			if ($t == '+') {
				$comment = sprintf('Create wiki page "%s"', $n);
			}
			elseif ($t == '-') {
				$comment = sprintf('Delete wiki page "%s"', $n);
			}
			elseif ($t == ':') {
				$comment = sprintf('Edit wiki page "%s"', $n);
			}
			else {
				// Attachment
				$t = null;
				$comment = $item->comment;
			}

			$subject = sprintf('[wiki] %s', $comment);

			$msg = strip_tags($comment);
			$msg .= "\n\n";

			if (!$t && preg_match('/\[(\/artifact\/.*?)\|/', $item->comment, $match)) {
				// Attachment
				$msg .= $this->url . ltrim($match[1], '/');
			}
			else {
				$msg .= 'Diff: ' . $this->url . 'wdiff?id=' . $item->short_hash;
				$msg .= "\n\n";
				$msg .= 'Page: ' . $this->url . 'wiki?name=' . rawurlencode($n);
			}

			if ($t == ':') {
				$r = $this->http($this->url . 'wdiff?id=' . $item->short_hash);

				if (preg_match('!<table[^>]+class=[\'\"][^\'\"]*diff[^>]*>(.*?)</table>!is', $r, $match)) {
					$html = $this->html($comment, $match[0]);
				}
			}
		}
		elseif ($item->type == self::TYPE_TICKET) {
			$change = 'Edit';
			$label = 'ticket';
			$ticket_id = null;

			if (preg_match('/\[(.*?)(?:\|(.*?))?\]/', $item->comment, $match)) {
				$ticket_id = $match[1];
			}

			if (preg_match('/^(\w+)\s+ticket/i', $comment, $match)) {
				$change = $match[1];
			}

			if (preg_match('!<i>(.*?)</i>!', $comment, $match)) {
				$label = html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
			}

			$subject = sprintf('[ticket] %s: %s', $change, $label);

			$msg = html_entity_decode(strip_tags($comment), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401);
			$msg .= "\n\n";
			$msg .= $this->url . 'info/' . $ticket_id;
			//$msg .= "\n\n";
			//$msg .= str_repeat("=", 70);
			//$msg .= "\n\n";

			if ($ticket_id) {
				// TODO
				//$msg .= $this->ticketHistory($ticket_id);
			}
		}

		$this->email($from, $this->to, $subject, $msg, $html, ['Date' => $item->date->format(\DATE_RFC822)], $attach);
	}

	public function report(string $since = null, int $limit = 100, array $types = [self::TYPE_CHECKIN, self::TYPE_TICKET, self::TYPE_WIKI])
	{
		if (!$this->to) {
			throw new \LogicException('"to" parameter is not set');
		}

		$report = $this->getReport($types, $since, $limit);

		if (!$report) {
			return null;
		}

		$hash = null;

		foreach ($report as $item) {
			if (!$hash) {
				$hash = $item->hash;
			}

			$this->sendReport($item);
		}

		return $hash;
	}
}
