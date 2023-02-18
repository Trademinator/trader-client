<?php
require_once __DIR__ . '/../vendor/autoload.php';
use OKayInc;
use OKayInc\Trademinator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

date_default_timezone_set('UTC');
$short = '';
$long = array('filename:','debug:','exchange:', 'symbol:', 'period:');
$options = getopt($short, $long);

$debug = array_key_exists('debug', $options)?(strtoupper($options['debug'])):'WARNING';
$exchange = array_key_exists('exchange', $options)?(strtolower($options['exchange'])):'bitso';
$symbol = array_key_exists('symbol', $options)?(strtoupper($options['symbol'])):'BTC/USD';
$period = array_key_exists('period', $options)?(strtoupper($options['period'])):'15m';
$filename = array_key_exists('filename', $options)?(strtoupper($options['filename'])):'trademinator.cfg';

$o = new \okayinc\trademinator\Orchestor($filename);
var_dump($o);
