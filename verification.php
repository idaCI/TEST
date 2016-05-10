<?php
require_once './VerificationClass.php';

$VerificationClass = new VerificationClass();
$dummy = '';
$a = '';

if (isset($a)) {
	echo $VerificationClass->fizzBuzz(100);
}

echo $VerificationClass->fizzBuzz(100);
echo $VerificationClass->fizzBuzz(200);
echo $VerificationClass->fizzBuzz(300);
echo $VerificationClass->fizzBuzz(400);

if (isset($a)) {
	echo $VerificationClass->fizzBuzz(100);
}

