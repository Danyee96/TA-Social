<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['SCRIPT_NAME'] = 'install.php'; // Let CLI report a missing config without an HTTP redirect.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/schema.php';

if ($CONFIG === null || !social_enabled()) {
    fwrite(STDERR, "Install TA Social and configure the Apify token before running the social sync.\n");
    exit(1);
}

try {
    migrate();
    echo now() . ': processed ' . social_tick() . " social profiles.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Social sync could not run. Check database access and the server configuration.\n");
    exit(1);
}
