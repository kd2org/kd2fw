<?php

use KD2\Test;
use KD2\Translate;
use KD2\Smartyer;

require __DIR__ . '/_assert.php';

test_strftime();
test_smartyer_block();
test_gettext();
test_gettext_ext('momo'); // .mo files
test_gettext_ext('popo'); // .po files

function test_strftime()
{
	$timestamp = new \DateTime('2016-02-03 13:24:45', new \DateTimeZone('Europe/Paris'));

	Translate::setLocale('fi_FI');
	$expected = '3. helmikuuta 2016 klo 13.24';
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

	$expected = '52,2016,16,2017';
	$output = Translate::strftime('%V,%G,%g,%Y', new \DateTime('2017-01-01'));
	Test::equals($expected, $output);

	$expected = '52,2002,02,2002';
	$output = Translate::strftime('%V,%G,%g,%Y', new \DateTime('2002-12-28'));
	Test::equals($expected, $output);

	$expected = '01,2003,03,2002';
	$output = Translate::strftime('%V,%G,%g,%Y', new \DateTime('2002-12-30'));
	Test::equals($expected, $output);

	$expected = '01,2003,03,2003';
	$output = Translate::strftime('%V,%G,%g,%Y', new \DateTime('2003-01-03'));
	Test::equals($expected, $output);

	$expected = '01:02:03';
	$output = Translate::strftime('%X', new \DateTime('2003-01-03 01:02:03'), 'fr_FR');
	Test::equals($expected, $output);
}

function test_smartyer_block()
{
	Translate::unregisterDomain('global');

	Translate::setLocale('fr_CH');

	Translate::importTranslations('global', 'fr', [
		'Translate this string.' => ['Traduit cette chaîne.'],
		"Other\004Translate this string." => ['Traduit cette chaîne, mais autrement.'],
		'One apple.' => ['%d pomme.', '%n pommes.'],
		'My name is %name and I have a dog named %s.' => ['Je m\'appelles %name et mon chien c\'est %dog.']
	]);

	$get = function ($code) {
		$tpl = Smartyer::fromString($code);
		$tpl->setCompiledDir(sys_get_temp_dir());
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

function test_gettext()
{
	Translate::setLocale('fr_CH');

	Translate::unregisterDomain('global');

	Translate::importTranslations('global', 'fr', [
		'Translate this string.' => ['Traduit cette chaîne.'],
		"Other\004Translate this string." => ['Traduit cette chaîne, mais autrement.'],
		'One apple.' => ['%d pomme.', '%d pommes.'],
		'My name is %name and I have a dog named %s.' => ['Je m\'appelles %name et mon chien c\'est %dog.']
	]);

	$string = 'Translate this string.';
	$expected = 'Traduit cette chaîne.';
	$output = \KD2\gettext($string);

	Test::equals($expected, $output, 'Simple string');

	$expected = 'Traduit cette chaîne, mais autrement.';
	$output = \KD2\pgettext('Other', $string);

	Test::equals($expected, $output, 'Simple string with different context');

	$output = \KD2\ngettext('One apple.', '%d apples.', 0);
	$expected = '%d pomme.';

	Test::equals($expected, $output, 'Plural for zero');

	$output = \KD2\ngettext('One apple.', '%d apples.', 1);
	$expected = '%d pomme.';

	Test::equals($expected, $output, 'Plural for one');

	$output = \KD2\ngettext('One apple.', '%d apples.', 2);
	$expected = '%d pommes.';

	Test::equals($expected, $output, 'Plural for two');

	Translate::importTranslations('blog', 'fr', [
		'New post' => ['Nouvel article'],
	]);

	$output = \KD2\dgettext('blog', 'New post');
	$expected = 'Nouvel article';

	Test::equals($expected, $output, 'Different domain');

	$output = \KD2\gettext('New post');
	$expected = 'New post';

	Test::equals($expected, $output, 'Different domain should not change default domain');
}

function test_gettext_ext($domain)
{
	Translate::setLocale('fr_BE');
	Translate::unregisterDomain('global');
	Translate::registerDomain($domain, __DIR__ . '/data/locales');

	$expected = 'Dieu est-il un chien ?';
	$output = \KD2\_('Is god a dog?');

	Test::equals($expected, $output, 'Simple string');

	$expected = 'Dieu est-il un chat ?';
	$output = \KD2\pgettext('Cat', 'Is god a dog?');

	Test::equals($expected, $output, 'Simple string with different context');

	$output = \KD2\ngettext('One death adder.', '%d death adders.', 0);
	$expected = '%d vipère de la mort.';

	Test::equals($expected, $output, 'Plural for zero');

	$output = \KD2\ngettext('One death adder.', '%d death adders.', 1);
	$expected = '%d vipère de la mort.';

	Test::equals($expected, $output, 'Plural for one');

	$output = \KD2\ngettext('One death adder.', '%d death adders.', 2);
	$expected = '%d vipères de la mort.';

	Test::equals($expected, $output, 'Plural for two');
}
