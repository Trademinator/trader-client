<?php
namespace ccxt;

// ----------------------------------------------------------------------------

// PLEASE DO NOT EDIT THIS FILE, IT IS GENERATED AND WILL BE OVERWRITTEN:
// https://github.com/ccxt/ccxt/blob/master/CONTRIBUTING.md#how-to-contribute-code

// -----------------------------------------------------------------------------

function test_leverage_tier($exchange, $method, $tier) {
    $format = array(
        'tier' => 1,
        'minNotional' => 0,
        'maxNotional' => 5000,
        'maintenanceMarginRate' => 0.01,
        'maxLeverage' => 25,
        'info' => array(),
    );
    $keys = is_array($format) ? array_keys($format) : array();
    for ($i = 0; $i < count($keys); $i++) {
        $key = $keys[$i];
        assert (is_array($tier) && array_key_exists($key, $tier));
    }
    assert ((is_float($tier['tier']) || is_int($tier['tier'])));
    assert ((is_float($tier['minNotional']) || is_int($tier['minNotional'])));
    assert ((is_float($tier['maxNotional']) || is_int($tier['maxNotional'])));
    assert ((is_float($tier['maintenanceMarginRate']) || is_int($tier['maintenanceMarginRate'])));
    assert ((is_float($tier['maxLeverage']) || is_int($tier['maxLeverage'])));
    assert ($tier['tier'] >= 0);
    assert ($tier['minNotional'] >= 0);
    assert ($tier['notionalCap'] >= 0);
    assert ($tier['maintenanceMarginRate'] <= 1);
    assert ($tier['maxLeverage'] >= 1);
    var_dump ($exchange->id, $method, $tier['tier'], $tier['minNotional'], $tier['maxNotional'], $tier['maintenanceMarginRate'], $tier['maxLeverage']);
    return $tier;
}

