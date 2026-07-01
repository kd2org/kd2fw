<?php

use KD2\Test;
use KD2\Office\OFXParser;
use KD2\Brindille_Exception;

require __DIR__ . '/../_assert.php';

function test_basic(string $str)
{
    $o = new OFXParser;
    $r = $o->parse($str);
    Test::assert(isset($r->accounts[0]->statement->start));
    Test::assert(isset($r->accounts[0]->statement->end));
    Test::assert(isset($r->accounts[0]->statement->transactions[0]));
    Test::assert(isset($r->accounts[0]->statement->transactions[0]->date));
    Test::assert(isset($r->accounts[0]->statement->transactions[0]->amount));
}

function test_empty(string $str)
{
    $o = new OFXParser;
    $r = $o->parse($str);
    Test::assert(isset($r->accounts[0]->statement->start));
    Test::assert(isset($r->accounts[0]->statement->end));
    Test::assert(count($r->accounts[0]->statement->transactions) === 0);
}

$test1 = <<<EOF
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252
COMPRESSION:NONE
OLDFILEUID:NONE
NEWFILEUID:NONE

<OFX>
  <SIGNONMSGSRSV1>
    <SONRS>
      <STATUS>
        <CODE>0
        <SEVERITY>INFO
      </STATUS>
      <DTSERVER>20260104180844.381
      <LANGUAGE>FRA
    </SONRS>
  </SIGNONMSGSRSV1>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <TRNUID>XXXXX
      <STATUS>
        <CODE>0
        <SEVERITY>INFO
      </STATUS>
      <STMTRS>
        <CURDEF>EUR
        <BANKACCTFROM>
          <BANKID>XXXXX
          <BRANCHID>XXXXX
          <ACCTID>XXXXX
          <ACCTTYPE>CHECKING
          <ACCTKEY>XX
        </BANKACCTFROM>
        <BANKTRANLIST>
          <DTSTART>20250506220000.000
          <DTEND>20260103230000.000
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20250507000000.000
            <TRNAMT>-1.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 070525 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20250610000000.000
            <TRNAMT>-1.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 070625 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20250707000000.000
            <TRNAMT>-1.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 070725 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20250807000000.000
            <TRNAMT>-1.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 070825 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20250908000000.000
            <TRNAMT>-1.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 070925 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20251007000000.000
            <TRNAMT>-1.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 071025 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>CREDIT
            <DTPOSTED>20251022000000.000
            <TRNAMT>500.00
            <FITID>XXXXX
            <NAME>VIR SEPA INST RECU
            <MEMO>/DE ELIADE SYLVAIN /REF NOTPROVIDED /MOTIF COURANT
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20251022000000.000
            <TRNAMT>-3.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 221025 SNCF VOYAGEURS  SAINT DENIS     CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20251107010000.000
            <TRNAMT>-1.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 071125 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20251208010000.000
            <TRNAMT>-1.00
            <FITID>XXXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 071225 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
        </BANKTRANLIST>
        <LEDGERBAL>
          <BALAMT>727.00
          <DTASOF>20260101230000.000
        </LEDGERBAL>
        <AVAILBAL>
          <BALAMT>727.00
          <DTASOF>20260101230000.000
        </AVAILBAL>
      </STMTRS>
      </STMTTRNRS>
    <STMTTRNRS>
      <TRNUID>XXXXX
      <STATUS>
        <CODE>0
        <SEVERITY>INFO
      </STATUS>
      <STMTRS>
        <CURDEF>EUR
        <BANKACCTFROM>
          <BANKID>XXXXX
          <BRANCHID>XXXXX
          <ACCTID>XXXXX
          <ACCTTYPE>CHECKING
          <ACCTKEY>XX
        </BANKACCTFROM>
        <BANKTRANLIST>
          <DTSTART>20250506220000.000
          <DTEND>20260103230000.000
          <STMTTRN>
            <TRNTYPE>DEBIT
            <DTPOSTED>20250507000000.000
            <TRNAMT>-1.00
            <FITID>XXXX
            <NAME>FACTURE CARTE
            <MEMO>DU 070525 QUADRATURE NET  PARIS           CARTE   4609XXXXXXXXXXXX
          </STMTTRN>
        </BANKTRANLIST>
        <LEDGERBAL>
          <BALAMT>727.00
          <DTASOF>20260101230000.000
        </LEDGERBAL>
        <AVAILBAL>
          <BALAMT>727.00
          <DTASOF>20260101230000.000
        </AVAILBAL>
      </STMTRS>    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
