<?php
require_once __DIR__ . '/../vendor/autoload.php';
date_default_timezone_set('UTC');
$short = '';
$long = array('filename:','debug:','exchange:', 'symbol:', 'period:');
$options = getopt($short, $long);

$debug = array_key_exists('debug', $options)?(strtoupper($options['debug'])):'WARNING';
$exchange = array_key_exists('exchange', $options)?(strtolower($options['exchange'])):'bitso';
$symbol = array_key_exists('symbol', $options)?(strtoupper($options['symbol'])):'BTC/USD';
$period = array_key_exists('period', $options)?(strtoupper($options['period'])):'15m';
$filename = array_key_exists('filename', $options)?(strtoupper($options['filename'])):'trademinator.cfg';

$fn = file_exists($filename)?'trademinator.cfg':null;
$c = new \OKayInc\Trademinator\Config($fn);
//$c->setup();
var_dump($c);

$c->setExchange_name($exchange);
$c->setSymbol($symbol);
var_dump($c->getExchange_name());
var_dump($c->getSymbol());
var_dump($c->safe_value('mode','abcd'));
var_dump($c->safe_value('secret', 'abc'));
