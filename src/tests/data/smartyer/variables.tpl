Simple: {$simple}
Escaped: {$html}
Not escaped: {$html|raw}
Comment: {* Comment *}
Rot13: {$simple|rot13}
PHP: <?=$simple?>
Chained: {$simple|rot13|substr(0, 5)}
With variable in modifier: {$simple|replace('Hello', $html)}
Magic variable: {$object.array.key1}
Object variable: {$this.template_path}
Object static variable: {$this::$cache_path}
