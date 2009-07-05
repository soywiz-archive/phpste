<?php
	require_once(__DIR__ . '/../ste.php');
	$template = new template(__DIR__ . '/templates');
	$template->show('extended', __DIR__ . '/templates_cache');
?>