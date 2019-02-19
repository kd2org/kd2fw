<?php
/*
	This file is part of KD2FW -- <http://dev.kd2.org/>

	Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
	All rights reserved.

	KD2FW is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	Foobar is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2;

/**
 * PLB beacon Hex ID decoder / encoder
 * Reference implementation: https://www.cospas-sarsat.int/media/com_cospas_sarsat/js/beacon_decoder.js
 *
 * @author bohwaz
 */
class Beacon
{
	// FIXME use ISO country codes instead!
	const COUNTRIES = [
		501 => 'Adelie Land',
		401 => 'Afghanistan',
		303 => 'Alaska',
		201 => 'Albania',
		605 => 'Algeria',
		559 => 'American Samoa',
		202 => 'Andorra',
		603 => 'Angola',
		301 => 'Anguilla',
		304 => 'Antigua',
		305 => 'Barbuda',
		701 => 'Argentine',
		216 => 'Armenia',
		307 => 'Aruba',
		608 => 'Ascension Island',
		503 => 'Australia',
		203 => 'Austria',
		423 => 'Azerbaijani',
		204 => 'Azores',
		308 => 'Bahamas',
		309 => 'Bahamas',
		311 => 'Bahamas',
		408 => 'Bahrain',
		405 => 'Bangladesh',
		314 => 'Barbados',
		206 => 'Belarus',
		205 => 'Belgium',
		312 => 'Belize',
		610 => 'Benin',
		310 => 'Bermuda',
		410 => 'Bhutan',
		720 => 'Bolivia',
		478 => 'Bosnia',
		611 => 'Botswana',
		710 => 'Brazil',
		378 => 'British Virgin Islands',
		508 => 'Brunei Darussalam',
		207 => 'Bulgaria',
		633 => 'Burkina Faso',
		609 => 'Burundi',
		514 => 'Cambodia',
		515 => 'Cambodia',
		613 => 'Cameroon',
		316 => 'Canada',
		617 => 'Cape Verde',
		319 => 'Cayman Islands',
		612 => 'Central African Republic',
		670 => 'Chad',
		725 => 'Chile',
		412 => 'China',
		413 => 'China',
		516 => 'Christmas Island',
		523 => 'Cocos',
		730 => 'Colombia',
		616 => 'Comoros',
		615 => 'Congo',
		518 => 'Cook Islands',
		321 => 'Costa Rica',
		619 => 'Côte d\'Ivoire',
		238 => 'Croatia',
		618 => 'Crozet Archipelago',
		323 => 'Cuba',
		209 => 'Cyprus',
		210 => 'Cyprus',
		212 => 'Cyprus',
		270 => 'Czech Republic',
		445 => 'Korea',
		676 => 'Congo',
		219 => 'Denmark',
		220 => 'Denmark',
		621 => 'Djibouti',
		325 => 'Dominica',
		327 => 'Dominican',
		735 => 'Ecuador',
		622 => 'Egypt',
		359 => 'El Salvador',
		631 => 'Equatorial Guinea',
		625 => 'Eritrea',
		276 => 'Estonia',
		624 => 'Ethiopia',
		740 => 'Falkland Islands',
		231 => 'Faroe Islands',
		520 => 'Fiji',
		230 => 'Finland',
		226 => 'France',
		227 => 'France',
		228 => 'France',
		546 => 'French Polynesia',
		626 => 'Gabonese Republic',
		629 => 'Gambia',
		213 => 'Georgia',
		211 => 'Germany',
		218 => 'Germany',
		627 => 'Ghana',
		236 => 'Gibraltar',
		237 => 'Greece',
		241 => 'Greece',
		239 => 'Greece',
		240 => 'Greece',
		331 => 'Greenland',
		330 => 'Grenada',
		329 => 'Guadeloupe',
		332 => 'Guatemala',
		745 => 'Guiana',
		630 => 'Guinea-Bissau',
		750 => 'Guyana',
		632 => 'Guinea',
		336 => 'Haiti',
		334 => 'Honduras',
		477 => 'Hong Kong',
		243 => 'Hungary',
		251 => 'Iceland',
		419 => 'India',
		525 => 'Indonesia',
		422 => 'Iran',
		425 => 'Iraq',
		250 => 'Ireland',
		428 => 'Israel',
		247 => 'Italy',
		339 => 'Jamaica',
		431 => 'Japan',
		432 => 'Japan',
		438 => 'Jordan',
		436 => 'Kazakhstan',
		634 => 'Kenya',
		635 => 'Kerguelen Islands',
		529 => 'Kiribati',
		440 => 'Korea',
		441 => 'Korea',
		447 => 'Kuwait',
		451 => 'Kyrgyz Republic',
		531 => 'Lao',
		275 => 'Latvia',
		450 => 'Lebanon',
		644 => 'Lesotho',
		636 => 'Liberia',
		637 => 'Liberia',
		252 => 'Liechtenstein',
		277 => 'Lithuania',
		253 => 'Luxembourg',
		453 => 'Macao',
		647 => 'Madagascar',
		255 => 'Madeira',
		655 => 'Malawi',
		533 => 'Malaysia',
		455 => 'Maldives',
		649 => 'Mali',
		215 => 'Malta',
		248 => 'Malta',
		249 => 'Malta',
		256 => 'Malta',
		538 => 'Marshall Islands',
		347 => 'Martinique',
		654 => 'Mauritania',
		645 => 'Mauritius',
		345 => 'Mexico',
		510 => 'Micronesia',
		214 => 'Moldova',
		254 => 'Monaco',
		457 => 'Mongolia',
		262 => 'Montenegro',
		348 => 'Montserrat',
		242 => 'Morocco',
		650 => 'Mozambique',
		506 => 'Myanmar',
		659 => 'Namibia',
		544 => 'Nauru',
		459 => 'Nepal',
		244 => 'Netherlands',
		245 => 'Netherlands',
		246 => 'Netherlands',
		306 => 'Netherlands Antilles',
		540 => 'New Caledonia',
		512 => 'New Zealand',
		350 => 'Nicaragua',
		656 => 'Niger',
		657 => 'Nigeria',
		542 => 'Niue',
		536 => 'Northern Mariana Islands',
		257 => 'Norway',
		258 => 'Norway',
		259 => 'Norway',
		461 => 'Oman',
		463 => 'Pakistan',
		511 => 'Palau',
		443 => 'Palestine',
		351 => 'Panama',
		352 => 'Panama',
		353 => 'Panama',
		354 => 'Panama',
		355 => 'Panama',
		356 => 'Panama',
		357 => 'Panama',
		370 => 'Panama',
		371 => 'Panama',
		373 => 'Panama',
		374 => 'Panama',
		372 => 'Panama',
		553 => 'Papua New Guinea',
		755 => 'Paraguay ',
		760 => 'Peru',
		548 => 'Philippines',
		555 => 'Pitcairn Island',
		261 => 'Poland',
		263 => 'Portugal',
		358 => 'Puerto Rico',
		466 => 'Qatar',
		660 => 'Reunion',
		264 => 'Romania',
		273 => 'Russian Federation',
		661 => 'Rwanda',
		665 => 'Saint Helena',
		341 => 'Saint Kitts and Nevis',
		343 => 'Saint Lucia',
		607 => 'Saint Paul and Amsterdam Islands',
		361 => 'Saint Pierre and Miquelon',
		375 => 'Saint Vincent and the Grenadines',
		376 => 'Saint Vincent and the Grenadines',
		377 => 'Saint Vincent and the Grenadines',
		561 => 'Samoa',
		268 => 'San Marino',
		668 => 'Sao Tome and Principe',
		403 => 'Saudi Arabia',
		663 => 'Senegal',
		279 => 'Serbia',
		664 => 'Seychelles',
		667 => 'Sierra Leone',
		563 => 'Singapore',
		564 => 'Singapore',
		565 => 'Singapore',
		566 => 'Singapore',
		267 => 'Slovak Republic',
		278 => 'Slovenia',
		642 => 'Socialist People\'s Libyan Arab Jamahiriya',
		557 => 'Solomon Islands',
		666 => 'Somali Democratic Republic',
		601 => 'South Africa',
		224 => 'Spain',
		225 => 'Spain',
		417 => 'Sri Lanka',
		662 => 'Sudan',
		765 => 'Suriname',
		669 => 'Swaziland',
		265 => 'Sweden',
		266 => 'Sweden',
		269 => 'Switzerland',
		468 => 'Syrian Arab Republic',
		416 => 'Taiwan',
		674 => 'Tanzania',
		677 => 'Tanzania',
		567 => 'Thailand',
		274 => 'Macedonia',
		671 => 'Togolese',
		570 => 'Tonga',
		362 => 'Trinidad and Tobago',
		672 => 'Tunisia',
		271 => 'Turkey',
		434 => 'Turkmenistan',
		364 => 'Turks and Caicos Islands',
		572 => 'Tuvalu',
		675 => 'Uganda',
		272 => 'Ukraine',
		470 => 'United Arab Emirates',
		232 => 'United Kingdom of Great Britain and Northern Ireland',
		233 => 'United Kingdom of Great Britain and Northern Ireland',
		234 => 'United Kingdom of Great Britain and Northern Ireland',
		235 => 'United Kingdom of Great Britain and Northern Ireland',
		379 => 'United States Virgin Islands',
		338 => 'United States of America',
		366 => 'United States of America',
		368 => 'United States of America',
		367 => 'United States of America',
		369 => 'United States of America',
		770 => 'Uruguay',
		437 => 'Uzbekistan',
		576 => 'Vanuatu',
		208 => 'Vatican City State',
		775 => 'Venezuela',
		574 => 'Viet Nam',
		578 => 'Wallis and Futuna Islands',
		473 => 'Yemen',
		475 => 'Yemen',
		678 => 'Zambia',
		679 => 'Zimbabwe',
	];

