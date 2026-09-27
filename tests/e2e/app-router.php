<?php

/**
 * Router for PHP's built-in server that behaves like Web Station: only public/ is served.
 * Run it with: php -S 127.0.0.1:18080 -t public tests/e2e/app-router.php
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/' || $path === '') {
    require dirname(__DIR__, 2) . '/public/index.php';

    return true;
}
if (preg_match('~^/(index|auth|upload)\.php$~', $path)) {
    require dirname(__DIR__, 2) . '/public' . $path;

    return true;
}

return false; // static files from public/
