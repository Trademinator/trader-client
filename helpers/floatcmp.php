<?php

define ("TRADEMINATOR_MIN_DIFFERENCE", 0.00001);

function floatcmp(float $a, float $b, float $min = TRADEMINATOR_MIN_DIFFERENCE){
	$result = null;

	if ($b == 0){
		if ($a == 0){
			$result = 0;
		}
		elseif ($a < 0){
			$result = -1;
		}
		elseif ($a > 0){
			$result = 1;
		}
	}
	else{
		if (abs(($a - $b) / $b) < $min) {
			$result = 0;
		}
		elseif (($a - $b) < 0){
			$result = -1;
		}
		elseif (($a - $b) > 0){
			$result = 1;
		}
	}
	return $result;
}
