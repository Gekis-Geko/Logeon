<?php

declare(strict_types=1);

/**
 * Location launcher + admin guided core smoke (CLI).
 *
 * Coverage:
 * - primary location flow: abilities use (self/character/scene) with location binding
 * - primary location flow: inventory item use via /items/use endpoint controller
 * - admin core policy: metadata_json blocked on items and item_equipment_rules
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-location-launcher-admin-guided-runtime.php
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
require_once $root . '/app/controllers/Items.php';
require_once $root . '/app/controllers/ItemEquipmentRules.php';
require_once $root . '/app/controllers/Inventory.php';

use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;
use Core\Http\AppError;

function launcherSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function launcherSmokeStep(string $label): void
{
    fwrite(STDOUT, "[STEP] {$label}\n");
}

/**
 * @param array<string,mixed> $data
 */
function launcherSmokeSetPostData(array $data): void
{
    $_POST = ['data' => json_encode((object) $data, JSON_UNESCAPED_UNICODE)];
    $_GET = [];
    $_REQUEST = $_POST;
}

/**
 * @param array<string,mixed> $session
 */
function launcherSmokeSetSession(array $session): void
{
    $_SESSION = [];
    foreach ($session as $key => $value) {
        $_SESSION[$key] = $value;
    }
}

function launcherSmokeEscape(string $value): string
{
    return str_replace("'", "''", $value);
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SERVER['REQUEST_TIME']) || (int) $_SERVER['REQUEST_TIME'] <= 0) {
        $_SERVER['REQUEST_TIME'] = time();
    }

    $db = DbAdapterFactory::createFromConfig();
    launcherSmokeAssert($db instanceof MysqliDbAdapter, 'DbAdapterFactory must return MysqliDbAdapter.');

    $userRow = $db->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->first();
    $characterRow = $db->query('SELECT id FROM characters ORDER BY id ASC LIMIT 1')->first();
    launcherSmokeAssert(!empty($userRow), 'No user available for launcher smoke.');
    launcherSmokeAssert(!empty($characterRow), 'No character available for launcher smoke.');

    $userId = (int) ($userRow->id ?? 0);
    $characterId = (int) ($characterRow->id ?? 0);
    launcherSmokeAssert($userId > 0 && $characterId > 0, 'Invalid user/character IDs for launcher smoke.');

    $baseSession = [
        'user_id' => $userId,
        'character_id' => $characterId,
        'last_activity' => time(),
        'user_session_version' => null,
        'user_session_version_checked_at' => 0,
    ];

    $locationsController = new \Locations();
    $abilitiesController = new \Abilities();
    $itemsController = new \Items();
    $itemEquipmentRulesController = new \ItemEquipmentRules();
    $inventoryController = new \Inventory();

    $marker = 'smoke_launcher_' . date('Ymd_His') . '_' . mt_rand(1000, 9999);
    $abilitySelfCode = $marker . '_ability_self';
    $abilityCharacterCode = $marker . '_ability_character';
    $abilitySceneCode = $marker . '_ability_scene';
    $itemSlug = str_replace('_', '-', $marker) . '-item';
    $itemName = 'Smoke Launcher Item ' . $marker;

    $locationId = 0;
    $abilitySelfId = 0;
    $abilityCharacterId = 0;
    $abilitySceneId = 0;
    $itemId = 0;
    $inventoryItemId = 0;
    $ruleId = 0;
    $chatMessageIds = [];
    $checks = 0;

    // 1) Resolve first location accessible by the character.
    launcherSmokeStep('resolve location access');
    $locationRows = $db->query('SELECT id FROM locations ORDER BY id ASC')->fetch();
    foreach ($locationRows as $row) {
        $candidate = (int) ($row->id ?? 0);
        if ($candidate <= 0) {
            continue;
        }
        $access = $locationsController->canAccess($candidate, $characterId);
        if (!empty($access['allowed'])) {
            $locationId = $candidate;
            break;
        }
    }
    launcherSmokeAssert($locationId > 0, 'No accessible location found for launcher smoke.');
    $checks++;

    // 2) Admin setup + metadata_json policy checks (items + equipment rules).
    launcherSmokeStep('admin setup and metadata policy');
    launcherSmokeSetSession($baseSession + [
        'user_is_administrator' => 1,
        'user_is_moderator' => 0,
        'user_is_master' => 0,
    ]);

    $abilitiesPayload = [
        [
            'code' => $abilitySelfCode,
            'name' => 'Smoke Launcher Self',
            'target_type' => 'self',
            'requires_target' => 0,
        ],
        [
            'code' => $abilityCharacterCode,
            'name' => 'Smoke Launcher Character',
            'target_type' => 'character',
            'requires_target' => 1,
        ],
        [
            'code' => $abilitySceneCode,
            'name' => 'Smoke Launcher Scene',
            'target_type' => 'scene',
            'requires_target' => 1,
        ],
    ];

    foreach ($abilitiesPayload as $payload) {
        launcherSmokeSetPostData([
            'code' => $payload['code'],
            'name' => $payload['name'],
            'description' => 'Launcher smoke ability ' . $payload['code'],
            'category' => 'Smoke Launcher',
            'resolution_mode' => 'direct',
            'target_type' => $payload['target_type'],
            'requires_target' => $payload['requires_target'],
            'applies_state_id' => null,
            'default_intensity' => 1.0,
            'default_duration_value' => 1,
            'default_duration_unit' => 'scene',
            'is_global' => 1,
            'is_active' => 1,
            'visible_to_players' => 1,
            'metadata_json' => '{"blocked":true}',
        ]);
        $abilitiesController->create(false);
    }

    $abilitiesDataset = $db->query(
        'SELECT id, code FROM abilities
         WHERE code IN (
            \'' . launcherSmokeEscape($abilitySelfCode) . '\',
            \'' . launcherSmokeEscape($abilityCharacterCode) . '\',
            \'' . launcherSmokeEscape($abilitySceneCode) . '\'
         )',
    )->fetch();
    foreach ($abilitiesDataset as $row) {
        $code = (string) ($row->code ?? '');
        $id = (int) ($row->id ?? 0);
        if ($code === $abilitySelfCode) {
            $abilitySelfId = $id;
        } elseif ($code === $abilityCharacterCode) {
            $abilityCharacterId = $id;
        } elseif ($code === $abilitySceneCode) {
            $abilitySceneId = $id;
        }
    }
    launcherSmokeAssert($abilitySelfId > 0 && $abilityCharacterId > 0 && $abilitySceneId > 0, 'Abilities setup failed.');
    $checks++;

    launcherSmokeSetPostData([
        'name' => $itemName,
        'slug' => $itemSlug,
        'description' => 'Launcher smoke item',
        'category_id' => null,
        'icon' => null,
        'image' => null,
        'type' => 'consumable',
        'rarity' => 'common',
        'rarity_id' => null,
        'stackable' => 1,
        'max_stack' => 10,
        'usable' => 1,
        'consumable' => 1,
        'tradable' => 0,
        'droppable' => 1,
        'destroyable' => 1,
        'weight' => 0,
        'value' => 0,
        'cooldown' => 0,
        'is_equippable' => 0,
        'equip_slot' => null,
        'script_effect' => 'heal',
        'metadata_json' => '{"blocked":"create"}',
    ]);
    $itemsController->create();

    $itemRow = $db->query(
        'SELECT id, metadata_json
         FROM items
         WHERE name = \'' . launcherSmokeEscape($itemName) . '\'
         ORDER BY id DESC
         LIMIT 1',
    )->first();
    launcherSmokeAssert(!empty($itemRow), 'Admin item create failed.');
    $itemId = (int) ($itemRow->id ?? 0);
    launcherSmokeAssert($itemId > 0, 'Created item has invalid id.');
    launcherSmokeAssert(trim((string) ($itemRow->metadata_json ?? '')) === '{}', 'metadata_json must be blocked on item create.');

    $db->query(
        'UPDATE items
         SET metadata_json = \'{"module":"keep"}\'
         WHERE id = ' . $db->safe($itemId),
    );

    launcherSmokeSetPostData([
        'id' => $itemId,
        'name' => $itemName . ' Updated',
        'slug' => $itemSlug,
        'description' => 'Launcher smoke item updated',
        'category_id' => null,
        'icon' => null,
        'image' => null,
        'type' => 'consumable',
        'rarity' => 'common',
        'rarity_id' => null,
        'stackable' => 1,
        'max_stack' => 10,
        'usable' => 1,
        'consumable' => 1,
        'tradable' => 0,
        'droppable' => 1,
        'destroyable' => 1,
        'weight' => 0,
        'value' => 0,
        'cooldown' => 0,
        'is_equippable' => 0,
        'equip_slot' => null,
        'script_effect' => 'heal',
        'metadata_json' => '{"blocked":"update"}',
    ]);
    $itemsController->update();

    $itemUpdatedRow = $db->query(
        'SELECT metadata_json
         FROM items
         WHERE id = ' . $db->safe($itemId) . '
         LIMIT 1',
    )->first();
    $itemMetadataAfterUpdate = (string) ($itemUpdatedRow->metadata_json ?? '');
    launcherSmokeAssert(strpos($itemMetadataAfterUpdate, '"module":"keep"') !== false, 'Item metadata_json must remain technical and not be overwritten on update.');
    launcherSmokeAssert(strpos($itemMetadataAfterUpdate, '"blocked"') === false, 'Blocked metadata_json leaked into item update.');
    $checks++;

    $slotRow = $db->query('SELECT id FROM equipment_slots ORDER BY id ASC LIMIT 1')->first();
    launcherSmokeAssert(!empty($slotRow), 'No equipment slot available for item_equipment_rules smoke.');
    $slotId = (int) ($slotRow->id ?? 0);
    launcherSmokeAssert($slotId > 0, 'Invalid slot id for item_equipment_rules smoke.');

    launcherSmokeSetPostData([
        'item_id' => $itemId,
        'slot_id' => $slotId,
        'priority' => 11,
        'metadata_json' => '{"blocked":"rule_create"}',
    ]);
    $itemEquipmentRulesController->create();

    $ruleRow = $db->query(
        'SELECT id, metadata_json, priority
         FROM item_equipment_rules
         WHERE item_id = ' . $db->safe($itemId) . '
           AND slot_id = ' . $db->safe($slotId) . '
         LIMIT 1',
    )->first();
    launcherSmokeAssert(!empty($ruleRow), 'Item equipment rule create failed.');
    $ruleId = (int) ($ruleRow->id ?? 0);
    launcherSmokeAssert($ruleId > 0, 'Invalid rule id after create.');
    launcherSmokeAssert(strpos((string) ($ruleRow->metadata_json ?? ''), '"blocked":"rule_create"') === false, 'Blocked metadata_json leaked into rule create.');

    $db->query(
        'UPDATE item_equipment_rules
         SET metadata_json = \'{"module":"rule_keep"}\'
         WHERE id = ' . $db->safe($ruleId),
    );

    launcherSmokeSetPostData([
        'id' => $ruleId,
        'item_id' => $itemId,
        'slot_id' => $slotId,
        'priority' => 13,
        'metadata_json' => '{"blocked":"rule_update"}',
    ]);
    $itemEquipmentRulesController->update();

    $ruleUpdatedRow = $db->query(
        'SELECT metadata_json, priority
         FROM item_equipment_rules
         WHERE id = ' . $db->safe($ruleId) . '
         LIMIT 1',
    )->first();
    launcherSmokeAssert((int) ($ruleUpdatedRow->priority ?? 0) === 13, 'Rule update priority mismatch.');
    $ruleMetadataAfterUpdate = (string) ($ruleUpdatedRow->metadata_json ?? '');
    launcherSmokeAssert(strpos($ruleMetadataAfterUpdate, '"module":"rule_keep"') !== false, 'Rule metadata_json must remain technical and not be overwritten on update.');
    launcherSmokeAssert(strpos($ruleMetadataAfterUpdate, '"blocked"') === false, 'Blocked metadata_json leaked into rule update.');
    $checks++;

    // Prepare inventory item for primary /items/use flow.
    $db->query(
        'INSERT INTO inventory_items SET
            owner_type = "player",
            owner_id = ' . $db->safe($characterId) . ',
            item_id = ' . $db->safe($itemId) . ',
            quantity = 1,
            metadata_json = "{}",
            created_at = NOW()',
    );
    $inventoryRow = $db->query(
        'SELECT id
         FROM inventory_items
         WHERE owner_type = "player"
           AND owner_id = ' . $db->safe($characterId) . '
           AND item_id = ' . $db->safe($itemId) . '
         ORDER BY id DESC
         LIMIT 1',
    )->first();
    launcherSmokeAssert(!empty($inventoryRow), 'Failed to insert inventory item for /items/use smoke.');
    $inventoryItemId = (int) ($inventoryRow->id ?? 0);
    launcherSmokeAssert($inventoryItemId > 0, 'Invalid inventory item id for /items/use smoke.');
    $checks++;

    // 3) Primary location launcher flow: abilities (self/character/scene) + item use.
    launcherSmokeStep('location launcher primary flow');
    launcherSmokeSetSession($baseSession + [
        'user_is_administrator' => 0,
        'user_is_moderator' => 0,
        'user_is_master' => 0,
    ]);

    launcherSmokeSetPostData([
        'ability_id' => $abilitySelfId,
        'location_id' => $locationId,
        'scene_id' => $locationId,
    ]);
    $selfUse = $abilitiesController->use(false);
    $selfDataset = $selfUse['dataset'] ?? [];
    launcherSmokeAssert(($selfDataset['status'] ?? '') === 'ok', 'Self ability use failed in location launcher flow.');
    launcherSmokeAssert(($selfDataset['event']['target_type'] ?? '') === 'character', 'Self ability target_type mismatch.');
    launcherSmokeAssert((int) ($selfDataset['event']['target_id'] ?? 0) === $characterId, 'Self ability target_id mismatch.');
    launcherSmokeAssert(!empty($selfDataset['chat_message']), 'Self ability must append system chat message in location flow.');
    $chatMessageIds[] = (int) (($selfDataset['chat_message']->id ?? 0));
    $checks++;

    launcherSmokeSetPostData([
        'ability_id' => $abilityCharacterId,
        'location_id' => $locationId,
        'scene_id' => $locationId,
        'target_id' => $characterId,
    ]);
    $characterUse = $abilitiesController->use(false);
    $characterDataset = $characterUse['dataset'] ?? [];
    launcherSmokeAssert(($characterDataset['status'] ?? '') === 'ok', 'Character-target ability use failed in location launcher flow.');
    launcherSmokeAssert(($characterDataset['event']['target_type'] ?? '') === 'character', 'Character ability target_type mismatch.');
    launcherSmokeAssert((int) ($characterDataset['event']['target_id'] ?? 0) === $characterId, 'Character ability target_id mismatch.');
    launcherSmokeAssert(!empty($characterDataset['chat_message']), 'Character ability must append system chat message in location flow.');
    $chatMessageIds[] = (int) (($characterDataset['chat_message']->id ?? 0));
    $checks++;

    $targetRequiredTriggered = false;
    try {
        launcherSmokeSetPostData([
            'ability_id' => $abilityCharacterId,
            'location_id' => $locationId,
            'scene_id' => $locationId,
        ]);
        $abilitiesController->use(false);
    } catch (AppError $e) {
        $targetRequiredTriggered = ($e->errorCode() === 'ability_target_required');
    }
    launcherSmokeAssert($targetRequiredTriggered, 'Character ability without target must return ability_target_required.');
    $checks++;

    launcherSmokeSetPostData([
        'ability_id' => $abilitySceneId,
        'location_id' => $locationId,
        'scene_id' => $locationId,
    ]);
    $sceneUse = $abilitiesController->use(false);
    $sceneDataset = $sceneUse['dataset'] ?? [];
    launcherSmokeAssert(($sceneDataset['status'] ?? '') === 'ok', 'Scene ability use failed in location launcher flow.');
    launcherSmokeAssert(($sceneDataset['event']['target_type'] ?? '') === 'scene', 'Scene ability target_type mismatch.');
    launcherSmokeAssert((int) ($sceneDataset['event']['target_id'] ?? 0) === $locationId, 'Scene ability target_id mismatch.');
    launcherSmokeAssert(!empty($sceneDataset['chat_message']), 'Scene ability must append system chat message in location flow.');
    $chatMessageIds[] = (int) (($sceneDataset['chat_message']->id ?? 0));
    $checks++;

    launcherSmokeSetPostData([
        'inventory_item_id' => $inventoryItemId,
    ]);
    ob_start();
    $inventoryController->useItem();
    $itemUseRaw = trim((string) ob_get_clean());
    $itemUseResponse = json_decode($itemUseRaw, true);
    launcherSmokeAssert(is_array($itemUseResponse), 'Inventory /items/use response is not valid JSON.');
    launcherSmokeAssert(!empty($itemUseResponse['success']), 'Inventory /items/use did not return success=true.');
    launcherSmokeAssert((int) ($itemUseResponse['inventory_item_id'] ?? 0) === $inventoryItemId, 'Inventory /items/use returned wrong inventory_item_id.');
    $checks++;

    // 4) Cleanup.
    launcherSmokeStep('cleanup');
    launcherSmokeSetSession($baseSession + [
        'user_is_administrator' => 1,
        'user_is_moderator' => 0,
        'user_is_master' => 0,
    ]);

    if (!empty($chatMessageIds)) {
        $safeIds = [];
        foreach ($chatMessageIds as $chatMessageId) {
            $chatMessageId = (int) $chatMessageId;
            if ($chatMessageId > 0) {
                $safeIds[] = $db->safe($chatMessageId);
            }
        }
        if (!empty($safeIds)) {
            $db->query('DELETE FROM locations_messages WHERE id IN (' . implode(',', $safeIds) . ')');
        }
    }

    if ($ruleId > 0) {
        $db->query('DELETE FROM item_equipment_rules WHERE id = ' . $db->safe($ruleId));
    }
    if ($inventoryItemId > 0) {
        $db->query('DELETE FROM inventory_items WHERE id = ' . $db->safe($inventoryItemId));
    }
    if ($itemId > 0) {
        $db->query('DELETE FROM character_item_instances WHERE item_id = ' . $db->safe($itemId) . ' AND character_id = ' . $db->safe($characterId));
        $db->query('DELETE FROM items WHERE id = ' . $db->safe($itemId));
    }

    $abilityIds = [$abilitySelfId, $abilityCharacterId, $abilitySceneId];
    foreach ($abilityIds as $abilityId) {
        $abilityId = (int) $abilityId;
        if ($abilityId <= 0) {
            continue;
        }
        launcherSmokeSetPostData(['id' => $abilityId]);
        $abilitiesController->adminDelete(false);
    }

    fwrite(STDOUT, "[OK] Location launcher/admin guided smoke passed ({$checks} checks).\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
