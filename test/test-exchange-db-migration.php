<?php
defined("TRADEMINATOR_ROOTDIR") or define("TRADEMINATOR_ROOTDIR", __DIR__ . DIRECTORY_SEPARATOR);

require_once __DIR__ . '/../vendor/autoload.php';
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

$fn = file_exists($filename)?'trademinator.cfg':null;

$c = new \OKayInc\Trademinator\Config($fn);
$e = new \okayinc\trademinator\client($exchange, $symbol, $c, Trademinator::DEBUG|Trademinator::ERROR|Trademinator::WARNING|Trademinator::NOTICE|Trademinator::INFO);
$db = new \SQLite3('trademinator.db', SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$db->enableExceptions(true);
$db->exec('PRAGMA journal_mode = wal;');
$db->enableExceptions(true);
$e->set_db($db);
$e->migrate_to_db(null);
$e->find_fullfillments();

exit;

for ($i = 0.01; $i <= 100000; $i += 2000){
	$possible_prices[] = $i;
}
foreach($possible_prices as $p){
	$a = $e->has_a_buy_to_compensate($p);
	echo '$p: '.$p.'; $a: '.$a.PHP_EOL;
}

$ex = $e->find_excess();
print_r($ex);
