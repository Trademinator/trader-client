<?php
require_once __DIR__ . '/../vendor/autoload.php';
use OKayInc;
use OKayInc\Trademinator;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;

$o = new \okayinc\trademinator\Orchestor('trademinator.cfg');
var_dump($o);
