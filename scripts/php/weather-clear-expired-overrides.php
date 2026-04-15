<?php

declare(strict_types=1);

/**
 * Weather maintenance utility (CLI).
 *
 * Clears expired location weather overrides from DB.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/weather-clear-expired-overrides.php
 */

$root = dirname(__DIR__, 2);

$bootstrap = [
    $root . '/configs/config.php',
    $root . '/configs/db.php',
    $root . '/vendor/autoload.php',
];

foreach ($bootstrap as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "[FAIL] Missing bootstrap file: {$file}\n");
        exit(1);
    }
    require_once $file;
}

try {
    $service = new \App\Services\WeatherOverrideService();
    $count = $service->clearExpiredOverrides();
    fwrite(STDOUT, "[OK] Expired weather overrides removed: {$count}\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
