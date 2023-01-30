<?php
/**
 * @author BohwaZ <https://bohwaz.net>
 * @license WTFPL
 *
 * Fossil Mailer
 * -------------
 *
 * Requires PHP 7.4+ and the 'fossil' binary in the PATH.
 * Suggested extensions: mbstring, curl.
 *
 * Just like svnmailer, this tool monitors the changes of a Fossil
 * repository and sends an email for each change.
 *
 * This script can currently monitor and send changes for:
 * - check-ins (commits), with text and HTML diff
 * - tickets changes (and tickets attachments), with comments
 * - wiki page changes (and wiki attachments), with HTML diff
 *
 * You can can choose to receive (or not) each type in the config file.
 *
 * This script requires the Fossil repo to be accessible via HTTP, as
 * the Fossil CLI tool is missing HTML/text diff outside of an open repo.
 * It is also missing wiki diff in CLI.
 *
 * Your Fossil repository can be private, see example config for details.
 *
 * Once you have created your config file, you can invoke this tool via:
 *
 * php fossil_mailer.php /path/to/config/file.ini
 *
 * You should then put that in a crontab, something like every 5 minutes :)
 */

const EXAMPLE_CONFIG = <<<EOF
; Note: comments start with a semi-colon (INI-style)
; Path to the Fossil repository file you want to monitor
repository="/home/fossil/myrepo.fossil"

