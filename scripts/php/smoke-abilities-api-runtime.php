<?php

declare(strict_types=1);

/**
 * Abilities/Narrative API-style runtime smoke (controllers, CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-abilities-api-runtime.php
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

require_once $root . '/app/controllers/Locations.php';
require_once $root . '/app/controllers/Users.php';
require_once $root . '/app/controllers/Abilities.php';
require_once $root . '/app/controllers/NarrativeStates.php';

use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;
use Core\Http\AppError;

function apiSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function apiSmokeStep(string $label): void
{
    fwrite(STDOUT, "[STEP] {$label}\n");
}

/**
 * @param array<string,mixed> $data
 */
function apiSmokeSetPostData(array $data): void
{
    $_POST = ['data' => json_encode((object) $data, JSON_UNESCAPED_UNICODE)];
    $_GET = [];
    $_REQUEST = $_POST;
}

/**
 * @param array<string,mixed> $session
 */
function apiSmokeSetSession(array $session): void
{
    $_SESSION = [];
    foreach ($session as $key => $value) {
        $_SESSION[$key] = $value;
    }
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SERVER['REQUEST_TIME']) || (int) $_SERVER['REQUEST_TIME'] <= 0) {
        $_SERVER['REQUEST_TIME'] = time();
    }

    $db = DbAdapterFactory::createFromConfig();
    apiSmokeAssert($db instanceof MysqliDbAdapter, 'DbAdapterFactory must return MysqliDbAdapter.');

    $userRow = $db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->first();
    $characterRow = $db->query('SELECT id FROM characters ORDER BY id ASC LIMIT 1')->first();
    apiSmokeAssert(!empty($userRow), 'No user available for API smoke.');
    apiSmokeAssert(!empty($characterRow), 'No character available for API smoke.');

    $userId = (int) ($userRow->id ?? 0);
    $characterId = (int) ($characterRow->id ?? 0);
    apiSmokeAssert($userId > 0 && $characterId > 0, 'Invalid user/character IDs for API smoke.');

    $baseSession = [
        'user_id' => $userId,
        'character_id' => $characterId,
        'last_activity' => time(),
        'user_session_version' => null,
        'user_session_version_checked_at' => 0,
    ];

    $abilitiesController = new \Abilities();
    $statesController = new \NarrativeStates();

    $marker = 'smoke_api_' . date('Ymd_His') . '_' . mt_rand(1000, 9999);
    $stateCode = $marker . '_state';
    $abilityCode = $marker . '_ability';
    $stateId = 0;
    $abilityId = 0;
    $checks = 0;

    // 1) Admin flow: create state + ability + assign/unassign.
    apiSmokeStep('admin flow');
    apiSmokeSetSession($baseSession + [
        'user_is_administrator' => 1,
        'user_is_moderator' => 0,
        'user_is_master' => 0,
    ]);

    apiSmokeSetPostData([
        'code' => $stateCode,
        'name' => 'Smoke API State',
        'description' => 'Runtime API smoke state.',
        'category' => 'Smoke API',
        'scope' => 'character',
        'stack_mode' => 'refresh',
        'max_stacks' => 1,
        'conflict_group' => null,
        'priority' => 8,
        'is_active' => 1,
        'visible_to_players' => 1,
        'metadata_json' => '{}',
    ]);
    $statesController->create(false);

    apiSmokeSetPostData(['include_inactive' => 1]);
    $statesList = $statesController->adminList(false);
    $statesDataset = is_array($statesList['dataset'] ?? null) ? $statesList['dataset'] : [];
    foreach ($statesDataset as $row) {
        if ((string) ($row->code ?? '') === $stateCode) {
            $stateId = (int) ($row->id ?? 0);
            break;
        }
    }
    apiSmokeAssert($stateId > 0, 'Admin narrative state create/list failed.');
    $checks++;

    apiSmokeSetPostData([
        'code' => $abilityCode,
        'name' => 'Smoke API Ability',
        'description' => 'Runtime API smoke ability.',
        'category' => 'Smoke API',
        'resolution_mode' => 'direct',
        'target_type' => 'self',
        'requires_target' => 0,
        'applies_state_id' => $stateId,
        'default_intensity' => 1.5,
        'default_duration_value' => 1,
        'default_duration_unit' => 'scene',
        'is_global' => 1,
        'is_active' => 1,
        'visible_to_players' => 1,
        'metadata_json' => '{}',
    ]);
    $abilitiesController->create(false);

    apiSmokeSetPostData(['include_inactive' => 1]);
    $abilitiesList = $abilitiesController->adminList(false);
    $abilitiesDataset = is_array($abilitiesList['dataset'] ?? null) ? $abilitiesList['dataset'] : [];
    foreach ($abilitiesDataset as $row) {
        if ((string) ($row->code ?? '') === $abilityCode) {
            $abilityId = (int) ($row->id ?? 0);
            break;
        }
    }
    apiSmokeAssert($abilityId > 0, 'Admin ability create/list failed.');
    $checks++;

    apiSmokeSetPostData([
        'character_id' => $characterId,
        'ability_id' => $abilityId,
        'level' => 3,
        'source' => 'smoke_api',
        'metadata_json' => '{}',
    ]);
    $abilitiesController->assign(false);

    apiSmokeSetPostData([
        'character_id' => $characterId,
        'ability_id' => $abilityId,
    ]);
    $abilitiesController->unassign(false);
    $checks++;

    // 2) Gameplay flow: list/use abilities and catalog/apply/remove states.
    apiSmokeStep('gameplay flow');
    apiSmokeSetSession($baseSession + [
        'user_is_administrator' => 0,
        'user_is_moderator' => 0,
        'user_is_master' => 0,
    ]);

    apiSmokeSetPostData([]);
    $publicList = $abilitiesController->list(false);
    $publicDataset = is_array($publicList['dataset'] ?? null) ? $publicList['dataset'] : [];
    $foundAbility = false;
    foreach ($publicDataset as $row) {
        if ((int) ($row->id ?? 0) === $abilityId) {
            $foundAbility = true;
            break;
        }
    }
    apiSmokeAssert($foundAbility, 'Gameplay abilities/list missing expected ability.');
    $checks++;

    apiSmokeSetPostData([
        'ability_id' => $abilityId,
        'scene_id' => 0,
        'location_id' => 0,
    ]);
    $useResponse = $abilitiesController->use(false);
    $useDataset = $useResponse['dataset'] ?? [];
    apiSmokeAssert(($useDataset['status'] ?? '') === 'ok', 'Gameplay abilities/use did not return status=ok.');
    apiSmokeAssert((int) ($useDataset['event']['ability_id'] ?? 0) === $abilityId, 'Gameplay abilities/use event ability_id mismatch.');
    apiSmokeAssert(($useDataset['event']['event_type'] ?? '') === 'ability_used', 'Gameplay abilities/use event_type mismatch.');
    $checks++;

    apiSmokeSetPostData([]);
    $catalogResponse = $statesController->catalog(false);
    $catalogDataset = is_array($catalogResponse['dataset'] ?? null) ? $catalogResponse['dataset'] : [];
    $foundState = false;
    foreach ($catalogDataset as $row) {
        if ((int) ($row->id ?? 0) === $stateId) {
            $foundState = true;
            break;
        }
    }
    apiSmokeAssert($foundState, 'Gameplay narrative-states/catalog missing expected state.');
    $checks++;

    apiSmokeSetPostData([
        'state_id' => $stateId,
        'target_type' => 'character',
        'target_id' => $characterId,
        'scene_id' => 0,
        'intensity' => 1.2,
        'duration_value' => 1,
        'duration_unit' => 'scene',
        'meta_json' => '{}',
    ]);
    $applyResponse = $statesController->apply(false);
    $applyDataset = $applyResponse['dataset'] ?? [];
    $appliedStateId = (int) ($applyDataset['applied_state']->id ?? 0);
    apiSmokeAssert(($applyDataset['status'] ?? '') === 'ok', 'Gameplay narrative-states/apply did not return status=ok.');
    apiSmokeAssert($appliedStateId > 0, 'Gameplay narrative-states/apply returned invalid applied_state.id.');

    apiSmokeSetPostData([
        'applied_state_id' => $appliedStateId,
        'reason' => 'smoke_api_cleanup',
    ]);
    $removeResponse = $statesController->remove(false);
    $removeDataset = $removeResponse['dataset'] ?? [];
    apiSmokeAssert(($removeDataset['status'] ?? '') === 'ok', 'Gameplay narrative-states/remove did not return status=ok.');
    apiSmokeAssert(((int) ($removeDataset['removed_count'] ?? 0)) > 0, 'Gameplay narrative-states/remove removed_count=0.');
    $checks++;

    // 3) Admin endpoint access denied for non-admin.
    apiSmokeStep('admin denied check');
    $adminDenied = false;
    try {
        apiSmokeSetPostData(['include_inactive' => 1]);
        $abilitiesController->adminList(false);
    } catch (AppError $e) {
        $adminDenied = ($e->status() === 403);
    }
    apiSmokeAssert($adminDenied, 'Admin endpoint did not deny non-admin session.');
    $checks++;

    // 4) Error contract: ability_not_found.
    apiSmokeStep('error contract check');
    $notFoundOk = false;
    try {
        apiSmokeSetPostData(['ability_id' => 99999999]);
        $abilitiesController->use(false);
    } catch (AppError $e) {
        $notFoundOk = ($e->errorCode() === 'ability_not_found');
    }
    apiSmokeAssert($notFoundOk, 'Error contract mismatch for ability_not_found.');
    $checks++;

    // Cleanup created rows with admin session.
    apiSmokeStep('cleanup');
    apiSmokeSetSession($baseSession + [
        'user_is_administrator' => 1,
        'user_is_moderator' => 0,
        'user_is_master' => 0,
    ]);
    if ($abilityId > 0) {
        apiSmokeSetPostData(['id' => $abilityId]);
        $abilitiesController->adminDelete(false);
    }
    if ($stateId > 0) {
        apiSmokeSetPostData(['id' => $stateId]);
        $statesController->adminDelete(false);
    }

    fwrite(STDOUT, "[OK] Abilities/Narrative API smoke passed ({$checks} checks).\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
