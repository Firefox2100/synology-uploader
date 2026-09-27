<?php

declare(strict_types=1);

use SynoUploader\App;
use SynoUploader\Config;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $app = new App(Config::load($root), $root);
    $removed = $app->tusServer()->cleanup();
    fwrite(STDOUT, "Removed $removed expired upload(s).\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Cleanup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
