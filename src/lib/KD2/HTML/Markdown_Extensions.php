<?php

namespace KD2\HTML;

class Markdown_Extensions
{
	const LIST = [
		'color'    => [self::class, 'color'],
		'bgcolor'  => [self::class, 'color'],
		'/color'   => [self::class, 'colorClose'],
		'/bgcolor' => [self::class, 'colorClose'],
		'grid'     => [self::class, 'grid'],
		'/grid'    => [self::class, 'gridClose'],
		'center'   => [self::class, 'align'],
		'/center'  => [self::class, 'alignClose'],
		'left'     => [self::class, 'align'],
		'/left'    => [self::class, 'alignClose'],
		'right'    => [self::class, 'align'],
		'/right'   => [self::class, 'alignClose'],
	];

	static protected bool $in_grid = false;

	static public function register(Markdown $md): void
	{
		foreach (self::LIST as $name => $callback) {
			$md->registerExtension($name, $callback);
		}
	}

	/**
	 * <<color|red>>text...<</color>>
	 * <<color|red|blue>>text...<</color>>
	 */
	static public function color(bool $block, array $args, ?string $content, string $name): string
	{
		// Only allow color names / hex codes
		foreach ($args as $k => $v) {
			if (!ctype_alnum(str_replace('#', '', strtolower($v)))) {
				unset($args[$k]);
			}
		}

		if (!isset($args[0])) {
			return '';
		}

		$tag = $block ? 'div' : 'span';
		$style = !$block ? 'display: inline; ' : '';
		$args = array_map('htmlspecialchars', $args);

		if ($name == 'color' && count($args) == 1) {
			$style .= 'color: ' . $args[0];
		}
		elseif ($name == 'color') {
			$style .= sprintf('background-size: 100%%; background: linear-gradient(to right, %s); -webkit-background-clip: text; -webkit-text-fill-color: transparent; -moz-text-fill-color: transparent; -moz-background-clip: text;', implode(', ', $args));
		}
		elseif ($name == 'bgcolor' && count($args) == 1) {
			$style .= 'background-color: ' . $args[0];
		}
		else {
			$style .= sprintf('background-size: 100%%; background: linear-gradient(to right, %s); -webkit-background-clip: initial; -webkit-text-fill-color: initial; -moz-text-fill-color: initial; -moz-background-clip: initial;', implode(', ', $args));
		}

		return sprintf('<%s style="%s">', $tag, $style);
	}

	static public function colorClose(bool $block): string
	{
		if ($block) {
			return '</div>';
		}
		else {
			return '</span>';
		}
	}

	static protected function filterStyleAttribute(string $str): ?string
	{
		$str = html_entity_decode($str);
		$str = rawurldecode($str);
		$str = str_replace([' ', "\t", "\n", "\r", "\0"], ' ', $str);

		if (strstr($str, '/*')) {
			return null;
		}

		if (preg_match('/url\s*\(|expression|script:|\\\\|@import/i', $str)) {
			return null;
		}

		return $str;
	}


	static public function gridBlock(array $args): string
	{
		$style = '';

		if (isset($args['column'])) {
			$style .= 'grid-column: ' . htmlspecialchars($args['column']);
		}

		if (isset($args['row'])) {
			$style .= 'grid-row: ' . htmlspecialchars($args['row']);
		}

		if (isset($args['align'])) {
			$style .= 'align-self: ' . htmlspecialchars($args['align']);
		}

		$style = self::filterStyleAttribute($style);

		return sprintf('<article class="web-block" style="%s">', $style);
	}

	static public function grid(bool $block, array $args, ?string $content, string $name): string
	{
		if (!$block) {
			return '';
		}

		$out = '';

		// Split grid in blocks
		if (!isset($args[0]) && !isset($args['short']) && !isset($args['template'])) {
			if (!self::$in_grid) {
				return '';
			}

			return '</article>' . self::gridBlock($args);
		}

		if (self::$in_grid) {
			$out .= self::gridClose($block);
		}

		$class = 'web-grid';
		$style = 'grid-template: ';

		// Automatic template from simple string:
		// !! = 2 columns, #!! = 1 50% column, two 25% columns
		if (isset($args[0]) || isset($args['short'])) {
			$template = $args[0] ?? $args['short'];
			$template = preg_replace('/[^!#]/', '', $template);
			$l = strlen($template);
			$fraction = ceil(100*(1/$l)) / 100;
			$template = str_replace('!', sprintf('minmax(0, %sfr) ', $fraction), $template);
			$template = preg_replace_callback('/(#+)/', fn ($match) => sprintf('minmax(0, %sfr) ', $fraction * strlen($match[1])), $template);
			$style .= 'none / ' . trim($template);
		}
		elseif (isset($args['template'])) {
			$style .= $args['template'];
		}
		else {
			$style .= '1fr';
		}

		if (isset($args['gap'])) {
			$style .= '; gap: ' . $args['gap'];
		}

		if (array_key_exists('debug', $args)) {
			$class .= ' web-grid-debug';
		}

		$style = self::filterStyleAttribute($style);

		$out .= sprintf('<section class="%s" style="--%s">', $class, htmlspecialchars($style));
		$out .= self::gridBlock($args);
		self::$in_grid = true;

		return $out;
	}

	static public function gridClose(bool $block): string
	{
		$out = '';

		$out .= '</article>';
		$out .= '</section>';

		self::$in_grid = false;
		return $out;
	}

	static public function align(bool $block, array $args, ?string $content, string $name): string
	{
		if (!$block) {
			return '';
		}

		return sprintf('<div style="text-align: %s">', $name);
	}

	static public function alignClose(bool $block): string
	{
		if (!$block) {
			return '';
		}

		return '</div>';
	}
}
