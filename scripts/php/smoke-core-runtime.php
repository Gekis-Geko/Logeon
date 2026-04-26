<?php

declare(strict_types=1);

/**
 * Core runtime smoke suite (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-core-runtime.php
 */

$root = dirname(__DIR__, 2);
$php = PHP_BINARY;

/**
 * @return mysqli|null
 */
function coreSmokeOpenDb(string $root)
{
    if (!defined('DB')) {
        $dbConfig = $root . '/configs/db.php';
        if (is_file($dbConfig)) {
            require_once $dbConfig;
        }
    }

    if (!defined('DB') || !is_array(DB) || !isset(DB['mysql']) || !is_array(DB['mysql'])) {
        return null;
    }

    $cfg = DB['mysql'];
    $host = (string) ($cfg['host'] ?? 'localhost');
    $dbName = (string) ($cfg['db_name'] ?? '');
    $user = (string) ($cfg['user'] ?? 'root');
    $pwd = (string) ($cfg['pwd'] ?? '');

    if ($dbName === '') {
        return null;
    }

    $db = @new mysqli($host, $user, $pwd, $dbName);
    if ($db->connect_errno) {
        return null;
    }

    return $db;
}

function coreSmokeTableExists($db, string $table): bool
{
    if (!($db instanceof mysqli)) {
        return false;
    }

    $tableEscaped = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$tableEscaped}'");
    if (!($result instanceof mysqli_result)) {
        return false;
    }

    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

function coreSmokeIsModuleActive($db, string $moduleId): bool
{
    if (!($db instanceof mysqli)) {
        return false;
    }

    if (!coreSmokeTableExists($db, 'sys_modules')) {
        return false;
    }

    $moduleEscaped = $db->real_escape_string($moduleId);
    $result = $db->query(
        "SELECT status FROM sys_modules WHERE module_id = '{$moduleEscaped}' LIMIT 1",
    );

    if (!($result instanceof mysqli_result)) {
        return false;
    }

    $row = $result->fetch_assoc();
    $result->free();
    if (!is_array($row)) {
        return false;
    }

    return isset($row['status']) && (string) $row['status'] === 'active';
}

$db = coreSmokeOpenDb($root);
$abilitiesModuleActive = coreSmokeIsModuleActive($db, 'logeon.abilities-spells');
$narrativeStatesCoreAvailable =
    coreSmokeTableExists($db, 'narrative_states')
    && coreSmokeTableExists($db, 'applied_narrative_states');
$narrativeCoherenceAvailable =
    coreSmokeTableExists($db, 'narrative_events')
    && coreSmokeTableExists($db, 'lifecycle_phase_definitions')
    && coreSmokeTableExists($db, 'character_lifecycle_transitions')
    && coreSmokeTableExists($db, 'factions')
    && coreSmokeTableExists($db, 'faction_memberships');
$systemEventsAvailable =
    coreSmokeTableExists($db, 'system_events')
    && coreSmokeTableExists($db, 'system_event_participations')
    && coreSmokeTableExists($db, 'system_event_effects');
$questsAvailable =
    coreSmokeTableExists($db, 'quest_definitions')
    && coreSmokeTableExists($db, 'quest_instances')
    && coreSmokeTableExists($db, 'quest_step_definitions')
    && coreSmokeTableExists($db, 'quest_progress_logs');
$themesAvailable = coreSmokeTableExists($db, 'sys_configs');

