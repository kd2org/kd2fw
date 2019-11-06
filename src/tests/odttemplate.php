<?php

use KD2\Test;
use KD2\ODTTemplate;

require __DIR__ . '/_assert.php';

test_odt();

function test_odt()
{
	$odt = new ODTTemplate(DATA_DIR . '/odttemplate/facture.odt');
	$odt->open();

	$odt->assign([
		'nom_asso' => 'La rustine',
		'adresse_asso' => "5 rue du Havre\n21000 DIJON",
		'total' => '38,00 €',
		'lignes' => [
			[
				'libelle'  => 'Pneu 26"',
				'quantite' => 3,
				'prix'     => '5,00 €',
				'total'    => '15,00 €',
			],
			[
				'libelle'  => 'Potence occasion',
				'quantite' => 1,
				'prix'     => '3,00 €',
				'total'    => '3,00 €',
			],
			[
				'libelle'  => 'Pneu 28" neuf',
				'quantite' => 2,
				'prix'     => '10,00 €',
				'total'    => '20,00 €',
			],
		]
	]);

	$odt->replace();
	$xml = $odt->getXML();
}