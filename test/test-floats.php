<?php
require_once __DIR__ . '/../vendor/autoload.php';

define ("TRADEMINATOR_MIN_DIFFERENCE", 0.00001);

$a = -0.171;
$b = 1 - 0.83; //0.17

$c = floatcmp($a, $b);
echo "floatcmp(float $a, float $b, float min = TRADEMINATOR_MIN_DIFFERENCE) = $c".PHP_EOL;