$suite = [
    'DB runtime' => $root . '/scripts/php/smoke-core-db-runtime.php',
    'Auth/Session runtime' => $root . '/scripts/php/smoke-core-auth-runtime.php',
    'Archetypes provider runtime' => $root . '/scripts/php/smoke-archetypes-provider-runtime.php',
    'Attributes provider runtime' => $root . '/scripts/php/smoke-attributes-provider-runtime.php',
    'Social status provider runtime' => $root . '/scripts/php/smoke-social-status-provider-runtime.php',
    'Social status module cutover runtime' => $root . '/scripts/php/smoke-social-status-module-cutover-runtime.php',
    'Factions provider runtime' => $root . '/scripts/php/smoke-factions-provider-runtime.php',
    'Factions module cutover runtime' => $root . '/scripts/php/smoke-factions-module-cutover-runtime.php',
    'Attributes module cutover runtime' => $root . '/scripts/php/smoke-attributes-module-cutover-runtime.php',
    'Multi-currency module cutover runtime' => $root . '/scripts/php/smoke-multi-currency-module-cutover-runtime.php',
    'Weather provider runtime' => $root . '/scripts/php/smoke-weather-provider-runtime.php',
    'Weather module cutover runtime' => $root . '/scripts/php/smoke-weather-module-cutover-runtime.php',
    'Novelty module cutover runtime' => $root . '/scripts/php/smoke-novelty-module-cutover-runtime.php',
    'Quests module cutover runtime' => $root . '/scripts/php/smoke-quests-module-cutover-runtime.php',
];

$skipped = [];

if ($abilitiesModuleActive && $narrativeStatesCoreAvailable) {
    $suite['Abilities/Narrative runtime'] = $root . '/scripts/php/smoke-abilities-narrative-runtime.php';
    $suite['Abilities/Narrative API runtime'] = $root . '/scripts/php/smoke-abilities-api-runtime.php';
} else {
    $skipped[] = 'Abilities/Narrative runtime';
    $skipped[] = 'Abilities/Narrative API runtime';
}

if ($abilitiesModuleActive) {
    $suite['Location launcher/Admin guided runtime'] = $root . '/scripts/php/smoke-location-launcher-admin-guided-runtime.php';
} else {
    $skipped[] = 'Location launcher/Admin guided runtime';
}

if ($narrativeCoherenceAvailable) {
    $suite['Core narrative coherence runtime'] = $root . '/scripts/php/smoke-core-narrative-coherence-runtime.php';
} else {
    $skipped[] = 'Core narrative coherence runtime';
}

if ($systemEventsAvailable) {
    $suite['System events runtime'] = $root . '/scripts/php/smoke-system-events-runtime.php';
} else {
    $skipped[] = 'System events runtime';
}

if ($questsAvailable) {
    $suite['Quests runtime'] = $root . '/scripts/php/smoke-quests-runtime.php';
} else {
    $skipped[] = 'Quests runtime';
}

if ($themesAvailable) {
    $suite['Themes runtime'] = $root . '/scripts/php/smoke-theme-runtime.php';
} else {
    $skipped[] = 'Themes runtime';
}

$passed = 0;
$total = count($suite);

fwrite(STDOUT, '[INFO] Modulo logeon.abilities-spells: ' . ($abilitiesModuleActive ? 'attivo' : 'inattivo') . PHP_EOL);
fwrite(STDOUT, '[INFO] Core narrative states: ' . ($narrativeStatesCoreAvailable ? 'disponibile' : 'non disponibile') . PHP_EOL);

if (!empty($skipped)) {
    foreach ($skipped as $label) {
        fwrite(STDOUT, "[SKIP] {$label}: prerequisiti non soddisfatti." . PHP_EOL);
    }
}

foreach ($suite as $label => $script) {
    if (!is_file($script)) {
        fwrite(STDERR, "[FAIL] {$label}: missing script {$script}\n");
        exit(1);
    }

    $cmd = '"' . $php . '" "' . $script . '"';
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    fwrite(STDOUT, "[RUN] {$label}\n");
    foreach ($output as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    if ($exitCode !== 0) {
        fwrite(STDERR, "[FAIL] {$label}: suite interrupted.\n");
        exit($exitCode);
    }

    $passed++;
}

if ($db instanceof mysqli) {
    @$db->close();
}

fwrite(STDOUT, "[OK] Core runtime smoke suite passed ({$passed}/{$total}).\n");
exit(0);
