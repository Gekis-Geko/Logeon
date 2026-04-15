<?php

declare(strict_types=1);

/**
 * Narrative Combat module Tier1 smoke.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-narrative-combat-tier1-runtime.php
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

use Core\Database\DbAdapterFactory;
use Core\Http\AppError;

function combatTierSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $moduleBootstrapFile = $root . '/modules/logeon.narrative-combat/bootstrap.php';
    combatTierSmokeAssert(is_file($moduleBootstrapFile), 'Missing narrative-combat bootstrap.php');

    $bootstrapFn = require $moduleBootstrapFile;
    combatTierSmokeAssert(is_callable($bootstrapFn), 'Narrative-combat bootstrap must return a callable.');

    $manifest = [
        '_path' => $root . '/modules/logeon.narrative-combat',
    ];
    $bootstrapFn(null, $manifest);

    $tierService = new \Logeon\NarrativeCombat\Services\CombatTierService();
    $tierService->ensureDefaultSetting();

    $db = DbAdapterFactory::createFromConfig();
    $moduleId = 'logeon.narrative-combat';
    $settingKey = 'combat_tier_level';

    $rowBefore = $db->query(
        'SELECT id, setting_value FROM sys_module_settings '
        . 'WHERE module_id = ' . $db->safe($moduleId)
        . ' AND setting_key = ' . $db->safe($settingKey)
        . ' LIMIT 1',
    )->first();

    $originalTier = (int) ($rowBefore->setting_value ?? 1);

    // For deterministic Tier1 smoke, force tier=1 only for test scope.
    $db->query(
        'UPDATE sys_module_settings SET setting_value = ' . $db->safe('1')
        . ' WHERE module_id = ' . $db->safe($moduleId)
        . ' AND setting_key = ' . $db->safe($settingKey)
        . ' LIMIT 1',
    );

    try {
        $tierLevel = $tierService->getTierLevel();
        combatTierSmokeAssert($tierLevel === 1, 'Expected effective combat_tier_level=1 during Tier1 smoke.');

        $controller = new \Logeon\NarrativeCombat\Controllers\NarrativeCombatController();

        $featureBlocked = false;
        try {
            $controller->phaseGet();
        } catch (AppError $e) {
            $featureBlocked = ($e->errorCode() === 'combat_feature_not_enabled');
        }

        combatTierSmokeAssert($featureBlocked, 'Tier2 endpoint /combat/phase must be gated with combat_feature_not_enabled.');

        $row = $db->query(
            'SELECT setting_value FROM sys_module_settings '
            . 'WHERE module_id = ' . $db->safe($moduleId)
            . ' AND setting_key = ' . $db->safe($settingKey)
            . ' LIMIT 1',
        )->first();

        combatTierSmokeAssert(is_object($row), 'Missing sys_module_settings row for combat_tier_level.');
        combatTierSmokeAssert(((int) ($row->setting_value ?? 0)) === 1, 'combat_tier_level value must be 1 during Tier1 smoke.');
    } finally {
        // Restore previous tier so Tier2 environments are not altered by this smoke.
        $restoreTier = (string) max(1, $originalTier);
        $db->query(
            'UPDATE sys_module_settings SET setting_value = ' . $db->safe($restoreTier)
            . ' WHERE module_id = ' . $db->safe($moduleId)
            . ' AND setting_key = ' . $db->safe($settingKey)
            . ' LIMIT 1',
        );
    }

    fwrite(STDOUT, "[OK] Narrative combat Tier1 smoke passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
