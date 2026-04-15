<?php

declare(strict_types=1);

/**
 * Quests maintenance runner (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/quests-maintenance.php
 * Optional:
 *   C:\xampp\php\php.exe scripts/php/quests-maintenance.php --force=1
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

use App\Services\QuestProgressService;

try {
    $force = false;
    foreach ($argv as $arg) {
        if (strpos((string) $arg, '--force=') === 0) {
            $force = ((int) substr((string) $arg, 8) === 1);
        }
    }

    $service = new QuestProgressService();
    $result = $service->maintenanceRun($force);
    $expired = is_array($result['expired_ids'] ?? null) ? count($result['expired_ids']) : 0;

    fwrite(STDOUT, '[OK] Quests maintenance completed. expired=' . $expired . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
