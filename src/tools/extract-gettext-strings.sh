#!/bin/sh

set -eu

( [ -z ${1+x} ] || [ ! -d "$1" ] || [ -z ${2+x} ] || [ ! -d "$2" ] ) && (echo "Usage: $0 LOCALES_DIRECTORY SOURCE_DIRECTORY" ; exit 1)


for LANG in `find "$1" -mindepth 1 -maxdepth 1 -type d -not -name '.'`
do
	mkdir -p $LANG/LC_MESSAGES

	find "$2" -iname '*.php*' -type f | xargs xgettext \
		--from-code=utf-8 -p $LANG/LC_MESSAGES -L PHP -c \
		-k"Translate::gettext:1,2,5c" \
		-k"string:1,4c" -k"plural:1,2,6c" \
		-k"pgettext:1c,2" -k"dpgettext:2c,3" -k"dcpgettext:2c,3" \
		-k"npgettext:1c,2,3" -k"dnpgettext:2c,3,4" -k"dcnpgettext:2c,3,4"
done