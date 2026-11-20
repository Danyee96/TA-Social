<?php
/**
 * TA Toolkit · Social Command — configuration
 *
 * The web installer (install.php) writes config.php for you.
 * To configure by hand instead: copy this file to config.php and fill it in.
 */
return [
    'db' => [
        // 'mysql' for Hostinger (recommended) or 'sqlite' for a quick local test
        'driver'      => 'mysql',
        'host'        => 'localhost',
        'port'        => 3306,
        'name'        => 'u000000000_social',
        'user'        => 'u000000000_social',
        'pass'        => 'change-me',
        // Only used when driver = sqlite
        'sqlite_path' => __DIR__ . '/storage/app.sqlite',
    ],
    'app' => [
        'name'     => 'TA Toolkit · Social Command',
        // Server timezone for timestamps (activity log, "published at")
        'timezone' => 'America/New_York',
        // Set to true once the site runs on HTTPS (Hostinger gives free SSL)
        'force_https' => false,
        // Show PHP errors (keep false on the live site)
        'debug' => false,
    ],
];
