Simple: {$simple}
Escaped: {$html}
Not escaped: {$html|raw}
Comment: {* Comment *}
Rot13: {$html|rot13}
PHP: <?=$simple?>
Chained: { $simple | rot13 | substr : 0 : 5 }
Args: {"Hello %s!"|args:"world"}
With variable in modifier: {$simple|replace:'Hello|:':$html}
With variable in modifier and smarty syntax: {$simple|replace:'Hello':$html}
Call to magic var in param: {$simple|replace:'Hello':$object.array.key1}
String with modifier: {"Hello"|replace:"lo":"LLLAAAA\"''!"}
Magic variable: {$object.array.key1}
Object variable: {$this.template_path}
Object static variable: {$this::$cache_dir}
Local variable from include: {$local}
Constant from class: {$testclass.TEST_CONSTANT}