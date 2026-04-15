<?php

declare(strict_types=1);

/**
 * Core DB runtime sanity check (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-core-db-runtime.php
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

use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;
use Core\Models;

try {
    $adapter = DbAdapterFactory::createFromConfig();
    if (!$adapter instanceof MysqliDbAdapter) {
        fwrite(STDERR, "[FAIL] DbAdapterFactory did not return MysqliDbAdapter.\n");
        exit(1);
    }

    $row = $adapter->query('SELECT 1 AS ok')->first();
    $ok = (int) ($row->ok ?? 0);
    if ($ok !== 1) {
        fwrite(STDERR, "[FAIL] Adapter query check failed (expected ok=1).\n");
        exit(1);
    }

    Models::setDbAdapter($adapter);
    $row2 = Models::query('SELECT 1 AS ok')->first();
    $ok2 = (int) ($row2->ok ?? 0);
    if ($ok2 !== 1) {
        fwrite(STDERR, "[FAIL] Models::query check failed (expected ok=1).\n");
        exit(1);
    }

    fwrite(STDOUT, "[OK] Core DB runtime sanity check passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
