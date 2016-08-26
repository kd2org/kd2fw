#!/bin/sh

([ "$1" = "" ] || [ ! -d "$1"] || [ "$2" = "" ] || [ ! -d "$2" ]) && echo "Usage: $0 LOCALES_DIRECTORY SOURCE_DIRECTORY" && exit 1


for LANG in `find "$1" -max-depth 1 -type d -not -name '.'`
do
	mkdir -p $LANG/LC_MESSAGES

	find "$2" -iname '*.php*' | xargs xgettext --from-code=utf-8 -o $LANG/messages.po \
		-L PHP -j -a -c -kKD2\gettext -kKD2\ngettext -kKD2\dgettext -kKD2\dngettext -kKD2\dcngettext \
		-kKD2\dpgettext -kKD2\dcpgettext -kKD2\pgettext -kKD2\dcnpgettext -kKD2\npgettext \
		-kKD2\_ -k"Translate::string" -k"Translate::plural"
done