EOF;

test_basic($test1);

$test2 = <<<EOF
<OFX>
  <SIGNONMSGSRSV1>
    <SONRS>
      <STATUS>
        <CODE>0</CODE>
        <SEVERITY>INFO</SEVERITY>
      </STATUS>
      <DTSERVER>20260321</DTSERVER>
      <LANGUAGE>FRA</LANGUAGE>
      <DTPROFUP>20260321</DTPROFUP>
      <DTACCTUP>20260321</DTACCTUP>
    </SONRS>
  </SIGNONMSGSRSV1>
  <BANKMSGSRSV1>
    <STMTTRNRS>
      <TRNUID>_</TRNUID>
      <STATUS>
        <CODE>0</CODE>
        <SEVERITY>INFO</SEVERITY>
      </STATUS>
      <STMTRS>
        <CURDEF>EUR</CURDEF>
        <BANKACCTFROM>
          <BANKID>_</BANKID>
          <BRANCHID>_</BRANCHID>
          <ACCTID>_</ACCTID>
          <ACCTTYPE>SAVINGS</ACCTTYPE>
        </BANKACCTFROM>
        <BANKTRANLIST>
          <DTSTART>20250901</DTSTART>
          <DTEND>20260321</DTEND>
          <STMTTRN>
            <TRNTYPE>CREDIT</TRNTYPE>
            <DTPOSTED>20260206</DTPOSTED>
            <TRNAMT>+5000,00</TRNAMT>
            <FITID>XXXXX</FITID>
            <NAME>XXXXX</NAME>
            <MEMO>XXXXX2</MEMO>
          </STMTTRN>
          <STMTTRN>
            <TRNTYPE>CREDIT</TRNTYPE>
            <DTPOSTED>20251231</DTPOSTED>
            <TRNAMT>+84,96</TRNAMT>
            <FITID>XXXXX</FITID>
            <NAME>INTERETS 2025</NAME>
            <MEMO>INTERETS 2025</MEMO>
          </STMTTRN>
        </BANKTRANLIST>
        <LEDGERBAL>
          <BALAMT/>
          <DTASOF/>
        </LEDGERBAL>
      </STMTRS>
    </STMTTRNRS>
  </BANKMSGSRSV1>
</OFX>
EOF;

test_basic($test2);

$test3 = <<<EOF
OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252
COMPRESSION:NONE
OLDFILEUID:NONE
NEWFILEUID:NONE

<OFX>
<SIGNONMSGSRSV1>
  <SONRS>
    <STATUS>
      <CODE>0
      <SEVERITY>INFO
    </STATUS>
    <DTSERVER>20260630000000
    <LANGUAGE>FRA
  </SONRS>
</SIGNONMSGSRSV1>
<BANKMSGSRSV1>
  <STMTTRNRS>
    <TRNUID>20260630000000
    <STATUS>
      <CODE>0
      <SEVERITY>INFO
    </STATUS>
    <STMTRS>
      <CURDEF>EUR
      <BANKACCTFROM>
        <BANKID>XXXXX
        <BRANCHID>XXXXX
        <ACCTID>XXXXXX
        <ACCTTYPE>CHECKING
      </BANKACCTFROM>
      <BANKTRANLIST>
        <DTSTART>20260630000000
        <DTEND>20260630000000
      </BANKTRANLIST>
      <LEDGERBAL>
        <BALAMT>2743.05
        <DTASOF>20260630000000
      </LEDGERBAL>
      <AVAILBAL>
        <BALAMT>0.00
        <DTASOF>20260630000000
      </AVAILBAL>
    </STMTRS>
  </STMTTRNRS>
</BANKMSGSRSV1>
</OFX>
EOF;

test_empty($test3);
