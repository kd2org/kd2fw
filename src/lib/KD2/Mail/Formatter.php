<?php
/*
  Part of the KD2 framework collection of tools: http://dev.kd2.org/

  Copyright (c) 2001+ BohwaZ <http://bohwaz.net/>
  See provided license file for details.
*/

namespace KD2\Mail;

class Formatter
{
	const SIGNATURE_REGEXP = '!(?:^--+$|^—$|^-\w)|(?:^Sent from (?:my|Mail) (?:\s*\w+){1,4}$|^Envoyé depuis)|(?:^={30,}$)$!';
	const QUOTE_HEADER_REGEXP = '!^(?:On|Le|El|Il|Op|W|Den|Am)\s+.*'
		. '(?:wrote|écrit|escribió|ha escrit|scritto|schreef|geschreven|pisze|napisał(?:\(a\))?|skrev|schrieb)\s*:$!';
	const QUOTE_SEPARATOR_REGEXP = '!^(?:____+|----+\s*Message[^-\s]+\s*----+)$!';

	protected string $message;
	protected ?string $text;
	protected ?string $signature = null;
	protected ?string $citation = null;

	public function __construct(string $message)
	{
		$this->message = $message;
	}

	public function getCitation(): ?string
	{
		$this->extractCitationAndSignature();
		return $this->citation;
	}

	public function getSignature(): ?string
	{
		$this->extractCitationAndSignature();
		return $this->signature;
	}

	public function getMessageWithoutCitationOrSignature(): string
	{
		$this->extractCitationAndSignature();
		return $this->text;
	}

	public function hasCitation(): bool
	{
		$this->extractCitationAndSignature();
		return $this->citation !== null;
	}

	public function hasSignature(): bool
	{
		$this->extractCitationAndSignature();
		return $this->signature !== null;
	}

	protected function extractCitationAndSignature()
	{
		if (isset($this->text)) {
			return;
		}

		$text = str_replace(["\r\n", "\r"], "\n", $this->message);

		$signature_line = null;
		$citation_line = null;
		$quoted_line = false;

		$text = explode("\n", $text);
		$next = null;
		$last = count($text) - 1;

		for ($i = $last; $i >= 0; $i--) {
			$line = trim($text[$i]);

			// Remove non-breaking space
			$line = str_replace("\xc2\xa0", ' ', $line);

			if (null === $signature_line
				&& !$quoted_line
				&& preg_match(self::SIGNATURE_REGEXP, $line)) {
				$signature_line = $i;
				continue;
			}
			elseif (preg_match(self::QUOTE_SEPARATOR_REGEXP, $line)) {
				$citation_line = $i;
				break;
			}
			elseif (preg_match(self::QUOTE_HEADER_REGEXP, $line)) {
				$citation_line = $i;
				break;
			}
			elseif ($next !== null
				&& preg_match(self::QUOTE_HEADER_REGEXP, $line . $next)) {
				$citation_line = $i;
				break;
			}
			elseif (substr($line, 0, 1) === '>') {
				$citation_line = $i;
				$quoted_line = true;
				continue;
			}
			elseif ($quoted_line && $next) {
				break;
			}

			$next = $line;
		}

		if ($signature_line) {
			$this->signature = implode("\n", array_slice($text, $signature_line));
			$text = array_slice($text, 0, $signature_line);
		}

		if ($citation_line) {
			$this->citation = implode("\n", array_slice($text, $citation_line));
			$text = array_slice($text, 0, $citation_line);
		}

		$this->text = implode("\n", $text);
	}
}
