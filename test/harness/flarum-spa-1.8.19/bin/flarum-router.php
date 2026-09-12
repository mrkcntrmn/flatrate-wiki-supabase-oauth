<?php

declare(strict_types=1);

$public = __DIR__.'/public';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$candidate = $public.$uri;

if ($uri !== '/' && is_file($candidate)) {
    return false;
}

chdir($public);
require $public.'/index.php';
