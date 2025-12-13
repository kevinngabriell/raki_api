<?php
header('Content-Type: text/plain');

echo "SAPI=" . php_sapi_name() . PHP_EOL;
echo "PHP=" . PHP_VERSION . PHP_EOL;
echo "curl_init=" . (function_exists('curl_init') ? "yes" : "no") . PHP_EOL;

if (!function_exists('curl_version')) exit;
print_r(curl_version());