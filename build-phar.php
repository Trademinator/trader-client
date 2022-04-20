<?php
ini_set("phar.readonly", 0);
try{
	echo 'Building PHAR...';
	$pharFile = 'trademinator-client.phar';
	if (file_exists($pharFile)) 
		unlink($pharFile);

	if (file_exists($pharFile . '.gz'))
		unlink($pharFile . '.gz');

	$phar = new Phar($pharFile);
	$phar->startBuffering();
	$defaultStub = $phar->createDefaultStub('main.php');

	$phar->buildFromDirectory(__DIR__, '/\.php$/');
	$phar->buildFromDirectory(__DIR__ . '/vendor');
	$phar->buildFromDirectory(__DIR__ . '/helpers');

	$stub = "#!/usr/bin/env php \n" . $defaultStub;
	$phar->setStub($stub);

	$phar->stopBuffering();
	$phar->compressFiles(Phar::GZ);
	chmod(__DIR__ . '/'.$pharFile, 0770);
}
catch (Exception $e){
	echo $e->getMessage();
}
