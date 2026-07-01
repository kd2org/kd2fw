<?php

use KD2\Test;
use KD2\Office\QIFParser;
use KD2\Brindille_Exception;

require __DIR__ . '/../_assert.php';

function test_basic(string $str)
{
    $o = new QIFParser;
    $r = $o->parse($str);
    Test::strictlyEquals(4, count($r));

    foreach ($r as $l) {
        Test::hasProperty('date', $l);
        Test::isInstanceOf(\DateTime::class, $l->date);
        Test::hasProperty('amount', $l);
        Test::assert(is_numeric($l->amount));
        Test::hasProperty('label', $l);
        Test::assert(mb_strlen($l->label) >= 1);
    }

    return $r;
}

function test_empty(string $str)
{
    $o = new QIFParser;
    $r = $o->parse($str);

    Test::assert(count($r) === 0);
}

// This is a valid (empty) QIF file
$test1 = <<<EOF
!Type:Banque

EOF;

test_empty($test1);

$test2 = <<<EOF
!Type:Bank
D10/01/2025
T-9.58
PFACT SGTXXXXX DONT TVA 0,38EUR
^
D14/01/2025
T-500.00
PVIR DON CAMPAGNE DEGMAILISATION
^
D14/01/2025
T518.00
PVIR STRIPE TECHNOLOGY EUROPE XXXX-20-XXXX
^
D20/01/2025
T-2,152.09
PPRLV SEPA GROUPEMENT D EMPLOYEU FACTURE GEA2101105 DE GROUPEMEN
^
EOF;

$r = test_basic($test2);

Test::strictlyEquals('-9.58', $r[0]->amount);
Test::strictlyEquals('-500.00', $r[1]->amount);
Test::strictlyEquals('518.00', $r[2]->amount);
Test::strictlyEquals('-2152.09', $r[3]->amount);
Test::strictlyEquals('2025-01-10', $r[0]->date->format('Y-m-d'));

Test::strictlyEquals('FACT SGTXXXXX DONT TVA 0,38EUR', $r[0]->label);
Test::strictlyEquals(null, $r[0]->category);


$test3 = <<<EOF
!Type:Bank
D02/01/2026
T1,300.00
PVir Sepa Paheko | VIR SEPA PAHEKO
LVirements reçus
^
D16/12/2025
T-268.00
PPrlv Sepa Direction Generale Des Finance | PRLV SEPA DIRECTION GENERALE DES FINANCE
LImpôts & Taxes
^
D08/12/2025
T-1.00
PQuadrature Net | CARTE 07/12/25 QUADRATURE NET CB*3765
LDons et Cadeaux
^
D04/12/2025
T-1,000.00
PVir Sepa Moi Fortuneo | VIR SEPA MOI Fortuneo
LVirements émis
^


EOF;

$r = test_basic($test3);

Test::strictlyEquals('1300.00', $r[0]->amount);
Test::strictlyEquals('-268.00', $r[1]->amount);
Test::strictlyEquals('-1.00', $r[2]->amount);
Test::strictlyEquals('-1000.00', $r[3]->amount);
Test::strictlyEquals('2026-01-02', $r[0]->date->format('Y-m-d'));

Test::strictlyEquals('Vir Sepa Paheko', $r[0]->label);
Test::strictlyEquals('Virements reçus', $r[0]->category);
