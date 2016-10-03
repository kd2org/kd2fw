<?php

use KD2\Test;
use KD2\Translate;
use KD2\Smartyer;

require __DIR__ . '/_assert.php';

test_strftime();
test_smartyer_block();

function test_strftime()
{
	$timestamp = strtotime('2016-02-03 13:24:45');

	Translate::setLocale('fi_FI');
	$expected = '3. helmikuuta 2016 13.24';
	$output = Translate::strftime('%c', $timestamp);
	Test::equals($expected, $output);

	Translate::setLocale('en_US');
	$expected = '2/3/16';
	$output = Translate::strftime('%x', $timestamp);
	Test::equals($expected, $output);

	Translate::setLocale('tr_TR');
	$expected = 'Çar';
	$output = Translate::strftime('%a', $timestamp);
	Test::equals($expected, $output);

	Translate::setLocale('nl_BE');
	$expected = 'woensdag';
	$output = Translate::strftime('%A', $timestamp);
	Test::equals($expected, $output);

	Translate::setLocale('fr_CA');
	$expected = 'févr.';
	$output = Translate::strftime('%b', $timestamp);
	Test::equals($expected, $output);

	Translate::setLocale('pt_BR');
	$expected = 'fevereiro';
	$output = Translate::strftime('%B', $timestamp);
	Test::equals($expected, $output);

	Translate::setLocale('it_CH');
	$expected = 'feb';
	$output = Translate::strftime('%h', $timestamp);
	Test::equals($expected, $output);

	Translate::setLocale('uk_UA');
	$expected = 'пп';
	$output = Translate::strftime('%p', $timestamp);
	Test::equals($expected, $output);

	Translate::setLocale('de_DE');
	$expected = '13h24';
	$output = Translate::strftime('%Hh%M', $timestamp);
	Test::equals($expected, $output);
}

function test_smartyer_block()
{
	Smartyer::setCompileDir(sys_get_temp_dir());

	Translate::setLocale('fr_CH');
	Translate::registerDomain('global');
	Translate::importTranslations('global', 'fr', [
		'Translate this string.' => ['Traduit cette chaîne.'],
		"Other\004Translate this string." => ['Traduit cette chaîne, mais autrement.'],
		'One apple.' => ['%d pomme.', '%n pommes.'],
		'My name is %name and I have a dog named %s.' => ['Je m\'appelles %name et mon chien c\'est %dog.']
	]);

	$get = function ($code) {
		$tpl = Smartyer::fromString($code);
		Translate::extendSmartyer($tpl);
		return $tpl->fetch();
	};
	
	$code = '{{Translate this string.}}';
	$expected = 'Traduit cette chaîne.';

	Test::equals($expected, $get($code), 'Simple string');

	$code = '{{Translate this string.} context="Other"}';
	$expected = 'Traduit cette chaîne, mais autrement.';

	Test::equals($expected, $get($code), 'Simple string with different context');

	$code = '{{One apple.}{%n apples.} n=0}';
	$expected = '0 pomme.';

	Test::equals($expected, $get($code), 'Plural for zero');

	$code = '{{One apple.}{%n apples.} n=1}';
	$expected = '1 pomme.';

	Test::equals($expected, $get($code), 'Plural for one');

	$code = '{{One apple.}{%n apples.} n=2}';
	$expected = '2 pommes.';

	Test::equals($expected, $get($code), 'Plural for two');

	$code = '{{My name is %name and I have a dog named %s.} d="OK" name="George" dog="Arthur" escape=off}';
	$expected = 'Je m\'appelles George et mon chien c\'est Arthur.';

	Test::equals($expected, $get($code), 'Arguments with confusing extra argument');

	$GLOBALS['TR_USER_NAME'] = 'Atchoum';

	$code = '{{My name is %name and I have a dog named %s.} name=$GLOBALS.TR_USER_NAME dog="Arthur" escape=off}';
	$expected = 'Je m\'appelles Atchoum et mon chien c\'est Arthur.';

	Test::equals($expected, $get($code), 'Using global variable as argument');
}