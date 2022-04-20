<?php

function stddev($values){
//	global $debug;
//	if ($debug){
//		echo '>>> '.__FUNCTION__.'(values)'.PHP_EOL;
//	}
	$num_of_elements = count($values);
	$variance = 0.0;
	$average = array_sum($values)/$num_of_elements;

	foreach($values as $i){
		$variance += pow(($i - $average), 2);
	}

	$answer = (float)sqrt($variance/$num_of_elements);
//	if ($debug){
//		echo '>>> '.__FUNCTION__.'(values) = '.$answer.PHP_EOL;
//	}

	return $answer;
}
