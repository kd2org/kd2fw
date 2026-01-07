<?php

namespace KD2\Office;

use SimpleXMLElement;
use stdClass;
use DateTime;
use DateTimeInterface;

/**
 * @see https://financialdataexchange.org/FDX/About/OFX-Work-Group.aspx
 * @see https://ofxtools.readthedocs.io/en/latest/
 * @see https://github.com/libofx/libofx
 * @see https://github.com/KDE/kmymoney/blob/main/kmymoney/plugins/ofx/import/ofximporter.cpp
 */
class OFXParser
{
	const TRANSACTION_TYPES = [
		'CREDIT',
		'DEBIT',
		'INT', // (Interest earned or paid) Note: Depends on signage of amount
		'DIV', // (Dividend)
		'FEE', // (bank fee)
		'SRVCHG', // Service charge
		'DEP', // (Deposit)
		'ATM', // (ATM debit or credit) Note: Depends on signage of amount
		'POS', // (Point of sale debit or credit) Note: Depends on signage of amount
		'XFER', // (Transfer)
		'CHECK', // (Check)
		'PAYMENT', // (Electronic payment)
		'CASH', // (Cash withdrawal)
		'DIRECTDEP', // (Direct deposit)
		'DIRECTDEBIT', // (Merchant initiated debit)
		'REPEATPMT', // (Repeating payment/standing order)
		'HOLD', // (Only valid in <STMTTRNP>; indicates the amount is under a hold) Note: Depends on signage of amount and account type
		'OTHER',
	];


	public function parse(string $str)
	{
		$str = $this->convertSGMLToXML($str);
		echo $str;

		libxml_clear_errors();
		libxml_use_internal_errors(true);

		$xml = simplexml_load_string($str, 'SimpleXMLElement', LIBXML_NOENT | LIBXML_DTDLOAD | LIBXML_DTDATTR);

		if ($errors = libxml_get_errors()) {
			throw new \InvalidArgumentException('Invalid OFX XML: ' . print_r($errors, true));
		}

		return $this->parseAccounts($xml);
	}

	public function convertSGMLToXML(string $str)
	{
		if (str_contains($str, '<?xml')) {
			return $str;
		}

		$str = function_exists('utf8_encode') ? @utf8_encode($str) : mb_convert_encoding($str, 'UTF-8', mb_list_encodings());

		// remove file header (anything before the first tag)
		if ($pos = stripos($str, '<OFX>')) {
			$str = substr($str, $pos);
		}

		// Normalize line endings
		$str = preg_replace("/\r\n|\r/", "\n", $str);

		// Make sure all the tags are enclosed
		$str = preg_replace('/<(\w+)>(?:[^<\n]+)(?!<\/\1>)/i', '\0</\1>', $str);

		// Escape ampersands
		$str = preg_replace('/&(?!#?[a-z0-9]+;)/', '&amp;', $str);

		return $str;
	}

	protected function parseAccounts(SimpleXMLElement $xml): array
	{
		$out = [];

		foreach ($xml->BANKMSGSRSV1->STMTTRNRS ?? [] as $statement) {
			foreach ($statement->STMTRS ?? [] as $response) {
				$out[] = $this->parseAccount($response);
			}
		}

		return $out;
	}

