<?php
	require_once(__DIR__ . '/../ste.php');
	$ste = new ste(__DIR__ . '/templates');
	$ste->show('extended', __DIR__ . '/templates_cache');
?>