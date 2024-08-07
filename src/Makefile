.PHONY: all release phpstan tests test

test: phpstan

tests:
	php tests/db.php
	php tests/DB/Entity.php
	php tests/DB/SQLite3.php

phpstan:
	phpstan.phar analyze -c phpstan.neon lib/KD2

all:

release:
	cd lib && zip -r KD2-7.4.zip KD2
	fossil uv rm KD2-7.4.zip
	fossil uv sync # This is to avoid a bug in Fossil 2.3
	fossil uv add lib/KD2-7.4.zip --as KD2-7.4.zip
	fossil uv sync
	rm -f lib/KD2-7.4.zip