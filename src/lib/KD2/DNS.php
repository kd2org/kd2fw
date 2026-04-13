<?php

namespace KD2;

class DNS
{
	static public function getRecordsFromDomainNS(string $type, string $record): array
	{
		$ns = dns_get_record($record, \DNS_NS);

		if (empty($ns) || empty($ns[0]['target'])) {
			return null;
		}

		$ns = $ns[0]['target'];

		return self::getRecordsFrom($ns, $type, $record);
	}

	/**
	 * Make a DNS a request to a custom nameserver, this is similar to dns_get_record, but allows you to query any nameserver
	 * Usage: dns_get_record_from('ns.server.tld', 'A', 'mydomain.tld');
	 * => ['42.42.42.42']
	 * @author bohwaz
	 * @see https://github.com/DaveRandom/LibDNS
	 * @see https://cabulous.medium.com/dns-message-how-to-read-query-and-response-message-cfebcb4fe817
	 */
	static public function getRecordsFrom(string $server, string $type, string $record, string $protocol = 'udp'): array
	{
		// Source: https://github.com/metaregistrar/php-dns-client/blob/master/DNS/dnsData/dnsTypes.php
		static $types = [
			1   => 'A',
			2   => 'NS',
			5   => 'CNAME',
			6   => 'SOA',
			12  => 'PTR',
			15  => 'MX',
			16  => 'TXT',
			28  => 'AAAA',
			255 => 'ANY',
		];

		$typeid = array_search($type, $types, true);

		if (!$typeid) {
			throw new \InvalidArgumentException('Invalid type');
		}

		$host = $protocol . '://' . $server;

		if (!$socket = @fsockopen($host, 53, $errno, $errstr, 10)) {
			throw new \RuntimeException('Failed to open socket to ' . $host);
		}

		//stream_set_chunk_size($socket, 0xffff);
		//stream_set_blocking($socket, false);

		$labels = explode('.', $record);
		$question_binary = '';

		foreach ($labels as $label) {
			$question_binary .= pack("C", strlen($label)); // size byte first
			$question_binary .= $label; // then the label
		}

		$question_binary .= pack("C", 0); // end it off

		$id = rand(1,255) | (rand(0,255)<<8);	// generate the ID

		// Set standard codes and flags
		$flags = (0x0100 & 0x0300) | 0x0020; // recursion & queryspecmask | authenticated data

		$opcode = 0x0000; // opcode

		// Build the header
		$header = "";
		$header .= pack("n", $id);
		$header .= pack("n", $opcode | $flags);
		$header .= pack("nnnn", 1, 0, 0, 0);
		$header .= $question_binary;
		$header .= pack("n", $typeid);
		$header .= pack("n", 0x0001); // internet class
		$headersize = strlen($header);
		$headersizebin = pack("n", $headersize);

		$request_size = fwrite($socket, $header, $headersize);
		$rawbuffer = fread($socket, 4096);
		fclose($socket);

		if (strlen($rawbuffer) < 12) {
			throw new \UnderflowException("DNS query return buffer too small");
		}

		$pos = 0;

		$read = function ($len) use (&$pos, $rawbuffer) {
			$out = substr($rawbuffer, $pos, $len);
			$pos += $len;
			return $out;
		};

		$read_name_pos = function ($offset_orig, $max_len=65536) use ($rawbuffer) {
			$out = [];
			$offset = $offset_orig;

			while (($len = ord(substr($rawbuffer, $offset, 1))) && $len > 0 && ($offset+$len < $offset_orig+$max_len ) ) {
				if ($len >= 64) {
					$offset = (($len & 0x3f) << 8) + ord(substr($rawbuffer, $offset + 1, 1));
					continue;
				}

				$out[] = substr($rawbuffer, $offset + 1, $len);
				$offset += $len + 1;
			}

			return $out;
		};

		$read_name = function() use (&$read, $read_name_pos) {
			$out = [];

			while (($len = ord($read(1))) && $len > 0) {
				if ($len >= 64) {
					$offset = (($len & 0x3f) << 8) + ord($read(1));
					$out = array_merge($out, $read_name_pos($offset));
					break;
				}
				else {
					$out[] = $read($len);
				}
			}

			return implode('.', $out);
		};

		$header = unpack("nid/nfields/nqdcount/nancount/nnscount/narcount", $read(12));
		$fields = $header['fields'];

		$flags = new \stdClass;
		$flags->rcode = $fields & 0xf;
		$flags->ra = (($fields >> 7) & 1) === 1;
		$flags->rd = (($fields >> 8) & 1) === 1;
		$flags->tc = (($fields >> 9) & 1) === 1;
		$flags->aa = (($fields >> 10) & 1) === 1;
		$flags->opcode = ($fields >> 11) & 0xf;
		$flags->qr = (($fields >> 15) & 1) === 1;

		if ($flags->tc) {
			throw new \OverflowException('The DNS server returned a truncated result for a UDP query');
		}

		// No answers
		if (!$header['ancount']) {
			return [];
		}

		$is_authorative = $flags->aa;

		// Question section
		if ($header['qdcount']) {
			// Skip name
			$read_name();

			// skip question part
			$pos += 4; // 4 => QTYPE + QCLASS
		}

		$responses = [];

		for ($a = 0; $a < $header['ancount']; $a++) {
			$read_name(); // Skip name
			$ans_header = unpack("ntype/nclass/Nttl/nlength", $read(10));

			$t = $types[$ans_header['type']] ?? null;

			if ($type !== 'ANY' && $t !== $type) {
				// Skip type that was not requested
				$t = null;
			}

			switch ($t) {
				case 'A':
					$responses[] = implode(".", unpack("Ca/Cb/Cc/Cd", $read(4)));
					break;
				case 'AAAA':
					$responses[] = implode(':', unpack("H4a/H4b/H4c/H4d/H4e/H4f/H4g/H4h", $read(16)));
					break;
				case 'MX':
					$prio = unpack('nprio', $read(2)); // priority
					//$responses[$prio['prio']] = $read_name();
					// Don't use priority as key, as there might be multiple responses of the same priority
					$responses[] = $read_name();
					break;
				case 'NS':
				case 'CNAME':
				case 'PTR':
					$responses[] = $read_name();
					break;
				case 'TXT':
					$data = '';
					for ($strCount = 0; strlen($data) + (1 + $strCount) < $ans_header['length']; $strCount++) {
						$data .= $read(ord($read(1)));
					}
					$responses[] = $data;
					break;
				default:
					// Skip
					$read($ans_header['length']);
					break;
			}
		}

		return $responses;
	}
}
