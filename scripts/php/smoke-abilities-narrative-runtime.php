<?php

declare(strict_types=1);

/**
 * Abilities + Narrative States runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-abilities-narrative-runtime.php
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

use App\Services\AbilityResolutionService;
use App\Services\AbilityService;
use App\Services\InventoryService;
use App\Services\NarrativeStateApplicationService;
use App\Services\NarrativeStateService;
use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;
use Core\Http\AppError;

function smokeAssertOrFail(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function smokeLastInsertId(MysqliDbAdapter $db): int
{
    $row = $db->query('SELECT LAST_INSERT_ID() AS id')->first();
    return (int) ($row->id ?? 0);
}

function smokeFindAbility(array $rows, int $abilityId): bool
{
    foreach ($rows as $row) {
        if ((int) ($row->id ?? 0) === $abilityId) {
            return true;
        }
    }
    return false;
}

try {
    $db = DbAdapterFactory::createFromConfig();
    smokeAssertOrFail($db instanceof MysqliDbAdapter, 'DbAdapterFactory must return MysqliDbAdapter.');

    $abilityService = new AbilityService($db);
    $narrativeStateService = new NarrativeStateService($db);
    $narrativeStateApplicationService = new NarrativeStateApplicationService($db);
    $abilityResolutionService = new AbilityResolutionService($db);
    $inventoryService = new InventoryService($db);

    $abilityResolutionService
        ->setAbilityService($abilityService)
        ->setNarrativeStateApplicationService($narrativeStateApplicationService);

    $characterRow = $db->query('SELECT id, last_location FROM characters ORDER BY id ASC LIMIT 1')->first();
    smokeAssertOrFail(!empty($characterRow), 'No character found for smoke runtime.');
    $characterId = (int) ($characterRow->id ?? 0);
    smokeAssertOrFail($characterId > 0, 'Invalid character ID for smoke runtime.');

    $locationId = (int) ($characterRow->last_location ?? 0);
    if ($locationId <= 0) {
        $locationRow = $db->query('SELECT id FROM locations ORDER BY id ASC LIMIT 1')->first();
        $locationId = (int) ($locationRow->id ?? 0);
    }
    smokeAssertOrFail($locationId > 0, 'No location found for smoke runtime.');

    $marker = 'smoke_abilities_' . date('Ymd_His') . '_' . mt_rand(1000, 9999);
    $conflictGroup = $marker . '_grp';

    $createdStateIds = [];
    $createdAbilityIds = [];
    $createdItemIds = [];
    $createdInventoryItemIds = [];
    $abilityMainId = 0;
    $cleanup = function () use (
        $db,
        $locationId,
        $characterId,
        $marker,
        &$createdInventoryItemIds,
        &$createdItemIds,
        &$createdStateIds,
        &$createdAbilityIds,
        &$abilityMainId
    ): void {
        if (!empty($createdInventoryItemIds)) {
            $db->query(
                'DELETE FROM inventory_items
                 WHERE id IN (' . implode(',', array_map('intval', $createdInventoryItemIds)) . ')',
            );
        }
        if (!empty($createdItemIds)) {
            $db->query(
                'DELETE FROM items
                 WHERE id IN (' . implode(',', array_map('intval', $createdItemIds)) . ')',
            );
        }
        if (!empty($createdStateIds)) {
            $stateIdsSql = implode(',', array_map('intval', $createdStateIds));
            $db->query(
                'DELETE FROM applied_narrative_states
                 WHERE state_id IN (' . $stateIdsSql . ')',
            );
        }
        if (!empty($createdAbilityIds)) {
            $abilityIdsSql = implode(',', array_map('intval', $createdAbilityIds));
            $db->query(
                'DELETE FROM character_abilities
                 WHERE ability_id IN (' . $abilityIdsSql . ')',
            );
            $db->query(
                'DELETE FROM abilities
                 WHERE id IN (' . $abilityIdsSql . ')',
            );
        }
        if (!empty($createdStateIds)) {
            $db->query(
                'DELETE FROM narrative_states
                 WHERE id IN (' . implode(',', array_map('intval', $createdStateIds)) . ')',
            );
        }
        $db->query(
            'DELETE FROM locations_messages
             WHERE location_id = ' . $db->safe($locationId) . '
               AND character_id = ' . $db->safe($characterId) . '
               AND type = 3
               AND (
                    body LIKE ' . $db->safe('%' . $marker . '%') . '
                    OR meta_json LIKE ' . $db->safe('%"ability_id":' . (int) $abilityMainId . '%') . '
               )',
        );
    };

    $createState = function (string $code, array $overrides = []) use ($narrativeStateService, &$createdStateIds): int {
        $payload = array_merge([
            'code' => $code,
            'name' => $code,
            'description' => 'Smoke runtime generated state.',
            'category' => 'Smoke',
            'scope' => 'character',
            'stack_mode' => 'replace',
            'max_stacks' => 1,
            'conflict_group' => null,
            'priority' => 0,
            'is_active' => 1,
            'visible_to_players' => 1,
            'metadata_json' => '{}',
        ], $overrides);

        $narrativeStateService->adminCreate((object) $payload);
        $state = $narrativeStateService->getByIdOrCode(0, $code, false);
        $stateId = (int) ($state->id ?? 0);
        smokeAssertOrFail($stateId > 0, 'Failed to create narrative state: ' . $code);
        $createdStateIds[] = $stateId;
        return $stateId;
    };

    $createAbility = function (string $code, array $overrides = []) use ($abilityService, &$createdAbilityIds): int {
        $payload = array_merge([
            'code' => $code,
            'name' => $code,
            'description' => 'Smoke runtime generated ability.',
            'category' => 'Smoke',
            'resolution_mode' => 'direct',
            'target_type' => 'self',
            'requires_target' => 0,
            'applies_state_id' => null,
            'default_intensity' => 1,
            'default_duration_value' => 1,
            'default_duration_unit' => 'scene',
            'is_global' => 1,
            'is_active' => 1,
            'visible_to_players' => 1,
            'metadata_json' => '{}',
        ], $overrides);

        $abilityService->adminCreate((object) $payload);
        $ability = $abilityService->getByIdOrCode(0, $code, $code, false);
        $abilityId = (int) ($ability->id ?? 0);
        smokeAssertOrFail($abilityId > 0, 'Failed to create ability: ' . $code);
        $createdAbilityIds[] = $abilityId;
        return $abilityId;
    };

    $stateMainId = $createState($marker . '_state_main', [
        'name' => 'Smoke State Main',
        'stack_mode' => 'refresh',
        'priority' => 5,
    ]);
    $stateHighId = $createState($marker . '_state_high', [
        'name' => 'Smoke State High',
        'conflict_group' => $conflictGroup,
        'priority' => 20,
    ]);
    $stateLowId = $createState($marker . '_state_low', [
        'name' => 'Smoke State Low',
        'conflict_group' => $conflictGroup,
        'priority' => 10,
    ]);
    $stateItemId = $createState($marker . '_state_item', [
        'name' => 'Smoke State Item',
        'stack_mode' => 'refresh',
        'priority' => 15,
    ]);

    $abilityMainId = $createAbility($marker . '_ability_main', [
        'name' => 'Smoke Ability Main',
        'applies_state_id' => $stateMainId,
        'target_type' => 'self',
        'requires_target' => 0,
        'is_global' => 1,
    ]);
    $abilityLearnedId = $createAbility($marker . '_ability_learned', [
        'name' => 'Smoke Ability Learned',
        'applies_state_id' => null,
        'target_type' => 'character',
        'requires_target' => 1,
        'is_global' => 0,
    ]);

    $checks = 0;

    // 1) Abilities list for character includes global ability.
    $list = $abilityService->listForCharacter($characterId, false, false);
    smokeAssertOrFail(smokeFindAbility($list, $abilityMainId), 'Global ability missing from character list.');
    $checks++;

    // 2) Ability use applies state + structured event payload.
    $useResult = $abilityResolutionService->useAbility([
        'character_id' => $characterId,
        'ability_id' => $abilityMainId,
        'scene_id' => $locationId,
    ]);
    smokeAssertOrFail(($useResult['status'] ?? '') === 'ok', 'Ability use did not return status=ok.');
    smokeAssertOrFail(($useResult['event']['event_type'] ?? '') === 'ability_used', 'Ability event_type mismatch.');
    smokeAssertOrFail((int) ($useResult['event']['ability_id'] ?? 0) === $abilityMainId, 'Ability event ability_id mismatch.');
    smokeAssertOrFail((int) ($useResult['event']['state_id'] ?? 0) === $stateMainId, 'Ability event state_id mismatch.');
    $checks++;

    $activeMain = $db->query(
        'SELECT id
         FROM applied_narrative_states
         WHERE state_id = ' . $db->safe($stateMainId) . '
           AND target_type = "character"
           AND target_id = ' . $db->safe($characterId) . '
           AND status = "active"
         ORDER BY id DESC
         LIMIT 1',
    )->first();
    smokeAssertOrFail(!empty($activeMain), 'Main state was not applied by ability.');
    $checks++;

    // 3) Conflict logic: lower-priority state blocked by higher-priority active state.
    $narrativeStateApplicationService->applyState([
        'state_id' => $stateHighId,
        'target_type' => 'character',
        'target_id' => $characterId,
        'scene_id' => $locationId,
        'applier_character_id' => $characterId,
        'intensity' => 1.0,
        'duration_value' => 1,
        'duration_unit' => 'scene',
        'meta_json' => '{}',
    ]);

    $blocked = false;
    try {
        $narrativeStateApplicationService->applyState([
            'state_id' => $stateLowId,
            'target_type' => 'character',
            'target_id' => $characterId,
            'scene_id' => $locationId,
            'applier_character_id' => $characterId,
            'intensity' => 1.0,
            'duration_value' => 1,
            'duration_unit' => 'scene',
            'meta_json' => '{}',
        ]);
    } catch (AppError $error) {
        $blocked = ($error->errorCode() === 'state_conflict_blocked');
    }
    smokeAssertOrFail($blocked, 'Conflict evaluation did not block lower-priority state.');
    $checks++;

    // 4) Hybrid ownership: learned ability visible only when assigned.
    $listBeforeAssign = $abilityService->listForCharacter($characterId, false, false);
    smokeAssertOrFail(!smokeFindAbility($listBeforeAssign, $abilityLearnedId), 'Learned ability unexpectedly visible before assign.');

    $abilityService->assignToCharacter((object) [
        'character_id' => $characterId,
        'ability_id' => $abilityLearnedId,
        'level' => 2,
        'source' => 'smoke',
        'metadata_json' => '{}',
    ]);
    $listAfterAssign = $abilityService->listForCharacter($characterId, false, false);
    smokeAssertOrFail(smokeFindAbility($listAfterAssign, $abilityLearnedId), 'Learned ability missing after assign.');

    $abilityService->unassignFromCharacter((object) [
        'character_id' => $characterId,
        'ability_id' => $abilityLearnedId,
    ]);
    $listAfterUnassign = $abilityService->listForCharacter($characterId, false, false);
    smokeAssertOrFail(!smokeFindAbility($listAfterUnassign, $abilityLearnedId), 'Learned ability still visible after unassign.');
    $checks++;

    $createConsumableItem = function (string $suffix, array $effectPayload) use ($db, $marker, $characterId, &$createdItemIds, &$createdInventoryItemIds): array {
        $itemName = 'Smoke Item ' . $suffix . ' ' . $marker;
        $slug = strtolower($marker . '_' . $suffix);
        $effectJson = json_encode($effectPayload, JSON_UNESCAPED_UNICODE);

        $db->query(
            'INSERT INTO items SET
                name = ' . $db->safe($itemName) . ',
                slug = ' . $db->safe($slug) . ',
                description = ' . $db->safe('Smoke runtime generated item.') . ',
                price = 0,
                value = 0,
                usable = 1,
                consumable = 1,
                script_effect = ' . $db->safe($effectJson) . ',
                metadata_json = "{}",
                created_at = NOW()',
        );
        $itemId = smokeLastInsertId($db);
        smokeAssertOrFail($itemId > 0, 'Failed to create smoke item: ' . $suffix);
        $createdItemIds[] = $itemId;

        $db->query(
            'INSERT INTO inventory_items SET
                item_id = ' . $db->safe($itemId) . ',
                owner_id = ' . $db->safe($characterId) . ',
                owner_type = "player",
                quantity = 1,
                metadata_json = "{}",
                created_at = NOW()',
        );
        $inventoryItemId = smokeLastInsertId($db);
        smokeAssertOrFail($inventoryItemId > 0, 'Failed to create smoke inventory item: ' . $suffix);
        $createdInventoryItemIds[] = $inventoryItemId;

        return [$itemId, $inventoryItemId];
    };

    // 5) Inventory integration: apply_status.
    [, $inventoryApplyId] = $createConsumableItem('apply', [
        'effect' => 'apply_status',
        'state_id' => $stateItemId,
        'target_type' => 'character',
        'target_id' => $characterId,
        'scene_id' => $locationId,
        'state_intensity' => 1.25,
        'state_duration_value' => 1,
        'state_duration_unit' => 'scene',
    ]);
    $applyResult = $inventoryService->useItem($characterId, $inventoryApplyId);
    smokeAssertOrFail(($applyResult['success'] ?? false) === true, 'Inventory use apply_status failed.');
    smokeAssertOrFail(($applyResult['effect']['effect'] ?? '') === 'apply_status', 'Inventory apply_status effect mismatch.');
    smokeAssertOrFail(($applyResult['effect']['applied'] ?? false) === true, 'Inventory apply_status not applied.');
    $checks++;

    $activeItemState = $db->query(
        'SELECT id, target_type, target_id, scene_id
         FROM applied_narrative_states
         WHERE state_id = ' . $db->safe($stateItemId) . '
           AND target_type = "character"
           AND target_id = ' . $db->safe($characterId) . '
           AND status = "active"
         ORDER BY id DESC
         LIMIT 1',
    )->first();
    $activeItemStateId = (int) ($activeItemState->id ?? 0);
    smokeAssertOrFail($activeItemStateId > 0, 'No active applied state after apply_status.');
    $activeItemTargetType = (string) ($activeItemState->target_type ?? '');
    $activeItemTargetId = (int) ($activeItemState->target_id ?? 0);
    $activeItemSceneId = (int) ($activeItemState->scene_id ?? 0);
    smokeAssertOrFail($activeItemTargetType === 'character', 'Applied item state target_type mismatch.');
    smokeAssertOrFail($activeItemTargetId === $characterId, 'Applied item state target_id mismatch.');
    $checks++;

    $itemApplyEvent = $db->query(
        'SELECT id, meta_json
         FROM locations_messages
         WHERE location_id = ' . $db->safe($locationId) . '
           AND character_id = ' . $db->safe($characterId) . '
           AND type = 3
           AND meta_json LIKE ' . $db->safe('%"event_type":"item_state_applied"%') . '
         ORDER BY id DESC
         LIMIT 1',
    )->first();
    smokeAssertOrFail(!empty($itemApplyEvent), 'Missing item_state_applied system event.');
    $itemApplyMeta = json_decode((string) ($itemApplyEvent->meta_json ?? ''), true);
    smokeAssertOrFail(is_array($itemApplyMeta), 'Invalid item_state_applied meta_json.');
    smokeAssertOrFail((int) ($itemApplyMeta['state_id'] ?? 0) === $stateItemId, 'item_state_applied state_id mismatch.');
    $checks++;

    // 6) Inventory integration: remove_status.
    [, $inventoryRemoveId] = $createConsumableItem('remove', [
        'effect' => 'remove_status',
        'applied_state_id' => $activeItemStateId,
        'remove_state_id' => $stateItemId,
        'target_type' => 'character',
        'target_id' => $characterId,
        'scene_id' => $activeItemSceneId,
    ]);
    $removeResult = $inventoryService->useItem($characterId, $inventoryRemoveId);
    smokeAssertOrFail(($removeResult['success'] ?? false) === true, 'Inventory use remove_status failed.');
    smokeAssertOrFail(($removeResult['effect']['effect'] ?? '') === 'remove_status', 'Inventory remove_status effect mismatch.');
    smokeAssertOrFail(((int) ($removeResult['effect']['removed_count'] ?? 0)) > 0, 'Inventory remove_status removed_count=0.');
    $checks++;

    $itemRemoveEvent = $db->query(
        'SELECT id
         FROM locations_messages
         WHERE location_id = ' . $db->safe($locationId) . '
           AND character_id = ' . $db->safe($characterId) . '
           AND type = 3
           AND meta_json LIKE ' . $db->safe('%"event_type":"item_state_removed"%') . '
         ORDER BY id DESC
         LIMIT 1',
    )->first();
    smokeAssertOrFail(!empty($itemRemoveEvent), 'Missing item_state_removed system event.');
    $checks++;

    // 7) Inventory integration: spawn_event.
    [, $inventorySpawnId] = $createConsumableItem('spawn', [
        'effect' => 'spawn_event',
        'scene_id' => $locationId,
        'event_title' => 'Smoke Spawn ' . $marker,
        'event_body' => 'Smoke narrative event.',
    ]);
    $spawnResult = $inventoryService->useItem($characterId, $inventorySpawnId);
    smokeAssertOrFail(($spawnResult['success'] ?? false) === true, 'Inventory use spawn_event failed.');
    smokeAssertOrFail(($spawnResult['effect']['effect'] ?? '') === 'spawn_event', 'Inventory spawn_event effect mismatch.');
    smokeAssertOrFail(($spawnResult['effect']['applied'] ?? false) === true, 'Inventory spawn_event not applied.');
    $checks++;

    $spawnEvent = $db->query(
        'SELECT id
         FROM locations_messages
         WHERE location_id = ' . $db->safe($locationId) . '
           AND character_id = ' . $db->safe($characterId) . '
           AND type = 3
           AND meta_json LIKE ' . $db->safe('%"event_type":"item_spawn_event"%') . '
         ORDER BY id DESC
         LIMIT 1',
    )->first();
    smokeAssertOrFail(!empty($spawnEvent), 'Missing item_spawn_event system event.');
    $checks++;

    $cleanup();

    fwrite(STDOUT, "[OK] Abilities/Narrative runtime smoke passed ({$checks} checks).\n");
    exit(0);
} catch (Throwable $e) {
    if (isset($cleanup) && is_callable($cleanup)) {
        try {
            $cleanup();
        } catch (Throwable $cleanupError) {
            // no-op
        }
    }
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
