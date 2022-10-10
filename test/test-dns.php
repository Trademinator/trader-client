<?php
require_once __DIR__ . '/../vendor/autoload.php';

$a = dns_get_record('version.trademinator.com', DNS_TXT);
print_r($a);


$aa = dns_get_record('download.trademinator.com', DNS_TXT);
print_r($aa);
echo sizeof($aa).PHP_EOL;


echo \OKayInc\Trademinator::$version.PHP_EOL;

if (version_compare(\OKayInc\Trademinator::$version, $a[0]['txt']) == -1){
	echo 'It is time to update. You are currently running Trademinator '.\OKayInc\Trademinator::$version.'. Latest release is '.$a[0]['txt'].PHP_EOL;
	echo 'You can download latest release from '.$aa[0]['txt'].PHP_EOL;
}

//$aaa = dns_get_record('motd.trademinator.com', DNS_TXT);
//print_r($aaa);
//echo sizeof($aaa).PHP_EOL;

show_motd();

function show_motd(){
//	if (is_null($this->last_motd) || ((time() - $this->last_motd) > 14400)){
		$motd = dns_get_record('motd.trademinator.com', DNS_TXT);
		foreach ($motd as $m){
//			$this->logger->info($m['txt'].PHP_EOL);
			echo 'MotD: '.$m['txt'].PHP_EOL;
		}
//		$this->last_motd = time();
//	}
}
