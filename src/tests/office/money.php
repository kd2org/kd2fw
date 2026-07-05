<?php

use KD2\Test;
use KD2\Office\Money;

require __DIR__ . '/../_assert.php';

function test_round2()
{
    Test::strictlyEquals('1.00', Money::round2('1.004'));
    Test::strictlyEquals('1.01', Money::round2('1.005'));
    Test::strictlyEquals('1.01', Money::round2('1.006'));
    Test::strictlyEquals('1.01', Money::round2('1.009'));
    Test::strictlyEquals('0.00', Money::round2('0.0049'));
    Test::strictlyEquals('2.00', Money::round2('1.995'));
}

test_round2();

function test_calc()
{
	Test::strictlyEquals('1', Money::calc('2.0000', '-', '1.000'));
	Test::strictlyEquals('1', Money::calc('2.0010', '-', '1.001'));
	Test::strictlyEquals('1.049', Money::calc('1.041', '+', '0.008'));
	Test::strictlyEquals('1.002', Money::calc('0.50100', '*', '2.000000'));
	Test::strictlyEquals('1.002444', Money::calc('0.501222', '*', '2.000000'));

	// Value will be too large
	Test::exception(\LogicException::class, fn() => Money::calc('1.2542232323', '*', '26.0000200020'));
	Test::strictlyEquals('32.60980648844646', Money::calc('1.25422323', '*', '26.000002'));

	// Max. 10 decimals
	Test::exception(\InvalidArgumentException::class, fn() => Money::calc('1.25422323233', '*', '26.0000200020'));
}

test_calc();
