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
	const QUOTE_HEADER_REGEXP = '!^(?:On|Le|El|Il|Op|W|Den|Am|\d+ [a-zûé]+)\s+.*'
		. '(?:wrote|écrit|escribió|ha escrit|scritto|schreef|geschreven|pisze|napisał(?:\(a\))?|skrev|schrieb)\s*:$!i';
	const QUOTE_SEPARATOR_REGEXP = '/^\s*(?:____+'
		. '|----+\s*Message[^-]+\s*----+'
		. '|-------+)\s*$'
		. '|^(?:De|From)[ ]?:.*?@.*?[\]>]\s*$'
		. '|^[ ]*Le\s.{1,800}\sa\sécrit\s?:[ ]*$/sm';

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

		// Remove non-breaking space
		$text = str_replace("\xc2\xa0", ' ', $text);

		if (preg_match(self::QUOTE_SEPARATOR_REGEXP, $text, $match, PREG_OFFSET_CAPTURE)) {
			$pos = $match[0][1];
			$this->citation = substr($text, $pos);
			$this->text = substr($text, 0, $pos);
			return;
		}

		$signature_line = null;
		$citation_line = null;

		$text = explode("\n", $text);
		$last = count($text) - 1;

		for ($i = $last; $i >= 0; $i--) {
			$line = ltrim($text[$i]);

			if (empty($line)) {
				continue;
			}
			elseif (substr($line, 0, 1) === '>') {
				$citation_line = $i;
			}
			else {
				break;
			}
		}

		// Remove last quote from text, and let's try to find the quote header
		if ($citation_line) {
			$this->citation = implode("\n", array_slice($text, $citation_line));
			$text = array_slice($text, 0, $citation_line);
		}

		$last = count($text) - 1;
		$citation_line = null;
		$next = null;

		for ($i = $last; $i >= 0; $i--) {
			$line = trim($text[$i]);

			if (empty($line)) {
				continue;
			}

			if (null === $signature_line
				&& preg_match(self::SIGNATURE_REGEXP, $line)) {
				$signature_line = $i;
				continue;
			}
			elseif (preg_match(self::QUOTE_HEADER_REGEXP, $line)) {
				$citation_line = $i - 1;
				break;
			}
			elseif ($next && preg_match(self::QUOTE_HEADER_REGEXP, $line . $next)) {
				$citation_line = $i;
				break;
			}
			elseif (null === $next) {
				$next = $line;
				continue;
			}
			else {
				break;
			}
		}

		if ($signature_line) {
			$this->signature = implode("\n", array_slice($text, $signature_line));
			$text = array_slice($text, 0, $signature_line);
		}

		if ($citation_line) {
			$this->citation ??= '';
			$this->citation = implode("\n", array_slice($text, $citation_line)) . ($this->citation ?? '');
			$text = array_slice($text, 0, $citation_line);
		}

		$this->text = implode("\n", $text);
	}
}