	protected function parseAccount(SimpleXMLElement $xml): \stdClass
	{
		// Convert to object, this avoids having to cast everything as string
		$xml = json_decode(json_encode($xml));

		$currency = $xml->CURDEF ?? null;

		$account = (object) [
			'bank' => $xml->BANKACCTFROM->BANKID ?? null,
			'branch' => $xml->BANKACCTFROM->BRANCHID ?? null,
			'number' => $xml->BANKACCTFROM->ACCTID ?? null,
			'key' => $xml->BANKACCTFROM->ACCTKEY ?? null,

			// CHECKING
			// SAVINGS
			// MONEYMRKT (Money Market)
			// CREDITLINE (Line of credit)
			// CD (Certificate of Deposit)
			'type' => $xml->BANKACCTFROM->ACCTTYPE ?? null,
			'balance' => $xml->LEDGERBAL->BALAMT ?? null,
			'balance_date' => $this->parseDateTime($xml->LEDGERBAL->DTASOF ?? null, $currency),
			'currency' => $currency,
			'statement' => (object) [
				'start' => $this->parseDateTime($xml->BANKTRANLIST->DTSTART ?? null, $currency),
				'end' => $this->parseDateTime($xml->BANKTRANLIST->DTEND ?? null, $currency),
				'transactions' => [],
			],
		];

		// Prepend FR76 to get the IBAN in France
		$account->full_number = (string) $account->bank . (string) $account->branch . (string) $account->number . (string) $account->key;

		if ($list = ($xml->BANKTRANLIST->STMTTRN ?? null)) {
			// is for making sure that we have an array even if there is only one XML item
			// this is due to the json_encode/json_decode trick above
			if (is_object($list)) {
				$list = [$list];
			}

			$account->statement->transactions = $this->parseTransactions($list, $currency);
		}

		return $account;
	}

	protected function parseTransactions(array $list, ?string $currency): array
	{
		$out = [];

		foreach ($list as $t) {
			$out[] = (object) [
				// <FITID> Transaction ID issued by financial institution.
				// Used to detect duplicate downloads
				'id' => $t->FITID ?? null,
				'name' => $t->NAME ?? null,
				'memo' => $t->MEMO ?? null,
				'type' => $t->TRNTYPE ?? null,

				// <DTPOSTED> Date transaction was posted to account, datetime
				'date' => $this->parseDateTime($t->DTPOSTED ?? null, $currency),

				// <DTUSER> Date user initiated transaction, if known, datetime
				'date_user' => $this->parseDateTime($t->DTUSER ?? null, $currency),

				// <DTAVAIL> Date funds are available (value date), datetime
				'date_available' => $this->parseDateTime($t->DTAVAIL ?? null, $currency),

				'amount' => $this->parseAmount($t->TRNAMT ?? null),
				'check_number' => $t->CHECKNUM ?? null,

				// Reference number that uniquely identifies the transaction.
				// Can be used in addition to or instead of a <CHECKNUM>
				'ref' => $t->REFNUM ?? null,
			];
		}

		return $out;
	}

	protected function parseAmount(?string $str): string
	{
		if ($str === '' || $str === null) {
			return null;
		}

		if (strpos($str, ',') > strpos($str, '.')) {
			return strtr($value, ['.' => '', ',' => '.']);
		}

		return str_replace(',', '', $str);
	}

	protected function parseDateTime(?string $date, ?string $currency): ?DateTimeInterface
	{
		if ($date === null || $date === '') {
			return null;
		}

		try {
			// YYYYMMDD
			if (is_numeric($date) && strlen($date) === strlen(date('Ymd'))) {
				$out = DateTime::createFromFormat('!Ymd', $date);
			}
			// YYYYMMDDHHMMSS.000[-X:ABC]
			elseif (is_numeric($date) || preg_match('/^\d+(?:\.\d+)?(?:\[[^]]+\])?$/', $date)) {
				$date = preg_replace('/\..*$|\[.*$/', '', $date);
				$out = DateTime::createFromFormat('YmdHis', $date);
			}
			// DD/MM/YYYY or MM/DD/YYYY
			elseif (substr_count($date, '/') === 2 && strlen($date) === strlen(date('d/m/Y'))) {
				$d = explode('/', $date);

				// US format: MM/DD/YYYY
				// if second part is > 12, means this is a day
				// or if both first parts <= 12 and currency is USD
				if ($d[1] > 12
					|| ($d[0] <= 12 && $currency === 'USD')) {
					$d = [$d[1], $d[0], $d[2]];
				}

				$out = new DateTime(sprintf('%d-%d-%d', $d[2], $d[1], $d[0]));
			}
			// ISO format / other
			else {
				$out = new DateTime($date);
			}

			return $out ?: null;
		}
		catch (\Exception $e) {
			return null;
		}
	}
}
