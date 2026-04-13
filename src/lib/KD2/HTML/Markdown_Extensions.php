<?php

namespace KD2\HTML;

class Markdown_Extensions
{
	const LIST = [
		'button'   => [self::class, 'button'],
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

	static protected ?array $grid_columns = null;
	static protected ?int $grid_count = null;
	static protected bool $grid_legacy = false;

	static public function register(Markdown $md): void
	{
		foreach (self::LIST as $name => $callback) {
			$md->registerExtension($name, $callback);
		}
	}

	static public function _checkColorValue(string $color): bool
	{
		return ctype_alnum(str_replace('#', '', strtolower($color)));
	}

	static public function button(bool $block, array $args, ?string $content, string $name): string
	{
		$fg = $args['color'] ?? '';
		$bg = $args['bgcolor'] ?? '';
		$size = intval($args['size'] ?? 18);
		$padding = round($size * 0.3);
		$href = $args['href'] ?? '';

		if (!$bg || !self::_checkColorValue($bg)) {
			$bg = 'lightblue';
		}

		if (!$fg || !self::_checkColorValue($fg)) {
			$fg = 'black';
		}

		return sprintf('<a href="%s" target="%s" style="padding: %dpt %dpt; display: %s; color: %s; background-color: %s; box-shadow: 0px 0px 5px #000; margin: %3$dpt; border-radius: %3$dpt; text-decoration: %s; font-size: %dpt; text-align: center; margin: .5em; margin-bottom: 1em;">%s</a>',
			htmlspecialchars($href),
			substr($href, 0, 4) === 'http' ? '_blank' : '_self',
			$padding,
			$padding*2,
			!empty($args['block']) ? 'block' : 'inline-block',
			htmlspecialchars($fg),
			htmlspecialchars($bg),
			!empty($args['underline']) ? 'underline' : 'none',
			$size,
			nl2br(htmlspecialchars($args['label'] ?? $content ?? ''))
		);
	}

	/**
	 * <<color|red>>text...<</color>>
	 * <<color|red|blue>>text...<</color>>
	 */
	static public function color(bool $block, array $args, ?string $content, string $name): string
	{
		// Only allow color names / hex codes
		foreach ($args as $k => $v) {
			if (!is_string($v)
				|| !self::_checkColorValue($v)) {
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

		if (self::$grid_count === count(self::$grid_columns)) {
			self::$grid_count = 0;
		}

		$align = $args['align'] ?? null;
		$valign = $args['valign'] ?? null;

		if (self::$grid_legacy) {
			$style .= sprintf(' width: %d%%;', self::$grid_columns[self::$grid_count ?? 0]*100 - count(self::$grid_columns)*2);

			if (null !== $valign) {
				if ($valign === 'start' || $valign === 'top') {
					$valign = 'top';
				}
				elseif ($valign === 'end' || $valign === 'bottom') {
					$valign = 'bottom';
				}
				else {
					$valign = 'middle';
				}

				$style .= sprintf(' vertical-align: %s;', $valign);
			}
		}
		else {
			if (isset($args['column'])) {
				$style .= 'grid-column: ' . htmlspecialchars($args['column']) . ';';
			}

			if (isset($args['row'])) {
				$style .= 'grid-row: ' . htmlspecialchars($args['row']) . ';';
			}

			// Allow aliases
			if (null !== $align) {
				if ($align === 'left') {
					$align = 'start';
				}
				elseif ($align === 'right') {
					$align = 'end';
				}

				$style .= sprintf('justify-self: %s;', htmlspecialchars($align));
			}

			// Allow aliases
			if (null !== $valign) {
				if ($valign === 'top') {
					$valign = 'start';
				}
				elseif ($valign === 'bottom') {
					$valign = 'end';
				}
				elseif ($valign === 'middle') {
					$valign = 'center';
				}

				$style .= 'align-self: ' . htmlspecialchars($valign) . ';';
			}

			$style = self::filterStyleAttribute($style);
		}

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
			if (null === self::$grid_count) {
				return '';
			}

			self::$grid_count++;
			$close = '</article>';

			return $close . self::gridBlock($args);
		}

		if (null !== self::$grid_count) {
			$out .= self::gridClose($block);
			self::$grid_count = 0;
		}

		$styles = [];
		self::$grid_legacy = array_key_exists('legacy', $args);
		$class = self::$grid_legacy ? 'web-grid-legacy' : 'web-grid';
		$columns = [100]; // mostly for legacy

		// Automatic template from simple string:
		// !! = 2 columns, #!! = 1 50% column, two 25% columns
		if (isset($args[0]) || isset($args['short'])) {
			$template = $args[0] ?? $args['short'];
			$template = preg_replace('/[^!#]/', '', $template);
			$l = strlen($template);
			$fraction = ceil(100*(1/$l)) / 100;

			preg_match_all('/(?:!|#+)/', $template, $match);

			$columns = [];
			$grid = [];

			foreach ($match[0] as $i) {
				if ($i === '!') {
					$columns[] = $fraction;
					$grid[] = sprintf('minmax(0, %sfr) ', $fraction);
				}
				else {
					$columns[] = $fraction * strlen($i);
					$grid[] = sprintf('minmax(0, %sfr) ', $fraction * strlen($i));
				}
			}

			$template = str_replace('!', sprintf('minmax(0, %sfr) ', $fraction), $template);
			$template = preg_replace_callback('/(#+)/', fn ($match) => sprintf('minmax(0, %sfr) ', $fraction * strlen($match[1])), $template);
			$styles['--grid-template'] = 'none / ' . trim(implode(' ', $grid));
		}
		elseif (isset($args['template'])) {
			$styles['--grid-template'] = $args['template'];
		}
		else {
			$styles['--grid-template'] = '1fr';
		}

		if (array_key_exists('debug', $args)) {
			$class .= ' web-grid-debug';
		}

		if (isset($args['class']) && preg_match('/^[a-z0-9_\s-]+$/', $args['class'])) {
			$class .= ' ' . $args['class'];
		}

		if (isset($args['bgcolor'])) {
			$args['padding'] ??= '.5em';
			$styles['background-color'] = $args['bgcolor'];
		}

		$valign = $args['valign'] ?? null;

		if (null !== $valign) {
			if ($valign === 'top') {
				$valign = 'start';
			}
			elseif ($valign === 'bottom') {
				$valign = 'end';
			}
			elseif ($valign === 'middle') {
				$valign = 'center';
			}

			$args['align-items'] = $valign;
		}

		static $allowed_properties = ['gap', 'color', 'padding', 'border', 'border-radius', 'background-color', 'text-align', 'align-items'];

		foreach ($allowed_properties as $name) {
			if (isset($args[$name])) {
				$styles[$name] = $args[$name];
			}
		}

		if (self::$grid_legacy) {
			unset($styles['--grid-template'], $styles['gap']);
		}

		$style = '';

		foreach ($styles as $name => $value) {
			$style .= $name . ':' . $value . ';';
		}

		$style = self::filterStyleAttribute($style);
		self::$grid_columns = $columns;

		$out .= sprintf('<section class="%s" style="%s">', $class, htmlspecialchars($style));
		$out .= self::gridBlock($args);
		self::$grid_count = 0;

		return $out;
	}

	static public function gridClose(bool $block): string
	{
		$out = '</article>';
		$out .= '</section>';

		self::$grid_count = null;
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