	const LOCATION_PROTOCOL_TYPES = [
		0b0010 => 'Standard Location - EPIRB (MMSI)',
		0b0011 => 'Standard Location - ELT (24-bit Address)',
		0b0100 => 'Standard Location - ELT (Serial)',
		0b0101 => 'Standard Location - ELT (Aircraft Operator Designator)',
		0b0110 => 'Standard Location - EPIRB (Serial)',
		0b0111 => 'Standard Location - PLB (Serial)',
		0b1000 => 'National Location - ELT',
		0b1001 => 'National Location - Spare',
		0b1010 => 'National Location - EPIRB',
		0b1011 => 'National Location - PLB',
		0b1110 => 'Standard Location - Test',
		0b1111 => 'National Location - Test',
		0b0000 => 'Invalid',
		0b0001 => 'Invalid',
		0b1100 => 'Ship Security',
		0b1101 => 'Invalid',
	];

	public $protocol_flag,
		$country_code,
		$location_protocol_type,
		$cs_number,
		$serial_number,
		$other;

	public function decodeHexID($id)
	{
		if (strlen($id) !== 15)
		{
			throw new \UnexpectedValueException('Hex ID must be 15 characters long');
		}

		$bin = hex2bin($id . '0');
		$bits = '';

		for ($i = 0; $i < strlen($bin); $i++)
		{
			$bits .= sprintf('%08b', ord($bin[$i]));
		}

		// Split
		$parts = [
			$bits[0], // 26 Protocol flag
			substr($bits, 1, 10), // 27-36 Country code
			substr($bits, 11, 4), // 37-40 Location protocol type
			substr($bits, 15, 10), // 41-50 C/S number
			substr($bits, 25, 14), // 51-64 serial number
			substr($bits, 39, 25), // 65+ (location etc.)
		];

		$this->protocol_flag = $parts[0];
		$this->country_code  = bindec($parts[1]);
		$this->location_protocol_type = bindec($parts[2]);
		$this->cs_number = bindec($parts[3]);
		$this->serial_number = bindec($parts[4]);
		$this->other = bindec($parts[5]);

		return $parts;
	}

	public function getBits()
	{
		return [
			$this->protocol_flag,
			sprintf('%010b', $this->country_code),
			sprintf('%04b', $this->location_protocol_type),
			sprintf('%010b', $this->cs_number),
			sprintf('%014b', $this->serial_number),
			sprintf('%025b', $this->other),
		];
	}

	public function encode()
	{
		$binary = implode('', $this->getBits());
		$binary = str_split($binary, 8);

		$out = '';

		foreach ($binary as $b)
		{
			$out .= dechex(bindec($b));
		}

		return strtoupper(substr($out, 0, 15));
	}
}
