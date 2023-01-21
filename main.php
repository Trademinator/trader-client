<?php
declare(ticks = 1);

if (PHP_VERSION_ID < 80100){
	echo 'You need PHP 8.1+ to execute Trademinator '.PHP_EOL;
	exit(1);
}

if (!verify_extensions()){
	exit(1);
}

use OKayInc\Trademinator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

defined("TRADEMINATOR_ROOTDIR") or define("TRADEMINATOR_ROOTDIR", __DIR__ . DIRECTORY_SEPARATOR);
defined("TRADEMINATOR_LOGS") or define("TRADEMINATOR_LOGS", __DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR);

require_once __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('UTC');
$short = '';
$long = array('filename:','debug:','dry','times:','reset-config','command:', 'yes');
$options = getopt($short, $long);
$reset_config = array_key_exists('reset-config', $options);
$debug = array_key_exists('debug', $options)?(strtoupper($options['debug'])):'WARNING';
$filename = array_key_exists('filename', $options)?($options['filename']):'trademinator.cfg';
$times = array_key_exists('times', $options)?intval($options['times']):null;
$command = array_key_exists('command', $options)?($options['command']):null;
$yes = array_key_exists('yes', $options);

verify_version();

$_loglevel = 0;
switch($debug){
	case 'DEBUG':
		$_loglevel |= Trademinator::DEBUG;
	case 'ERROR':
		$_loglevel |= Trademinator::ERROR;
	case 'WARNING':
		$_loglevel |= Trademinator::WARNING;
	case 'NOTICE':
		$_loglevel |= Trademinator::NOTICE;
	case 'INFO':
		$_loglevel |= Trademinator::INFO;
		break;
	default:
		$_loglevel = Trademinator::INFO | Trademinator::NOTICE;
		break;
}

if (!is_dir(TRADEMINATOR_LOGS)) {
	mkdir(TRADEMINATOR_LOGS);
}

if ($reset_config && file_exists($filename)){
	unlink($filename);
}

if (!file_exists($filename)){
	$first_config = new \OKayInc\Trademinator\Config($filename);
	$first_config->setup();
}

if ($times == 0){
	$times = null;
}

if ($yes){
	$conformity = true;
}
else{
	$conformity = ask_bool('Do you understand and accept the inherit risks and consequences of cryptocurrency trading? Yes or No: ', false);
}

if ($conformity){
	$o = new \OKayInc\Trademinator\Orchestor($filename, $_loglevel);

	if (!is_null($command)){
		list($command, $arguments) = explode(' ', $command, 2);

		switch ($command){
			case 'buy':
			case 'sell':
				$matches = array();
				if (preg_match('/^(\d+(\.\d+)?)\s+(\w{2,}\/\w{2,})\s+on\s+(\w+)\s+at\s+(\d+(\.\d+)?)/', $arguments, $matches)){
					$amount_xxx = floatval($matches[1]);
					$symbol = strtoupper($matches[3]);
					$exchange_name = strtolower($matches[4]);
					$price = floatval($matches[5]);

					$o->operate_exchange($command, $exchange_name, $symbol, $price, $amount_xxx);
				}
				else{
					echo 'Invalid arguments: {buy|sell} 999.999 XXX/YYY on exchange at 999.999'.PHP_EOL;
				}
		}

		exit;
	}

	$o->run($times);
}
else{
	echo 'You can not run Trademinator until you accept this, you must type "yes" (case insensitive).'.PHP_EOL;
}


function verify_extensions():bool {
	$answer = true;
	$extensions = ['bcmath','curl','mbstring','json','gmp','sqlite3'];
	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		$prefix = 'php_';
		$sufix = '';
	}
	else{
		$prefix = '';
		$sufix = '.'.PHP_SHLIB_SUFFIX;
	}
	foreach ($extensions as $extension){
		if (!verify_specific_extension($extension, $prefix, $sufix)){
			$answer = false;
			break;
		}
	}
	
	return $answer;
}

function verify_specific_extension(string $extension, string $prefix = '', string $sufix = ''): bool{
	$answer = true;
	if (!extension_loaded($extension)){
		if (!dl($prefix . $extension . $sufix)){
			$answer = false;
			echo 'Could not load extension '.$extension.'. Please fix this isse and try again.'.PHP_EOL;
		}
	}
	return $answer;
}

function ask_bool($prompt, ?bool $default): bool {
	$options = array();
	$options['flags'] = FILTER_NULL_ON_FAILURE;
	if (is_bool($default)){
		$options['options']['default'] = $default;
	}
	do{
		$line = readline($prompt);
		if (strlen($line) == 0 && is_bool($default)){
			$line = $default;
		}
		$answer = filter_var($line, FILTER_VALIDATE_BOOLEAN, $options);		// Bool has a different logic
	}while (is_null($answer));
	return $answer;
}

function verify_version(){
	$a = dns_get_record('version.trademinator.com', DNS_TXT);
	$aa = dns_get_record('download.trademinator.com', DNS_TXT);

	if (sizeof($a) > 0){
		if (version_compare(\OKayInc\Trademinator::$version, $a[0]['txt']) == -1){
			echo 'It is time to update. You are currently running Trademinator '.\OKayInc\Trademinator::$version.'. Latest release is '.$a[0]['txt'].PHP_EOL;
			echo 'You can download latest release from '.$aa[0]['txt'].PHP_EOL;
		}
	}
}
