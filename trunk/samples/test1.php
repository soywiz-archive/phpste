<?php
require_once(__DIR__ . '/../ste.php');
$ste = new ste\ste(__DIR__ . '/templates', null);
$ste->show('extended', array(
	'var' => 'test',
	'list' => array('a', 'b', 'c')
));
?>