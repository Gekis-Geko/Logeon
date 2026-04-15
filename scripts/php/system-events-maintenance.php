<?php

declare(strict_types=1);

/**
 * System events maintenance runner (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/system-events-maintenance.php
 * Optional:
 *   C:\xampp\php\php.exe scripts/php/system-events-maintenance.php --force=1
 */

$root = dirname(__DIR__, 2);

$bootstrap = [
    $root . '/configs/config.php',
    $root . '/configs/db.php',
    $root . '/configs/app.php',
    $root . '/vendor/autoload.php',
];

foreach ($bootstrap as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "[FAIL] Missing bootstrap file: {$file}\n");
        exit(1);
    }
    require_once $file;
}

$customBootstrap = $root . '/custom/bootstrap.php';
if (is_file($customBootstrap)) {
    require_once $customBootstrap;
}

use App\Services\SystemEventService;

try {
    $force = false;
    foreach ($argv as $arg) {
        if (strpos((string) $arg, '--force=') === 0) {
            $force = ((int) substr((string) $arg, 8) === 1);
        }
    }

    $service = new SystemEventService();
    $result = $service->maintenanceRun($force);

    $activated = is_array($result['activated_ids'] ?? null) ? count($result['activated_ids']) : 0;
    $completed = is_array($result['completed_ids'] ?? null) ? count($result['completed_ids']) : 0;
    $generated = is_array($result['generated_ids'] ?? null) ? count($result['generated_ids']) : 0;

    fwrite(STDOUT, '[OK] System events maintenance completed. activated=' . $activated . ', completed=' . $completed . ', generated=' . $generated . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
