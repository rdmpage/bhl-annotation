<?php

// make a manifest from IA identifier

error_reporting(E_ALL);

require (dirname(__FILE__) . '/ia.php');

$ia = 'entomologist451912brit';
$ia = 'austrobaileya1quee';

$manifest = get_manifest($ia, true);

print_r($manifest);

?>

