<?php
require_once __DIR__ . '/../vendor/autoload.php';

$fn = file_exists('trademinator.cfg')?'trademinator.cfg':null;

$c = new \OKayInc\Trademinator\Config($fn);

$c->setup();

var_dump($c);