; URL of the Fossil repository
; If the repo is private (anonymous visitors can't see diffs or artifacts),
; you'll need to create a read-only user and provide a username/password here
; like this: "https://user:password@fossil.project.tld/"
url="https://fossil.project.tld/"

; Location of a text file that will contain the hash of the last
; change processed by this script. If this file doesn't exist,
; then the last change in the timeline will be sent.
last_change_file="/home/fossil/myrepo.last.monitor"

; Email address used as the 'From'
from="dev@project.tld"

; Email address used as the 'To'
to="changes@project.tld"

; Enable or disable types to monitor here, by setting them to 'true' or 'false'
ticket=true
checkin=true
wiki=true
EOF;

$argv = $_SERVER['argv'];

if (empty($argv[1]) || !is_readable($argv[1])) {
	printf("Usage: %s CONFIG_FILE", $argv[0]) . PHP_EOL;
	echo "\n\nWhere CONFIG_FILE is the path to the configuration file.\n\nConfiguration example:\n\n";
	echo EXAMPLE_CONFIG . "\n\n";
	exit(1);
}

$config = (object) parse_ini_file($argv[1], false);

$f = new FossilMonitor($config->repository, $config->url);
$f->from_email = $config->from;
$f->to = $config->to;

$since = file_exists($config->last_change_file) ? trim(file_get_contents($config->last_change_file)) : null;
$types = [];

if (!empty($config->ticket)) {
	$types[] = $f::TYPE_TICKET;
}

if (!empty($config->checkin)) {
	$types[] = $f::TYPE_CHECKIN;
}

if (!empty($config->wiki)) {
	$types[] = $f::TYPE_WIKI;
}

$last = $f->report($since, $since ? 100 : 1, $types);

if ($last) {
	file_put_contents($config->last_change_file, $last);
}

class FossilMonitor
{
	const TYPE_CHECKIN = 'ci';
	const TYPE_TICKET = 't';
	const TYPE_WIKI = 'w';
	// TODO:
	//const TYPE_FORUM = 'f';
	//const TYPE_TECH_NOTES = 'e';

	protected string $repo;
	protected string $url;
	protected bool $json;
	public string $from_email = 'fossil@localhost';
	public string $to;

	public function __construct(string $repo, string $url)
	{
		$this->repo = $repo;
		$this->url = rtrim($url, '/') . '/';
		system('fossil json version > /dev/null 2>&1', $r);
		$this->json = $r == 0 ? true : false;
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

			if ($html) {
				$msg.= sprintf("--%s\r\n", $boundary);
				$msg.= "Content-Type: text/html; charset=\"utf-8\"\r\n";
				$msg.= "Content-Transfer-Encoding: 8bit\r\n\r\n";
				$msg.= $html . "\r\n\r\n";
			}

			foreach ($attach as $name => $content) {
				$msg.= sprintf("--%s\r\n", $boundary);
				$msg.= sprintf("Content-Type: text/plain; charset=\"utf-8\"; name=\"%s\"\r\n", $name);
				$msg.= "Content-Disposition: attachment\r\n";
				$msg.= "Content-Transfer-Encoding: 8bit\r\n\r\n";
				$msg.= $content . "\r\n\r\n";
			}

			$msg.= sprintf("--%s--", $boundary);
		}
		else {
			$header .= "Content-Type: text/plain; charset=utf-8\r\n";
		}

		$header.= "\r\n";

		mail($to, $subject, $msg ?? $text, $header);
	}

	protected function exec(string $command, string $args): string
	{
		$cmd = 'fossil ' . $command . ' -R ' . escapeshellarg($this->repo) . ' ' . $args;

		$r = shell_exec($cmd);

		if (null === $r) {
			throw new \RuntimeException('Command failed: ' . $cmd);
		}

		return $r;
	}

	public function timeline(string $type, int $limit = 200, string $since = null): array
	{
		$options = "-F '%h;%H;%a;%d;%b;%t;%p;%c' "
			. sprintf('-t %s -v -n %d', $type, $limit);

		if ($since) {
			$options = sprintf('after %s %s', escapeshellarg($since), $options);
		}

		$tl = $this->exec('timeline', $options);

		$tl = explode("\n", $tl);

		$out = [];
		$current = null;

		foreach ($tl as $line) {
			if (substr($line, 0, 2) == '--' || substr($line, 0, 3) == '+++') {
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
		else {
			//var_dump($r); exit;
		}

		return $out;
	}


	public function ticketChanges(string $uuid): string
	{
		static $tags = ['status', 'type', 'priority', 'severity', 'foundin', 'resolution', 'title', 'icomment'];

		// https://fossil-scm.org/home/doc/trunk/www/fileformat.wiki#tktchng
		$artifact = $this->exec('artifact', escapeshellarg($uuid));
		$artifact = explode("\n", trim($artifact));
		$user = null;
		$changes = [];

		foreach ($artifact as $line) {
			$type = strtok($line, ' ');

			if ($type == 'U') {
				$user = strtok(false);
				continue;
			}

			if ($type != 'J') {
				continue;
			}

			$key = strtok(' ');
			$value = strtok(false);

			if (in_array($key, $tags)) {
				$changes[$key] = $this->decodeArtifactString($value);
			}
		}

		$out = '';

		if (isset($changes['title'])) {
			$len = function_exists('mb_strlen') ? mb_strlen($changes['title']) : strlen($changes['title']);
			$out .= $changes['title'] . "\n" . str_repeat('=', $len) . "\n\n";
			unset($changes['title']);
		}

		if (isset($changes['icomment'])) {
			$out .= $changes['icomment'] . "\n\n" . str_repeat('-', 70) . "\n\n";
			unset($changes['icomment']);
		}

		$out .= 'Author: ' . $user . "\n\n";

		foreach ($changes as $key => $value) {
			$out .= sprintf("%s: %s\n", $key, $value);
		}

		return $out;
	}

	protected function decodeArtifactString(string $str): string
	{
		return strtr($str, ['\\s' => ' ', '\\n' => "\n", '\\\\' => '\\', '\\r' => '', '\\t' => "\t"]);
	}

	public function getReport(array $types = [self::TYPE_CHECKIN, self::TYPE_TICKET, self::TYPE_WIKI], string $since = null, int $limit = 100)
	{
		$timeline = [];

		foreach ($types as $type) {
			$timeline = array_merge($timeline, $this->timeline($type, $limit * 2, $since));
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

			//$attach[$item->short_hash . '.patch'] = $diff['text'];
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
			$msg .= "\n\n";
			$msg .= str_repeat("-", 70);
			$msg .= "\n\n";

			$msg .= $this->ticketChanges($item->hash);
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
