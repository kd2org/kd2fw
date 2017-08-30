#!/bin/bash

set -eu

EXPECTED_NUMBER=3

cd errormanager

echo > error.log

php -d max_execution_time=1 timeout.php 2> /dev/null || echo -n .

php syntax.php 2> /dev/null || echo -n .
php assertion.php 2> /dev/null || echo -n .

NUMBER=$(fgrep '== Error ref' error.log | wc -l)

[ $NUMBER = $EXPECTED_NUMBER ] || echo "FAIL"