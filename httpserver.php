#!/usr/local/bin/php -q
<?php
error_reporting(E_ALL);
ini_set('display_errors', true);
date_default_timezone_set('Europe/Amsterdam');
set_time_limit(0);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', false);
ini_set('implicit_flush', true);
ob_implicit_flush(true);

require 'include/Loader.php';
(new cHttpServer())->run();
?>