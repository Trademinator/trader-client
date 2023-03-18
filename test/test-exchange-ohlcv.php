<?php
require_once __DIR__ . '/../vendor/autoload.php';
use OKayInc\Trademinator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
defined("TRADEMINATOR_ROOTDIR") or define("TRADEMINATOR_ROOTDIR", __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR);
defined("TRADEMINATOR_LOGS") or define("TRADEMINATOR_LOGS", __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR);

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
$e = new \okayinc\trademinator\client($exchange, $symbol, $c, Trademinator::DEBUG|Trademinator::ERROR|Trademinator::WARNING|Trademinator::NOTICE|Trademinator::INFO);
$go_back = strtotime('-30 day') * 1000;
$ohlcv = $e->get_exchange()->fetch_ohlcv ($symbol, $period, $go_back, 999);

print_r($ohlcv);
