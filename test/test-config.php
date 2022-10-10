<?php
require_once __DIR__ . '/../vendor/autoload.php';

$fn = file_exists('trademinator.cfg')?'trademinator.cfg':null;

$c = new \OKayInc\Trademinator\Config($fn);

//$c->setup();

var_dump($c);

$c->setExchange_name('bitso');
$c->setSymbol('BTC/USD');
var_dump($c->getExchange_name());
var_dump($c->getSymbol());
var_dump($c->safe_value('mode','abcd'));
var_dump($c->safe_value('secret', 'abc'));
