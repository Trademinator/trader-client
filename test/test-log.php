<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

$log = new \Monolog('name');
$log->pushHandler(new Monolog\Handler\StreamHandler(__DIR__.'/test-log.log', Logger::WARNING));

// add records to the log
$log->warning('Foo');
$log->error('Bar');
