<?php
require_once __DIR__ . '/../vendor/autoload.php';

$a = dns_get_record('version.trademinator.com', DNS_TXT);
print_r($a);


$aa = dns_get_record('download.trademinator.com', DNS_TXT);
print_r($aa);


echo \OKayInc\Trademinator::$version.PHP_EOL;

if (version_compare(\OKayInc\Trademinator::$version, $a[0]['txt']) == -1){
	echo 'It is time to update. You are currently running Trademinator '.\OKayInc\Trademinator::$version.'. Latest release is '.$a[0]['txt'].PHP_EOL;
	echo 'You can download latest release from '.$aa[0]['txt'].PHP_EOL;
}
