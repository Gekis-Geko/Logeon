<?php

declare(strict_types=1);

/**
 * Narrative Combat module Tier2 smoke.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-narrative-combat-tier2-runtime.php
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

function combatTier2SmokeAssert(bool $condition, string $message): void
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
    combatTier2SmokeAssert(is_file($moduleBootstrapFile), 'Missing narrative-combat bootstrap.php');

    $bootstrapFn = require $moduleBootstrapFile;
    combatTier2SmokeAssert(is_callable($bootstrapFn), 'Narrative-combat bootstrap must return a callable.');

    $manifest = [
        '_path' => $root . '/modules/logeon.narrative-combat',
    ];
    $bootstrapFn(null, $manifest);

    $db = DbAdapterFactory::createFromConfig();
    $moduleId = 'logeon.narrative-combat';
    $key = 'combat_tier_level';

    $row = $db->query(
        'SELECT id, setting_value FROM sys_module_settings '
        . 'WHERE module_id = ' . $db->safe($moduleId)
        . ' AND setting_key = ' . $db->safe($key)
        . ' LIMIT 1',
    )->first();

    $hadRow = is_object($row) && isset($row->id);
    $originalTier = $hadRow ? (int) ($row->setting_value ?? 1) : 1;

    if (!$hadRow) {
        $db->query(
            'INSERT INTO sys_module_settings SET '
            . 'module_id = ' . $db->safe($moduleId) . ', '
            . 'setting_key = ' . $db->safe($key) . ', '
            . 'setting_value = ' . $db->safe('2') . ', '
            . 'value_type = ' . $db->safe('number'),
        );
    } else {
        $db->query(
            'UPDATE sys_module_settings SET '
            . 'setting_value = ' . $db->safe('2') . ' '
            . 'WHERE id = ' . $db->safe((int) $row->id, false),
        );
    }

    $tierService = new \Logeon\NarrativeCombat\Services\CombatTierService();
    $tierLevel = $tierService->getTierLevel();
    combatTier2SmokeAssert($tierLevel >= 2, 'Expected combat_tier_level >= 2 in Tier2 smoke.');
    $tierService->ensureTierAtLeast(2, 'phase');

    $phaseService = new \Logeon\NarrativeCombat\Services\CombatPhaseService();
    $phaseInfo = $phaseService->getCurrentPhaseInfo(0);
    combatTier2SmokeAssert(is_array($phaseInfo) && isset($phaseInfo['phase']), 'Phase service not available in Tier2 smoke.');

    $groupService = new \Logeon\NarrativeCombat\Services\CombatGroupService();
    $groupSummary = $groupService->getSideSummary(0);
    combatTier2SmokeAssert(is_array($groupSummary) && array_key_exists('sides', $groupSummary), 'Group service not available in Tier2 smoke.');

    $environmentService = new \Logeon\NarrativeCombat\Services\CombatEnvironmentService();
    $environment = $environmentService->narrativeDescription(0);
    combatTier2SmokeAssert(is_array($environment) && array_key_exists('conditions', $environment), 'Environment service not available in Tier2 smoke.');

    if (!$hadRow) {
        $db->query(
            'DELETE FROM sys_module_settings '
            . 'WHERE module_id = ' . $db->safe($moduleId)
            . ' AND setting_key = ' . $db->safe($key)
            . ' LIMIT 1',
        );
    } else {
        $db->query(
            'UPDATE sys_module_settings SET '
            . 'setting_value = ' . $db->safe((string) $originalTier) . ' '
            . 'WHERE id = ' . $db->safe((int) $row->id, false),
        );
    }

    fwrite(STDOUT, "[OK] Narrative combat Tier2 smoke passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
