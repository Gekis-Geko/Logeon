<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Hooks;
use Core\Http\AppError;

class InventoryService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var InventoryCapacityService|null */
    private $inventoryCapacityService = null;
    /** @var bool|null */
    private $equipmentSchemaAvailable = null;
    /** @var bool|null */
    private $phase2EquipmentRulesAvailable = null;
    /** @var array|null cached hook result for item state SQL fragments */
    private $itemStateFragmentsCache = null;
    /** @var array|null */
    private $equipmentSlotIndexCache = null;
    /** @var object|null */
    private $narrativeStateApplicationService = null;
    /** @var LocationMessageService|null */
    private $locationMessageService = null;
    /** @var array<string,bool> */
    private $columnExistsCache = [];
    /** @var array<int,string> */
    private $conflictModeByCharacterCache = [];
    /** @var string|null */
    private $globalConflictModeCache = null;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    public function setInventoryCapacityService(InventoryCapacityService $inventoryCapacityService = null)
    {
        $this->inventoryCapacityService = $inventoryCapacityService;
        return $this;
    }

    private function inventoryCapacityService(): InventoryCapacityService
    {
        if ($this->inventoryCapacityService instanceof InventoryCapacityService) {
            return $this->inventoryCapacityService;
        }

        $this->inventoryCapacityService = new InventoryCapacityService($this->db);
        return $this->inventoryCapacityService;
    }

    public function setNarrativeStateApplicationService($service = null)
    {
        $this->narrativeStateApplicationService = $service;
        return $this;
    }

    private function narrativeStateApplicationService()
    {
        if (is_object($this->narrativeStateApplicationService)
            && method_exists($this->narrativeStateApplicationService, 'applyState')
            && method_exists($this->narrativeStateApplicationService, 'removeState')
        ) {
            return $this->narrativeStateApplicationService;
        }

        $service = Hooks::filter('inventory.runtime.narrative_state_service', null, $this->db);
        if ((!is_object($service)
                || !method_exists($service, 'applyState')
                || !method_exists($service, 'removeState'))
            && $this->hasNarrativeStatesTable()
            && class_exists('\\App\\Services\\NarrativeStateApplicationService')
        ) {
            $service = new \App\Services\NarrativeStateApplicationService($this->db);
        }

        if (!is_object($service)
            || !method_exists($service, 'applyState')
            || !method_exists($service, 'removeState')
        ) {
            $this->narrativeStateApplicationService = null;
            return null;
        }

        $this->narrativeStateApplicationService = $service;
        return $this->narrativeStateApplicationService;
    }

    private function hasNarrativeStatesTable(): bool
    {
        return $this->tableExists('narrative_states')
            && $this->tableExists('applied_narrative_states');
    }

    private function isNarrativeStatesRuntimeAvailable(): bool
    {
        return $this->hasNarrativeStatesTable()
            && is_object($this->narrativeStateApplicationService());
    }

    public function setLocationMessageService(LocationMessageService $service = null)
    {
        $this->locationMessageService = $service;
        return $this;
    }

    private function locationMessageService(): LocationMessageService
    {
        if ($this->locationMessageService instanceof LocationMessageService) {
            return $this->locationMessageService;
        }

        $this->locationMessageService = new LocationMessageService($this->db);
        return $this->locationMessageService;
    }

    public function getCapacitySnapshot($characterId): array
    {
        return $this->inventoryCapacityService()->getCapacitySnapshot((int) $characterId);
    }

    /**
     * @param array<int,mixed> $params
     * @return mixed
     */
    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,mixed>
     */
    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     */
    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function failValidation($message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failCharacterInvalid(): void
    {
        $this->failValidation('Personaggio non valido', 'character_invalid');
    }

    private function failItemInvalid(): void
    {
        $this->failValidation('Oggetto non valido', 'item_invalid');
    }

    private function failItemNotFound(): void
    {
        $this->failValidation('Oggetto non trovato', 'item_not_found');
    }

    private function failItemNotEquippable(): void
    {
        $this->failValidation('Oggetto non equipaggiabile', 'item_not_equippable');
    }

    private function failItemNotUsable(): void
    {
        $this->failValidation('Oggetto non utilizzabile', 'item_not_usable');
    }

    private function failItemCooldownActive(): void
    {
        $this->failValidation('Oggetto in cooldown', 'item_cooldown_active');
    }

    private function failAmmoRequired(): void
    {
        $this->failValidation('Arma da tiro non configurata con munizione valida', 'ammo_required');
    }

    private function failAmmoNotEnough(string $message = 'Munizioni insufficienti'): void
    {
        $this->failValidation($message, 'ammo_not_enough');
    }

    private function failAmmoReloadRequired(string $message = 'Ricarica necessaria'): void
    {
        $this->failValidation($message, 'ammo_reload_required');
    }

    private function failAmmoReloadNotSupported(): void
    {
        $this->failValidation('Ricarica non supportata per questo oggetto', 'ammo_reload_not_supported');
    }

    private function failAmmoReloadDisabledInNarrative(): void
    {
        $this->failValidation(
            'Ricarica disponibile solo con sistema conflitti casuale attivo',
            'ammo_reload_disabled_in_narrative',
        );
    }

    private function failAmmoMagazineFull(): void
    {
        $this->failValidation('Caricatore gia pieno', 'ammo_magazine_full');
    }

    private function failItemNeedsMaintenance(): void
    {
        $this->failValidation('Questo equipaggiamento richiede manutenzione', 'item_needs_maintenance');
    }

    private function failItemJammed(): void
    {
        $this->failValidation('L\'arma si e inceppata', 'item_jammed');
    }

    private function failItemMaintenanceNotSupported(): void
    {
        $this->failValidation('Manutenzione non supportata per questo oggetto', 'item_maintenance_not_supported');
    }

    private function failSlotUnavailable(): void
    {
        $this->failValidation('Slot non disponibile', 'slot_unavailable');
    }

    private function failSlotRequired(): void
    {
        $this->failValidation('Seleziona lo slot', 'slot_required');
    }

    private function failSlotInvalid(): void
    {
        $this->failValidation('Slot non valido', 'slot_invalid');
    }

    private function failSlotGroupLimitReached(): void
    {
        $this->failValidation('Limite equipaggiamento raggiunto per questo gruppo di slot', 'slot_group_limit_reached');
    }

    private function failEquipmentRequirementNotMet(string $message = 'Requisiti di equipaggiamento non soddisfatti'): void
    {
        $this->failValidation($message, 'equipment_requirement_not_met');
    }

    private function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        $this->db->query('COMMIT');
    }

    private function rollback(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $e) {
            // no-op: rollback best effort
        }
    }

    private function tableExists(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS c
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1',
            [$table],
        );

        return !empty($row) && (int) ($row->c ?? 0) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $table = trim($table);
        $column = trim($column);
        if ($table === '' || $column === '') {
            return false;
        }

        $cacheKey = strtolower($table . '.' . $column);
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return (bool) $this->columnExistsCache[$cacheKey];
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS c
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND column_name = ?
             LIMIT 1',
            [$table, $column],
        );

        $exists = !empty($row) && (int) ($row->c ?? 0) > 0;
        $this->columnExistsCache[$cacheKey] = $exists;
        return $exists;
    }

    private function hasEquipmentSchema(): bool
    {
        if ($this->equipmentSchemaAvailable !== null) {
            return (bool) $this->equipmentSchemaAvailable;
        }

        $this->equipmentSchemaAvailable =
            $this->tableExists('equipment_slots')
            && $this->tableExists('character_equipment');

        return (bool) $this->equipmentSchemaAvailable;
    }

    /**
     * Returns the SQL SELECT and JOIN fragments for item-state name columns.
     * Active modules contribute the actual fragments by registering:
     *   Hooks::add('inventory.query.item_state_fragments', function(array $f, string $alias): array {
     *       $f['select'] = "ns_a.name AS applies_state_name, ns_r.name AS removes_state_name";
     *       $f['join']   = " LEFT JOIN narrative_states ns_a ON ns_a.id = {$alias}.applies_state_id"
     *                    . " LEFT JOIN narrative_states ns_r ON ns_r.id = {$alias}.removes_state_id";
     *       return $f;
     *   });
     */
    private function resolveItemStateFragments(string $itemAlias = 'i'): array
    {
        if ($this->itemStateFragmentsCache !== null) {
            return $this->itemStateFragmentsCache;
        }
        $default = [
            'select' => 'NULL AS applies_state_name, NULL AS removes_state_name',
            'join' => '',
        ];
        $result = Hooks::filter('inventory.query.item_state_fragments', $default, $itemAlias);
        $this->itemStateFragmentsCache = is_array($result) ? $result : $default;
        return $this->itemStateFragmentsCache;
    }

    private function narrativeStateSelectFragment(string $itemAlias = 'i'): string
    {
        $f = $this->resolveItemStateFragments($itemAlias);
        return (string) ($f['select'] ?? 'NULL AS applies_state_name, NULL AS removes_state_name');
    }

    private function narrativeStateJoinFragment(string $itemAlias = 'i'): string
    {
        $f = $this->resolveItemStateFragments($itemAlias);
        return (string) ($f['join'] ?? '');
    }

    private function ensureEquipmentSchema(): void
    {
        if ($this->hasEquipmentSchema()) {
            return;
        }

        $this->failValidation(
            'Schema equipaggiamento non disponibile. Allinea il database con database/logeon_db_core.sql.',
            'equipment_schema_missing',
        );
    }

    private function hasPhase2EquipmentRules(): bool
    {
        if ($this->phase2EquipmentRulesAvailable !== null) {
            return (bool) $this->phase2EquipmentRulesAvailable;
        }

        $this->phase2EquipmentRulesAvailable =
            $this->hasEquipmentSchema()
            && $this->tableExists('item_equipment_rules');

        return (bool) $this->phase2EquipmentRulesAvailable;
    }

    private function normalizeSlotList(array $slots): array
    {
        $normalized = [];
        foreach ($slots as $slot) {
            $key = trim((string) $slot);
            if ($key === '') {
                continue;
            }
            if (!in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
        }
        return $normalized;
    }

    public function getEquipmentSlots(): array
    {
        if (!$this->hasEquipmentSchema()) {
            return [];
        }
        return $this->fetchPrepared(
            'SELECT
                id,
                `key`,
                `name`,
                description,
                icon,
                group_key,
                sort_order,
                is_active,
                max_equipped
             FROM equipment_slots
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC',
        );
    }

    private function equipmentSlotIndex(): array
    {
        if (is_array($this->equipmentSlotIndexCache)) {
            return $this->equipmentSlotIndexCache;
        }

        $this->equipmentSlotIndexCache = [];
        $slots = $this->getEquipmentSlots();
        foreach ($slots as $slot) {
            $key = trim((string) ($slot->key ?? ''));
            if ($key === '') {
                continue;
            }
            $this->equipmentSlotIndexCache[$key] = $slot;
        }

        return $this->equipmentSlotIndexCache;
    }

    private function equipmentSlotByKey(string $slotKey)
    {
        $slotKey = trim($slotKey);
        if ($slotKey === '') {
            return null;
        }

        $index = $this->equipmentSlotIndex();
        return $index[$slotKey] ?? null;
    }

    private function slotGroupsForKeys(array $slotKeys): array
    {
        $groups = [];
        if (empty($slotKeys)) {
            return $groups;
        }

        $index = $this->equipmentSlotIndex();
        foreach ($slotKeys as $slotKey) {
            $slotKey = trim((string) $slotKey);
            if ($slotKey === '') {
                continue;
            }

            $slot = $index[$slotKey] ?? null;
            $group = trim((string) ($slot->group_key ?? ''));
            if ($group === '') {
                $group = $slotKey;
            }
            if (!in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private function allowedSlotsForItem(int $itemId, $legacyEquipSlot): array
    {
        $slots = [];
        if ($itemId > 0 && $this->hasPhase2EquipmentRules()) {
            $rows = $this->fetchPrepared(
                'SELECT es.`key`
                 FROM item_equipment_rules ier
                 INNER JOIN equipment_slots es ON es.id = ier.slot_id
                 WHERE ier.item_id = ?
                   AND es.is_active = 1
                 ORDER BY ier.priority ASC, es.sort_order ASC, es.id ASC',
                [$itemId],
            );

            foreach ($rows as $row) {
                $key = trim((string) ($row->key ?? ''));
                if ($key !== '') {
                    $slots[] = $key;
                }
            }
        }

        if (empty($slots)) {
            $slots = $this->getAllowedSlots($legacyEquipSlot);
        }

        return $this->normalizeSlotList($slots);
    }

    private function buildBagOrderBy($orderBy)
    {
        $field = 'item_name';
        $dir = 'ASC';

        $parts = explode('|', (string) $orderBy);
        if (count($parts) === 2) {
            $rawField = trim((string) $parts[0]);
            $rawDir = strtoupper(trim((string) $parts[1]));
            if ($rawDir === 'DESC') {
                $dir = 'DESC';
            }

            $map = [
                'name' => 'item_name',
                'item_name' => 'item_name',
                'type' => 'item_type',
                'item_type' => 'item_type',
                'quantity' => 'quantity',
                'rarity' => 'rarity_sort_order',
                'rarity_sort_order' => 'rarity_sort_order',
                'date_created' => 'date_created',
            ];

            if (isset($map[$rawField])) {
                $field = $map[$rawField];
            }
        }

        if ($field === 'rarity_sort_order') {
            return ' ORDER BY rarity_sort_order ' . $dir . ', item_name ASC';
        }

        return ' ORDER BY ' . $field . ' ' . $dir;
    }

    private function parseJsonObject($json): array
    {
        if ($json === null) {
            return [];
        }
        $raw = trim((string) $json);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    private function parseIntFromMeta(array $meta, array $keys): int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            if (is_numeric((string) $meta[$key])) {
                return (int) $meta[$key];
            }
        }
        return 0;
    }

    private function parseBoolFromMeta(array $meta, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }

            $value = $meta[$key];
            if (is_bool($value)) {
                return $value;
            }

            $raw = strtolower(trim((string) $value));
            if (in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return false;
    }

    private function itemRuleMetaByItemAndSlot(int $itemId, int $slotId = 0): array
    {
        if ($itemId <= 0 || !$this->hasPhase2EquipmentRules()) {
            return [];
        }

        if ($slotId > 0) {
            $row = $this->firstPrepared(
                'SELECT metadata_json
                 FROM item_equipment_rules
                 WHERE item_id = ?
                   AND slot_id = ?
                 ORDER BY priority ASC, id ASC
                 LIMIT 1',
                [$itemId, $slotId],
            );
            if (!empty($row)) {
                return $this->parseJsonObject($row->metadata_json ?? null);
            }
        }

        $fallbackRow = $this->firstPrepared(
            'SELECT metadata_json
             FROM item_equipment_rules
             WHERE item_id = ?
             ORDER BY priority ASC, id ASC
             LIMIT 1',
            [$itemId],
        );

        if (empty($fallbackRow)) {
            return [];
        }

        return $this->parseJsonObject($fallbackRow->metadata_json ?? null);
    }

    private function isEquipmentItemById(int $itemId): bool
    {
        if ($itemId <= 0) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT
                COALESCE(is_equippable, 0) AS is_equippable,
                item_kind
             FROM items
             WHERE id = ?
             LIMIT 1',
            [$itemId],
        );
        if (empty($row)) {
            return false;
        }

        if ((int) ($row->is_equippable ?? 0) === 1) {
            return true;
        }

        return strtolower(trim((string) ($row->item_kind ?? ''))) === 'equipment';
    }

    private function equipmentSlotIdByKey(string $slotKey): int
    {
        $slotKey = trim($slotKey);
        if ($slotKey === '') {
            return 0;
        }
        $slotDef = $this->equipmentSlotByKey($slotKey);
        return (int) ($slotDef->id ?? 0);
    }

    private function resolveMaintenanceRuleForItem(int $itemId, int $slotId = 0, $isEquipmentHint = null): array
    {
        $isEquipment = null;
        if (is_bool($isEquipmentHint)) {
            $isEquipment = $isEquipmentHint;
        } else {
            $isEquipment = $this->isEquipmentItemById($itemId);
        }

        $meta = $this->itemRuleMetaByItemAndSlot($itemId, $slotId);

        $durabilityEnabled = $this->parseBoolFromMeta($meta, ['durability_enabled', 'quality_enabled']);
        if (!array_key_exists('durability_enabled', $meta) && !array_key_exists('quality_enabled', $meta)) {
            $durabilityEnabled = $isEquipment === true;
        }

        $durabilityMax = $this->parseIntFromMeta($meta, ['durability_max', 'quality_max', 'max_durability']);
        if ($durabilityMax < 1) {
            $durabilityMax = 100;
        }
        if ($durabilityMax > 9999) {
            $durabilityMax = 9999;
        }

        $durabilityLossOnUse = $this->parseIntFromMeta($meta, ['durability_loss_on_use', 'quality_loss_on_use', 'wear_on_use']);
        if ($durabilityLossOnUse < 0) {
            $durabilityLossOnUse = 0;
        }
        if ($durabilityLossOnUse > 9999) {
            $durabilityLossOnUse = 9999;
        }

        $durabilityLossOnEquip = $this->parseIntFromMeta($meta, ['durability_loss_on_equip', 'quality_loss_on_equip', 'wear_on_equip']);
        if ($durabilityLossOnEquip < 0) {
            $durabilityLossOnEquip = 0;
        }
        if ($durabilityLossOnEquip > 9999) {
            $durabilityLossOnEquip = 9999;
        }

        $jamEnabled = $this->parseBoolFromMeta($meta, ['jam_enabled', 'weapon_jam_enabled']);
        $jamChancePercent = $this->parseIntFromMeta($meta, ['jam_chance_percent', 'weapon_jam_chance', 'jam_chance']);
        if ($jamChancePercent < 0) {
            $jamChancePercent = 0;
        }
        if ($jamChancePercent > 100) {
            $jamChancePercent = 100;
        }
        if ($jamEnabled && $jamChancePercent <= 0) {
            $jamChancePercent = 5;
        }

        return [
            'durability_enabled' => $durabilityEnabled,
            'durability_max' => $durabilityMax,
            'durability_loss_on_use' => $durabilityLossOnUse,
            'durability_loss_on_equip' => $durabilityLossOnEquip,
            'jam_enabled' => $jamEnabled,
            'jam_chance_percent' => $jamChancePercent,
        ];
    }

    private function buildQualitySnapshot(array $maintenanceRule, $durabilityValue): array
    {
        $enabled = (($maintenanceRule['durability_enabled'] ?? false) === true);
        $max = max(1, (int) ($maintenanceRule['durability_max'] ?? 100));
        $current = ($durabilityValue === null) ? $max : (int) $durabilityValue;
        if ($current < 0) {
            $current = 0;
        }
        if ($current > $max) {
            $current = $max;
        }

        $percent = (int) round(($current / $max) * 100);
        if ($percent < 0) {
            $percent = 0;
        }
        if ($percent > 100) {
            $percent = 100;
        }

        return [
            'quality_enabled' => $enabled,
            'durability_enabled' => $enabled,
            'quality_current' => $current,
            'quality_max' => $max,
            'quality_percent' => $percent,
            'needs_maintenance' => $enabled && $current <= 0,
        ];
    }

    private function applyDurabilityLossOnInstance(int $characterId, int $instanceId, array $maintenanceRule, int $loss): array
    {
        $loss = max(0, $loss);
        $rule = $maintenanceRule;
        if (($rule['durability_enabled'] ?? false) !== true || $instanceId <= 0 || $characterId <= 0) {
            return $this->buildQualitySnapshot($rule, null);
        }

        $row = $this->firstPrepared(
            'SELECT durability
             FROM character_item_instances
             WHERE id = ?
               AND character_id = ?
             LIMIT 1
             FOR UPDATE',
            [$instanceId, $characterId],
        );
        if (empty($row)) {
            return $this->buildQualitySnapshot($rule, null);
        }

        $max = max(1, (int) ($rule['durability_max'] ?? 100));
        $current = ($row->durability === null) ? $max : (int) $row->durability;
        if ($current < 0) {
            $current = 0;
        }
        if ($current > $max) {
            $current = $max;
        }

        if ($loss > 0) {
            $current -= $loss;
            if ($current < 0) {
                $current = 0;
            }
        }

        $this->execPrepared(
            'UPDATE character_item_instances SET
                durability = ?,
                date_updated = NOW()
             WHERE id = ?
               AND character_id = ?
             LIMIT 1',
            [$current, $instanceId, $characterId],
        );

        return $this->buildQualitySnapshot($rule, $current);
    }

    private function shouldJamOnUse(array $maintenanceRule, int $durabilityCurrent): bool
    {
        if (($maintenanceRule['jam_enabled'] ?? false) !== true) {
            return false;
        }

        $baseChance = (float) ($maintenanceRule['jam_chance_percent'] ?? 0);
        if ($baseChance <= 0) {
            return false;
        }

        $max = max(1, (int) ($maintenanceRule['durability_max'] ?? 100));
        $current = max(0, min($max, $durabilityCurrent));
        $wearRatio = 1 - ($current / $max);
        if ($wearRatio < 0) {
            $wearRatio = 0;
        }
        $effectiveChance = $baseChance + ($wearRatio * 10.0);
        if ($effectiveChance > 100.0) {
            $effectiveChance = 100.0;
        }
        if ($effectiveChance <= 0.0) {
            return false;
        }

        $roll = mt_rand(1, 10000) / 100.0;
        return $roll <= $effectiveChance;
    }

    private function resolveAmmoRuleForItem(int $itemId): array
    {
        $out = [
            'requires_ammo' => false,
            'ammo_item_id' => 0,
            'ammo_per_use' => 1,
            'ammo_magazine_size' => 0,
        ];

        if ($itemId <= 0 || !$this->hasPhase2EquipmentRules()) {
            return $out;
        }

        $rows = $this->fetchPrepared(
            'SELECT metadata_json
             FROM item_equipment_rules
             WHERE item_id = ?
             ORDER BY priority ASC, id ASC',
            [$itemId],
        );

        foreach ($rows as $row) {
            $meta = $this->parseJsonObject($row->metadata_json ?? null);
            if (empty($meta)) {
                continue;
            }

            $requiresAmmo = $this->parseBoolFromMeta($meta, ['requires_ammo', 'ammo_required', 'needs_ammo']);
            $ammoItemId = $this->parseIntFromMeta($meta, ['ammo_item_id', 'ammunition_item_id', 'ammo_id']);
            $ammoPerUse = $this->parseIntFromMeta($meta, ['ammo_per_use', 'ammo_consumption', 'consumption_per_use']);
            $ammoMagazineSize = $this->parseIntFromMeta($meta, ['ammo_magazine_size', 'magazine_size', 'clip_size']);
            if ($ammoPerUse < 1) {
                $ammoPerUse = 1;
            }
            if ($ammoMagazineSize < 0) {
                $ammoMagazineSize = 0;
            }
            if ($ammoMagazineSize > 0 && $ammoPerUse > $ammoMagazineSize) {
                $ammoPerUse = $ammoMagazineSize;
            }

            if ($requiresAmmo || $ammoItemId > 0) {
                return [
                    'requires_ammo' => true,
                    'ammo_item_id' => max(0, $ammoItemId),
                    'ammo_per_use' => $ammoPerUse,
                    'ammo_magazine_size' => $ammoMagazineSize,
                ];
            }
        }

        return $out;
    }

    private function availableAmmoQuantity(int $characterId, int $ammoItemId): int
    {
        if ($characterId <= 0 || $ammoItemId <= 0) {
            return 0;
        }

        $stackRow = $this->firstPrepared(
            'SELECT COALESCE(SUM(quantity), 0) AS qty
             FROM inventory_items
             WHERE owner_type = "player"
               AND owner_id = ?
               AND item_id = ?
               AND legacy_instance_id IS NULL',
            [$characterId, $ammoItemId],
        );
        $stackQty = (int) ($stackRow->qty ?? 0);

        $instanceRow = $this->firstPrepared(
            'SELECT COUNT(*) AS qty
             FROM character_item_instances
             WHERE character_id = ?
               AND item_id = ?
               AND is_equipped = 0',
            [$characterId, $ammoItemId],
        );
        $instanceQty = (int) ($instanceRow->qty ?? 0);

        return $stackQty + $instanceQty;
    }

    private function ammoItemName(int $ammoItemId): string
    {
        if ($ammoItemId <= 0) {
            return 'Munizioni';
        }

        $row = $this->firstPrepared(
            'SELECT name
             FROM items
             WHERE id = ?
             LIMIT 1',
            [$ammoItemId],
        );

        $name = trim((string) ($row->name ?? ''));
        return ($name !== '') ? $name : 'Munizioni';
    }

    private function parseInstanceMeta($json): array
    {
        return $this->parseJsonObject($json);
    }

    private function encodeInstanceMeta(array $meta): string
    {
        if (empty($meta)) {
            return '{}';
        }
        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '{}';
    }

    private function getLoadedAmmoFromInstanceMeta(array $meta): int
    {
        $loaded = $this->parseIntFromMeta($meta, ['ammo_loaded', 'loaded_ammo', 'magazine_loaded']);
        return max(0, $loaded);
    }

    private function setLoadedAmmoInInstanceMeta(array $meta, int $loaded): array
    {
        $loaded = max(0, $loaded);
        $meta['ammo_loaded'] = $loaded;
        return $meta;
    }

    private function instanceRowForUseByInventoryItem(int $characterId, int $inventoryItemId)
    {
        if ($characterId <= 0 || $inventoryItemId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT
                cii.id AS character_item_instance_id,
                cii.meta_json AS instance_meta_json
             FROM inventory_items ii
             INNER JOIN character_item_instances cii
               ON cii.id = ii.legacy_instance_id
              AND cii.character_id = ii.owner_id
             WHERE ii.id = ?
               AND ii.owner_type = "player"
               AND ii.owner_id = ?
             LIMIT 1
             FOR UPDATE',
            [$inventoryItemId, $characterId],
        );
    }

    private function updateInstanceMetaJson(int $characterId, int $instanceId, array $meta): void
    {
        if ($characterId <= 0 || $instanceId <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE character_item_instances SET
                meta_json = ?,
                date_updated = NOW()
             WHERE id = ?
               AND character_id = ?
             LIMIT 1',
            [$this->encodeInstanceMeta($meta), $instanceId, $characterId],
        );
    }

    private function resolveAmmoUiPayload(array $ammoRule, int $loadedAmmo = 0): array
    {
        if (($ammoRule['requires_ammo'] ?? false) !== true) {
            return [
                'requires_ammo' => false,
                'ammo_item_id' => 0,
                'ammo_item_name' => '',
                'ammo_per_use' => 1,
                'ammo_magazine_size' => 0,
                'ammo_loaded' => 0,
            ];
        }

        $ammoItemId = (int) ($ammoRule['ammo_item_id'] ?? 0);
        $ammoPerUse = max(1, (int) ($ammoRule['ammo_per_use'] ?? 1));
        $ammoMagazineSize = max(0, (int) ($ammoRule['ammo_magazine_size'] ?? 0));
        if ($ammoMagazineSize > 0 && $ammoPerUse > $ammoMagazineSize) {
            $ammoPerUse = $ammoMagazineSize;
        }

        return [
            'requires_ammo' => true,
            'ammo_item_id' => $ammoItemId,
            'ammo_item_name' => $this->ammoItemName($ammoItemId),
            'ammo_per_use' => $ammoPerUse,
            'ammo_magazine_size' => $ammoMagazineSize,
            'ammo_loaded' => max(0, $loadedAmmo),
        ];
    }

    private function consumeAmmoQuantity(int $characterId, int $ammoItemId, int $quantity): void
    {
        if ($characterId <= 0 || $ammoItemId <= 0 || $quantity <= 0) {
            return;
        }

        $remaining = $quantity;

        $stackRows = $this->fetchPrepared(
            'SELECT id, quantity
             FROM inventory_items
             WHERE owner_type = "player"
               AND owner_id = ?
               AND item_id = ?
               AND legacy_instance_id IS NULL
               AND quantity > 0
             ORDER BY id ASC
             FOR UPDATE',
            [$characterId, $ammoItemId],
        );

        foreach ($stackRows as $row) {
            if ($remaining <= 0) {
                break;
            }

            $rowId = (int) ($row->id ?? 0);
            $rowQty = (int) ($row->quantity ?? 0);
            if ($rowId <= 0 || $rowQty <= 0) {
                continue;
            }

            $consume = ($rowQty >= $remaining) ? $remaining : $rowQty;
            $nextQty = $rowQty - $consume;

            if ($nextQty <= 0) {
                $this->execPrepared(
                    'DELETE FROM inventory_items
                     WHERE id = ?
                     LIMIT 1',
                    [$rowId],
                );
            } else {
                $this->execPrepared(
                    'UPDATE inventory_items SET
                        quantity = ?,
                        updated_at = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [$nextQty, $rowId],
                );
            }

            $remaining -= $consume;
        }

        if ($remaining <= 0) {
            return;
        }

        $instanceRows = $this->fetchPrepared(
            'SELECT id
             FROM character_item_instances
             WHERE character_id = ?
               AND item_id = ?
               AND is_equipped = 0
             ORDER BY id ASC
             FOR UPDATE',
            [$characterId, $ammoItemId],
        );

        $instanceIds = [];
        foreach ($instanceRows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id > 0) {
                $instanceIds[] = $id;
            }
        }

        while ($remaining > 0 && !empty($instanceIds)) {
            $instanceId = array_shift($instanceIds);
            $this->execPrepared(
                'DELETE FROM character_item_instances
                 WHERE id = ?
                   AND character_id = ?
                 LIMIT 1',
                [$instanceId, $characterId],
            );
            $this->execPrepared(
                'DELETE FROM inventory_items
                 WHERE owner_type = "player"
                   AND owner_id = ?
                   AND legacy_instance_id = ?',
                [$characterId, $instanceId],
            );

            $remaining--;
        }

        if ($remaining > 0) {
            $this->failAmmoNotEnough();
        }
    }

    private function getCharacterRankForUpdate(int $characterId): int
    {
        $row = $this->firstPrepared(
            'SELECT COALESCE(rank, 0) AS rank
             FROM characters
             WHERE id = ?
             LIMIT 1
             FOR UPDATE',
            [$characterId],
        );

        if (empty($row)) {
            $this->failCharacterInvalid();
        }

        return (int) ($row->rank ?? 0);
    }

    private function getItemEquipmentRuleMeta(int $itemId, int $slotId): array
    {
        if ($itemId <= 0 || $slotId <= 0 || !$this->hasPhase2EquipmentRules()) {
            return [];
        }

        $row = $this->firstPrepared(
            'SELECT metadata_json
             FROM item_equipment_rules
             WHERE item_id = ?
               AND slot_id = ?
             LIMIT 1',
            [$itemId, $slotId],
        );

        if (empty($row)) {
            return [];
        }

        return $this->parseJsonObject($row->metadata_json ?? null);
    }

    private function isItemRuleTwoHandedForGroup(int $itemId, string $groupKey): bool
    {
        if ($itemId <= 0 || trim($groupKey) === '' || !$this->hasPhase2EquipmentRules()) {
            return false;
        }

        $rows = $this->fetchPrepared(
            'SELECT ier.metadata_json
             FROM item_equipment_rules ier
             INNER JOIN equipment_slots es ON es.id = ier.slot_id
             WHERE ier.item_id = ?
               AND es.group_key = ?',
            [$itemId, $groupKey],
        );

        foreach ($rows as $row) {
            $meta = $this->parseJsonObject($row->metadata_json ?? null);
            if ($this->parseBoolFromMeta($meta, ['is_two_handed', 'two_handed', 'requires_two_hands'])) {
                return true;
            }
        }

        return false;
    }

    private function isItemTwoHandedForSlot(object $itemRow, object $slotDef, int $slotId): bool
    {
        $ruleMeta = $this->getItemEquipmentRuleMeta((int) ($itemRow->item_id ?? 0), $slotId);
        if ($this->parseBoolFromMeta($ruleMeta, ['is_two_handed', 'two_handed', 'requires_two_hands'])) {
            return true;
        }

        $groupKey = trim((string) ($slotDef->group_key ?? ''));
        if ($groupKey === '') {
            $groupKey = trim((string) ($slotDef->key ?? ''));
        }
        if ($groupKey === '') {
            return false;
        }

        return $this->isItemRuleTwoHandedForGroup((int) ($itemRow->item_id ?? 0), $groupKey);
    }

    private function equippedRowsByGroupForUpdate(int $characterId, string $groupKey): array
    {
        if ($characterId <= 0 || trim($groupKey) === '') {
            return [];
        }

        return $this->fetchPrepared(
            'SELECT
                ce.id,
                ce.slot_id,
                ce.character_item_instance_id,
                es.`key` AS slot_key,
                es.group_key
             FROM character_equipment ce
             INNER JOIN equipment_slots es
               ON es.id = ce.slot_id
              AND es.is_active = 1
             WHERE ce.character_id = ?
               AND es.group_key = ?
               AND ce.character_item_instance_id IS NOT NULL
             FOR UPDATE',
            [$characterId, $groupKey],
        );
    }

    private function equippedInstancesByGroupForUpdate(int $characterId, string $groupKey): array
    {
        $rows = $this->equippedRowsByGroupForUpdate($characterId, $groupKey);
        $instances = [];
        foreach ($rows as $row) {
            $instanceId = (int) ($row->character_item_instance_id ?? 0);
            if ($instanceId <= 0) {
                continue;
            }
            if (!in_array($instanceId, $instances, true)) {
                $instances[] = $instanceId;
            }
        }

        return $instances;
    }

    private function isEquippedInstanceTwoHandedInGroup(int $characterId, int $instanceId, string $groupKey): bool
    {
        if ($characterId <= 0 || $instanceId <= 0 || trim($groupKey) === '') {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT i.id AS item_id
             FROM character_item_instances cii
             INNER JOIN items i ON i.id = cii.item_id
             WHERE cii.id = ?
               AND cii.character_id = ?
             LIMIT 1',
            [$instanceId, $characterId],
        );

        if (empty($row)) {
            return false;
        }

        return $this->isItemRuleTwoHandedForGroup((int) ($row->item_id ?? 0), $groupKey);
    }

    private function clearEquippedInstance(int $characterId, int $instanceId): void
    {
        if ($characterId <= 0 || $instanceId <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM character_equipment
             WHERE character_id = ?
               AND character_item_instance_id = ?',
            [$characterId, $instanceId],
        );

        $this->execPrepared(
            'UPDATE character_item_instances SET
                is_equipped = 0,
                slot = NULL,
                date_updated = NOW()
             WHERE id = ?
               AND character_id = ?',
            [$instanceId, $characterId],
        );
    }

    private function validateEquipmentRequirements(int $characterId, object $itemRow, object $slotDef, int $slotId): void
    {
        $itemMeta = $this->parseJsonObject($itemRow->metadata_json ?? null);
        $ruleMeta = $this->getItemEquipmentRuleMeta((int) ($itemRow->item_id ?? 0), $slotId);

        $requiredRank = max(
            $this->parseIntFromMeta($itemMeta, ['level_requirement', 'min_level', 'rank_required', 'min_rank']),
            $this->parseIntFromMeta($ruleMeta, ['level_requirement', 'min_level', 'rank_required', 'min_rank']),
        );

        if ($requiredRank > 0) {
            $currentRank = $this->getCharacterRankForUpdate($characterId);
            if ($currentRank < $requiredRank) {
                $this->failEquipmentRequirementNotMet(
                    'Requisito non soddisfatto: rank minimo ' . $requiredRank . ' (attuale ' . $currentRank . ')',
                );
            }
        }
    }

    private function enforceSlotGroupCapacity(int $characterId, object $slotDef, int $incomingInstanceId, int $replacedInstanceId = 0): void
    {
        $groupKey = trim((string) ($slotDef->group_key ?? ''));
        if ($groupKey === '') {
            $groupKey = trim((string) ($slotDef->key ?? ''));
        }
        if ($groupKey === '') {
            return;
        }

        $capRow = $this->firstPrepared(
            'SELECT COALESCE(SUM(max_equipped), 0) AS cap
             FROM equipment_slots
             WHERE is_active = 1
               AND group_key = ?',
            [$groupKey],
        );
        $groupCap = (int) ($capRow->cap ?? 0);
        if ($groupCap <= 0) {
            $groupCap = 1;
        }

        $exclusions = [];
        if ($incomingInstanceId > 0) {
            $exclusions[] = (int) $incomingInstanceId;
        }
        if ($replacedInstanceId > 0) {
            $exclusions[] = (int) $replacedInstanceId;
        }
        $excludeSql = '';
        if (!empty($exclusions)) {
            $excludeSql = ' AND ce.character_item_instance_id NOT IN (' . implode(',', array_fill(0, count($exclusions), '?')) . ')';
        }

        $countSql = 'SELECT COUNT(*) AS qty
             FROM character_equipment ce
             INNER JOIN equipment_slots es ON es.id = ce.slot_id AND es.is_active = 1
             WHERE ce.character_id = ?
               AND es.group_key = ?
               AND ce.character_item_instance_id IS NOT NULL' . $excludeSql . '
             FOR UPDATE';
        $countParams = [$characterId, $groupKey];
        if (!empty($exclusions)) {
            $countParams = array_merge($countParams, $exclusions);
        }
        $countRow = $this->firstPrepared($countSql, $countParams);
        $existing = (int) ($countRow->qty ?? 0);
        $future = $existing + 1;

        if ($future > $groupCap) {
            $this->failSlotGroupLimitReached();
        }
    }

    private function inventoryItemIdByInstanceId(int $characterId, int $instanceId): int
    {
        if ($characterId <= 0 || $instanceId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT id
             FROM inventory_items
             WHERE owner_type = "player"
               AND owner_id = ?
               AND legacy_instance_id = ?
             LIMIT 1',
            [$characterId, $instanceId],
        );

        return (!empty($row) && isset($row->id)) ? (int) $row->id : 0;
    }

    public function resolveInstanceIdByInventoryItem(int $characterId, int $inventoryItemId): int
    {
        if ($characterId <= 0 || $inventoryItemId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT legacy_instance_id
             FROM inventory_items
             WHERE id = ?
               AND owner_type = "player"
               AND owner_id = ?
             LIMIT 1',
            [$inventoryItemId, $characterId],
        );

        if (empty($row)) {
            return 0;
        }

        return (int) ($row->legacy_instance_id ?? 0);
    }

    public function grantItemReward(int $characterId, int $itemId, int $quantity = 1): array
    {
        $characterId = (int) $characterId;
        $itemId = (int) $itemId;
        $quantity = (int) $quantity;

        if ($characterId <= 0) {
            $this->failCharacterInvalid();
        }
        if ($itemId <= 0) {
            $this->failItemInvalid();
        }
        if ($quantity < 1) {
            $quantity = 1;
        }

        $this->begin();
        try {
            $result = $this->applyItemEffect($characterId, [
                'effect' => 'grant_item',
                'item_id' => $itemId,
                'quantity' => $quantity,
            ]);

            if (empty($result['applied'])) {
                $reason = trim((string) ($result['reason'] ?? ''));
                if ($reason === '') {
                    $reason = 'grant_item_failed';
                }
                throw AppError::validation('Impossibile assegnare oggetto ricompensa', $result, $reason);
            }

            $this->commit();
            return $result;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function resolveItemEffectPayload($scriptEffect, $itemMetadata, $inventoryMetadata): array
    {
        $payload = [];
        $scriptRaw = trim((string) $scriptEffect);
        if ($scriptRaw !== '') {
            $scriptJson = $this->parseJsonObject($scriptRaw);
            if (!empty($scriptJson)) {
                $payload = $scriptJson;
            } else {
                $payload = ['effect' => $scriptRaw];
            }
        }

        $itemMeta = $this->parseJsonObject($itemMetadata);
        $inventoryMeta = $this->parseJsonObject($inventoryMetadata);

        if (!empty($itemMeta)) {
            $payload = array_merge($itemMeta, $payload);
        }
        if (!empty($inventoryMeta)) {
            $payload = array_merge($payload, ['instance' => $inventoryMeta]);
        }

        return $payload;
    }

    private function characterLocationId(int $characterId): int
    {
        if ($characterId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT last_location
             FROM characters
             WHERE id = ?
             LIMIT 1',
            [$characterId],
        );
        if (empty($row)) {
            return 0;
        }

        return (int) ($row->last_location ?? 0);
    }

    private function normalizeConflictMode($mode): string
    {
        $value = strtolower(trim((string) $mode));
        return ($value === 'random') ? 'random' : 'narrative';
    }

    private function resolveGlobalConflictMode(): string
    {
        if ($this->globalConflictModeCache !== null) {
            return $this->globalConflictModeCache;
        }

        if (!$this->tableExists('sys_configs')) {
            $this->globalConflictModeCache = 'narrative';
            return $this->globalConflictModeCache;
        }

        $row = $this->firstPrepared(
            'SELECT value
             FROM sys_configs
             WHERE `key` = ?
             LIMIT 1',
            ['conflict_resolution_mode'],
        );

        $this->globalConflictModeCache = $this->normalizeConflictMode($row->value ?? null);
        return $this->globalConflictModeCache;
    }

    private function resolveConflictModeForCharacter(int $characterId): string
    {
        if ($characterId <= 0) {
            return $this->resolveGlobalConflictMode();
        }

        if (isset($this->conflictModeByCharacterCache[$characterId])) {
            return (string) $this->conflictModeByCharacterCache[$characterId];
        }

        $fallbackMode = $this->resolveGlobalConflictMode();
        $locationId = $this->characterLocationId($characterId);
        if ($locationId <= 0
            || !$this->tableExists('conflicts')
            || !$this->columnExists('conflicts', 'resolution_mode')
            || !$this->columnExists('conflicts', 'location_id')
        ) {
            $this->conflictModeByCharacterCache[$characterId] = $fallbackMode;
            return $fallbackMode;
        }

        $sql = 'SELECT resolution_mode
                FROM conflicts
                WHERE location_id = ? ';
        $params = [$locationId];

        if ($this->columnExists('conflicts', 'status')) {
            $sql .= ' AND status IN (?, ?, ?, ?) ';
            $params = array_merge($params, ['active', 'open', 'awaiting_resolution', 'proposal']);
            $sql .= ' ORDER BY CASE status
                            WHEN "active" THEN 1
                            WHEN "open" THEN 2
                            WHEN "awaiting_resolution" THEN 3
                            WHEN "proposal" THEN 4
                            ELSE 9
                        END ASC, id DESC ';
        } else {
            $sql .= ' ORDER BY id DESC ';
        }
        $sql .= 'LIMIT 1';

        $row = $this->firstPrepared($sql, $params);
        $mode = empty($row) ? $fallbackMode : $this->normalizeConflictMode($row->resolution_mode ?? null);

        $this->conflictModeByCharacterCache[$characterId] = $mode;
        return $mode;
    }

    private function resolveStateIdFromEffect(array $effectPayload, string $field): int
    {
        if ($field === 'apply') {
            $stateId = isset($effectPayload['state_id']) ? (int) $effectPayload['state_id'] : 0;
            if ($stateId <= 0) {
                $stateId = isset($effectPayload['applies_state_id']) ? (int) $effectPayload['applies_state_id'] : 0;
            }
            return ($stateId > 0) ? $stateId : 0;
        }

        $stateId = isset($effectPayload['remove_state_id']) ? (int) $effectPayload['remove_state_id'] : 0;
        if ($stateId <= 0) {
            $stateId = isset($effectPayload['removes_state_id']) ? (int) $effectPayload['removes_state_id'] : 0;
        }
        return ($stateId > 0) ? $stateId : 0;
    }

    private function resolveDurationPayload(array $effectPayload): array
    {
        $value = isset($effectPayload['state_duration_value']) ? (int) $effectPayload['state_duration_value'] : 0;
        if ($value <= 0 && isset($effectPayload['duration_value'])) {
            $value = (int) $effectPayload['duration_value'];
        }
        if ($value <= 0) {
            $value = null;
        }

        $unit = strtolower(trim((string) ($effectPayload['state_duration_unit'] ?? ($effectPayload['duration_unit'] ?? 'scene'))));
        $allowed = ['turn', 'minute', 'hour', 'day', 'scene'];
        if (!in_array($unit, $allowed, true)) {
            $unit = 'scene';
        }
        if ($value === null && $unit !== 'scene') {
            $unit = 'scene';
        }

        return [
            'value' => $value,
            'unit' => $unit,
        ];
    }

    private function buildNarrativeEventMeta(string $eventType, array $event): string
    {
        $payload = [
            'event_type' => $eventType,
            'state_id' => isset($event['state_id']) ? (int) $event['state_id'] : 0,
            'target_type' => (string) ($event['target_type'] ?? 'character'),
            'target_id' => isset($event['target_id']) ? (int) $event['target_id'] : 0,
            'scene_id' => isset($event['scene_id']) ? (int) $event['scene_id'] : null,
            'intensity' => isset($event['intensity']) ? (float) $event['intensity'] : null,
            'duration' => $event['duration'] ?? null,
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function insertNarrativeEventMessage(int $locationId, int $characterId, string $body, string $metaJson): void
    {
        if ($locationId <= 0 || $characterId <= 0) {
            return;
        }

        $this->locationMessageService()->insertMessage(
            $locationId,
            $characterId,
            3,
            $body,
            $metaJson,
        );
    }

    private function applyItemEffect(int $characterId, array $effectPayload): array
    {
        $effectType = strtolower(trim((string) ($effectPayload['effect'] ?? '')));
        if ($effectType === '') {
            return ['effect' => '', 'applied' => false];
        }

        switch ($effectType) {
            case 'heal':
                $amount = isset($effectPayload['amount']) ? (float) $effectPayload['amount'] : 0.0;
                if ($amount <= 0 && isset($effectPayload['healing'])) {
                    $amount = (float) $effectPayload['healing'];
                }
                if ($amount <= 0) {
                    return ['effect' => 'heal', 'applied' => false, 'reason' => 'invalid_amount'];
                }

                $character = $this->firstPrepared(
                    'SELECT health, health_max
                     FROM characters
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$characterId],
                );
                if (empty($character)) {
                    $this->failCharacterInvalid();
                }

                $current = (float) ($character->health ?? 0);
                $max = (float) ($character->health_max ?? 100);
                if ($max <= 0) {
                    $max = 100;
                }
                $next = $current + $amount;
                if ($next > $max) {
                    $next = $max;
                }
                if ($next < 0) {
                    $next = 0;
                }

                $this->execPrepared(
                    'UPDATE characters SET
                        health = ?
                     WHERE id = ?',
                    [$next, $characterId],
                );

                return [
                    'effect' => 'heal',
                    'applied' => true,
                    'amount' => $amount,
                    'health_before' => $current,
                    'health_after' => $next,
                ];

            case 'grant_item':
                $itemId = isset($effectPayload['item_id']) ? (int) $effectPayload['item_id'] : 0;
                if ($itemId <= 0 && isset($effectPayload['grant_item_id'])) {
                    $itemId = (int) $effectPayload['grant_item_id'];
                }
                if ($itemId <= 0) {
                    return ['effect' => 'grant_item', 'applied' => false, 'reason' => 'invalid_item'];
                }

                $qty = isset($effectPayload['quantity']) ? (int) $effectPayload['quantity'] : 1;
                if ($qty < 1) {
                    $qty = 1;
                }
                $targetItem = $this->firstPrepared(
                    'SELECT
                        id,
                        COALESCE(stackable, is_stackable) AS stackable,
                        COALESCE(is_equippable, 0) AS is_equippable
                     FROM items
                     WHERE id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$itemId],
                );
                if (empty($targetItem)) {
                    return ['effect' => 'grant_item', 'applied' => false, 'reason' => 'target_item_not_found'];
                }

                $isStackable = ((int) ($targetItem->stackable ?? 0) === 1);
                $isEquippable = ((int) ($targetItem->is_equippable ?? 0) === 1);
                $storeInInventory = $isStackable || !$isEquippable;
                $this->inventoryCapacityService()->assertCanAddItem($characterId, $itemId, $qty, $isStackable);

                if ($storeInInventory) {
                    $this->execPrepared(
                        'UPDATE inventory_items SET
                            quantity = quantity + ?,
                            updated_at = NOW()
                         WHERE owner_type = "player"
                           AND owner_id = ?
                           AND item_id = ?
                         LIMIT 1',
                        [$qty, $characterId, $itemId],
                    );
                    $affectedRow = $this->firstPrepared('SELECT ROW_COUNT() AS affected');
                    $affected = (!empty($affectedRow) && isset($affectedRow->affected)) ? (int) $affectedRow->affected : 0;
                    if ($affected < 1) {
                        $this->execPrepared(
                            'INSERT INTO inventory_items SET
                                owner_type = "player",
                                owner_id = ?,
                                item_id = ?,
                                quantity = ?,
                                metadata_json = "{}",
                                created_at = NOW()',
                            [$characterId, $itemId, $qty],
                        );
                    }
                } else {
                    for ($i = 0; $i < $qty; $i++) {
                        $this->execPrepared(
                            'INSERT INTO character_item_instances SET
                                character_id = ?,
                                item_id = ?,
                                is_equipped = 0,
                                slot = NULL,
                                durability = NULL,
                                meta_json = NULL,
                                date_created = NOW(),
                                date_updated = NULL',
                            [$characterId, $itemId],
                        );
                    }
                }

                return [
                    'effect' => 'grant_item',
                    'applied' => true,
                    'item_id' => $itemId,
                    'quantity' => $qty,
                ];

            case 'apply_status':
                if (!$this->hasNarrativeStatesTable()) {
                    return [
                        'effect' => 'apply_status',
                        'applied' => false,
                        'reason' => 'narrative_states_unavailable',
                    ];
                }

                $stateId = $this->resolveStateIdFromEffect($effectPayload, 'apply');
                if ($stateId <= 0) {
                    return [
                        'effect' => 'apply_status',
                        'applied' => false,
                        'reason' => 'state_missing',
                    ];
                }

                $targetType = strtolower(trim((string) ($effectPayload['target_type'] ?? 'character')));
                if ($targetType !== 'scene') {
                    $targetType = 'character';
                }

                $sceneId = isset($effectPayload['scene_id']) ? (int) $effectPayload['scene_id'] : 0;
                if ($sceneId <= 0) {
                    $sceneId = $this->characterLocationId($characterId);
                }

                $targetId = isset($effectPayload['target_id']) ? (int) $effectPayload['target_id'] : 0;
                if ($targetType === 'scene') {
                    if ($sceneId <= 0) {
                        return [
                            'effect' => 'apply_status',
                            'applied' => false,
                            'reason' => 'scene_missing',
                        ];
                    }
                    $targetId = ($targetId > 0) ? $targetId : $sceneId;
                } elseif ($targetId <= 0) {
                    $targetId = $characterId;
                }

                $intensity = isset($effectPayload['state_intensity']) ? (float) $effectPayload['state_intensity'] : null;
                if ($intensity === null || $intensity <= 0) {
                    $intensity = isset($effectPayload['intensity']) ? (float) $effectPayload['intensity'] : 1.0;
                }
                if ($intensity <= 0) {
                    $intensity = 1.0;
                }
                $duration = $this->resolveDurationPayload($effectPayload);

                $stateRuntime = $this->narrativeStateApplicationService();
                if (!is_object($stateRuntime) || !method_exists($stateRuntime, 'applyState')) {
                    return [
                        'effect' => 'apply_status',
                        'applied' => false,
                        'reason' => 'narrative_state_runtime_unavailable',
                    ];
                }

                $applyResult = $stateRuntime->applyState([
                    'state_id' => $stateId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'scene_id' => $sceneId,
                    'applier_character_id' => $characterId,
                    'intensity' => $intensity,
                    'duration_value' => $duration['value'],
                    'duration_unit' => $duration['unit'],
                    'meta_json' => json_encode(['source' => 'item_use'], JSON_UNESCAPED_UNICODE),
                ]);

                $this->insertNarrativeEventMessage(
                    $sceneId,
                    $characterId,
                    '<div class="text-center"><p class="mb-0"><b>Effetto oggetto</b> stato applicato.</p></div>',
                    $this->buildNarrativeEventMeta('item_state_applied', [
                        'state_id' => $stateId,
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'scene_id' => $sceneId,
                        'intensity' => $intensity,
                        'duration' => $duration,
                    ]),
                );

                return [
                    'effect' => 'apply_status',
                    'applied' => true,
                    'state' => $applyResult['applied_state'] ?? null,
                    'action' => $applyResult['action'] ?? 'insert',
                ];

            case 'remove_status':
                if (!$this->hasNarrativeStatesTable()) {
                    return [
                        'effect' => 'remove_status',
                        'applied' => false,
                        'reason' => 'narrative_states_unavailable',
                    ];
                }

                $appliedStateId = isset($effectPayload['applied_state_id']) ? (int) $effectPayload['applied_state_id'] : 0;
                $stateId = $this->resolveStateIdFromEffect($effectPayload, 'remove');
                if ($appliedStateId <= 0 && $stateId <= 0) {
                    return [
                        'effect' => 'remove_status',
                        'applied' => false,
                        'reason' => 'state_missing',
                    ];
                }

                $targetType = strtolower(trim((string) ($effectPayload['target_type'] ?? 'character')));
                if ($targetType !== 'scene') {
                    $targetType = 'character';
                }

                $sceneId = isset($effectPayload['scene_id']) ? (int) $effectPayload['scene_id'] : 0;
                if ($sceneId <= 0) {
                    $sceneId = $this->characterLocationId($characterId);
                }

                $targetId = isset($effectPayload['target_id']) ? (int) $effectPayload['target_id'] : 0;
                if ($targetType === 'scene') {
                    if ($sceneId <= 0) {
                        return [
                            'effect' => 'remove_status',
                            'applied' => false,
                            'reason' => 'scene_missing',
                        ];
                    }
                    $targetId = ($targetId > 0) ? $targetId : $sceneId;
                } elseif ($targetId <= 0) {
                    $targetId = $characterId;
                }

                $stateRuntime = $this->narrativeStateApplicationService();
                if (!is_object($stateRuntime) || !method_exists($stateRuntime, 'removeState')) {
                    return [
                        'effect' => 'remove_status',
                        'applied' => false,
                        'reason' => 'narrative_state_runtime_unavailable',
                    ];
                }

                $removeResult = $stateRuntime->removeState([
                    'applied_state_id' => $appliedStateId,
                    'state_id' => $stateId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'scene_id' => $sceneId,
                    'reason' => 'item_remove_status',
                ]);

                $this->insertNarrativeEventMessage(
                    $sceneId,
                    $characterId,
                    '<div class="text-center"><p class="mb-0"><b>Effetto oggetto</b> stato rimosso.</p></div>',
                    $this->buildNarrativeEventMeta('item_state_removed', [
                        'state_id' => $stateId,
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'scene_id' => $sceneId,
                        'intensity' => null,
                        'duration' => null,
                    ]),
                );

                return [
                    'effect' => 'remove_status',
                    'applied' => true,
                    'removed_count' => (int) ($removeResult['removed_count'] ?? 0),
                ];

            case 'spawn_event':
                if (!$this->isNarrativeStatesRuntimeAvailable()) {
                    return [
                        'effect' => 'spawn_event',
                        'applied' => false,
                        'reason' => 'narrative_runtime_unavailable',
                    ];
                }

                $sceneId = isset($effectPayload['scene_id']) ? (int) $effectPayload['scene_id'] : 0;
                if ($sceneId <= 0) {
                    $sceneId = $this->characterLocationId($characterId);
                }

                $eventTitle = trim((string) ($effectPayload['event_title'] ?? 'Evento oggetto'));
                $eventBody = trim((string) ($effectPayload['event_body'] ?? 'Un effetto narrativo e stato attivato da un oggetto.'));
                $body = '<div class="text-center"><p class="mb-1"><b>' . htmlentities($eventTitle, ENT_QUOTES, 'UTF-8') . '</b></p><p class="mb-0">' . htmlentities($eventBody, ENT_QUOTES, 'UTF-8') . '</p></div>';

                if ($sceneId > 0) {
                    $this->insertNarrativeEventMessage(
                        $sceneId,
                        $characterId,
                        $body,
                        $this->buildNarrativeEventMeta('item_spawn_event', [
                            'state_id' => 0,
                            'target_type' => 'scene',
                            'target_id' => $sceneId,
                            'scene_id' => $sceneId,
                            'intensity' => null,
                            'duration' => null,
                        ]),
                    );
                }

                return [
                    'effect' => 'spawn_event',
                    'applied' => ($sceneId > 0),
                    'scene_id' => $sceneId,
                ];

            case 'restore_resource':
                return [
                    'effect' => 'restore_resource',
                    'applied' => false,
                    'reason' => 'effect_not_implemented_yet',
                ];

            default:
                return [
                    'effect' => $effectType,
                    'applied' => false,
                    'reason' => 'effect_not_supported',
                ];
        }
    }

    public function getAllowedSlots($equipSlot)
    {
        $equipSlot = trim((string) $equipSlot);
        if ($equipSlot === '') {
            return [];
        }

        $dynamic = [];
        $index = $this->equipmentSlotIndex();
        if (!empty($index)) {
            if (isset($index[$equipSlot])) {
                return [$equipSlot];
            }

            foreach ($index as $slotKey => $slotRow) {
                if ((string) ($slotRow->group_key ?? '') === $equipSlot) {
                    $dynamic[] = $slotKey;
                }
            }

            if (!empty($dynamic)) {
                return $this->normalizeSlotList($dynamic);
            }
        }

        switch ($equipSlot) {
            case 'weapon':
                return ['weapon_1', 'weapon_2'];
            case 'ring':
                return ['ring_1', 'ring_2'];
            default:
                return [$equipSlot];
        }
    }

    public function getEquippedByCharacter($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return [];
        }

        if (!$this->hasEquipmentSchema()) {
            return [];
        }
        $stateSelect = $this->narrativeStateSelectFragment('i');
        $stateJoin = $this->narrativeStateJoinFragment('i');
        $rows = $this->fetchPrepared(
            'SELECT
                cii.id AS character_item_instance_id,
                ce.inventory_item_id,
                ce.slot_id AS equipped_slot_id,
                cii.item_id,
                cii.meta_json AS instance_meta_json,
                cii.durability,
                es.`key` AS slot,
                1 AS is_equipped,
                i.name,
                i.description,
                COALESCE(NULLIF(i.icon, ""), i.image) AS image,
                i.type,
                COALESCE(i.is_equippable, 0) AS is_equippable,
                i.item_kind,
                i.equip_slot,
                COALESCE(i.usable, 0) AS usable,
                COALESCE(i.cooldown, 0) AS cooldown,
                i.applies_state_id,
                i.removes_state_id,
                ' . $stateSelect . ',
                es.`name` AS slot_name,
                es.group_key AS slot_group_key,
                es.sort_order AS slot_sort_order
             FROM character_equipment ce
             INNER JOIN equipment_slots es
               ON es.id = ce.slot_id
              AND es.is_active = 1
             INNER JOIN character_item_instances cii
               ON cii.id = ce.character_item_instance_id
              AND cii.character_id = ce.character_id
             LEFT JOIN items i ON i.id = cii.item_id
             ' . $stateJoin . '
             WHERE ce.character_id = ?
             ORDER BY es.sort_order ASC, es.id ASC',
            [$characterId],
        );

        $itemAllowedCache = [];
        $ammoRuleCache = [];
        $maintenanceRuleCache = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row->item_id ?? 0);
            $legacySlot = (string) ($row->equip_slot ?? '');

            if (!array_key_exists($itemId, $itemAllowedCache)) {
                $allowedSlots = $this->allowedSlotsForItem($itemId, $legacySlot);
                $itemAllowedCache[$itemId] = [
                    'slots' => $allowedSlots,
                    'groups' => $this->slotGroupsForKeys($allowedSlots),
                ];
            }

            $allowed = $itemAllowedCache[$itemId];
            $row->allowed_slot_keys = implode(',', $allowed['slots']);
            $row->allowed_slot_groups = implode(',', $allowed['groups']);

            if (!array_key_exists($itemId, $ammoRuleCache)) {
                $ammoRuleCache[$itemId] = $this->resolveAmmoRuleForItem($itemId);
            }

            $instanceMeta = $this->parseInstanceMeta($row->instance_meta_json ?? null);
            $loadedAmmo = $this->getLoadedAmmoFromInstanceMeta($instanceMeta);
            $ammoUi = $this->resolveAmmoUiPayload($ammoRuleCache[$itemId], $loadedAmmo);

            $row->requires_ammo = $ammoUi['requires_ammo'] ? 1 : 0;
            $row->ammo_item_id = (int) $ammoUi['ammo_item_id'];
            $row->ammo_item_name = (string) $ammoUi['ammo_item_name'];
            $row->ammo_per_use = (int) $ammoUi['ammo_per_use'];
            $row->ammo_magazine_size = (int) $ammoUi['ammo_magazine_size'];
            $row->ammo_loaded = (int) $ammoUi['ammo_loaded'];

            $slotId = (int) ($row->equipped_slot_id ?? 0);
            $maintenanceCacheKey = $itemId . ':' . $slotId;
            if (!array_key_exists($maintenanceCacheKey, $maintenanceRuleCache)) {
                $isEquipment = ((int) ($row->is_equippable ?? 0) === 1)
                    || strtolower(trim((string) ($row->item_kind ?? ''))) === 'equipment';
                $maintenanceRuleCache[$maintenanceCacheKey] = $this->resolveMaintenanceRuleForItem($itemId, $slotId, $isEquipment);
            }
            $quality = $this->buildQualitySnapshot($maintenanceRuleCache[$maintenanceCacheKey], $row->durability ?? null);
            $row->quality_enabled = $quality['quality_enabled'] ? 1 : 0;
            $row->quality_current = (int) $quality['quality_current'];
            $row->quality_max = (int) $quality['quality_max'];
            $row->quality_percent = (int) $quality['quality_percent'];
            $row->needs_maintenance = $quality['needs_maintenance'] ? 1 : 0;
            $row->jam_enabled = $maintenanceRuleCache[$maintenanceCacheKey]['jam_enabled'] ? 1 : 0;
            $row->jam_chance_percent = (int) ($maintenanceRuleCache[$maintenanceCacheKey]['jam_chance_percent'] ?? 0);
        }

        return $rows;
    }

    public function getUnequippedEquippables($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return [];
        }

        $stateSelect = $this->narrativeStateSelectFragment('i');
        $stateJoin = $this->narrativeStateJoinFragment('i');
        $rows = $this->fetchPrepared(
            'SELECT
                cii.id AS character_item_instance_id,
                ii.id AS inventory_item_id,
                cii.item_id,
                cii.is_equipped,
                cii.slot,
                cii.durability,
                cii.meta_json,
                i.name,
                i.description,
                COALESCE(NULLIF(i.icon, ""), i.image) AS image,
                i.type,
                i.item_kind,
                i.is_equippable,
                i.equip_slot,
                COALESCE(i.usable, 0) AS usable,
                COALESCE(i.cooldown, 0) AS cooldown,
                i.applies_state_id,
                i.removes_state_id,
                ' . $stateSelect . '
             FROM character_item_instances cii
             LEFT JOIN inventory_items ii
               ON ii.owner_type = "player"
              AND ii.owner_id = cii.character_id
              AND ii.legacy_instance_id = cii.id
             LEFT JOIN items i ON i.id = cii.item_id
             ' . $stateJoin . '
             WHERE cii.character_id = ?
               AND cii.is_equipped = 0
               AND (COALESCE(i.is_equippable, 0) = 1 OR i.item_kind = "equipment")
             ORDER BY i.name ASC',
            [$characterId],
        );

        $itemAllowedCache = [];
        $maintenanceRuleCache = [];
        foreach ($rows as $row) {
            $itemId = (int) ($row->item_id ?? 0);
            $legacySlot = (string) ($row->equip_slot ?? '');

            if (!array_key_exists($itemId, $itemAllowedCache)) {
                $allowedSlots = $this->allowedSlotsForItem($itemId, $legacySlot);
                $itemAllowedCache[$itemId] = [
                    'slots' => $allowedSlots,
                    'groups' => $this->slotGroupsForKeys($allowedSlots),
                ];
            }

            $allowed = $itemAllowedCache[$itemId];
            $row->allowed_slot_keys = implode(',', $allowed['slots']);
            $row->allowed_slot_groups = implode(',', $allowed['groups']);

            if (!array_key_exists($itemId, $maintenanceRuleCache)) {
                $isEquipment = ((int) ($row->is_equippable ?? 0) === 1)
                    || strtolower(trim((string) ($row->item_kind ?? ''))) === 'equipment';
                $maintenanceRuleCache[$itemId] = $this->resolveMaintenanceRuleForItem($itemId, 0, $isEquipment);
            }
            $quality = $this->buildQualitySnapshot($maintenanceRuleCache[$itemId], $row->durability ?? null);
            $row->quality_enabled = $quality['quality_enabled'] ? 1 : 0;
            $row->quality_current = (int) $quality['quality_current'];
            $row->quality_max = (int) $quality['quality_max'];
            $row->quality_percent = (int) $quality['quality_percent'];
            $row->needs_maintenance = $quality['needs_maintenance'] ? 1 : 0;
            $row->jam_enabled = $maintenanceRuleCache[$itemId]['jam_enabled'] ? 1 : 0;
            $row->jam_chance_percent = (int) ($maintenanceRuleCache[$itemId]['jam_chance_percent'] ?? 0);
        }

        return $rows;
    }

    public function getCategoriesByCharacter($characterId)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return [];
        }

        $inventoryUnionSql = '(
                SELECT item_id, quantity AS qty
                FROM inventory_items
                WHERE owner_type = "player"
                  AND owner_id = ?
                  AND legacy_instance_id IS NULL
                UNION ALL
                SELECT item_id, 1 AS qty
                FROM character_item_instances
                WHERE character_id = ?
            )';

        $categories = $this->fetchPrepared(
            'SELECT
                c.id AS category_id,
                c.name AS name,
                COALESCE(c.sort_order, 9999) AS sort_order,
                COALESCE(inv.total, 0) AS total
             FROM item_categories c
             LEFT JOIN (
                SELECT
                    COALESCE(i.category_id, 0) AS category_id,
                    SUM(src.qty) AS total
                FROM ' . $inventoryUnionSql . ' src
                LEFT JOIN items i ON i.id = src.item_id
                GROUP BY COALESCE(i.category_id, 0)
             ) inv ON inv.category_id = c.id
             ORDER BY sort_order ASC, name ASC',
            [$characterId, $characterId],
        );

        if (empty($categories)) {
            $categories = [];
        }

        $uncategorized = $this->firstPrepared(
            'SELECT COALESCE(SUM(src.qty), 0) AS total
             FROM ' . $inventoryUnionSql . ' src
             LEFT JOIN items i ON i.id = src.item_id
             WHERE COALESCE(i.category_id, 0) = 0',
            [$characterId, $characterId],
        );

        if (!empty($uncategorized) && (int) ($uncategorized->total ?? 0) > 0) {
            $categories[] = (object) [
                'category_id' => 0,
                'name' => 'Altro',
                'sort_order' => 9999,
                'total' => (int) $uncategorized->total,
            ];
        }

        return $categories;
    }

    public function listBag($characterId, $page, $results, $orderBy, $query): array
    {
        $characterId = (int) $characterId;
        $page = (int) $page;
        $results = (int) $results;
        if ($characterId <= 0) {
            return ['count' => (object) ['count' => 0], 'dataset' => []];
        }

        if ($page < 1) {
            $page = 1;
        }
        if ($results < 1) {
            $results = 10;
        }

        $stackWhere = [
            'ii.owner_type = "player"',
            'ii.owner_id = ?',
            'ii.legacy_instance_id IS NULL',
            'COALESCE(i.is_equippable, 0) = 0',
        ];
        $instWhere = [
            'cii.character_id = ?',
        ];
        $stackParams = [$characterId];
        $instParams = [$characterId];

        if (!empty($query) && is_object($query)) {
            if (!empty($query->type)) {
                $stackWhere[] = 'i.type = ?';
                $instWhere[] = 'i.type = ?';
                $stackParams[] = (string) $query->type;
                $instParams[] = (string) $query->type;
            }
            if (property_exists($query, 'category_id')) {
                $categoryId = $query->category_id;
                if ($categoryId === 0 || $categoryId === '0') {
                    $stackWhere[] = '(i.category_id IS NULL OR i.category_id = 0)';
                    $instWhere[] = '(i.category_id IS NULL OR i.category_id = 0)';
                } elseif (!empty($categoryId)) {
                    $stackWhere[] = 'i.category_id = ?';
                    $instWhere[] = 'i.category_id = ?';
                    $stackParams[] = (int) $categoryId;
                    $instParams[] = (int) $categoryId;
                }
            }
            if (!empty($query->search)) {
                $term = trim((string) $query->search);
                if ($term !== '') {
                    $like = '%' . $term . '%';
                    $stackWhere[] = '(i.name LIKE ? OR i.description LIKE ?)';
                    $instWhere[] = '(i.name LIKE ? OR i.description LIKE ?)';
                    $stackParams[] = $like;
                    $stackParams[] = $like;
                    $instParams[] = $like;
                    $instParams[] = $like;
                }
            }
        }
        $stackWhereSql = implode(' AND ', $stackWhere);
        $instWhereSql = implode(' AND ', $instWhere);

        $stateSelect = $this->narrativeStateSelectFragment('i');
        $stateJoin = $this->narrativeStateJoinFragment('i');
        $stackSql = 'SELECT
                ii.id AS id,
                ii.id AS character_item_id,
                NULL AS character_item_instance_id,
                ii.item_id,
                ii.quantity,
                ii.created_at AS date_created,
                i.name AS item_name,
                i.description AS item_description,
                COALESCE(NULLIF(i.icon, ""), i.image) AS item_image,
                i.type AS item_type,
                COALESCE(i.category_id, 0) AS item_category_id,
                COALESCE(i.stackable, i.is_stackable) AS is_stackable,
                i.is_equippable,
                i.equip_slot,
                COALESCE(i.droppable, 1) AS droppable,
                COALESCE(i.usable, 0) AS usable,
                COALESCE(i.consumable, 0) AS consumable,
                COALESCE(i.cooldown, 0) AS cooldown,
                i.applies_state_id,
                i.removes_state_id,
                i.state_intensity,
                i.state_duration_value,
                i.state_duration_unit,
                ' . $stateSelect . ',
                COALESCE(ir.id, 0) AS rarity_id,
                COALESCE(NULLIF(i.rarity, ""), ir.code, "common") AS rarity_code,
                COALESCE(ir.name, CONCAT(UCASE(LEFT(COALESCE(NULLIF(i.rarity, ""), "common"), 1)), SUBSTRING(COALESCE(NULLIF(i.rarity, ""), "common"), 2))) AS rarity_name,
                COALESCE(ir.color_hex, "#6c757d") AS rarity_color,
                COALESCE(ir.sort_order, 9999) AS rarity_sort_order,
                0 AS is_equipped,
                NULL AS slot,
                "stack" AS source
            FROM inventory_items ii
            LEFT JOIN items i ON i.id = ii.item_id
            LEFT JOIN item_rarities ir ON ir.id = i.rarity_id
            ' . $stateJoin . '
            WHERE ' . $stackWhereSql;

        $instSql = 'SELECT
                cii.id AS id,
                NULL AS character_item_id,
                cii.id AS character_item_instance_id,
                cii.item_id,
                1 AS quantity,
                cii.date_created AS date_created,
                i.name AS item_name,
                i.description AS item_description,
                COALESCE(NULLIF(i.icon, ""), i.image) AS item_image,
                i.type AS item_type,
                COALESCE(i.category_id, 0) AS item_category_id,
                COALESCE(i.stackable, i.is_stackable) AS is_stackable,
                i.is_equippable,
                i.equip_slot,
                COALESCE(i.droppable, 1) AS droppable,
                COALESCE(i.usable, 0) AS usable,
                COALESCE(i.consumable, 0) AS consumable,
                COALESCE(i.cooldown, 0) AS cooldown,
                i.applies_state_id,
                i.removes_state_id,
                i.state_intensity,
                i.state_duration_value,
                i.state_duration_unit,
                ' . $stateSelect . ',
                COALESCE(ir.id, 0) AS rarity_id,
                COALESCE(NULLIF(i.rarity, ""), ir.code, "common") AS rarity_code,
                COALESCE(ir.name, CONCAT(UCASE(LEFT(COALESCE(NULLIF(i.rarity, ""), "common"), 1)), SUBSTRING(COALESCE(NULLIF(i.rarity, ""), "common"), 2))) AS rarity_name,
                COALESCE(ir.color_hex, "#6c757d") AS rarity_color,
                COALESCE(ir.sort_order, 9999) AS rarity_sort_order,
                cii.is_equipped,
                cii.slot,
                "instance" AS source
            FROM character_item_instances cii
            LEFT JOIN items i ON i.id = cii.item_id
            LEFT JOIN item_rarities ir ON ir.id = i.rarity_id
            ' . $stateJoin . '
            WHERE ' . $instWhereSql;

        $unionSql = '(' . $stackSql . ') UNION ALL (' . $instSql . ')';
        $orderSql = $this->buildBagOrderBy($orderBy);
        $offset = ($page - 1) * $results;
        $countParams = array_merge($stackParams, $instParams);
        $count = $this->firstPrepared('SELECT COUNT(*) AS count FROM (' . $unionSql . ') t', $countParams);

        $datasetSql = $unionSql . $orderSql . ' LIMIT ?, ?';
        $datasetParams = array_merge($countParams, [$offset, $results]);
        $dataset = $this->fetchPrepared($datasetSql, $datasetParams);

        return [
            'count' => $count,
            'dataset' => $dataset,
        ];
    }

    public function destroyItem(int $characterId, object $data): void
    {
        if ($characterId <= 0) {
            throw \Core\Http\AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $instanceId = isset($data->character_item_instance_id) ? (int) $data->character_item_instance_id : 0;
        $stackId = isset($data->character_item_id) ? (int) $data->character_item_id : 0;
        $quantity = isset($data->quantity) ? max(1, (int) $data->quantity) : 1;

        if ($instanceId > 0) {
            $row = $this->firstPrepared(
                'SELECT id, character_id, is_equipped FROM character_item_instances
                 WHERE id = ? LIMIT 1',
                [$instanceId],
            );
            if (empty($row) || (int) $row->character_id !== $characterId) {
                throw \Core\Http\AppError::validation('Oggetto non trovato', [], 'item_not_found');
            }
            if ((int) ($row->is_equipped ?? 0) === 1) {
                throw \Core\Http\AppError::validation('Non puoi distruggere un oggetto equipaggiato', [], 'item_is_equipped');
            }
            $this->execPrepared('DELETE FROM character_item_instances WHERE id = ?', [$instanceId]);
            $this->execPrepared(
                'DELETE FROM inventory_items
                 WHERE legacy_instance_id = ?
                   AND owner_type = "player"
                   AND owner_id = ?',
                [$instanceId, $characterId],
            );
        } elseif ($stackId > 0) {
            $row = $this->firstPrepared(
                'SELECT id, owner_id, owner_type, quantity FROM inventory_items
                 WHERE id = ? LIMIT 1',
                [$stackId],
            );
            if (empty($row) || $row->owner_type !== 'player' || (int) $row->owner_id !== $characterId) {
                throw \Core\Http\AppError::validation('Oggetto non trovato', [], 'item_not_found');
            }
            $currentQty = (int) ($row->quantity ?? 1);
            if ($quantity >= $currentQty) {
                $this->execPrepared('DELETE FROM inventory_items WHERE id = ?', [$stackId]);
            } else {
                $this->execPrepared(
                    'UPDATE inventory_items SET quantity = ? WHERE id = ?',
                    [$currentQty - $quantity, $stackId],
                );
            }
        } else {
            throw \Core\Http\AppError::validation('Riferimento oggetto mancante', [], 'item_ref_missing');
        }
    }

    public function equipStrict($characterId, $instanceId, $slot = null): array
    {
        $characterId = (int) $characterId;
        $instanceId = (int) $instanceId;
        $slot = ($slot !== null) ? trim((string) $slot) : null;
        if ($characterId <= 0) {
            $this->failCharacterInvalid();
        }
        if ($instanceId <= 0) {
            $this->failItemInvalid();
        }

        $this->begin();
        try {
            $row = $this->firstPrepared(
                'SELECT
                    cii.id,
                    cii.item_id,
                    cii.is_equipped,
                    cii.slot,
                    cii.durability,
                    i.name,
                    i.is_equippable,
                    i.item_kind,
                    i.equip_slot,
                    i.metadata_json
                FROM character_item_instances cii
                LEFT JOIN items i ON i.id = cii.item_id
                WHERE cii.id = ? AND cii.character_id = ?
                LIMIT 1
                FOR UPDATE',
                [$instanceId, $characterId],
            );

            if (empty($row)) {
                $this->failItemNotFound();
            }

            $isEquippable = ((int) ($row->is_equippable ?? 0) === 1)
                || strtolower(trim((string) ($row->item_kind ?? ''))) === 'equipment';
            if (!$isEquippable) {
                $this->failItemNotEquippable();
            }

            $allowed = $this->allowedSlotsForItem((int) ($row->item_id ?? 0), $row->equip_slot ?? null);
            if (empty($allowed)) {
                $this->failSlotUnavailable();
            }

            if (empty($slot)) {
                if (count($allowed) === 1) {
                    $slot = $allowed[0];
                } else {
                    $this->failSlotRequired();
                }
            }

            if (!in_array($slot, $allowed, true)) {
                $this->failSlotInvalid();
            }

            $this->ensureEquipmentSchema();
            $slotDef = $this->equipmentSlotByKey((string) $slot);
            $slotId = (int) ($slotDef->id ?? 0);
            if (empty($slotDef) || (int) ($slotDef->is_active ?? 1) !== 1 || $slotId <= 0) {
                $this->failSlotInvalid();
            }
            if ((int) ($slotDef->max_equipped ?? 1) <= 0) {
                $this->failSlotUnavailable();
            }

            $slotGroupKey = trim((string) ($slotDef->group_key ?? ''));
            if ($slotGroupKey === '') {
                $slotGroupKey = trim((string) ($slotDef->key ?? ''));
            }
            $incomingTwoHanded = ($slotGroupKey !== '')
                ? $this->isItemTwoHandedForSlot($row, $slotDef, $slotId)
                : false;

            $occupied = $this->firstPrepared(
                'SELECT id, character_item_instance_id
                 FROM character_equipment
                 WHERE character_id = ?
                   AND slot_id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$characterId, $slotId],
            );
            $occupiedInstanceId = (int) ($occupied->character_item_instance_id ?? 0);

            if (!$incomingTwoHanded && $slotGroupKey !== '') {
                $equippedGroupInstances = $this->equippedInstancesByGroupForUpdate($characterId, $slotGroupKey);
                foreach ($equippedGroupInstances as $groupInstanceId) {
                    if ($groupInstanceId <= 0 || $groupInstanceId === $instanceId) {
                        continue;
                    }

                    if (!$this->isEquippedInstanceTwoHandedInGroup($characterId, $groupInstanceId, $slotGroupKey)) {
                        continue;
                    }

                    if ($occupiedInstanceId > 0 && $groupInstanceId === $occupiedInstanceId) {
                        continue;
                    }

                    $this->failEquipmentRequirementNotMet(
                        'Hai un\'arma a due mani equipaggiata nel gruppo selezionato. Rimuovila prima di equipaggiare un altro oggetto.',
                    );
                }
            }

            $this->validateEquipmentRequirements($characterId, $row, $slotDef, $slotId);

            $alreadyEquippedRow = $this->firstPrepared(
                'SELECT id, slot_id
                 FROM character_equipment
                 WHERE character_id = ?
                   AND character_item_instance_id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$characterId, $instanceId],
            );

            if ($incomingTwoHanded && $slotGroupKey !== '') {
                $groupInstanceIds = $this->equippedInstancesByGroupForUpdate($characterId, $slotGroupKey);
                foreach ($groupInstanceIds as $groupInstanceId) {
                    if ($groupInstanceId > 0 && $groupInstanceId !== $instanceId) {
                        $this->clearEquippedInstance($characterId, $groupInstanceId);
                    }
                }

                $this->execPrepared(
                    'DELETE FROM character_equipment
                     WHERE character_id = ?
                       AND slot_id = ?
                     LIMIT 1',
                    [$characterId, $slotId],
                );

                $this->execPrepared(
                    'DELETE FROM character_equipment
                     WHERE character_id = ?
                       AND character_item_instance_id = ?',
                    [$characterId, $instanceId],
                );
            } else {
                $this->enforceSlotGroupCapacity($characterId, $slotDef, $instanceId, $occupiedInstanceId);

                if (!empty($occupied) && (int) ($occupied->character_item_instance_id ?? 0) !== $instanceId) {
                    if ($occupiedInstanceId > 0) {
                        $this->clearEquippedInstance($characterId, $occupiedInstanceId);
                    }
                }

                if (!empty($alreadyEquippedRow) && (int) ($alreadyEquippedRow->slot_id ?? 0) !== $slotId) {
                    $this->execPrepared(
                        'DELETE FROM character_equipment
                         WHERE character_id = ?
                           AND character_item_instance_id = ?',
                        [$characterId, $instanceId],
                    );
                }

                $this->execPrepared(
                    'DELETE FROM character_equipment
                     WHERE character_id = ?
                       AND slot_id = ?
                     LIMIT 1',
                    [$characterId, $slotId],
                );
            }

            $inventoryItemId = $this->inventoryItemIdByInstanceId($characterId, $instanceId);
            $this->execPrepared(
                'INSERT INTO character_equipment SET
                    character_id = ?,
                    slot_id = ?,
                    character_item_instance_id = ?,
                    inventory_item_id = ?,
                    equipped_at = NOW(),
                    date_created = NOW()',
                [$characterId, $slotId, $instanceId, $inventoryItemId > 0 ? $inventoryItemId : null],
            );

            $this->execPrepared(
                'UPDATE character_item_instances SET
                    is_equipped = 1,
                    slot = ?,
                    date_updated = NOW()
                WHERE id = ? AND character_id = ?',
                [$slot, $instanceId, $characterId],
            );

            $isEquipment = ((int) ($row->is_equippable ?? 0) === 1)
                || strtolower(trim((string) ($row->item_kind ?? ''))) === 'equipment';
            $maintenanceRule = $this->resolveMaintenanceRuleForItem((int) ($row->item_id ?? 0), $slotId, $isEquipment);
            $quality = null;
            if (($maintenanceRule['durability_enabled'] ?? false) === true) {
                $lossOnEquip = max(0, (int) ($maintenanceRule['durability_loss_on_equip'] ?? 0));
                $quality = $this->applyDurabilityLossOnInstance($characterId, $instanceId, $maintenanceRule, $lossOnEquip);
            }

            $this->commit();
            return [
                'status' => 'ok',
                'slot' => $slot,
                'is_two_handed' => $incomingTwoHanded ? 1 : 0,
                'quality' => $quality,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function unequipStrict($characterId, $instanceId): array
    {
        $characterId = (int) $characterId;
        $instanceId = (int) $instanceId;
        if ($characterId <= 0) {
            $this->failCharacterInvalid();
        }
        if ($instanceId <= 0) {
            $this->failItemInvalid();
        }

        $this->begin();
        try {
            $row = $this->firstPrepared(
                'SELECT id FROM character_item_instances
                WHERE id = ? AND character_id = ?
                LIMIT 1
                FOR UPDATE',
                [$instanceId, $characterId],
            );

            if (empty($row)) {
                $this->failItemNotFound();
            }

            $this->ensureEquipmentSchema();
            $this->execPrepared(
                'DELETE FROM character_equipment
                 WHERE character_id = ?
                   AND character_item_instance_id = ?',
                [$characterId, $instanceId],
            );

            $this->execPrepared(
                'UPDATE character_item_instances SET
                    is_equipped = 0,
                    slot = NULL,
                    date_updated = NOW()
                WHERE id = ? AND character_id = ?',
                [$instanceId, $characterId],
            );

            $this->commit();
            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function swapStrict($characterId, $fromSlot, $toSlot): array
    {
        $characterId = (int) $characterId;
        $fromSlot = trim((string) $fromSlot);
        $toSlot = trim((string) $toSlot);

        if ($characterId <= 0) {
            $this->failCharacterInvalid();
        }
        if ($fromSlot === '' || $toSlot === '' || $fromSlot === $toSlot) {
            $this->failSlotInvalid();
        }

        $this->begin();
        try {
            $this->ensureEquipmentSchema();

            $fromDef = $this->equipmentSlotByKey($fromSlot);
            $toDef = $this->equipmentSlotByKey($toSlot);
            $fromSlotId = (int) ($fromDef->id ?? 0);
            $toSlotId = (int) ($toDef->id ?? 0);
            if (empty($fromDef) || empty($toDef) || $fromSlotId <= 0 || $toSlotId <= 0) {
                $this->failSlotInvalid();
            }
            if ((int) ($fromDef->is_active ?? 1) !== 1 || (int) ($fromDef->max_equipped ?? 1) <= 0) {
                $this->failSlotUnavailable();
            }
            if ((int) ($toDef->is_active ?? 1) !== 1 || (int) ($toDef->max_equipped ?? 1) <= 0) {
                $this->failSlotUnavailable();
            }

            $fromRow = $this->firstPrepared(
                'SELECT id, character_item_instance_id
                 FROM character_equipment
                 WHERE character_id = ?
                   AND slot_id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$characterId, $fromSlotId],
            );
            if (empty($fromRow) || (int) ($fromRow->character_item_instance_id ?? 0) <= 0) {
                $this->failValidation('Nessun oggetto equipaggiato nello slot di origine', 'swap_source_empty');
            }

            $toRow = $this->firstPrepared(
                'SELECT id, character_item_instance_id
                 FROM character_equipment
                 WHERE character_id = ?
                   AND slot_id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$characterId, $toSlotId],
            );

            $sourceInstanceId = (int) ($fromRow->character_item_instance_id ?? 0);
            $targetInstanceId = (int) ($toRow->character_item_instance_id ?? 0);

            $sourceItem = $this->firstPrepared(
                'SELECT i.id AS item_id, i.equip_slot, i.metadata_json
                 FROM character_item_instances cii
                 INNER JOIN items i ON i.id = cii.item_id
                 WHERE cii.id = ?
                   AND cii.character_id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$sourceInstanceId, $characterId],
            );
            if (empty($sourceItem)) {
                $this->failItemNotFound();
            }

            $sourceAllowed = $this->allowedSlotsForItem((int) ($sourceItem->item_id ?? 0), $sourceItem->equip_slot ?? null);
            if (!in_array($toSlot, $sourceAllowed, true)) {
                $this->failValidation('L\'oggetto nello slot origine non e compatibile con lo slot destinazione', 'swap_target_incompatible');
            }
            $this->validateEquipmentRequirements($characterId, $sourceItem, $toDef, $toSlotId);

            if ($targetInstanceId > 0) {
                $targetItem = $this->firstPrepared(
                    'SELECT i.id AS item_id, i.equip_slot, i.metadata_json
                     FROM character_item_instances cii
                     INNER JOIN items i ON i.id = cii.item_id
                     WHERE cii.id = ?
                       AND cii.character_id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$targetInstanceId, $characterId],
                );
                if (empty($targetItem)) {
                    $this->failItemNotFound();
                }

                $targetAllowed = $this->allowedSlotsForItem((int) ($targetItem->item_id ?? 0), $targetItem->equip_slot ?? null);
                if (!in_array($fromSlot, $targetAllowed, true)) {
                    $this->failValidation('L\'oggetto nello slot destinazione non e compatibile con lo slot origine', 'swap_source_incompatible');
                }
                $this->validateEquipmentRequirements($characterId, $targetItem, $fromDef, $fromSlotId);
            } else {
                // Move verso slot vuoto: applica cap di gruppo come per equip.
                $this->enforceSlotGroupCapacity($characterId, $toDef, $sourceInstanceId, 0);
            }

            $sourceInventoryId = $this->inventoryItemIdByInstanceId($characterId, $sourceInstanceId);
            $targetInventoryId = ($targetInstanceId > 0) ? $this->inventoryItemIdByInstanceId($characterId, $targetInstanceId) : 0;

            if ($targetInstanceId > 0) {
                $this->execPrepared(
                    'UPDATE character_equipment SET
                        character_item_instance_id = NULL,
                        inventory_item_id = NULL,
                        date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [(int) $fromRow->id],
                );

                $this->execPrepared(
                    'UPDATE character_equipment SET
                        character_item_instance_id = ?,
                        inventory_item_id = ?,
                        equipped_at = NOW(),
                        date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [$sourceInstanceId, $sourceInventoryId > 0 ? $sourceInventoryId : null, (int) $toRow->id],
                );

                $this->execPrepared(
                    'UPDATE character_equipment SET
                        character_item_instance_id = ?,
                        inventory_item_id = ?,
                        equipped_at = NOW(),
                        date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [$targetInstanceId, $targetInventoryId > 0 ? $targetInventoryId : null, (int) $fromRow->id],
                );

                $this->execPrepared(
                    'UPDATE character_item_instances SET
                        slot = ?,
                        is_equipped = 1,
                        date_updated = NOW()
                     WHERE id = ?
                       AND character_id = ?',
                    [$toSlot, $sourceInstanceId, $characterId],
                );

                $this->execPrepared(
                    'UPDATE character_item_instances SET
                        slot = ?,
                        is_equipped = 1,
                        date_updated = NOW()
                     WHERE id = ?
                       AND character_id = ?',
                    [$fromSlot, $targetInstanceId, $characterId],
                );
            } else {
                $this->execPrepared(
                    'UPDATE character_equipment SET
                        slot_id = ?,
                        character_item_instance_id = ?,
                        inventory_item_id = ?,
                        equipped_at = NOW(),
                        date_updated = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [$toSlotId, $sourceInstanceId, $sourceInventoryId > 0 ? $sourceInventoryId : null, (int) $fromRow->id],
                );

                $this->execPrepared(
                    'UPDATE character_item_instances SET
                        slot = ?,
                        is_equipped = 1,
                        date_updated = NOW()
                     WHERE id = ?
                       AND character_id = ?',
                    [$toSlot, $sourceInstanceId, $characterId],
                );
            }

            $sourceQuality = null;
            $sourceMaintenanceRule = $this->resolveMaintenanceRuleForItem((int) ($sourceItem->item_id ?? 0), $toSlotId, true);
            if (($sourceMaintenanceRule['durability_enabled'] ?? false) === true) {
                $sourceLoss = max(0, (int) ($sourceMaintenanceRule['durability_loss_on_equip'] ?? 0));
                $sourceQuality = $this->applyDurabilityLossOnInstance($characterId, $sourceInstanceId, $sourceMaintenanceRule, $sourceLoss);
            }

            $targetQuality = null;
            if ($targetInstanceId > 0 && !empty($targetItem)) {
                $targetMaintenanceRule = $this->resolveMaintenanceRuleForItem((int) ($targetItem->item_id ?? 0), $fromSlotId, true);
                if (($targetMaintenanceRule['durability_enabled'] ?? false) === true) {
                    $targetLoss = max(0, (int) ($targetMaintenanceRule['durability_loss_on_equip'] ?? 0));
                    $targetQuality = $this->applyDurabilityLossOnInstance($characterId, $targetInstanceId, $targetMaintenanceRule, $targetLoss);
                }
            }

            $this->commit();
            return [
                'status' => 'ok',
                'from_slot' => $fromSlot,
                'to_slot' => $toSlot,
                'swapped' => ($targetInstanceId > 0),
                'source_quality' => $sourceQuality,
                'target_quality' => $targetQuality,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function getSellableQuantity($characterId, $itemId)
    {
        $characterId = (int) $characterId;
        $itemId = (int) $itemId;
        if ($characterId <= 0 || $itemId <= 0) {
            return 0;
        }

        $stackRow = $this->firstPrepared(
            'SELECT COALESCE(SUM(quantity), 0) AS quantity
             FROM inventory_items
             WHERE owner_type = "player"
               AND owner_id = ?
               AND legacy_instance_id IS NULL
               AND item_id = ?
             LIMIT 1',
            [$characterId, $itemId],
        );
        $stackQty = (int) ($stackRow->quantity ?? 0);

        $instanceRow = $this->firstPrepared(
            'SELECT COUNT(*) AS qty
             FROM character_item_instances
             WHERE character_id = ?
               AND item_id = ?
               AND is_equipped = 0',
            [$characterId, $itemId],
        );
        $instanceQty = (int) ($instanceRow->qty ?? 0);

        return $stackQty + $instanceQty;
    }

    public function useItem(int $characterId, int $inventoryItemId): array
    {
        if ($characterId <= 0) {
            $this->failCharacterInvalid();
        }
        if ($inventoryItemId <= 0) {
            $this->failItemInvalid();
        }

        $this->begin();
        try {
            $row = $this->firstPrepared(
                'SELECT
                    ii.id,
                    ii.item_id,
                    ii.quantity,
                    ii.cooldown_until,
                    ii.legacy_instance_id,
                    ii.metadata_json AS inventory_metadata_json,
                    i.name,
                    COALESCE(i.usable, 0) AS usable,
                    COALESCE(i.consumable, 0) AS consumable,
                    COALESCE(i.cooldown, 0) AS cooldown,
                    COALESCE(i.is_equippable, 0) AS is_equippable,
                    i.item_kind,
                    i.script_effect,
                    i.applies_state_id,
                    i.removes_state_id,
                    i.state_intensity,
                    i.state_duration_value,
                    i.state_duration_unit,
                    cii.durability AS instance_durability,
                    cii.slot AS instance_slot,
                    i.metadata_json AS item_metadata_json
                 FROM inventory_items ii
                 LEFT JOIN items i ON i.id = ii.item_id
                 LEFT JOIN character_item_instances cii
                   ON cii.id = ii.legacy_instance_id
                  AND cii.character_id = ii.owner_id
                 WHERE ii.id = ?
                   AND ii.owner_type = "player"
                   AND ii.owner_id = ?
                 LIMIT 1
                 FOR UPDATE',
                [$inventoryItemId, $characterId],
            );

            if (empty($row)) {
                $this->failItemNotFound();
            }

            if ((int) ($row->usable ?? 0) !== 1) {
                $this->failItemNotUsable();
            }

            if (!empty($row->cooldown_until)) {
                $cooldownUntil = strtotime((string) $row->cooldown_until);
                if ($cooldownUntil !== false && $cooldownUntil > time()) {
                    $this->failItemCooldownActive();
                }
            }

            $instanceId = (int) ($row->legacy_instance_id ?? 0);
            $isEquipment = ((int) ($row->is_equippable ?? 0) === 1)
                || strtolower(trim((string) ($row->item_kind ?? ''))) === 'equipment';
            $conflictMode = $this->resolveConflictModeForCharacter($characterId);
            $isRandomMode = ($conflictMode === 'random');
            $slotId = 0;
            $instanceSlot = trim((string) ($row->instance_slot ?? ''));
            if ($instanceSlot !== '' && $this->hasEquipmentSchema()) {
                $slotId = $this->equipmentSlotIdByKey($instanceSlot);
            }
            $maintenanceRule = $this->resolveMaintenanceRuleForItem((int) ($row->item_id ?? 0), $slotId, $isEquipment);
            $qualitySnapshot = null;
            $durabilityLossOnUse = 0;
            $durabilityCurrent = null;
            if ($instanceId > 0 && ($maintenanceRule['durability_enabled'] ?? false) === true) {
                $qualitySnapshot = $this->buildQualitySnapshot($maintenanceRule, $row->instance_durability ?? null);
                $durabilityCurrent = (int) ($qualitySnapshot['quality_current'] ?? 0);
                if ($isRandomMode && ($qualitySnapshot['needs_maintenance'] ?? false) === true) {
                    $this->failItemNeedsMaintenance();
                }
                $durabilityLossOnUse = $isRandomMode
                    ? max(0, (int) ($maintenanceRule['durability_loss_on_use'] ?? 0))
                    : 0;
            }

            $ammoRule = $this->resolveAmmoRuleForItem((int) ($row->item_id ?? 0));
            if ($isRandomMode
                && $instanceId > 0
                && ($maintenanceRule['jam_enabled'] ?? false) === true
                && ($ammoRule['requires_ammo'] ?? false) === true
                && is_int($durabilityCurrent)
                && $this->shouldJamOnUse($maintenanceRule, $durabilityCurrent)
            ) {
                $this->failItemJammed();
            }
            $ammoConsumption = null;
            if ($isRandomMode && ($ammoRule['requires_ammo'] ?? false) === true) {
                $ammoItemId = (int) ($ammoRule['ammo_item_id'] ?? 0);
                $ammoPerUse = (int) ($ammoRule['ammo_per_use'] ?? 1);
                $ammoMagazineSize = (int) ($ammoRule['ammo_magazine_size'] ?? 0);
                if ($ammoPerUse < 1) {
                    $ammoPerUse = 1;
                }
                if ($ammoMagazineSize < 0) {
                    $ammoMagazineSize = 0;
                }
                if ($ammoMagazineSize > 0 && $ammoPerUse > $ammoMagazineSize) {
                    $ammoPerUse = $ammoMagazineSize;
                }

                if ($ammoItemId <= 0) {
                    $this->failAmmoRequired();
                }

                if ($ammoMagazineSize > 0) {
                    $instanceRow = $this->instanceRowForUseByInventoryItem($characterId, $inventoryItemId);
                    if (empty($instanceRow)) {
                        $this->failAmmoReloadRequired('Ricarica disponibile solo per armi istanza/equipaggiate.');
                    }

                    $instanceMeta = $this->parseInstanceMeta($instanceRow->instance_meta_json ?? null);
                    $loadedAmmo = $this->getLoadedAmmoFromInstanceMeta($instanceMeta);
                    if ($loadedAmmo < $ammoPerUse) {
                        $this->failAmmoReloadRequired(
                            'Caricatore insufficiente: disponibili ' . $loadedAmmo . ' colpi, richiesti ' . $ammoPerUse,
                        );
                    }

                    $newLoaded = $loadedAmmo - $ammoPerUse;
                    $instanceMeta = $this->setLoadedAmmoInInstanceMeta($instanceMeta, $newLoaded);
                    $this->updateInstanceMetaJson(
                        $characterId,
                        (int) ($instanceRow->character_item_instance_id ?? 0),
                        $instanceMeta,
                    );

                    $ammoConsumption = $this->resolveAmmoUiPayload($ammoRule, $newLoaded);
                    $ammoConsumption['consumed'] = $ammoPerUse;
                    $ammoConsumption['source'] = 'magazine';
                } else {
                    $availableAmmo = $this->availableAmmoQuantity($characterId, $ammoItemId);
                    if ($availableAmmo < $ammoPerUse) {
                        $this->failAmmoNotEnough(
                            'Munizioni insufficienti: richieste ' . $ammoPerUse
                            . ' (' . $this->ammoItemName($ammoItemId) . '), disponibili ' . $availableAmmo,
                        );
                    }

                    $this->consumeAmmoQuantity($characterId, $ammoItemId, $ammoPerUse);
                    $ammoConsumption = $this->resolveAmmoUiPayload($ammoRule, 0);
                    $ammoConsumption['consumed'] = $ammoPerUse;
                    $ammoConsumption['remaining_inventory'] = max(0, $availableAmmo - $ammoPerUse);
                    $ammoConsumption['source'] = 'inventory';
                }
            } elseif (($ammoRule['requires_ammo'] ?? false) === true) {
                $ammoConsumption = $this->resolveAmmoUiPayload($ammoRule, 0);
                $ammoConsumption['consumed'] = 0;
                $ammoConsumption['source'] = 'narrative';
                $ammoConsumption['mechanics_skipped'] = true;
            }

            $effectPayload = $this->resolveItemEffectPayload(
                $row->script_effect ?? null,
                $row->item_metadata_json ?? null,
                $row->inventory_metadata_json ?? null,
            );
            if (!isset($effectPayload['applies_state_id']) && isset($row->applies_state_id)) {
                $effectPayload['applies_state_id'] = (int) $row->applies_state_id;
            }
            if (!isset($effectPayload['removes_state_id']) && isset($row->removes_state_id)) {
                $effectPayload['removes_state_id'] = (int) $row->removes_state_id;
            }
            if (!isset($effectPayload['state_intensity']) && isset($row->state_intensity) && $row->state_intensity !== null) {
                $effectPayload['state_intensity'] = (float) $row->state_intensity;
            }
            if (!isset($effectPayload['state_duration_value']) && isset($row->state_duration_value) && $row->state_duration_value !== null) {
                $effectPayload['state_duration_value'] = (int) $row->state_duration_value;
            }
            if (!isset($effectPayload['state_duration_unit']) && isset($row->state_duration_unit) && $row->state_duration_unit !== null) {
                $effectPayload['state_duration_unit'] = (string) $row->state_duration_unit;
            }
            $effectResult = $this->applyItemEffect($characterId, $effectPayload);

            $currentQty = (int) ($row->quantity ?? 0);
            if ($currentQty < 1) {
                $currentQty = 1;
            }

            $remaining = $currentQty;
            if ((int) ($row->consumable ?? 0) === 1) {
                if ($currentQty <= 1) {
                    $this->execPrepared('DELETE FROM inventory_items WHERE id = ?', [$inventoryItemId]);
                    $remaining = 0;
                } else {
                    $this->execPrepared(
                        'UPDATE inventory_items SET
                            quantity = quantity - 1,
                            updated_at = NOW()
                         WHERE id = ?',
                        [$inventoryItemId],
                    );
                    $remaining = $currentQty - 1;
                }
            }

            $cooldown = (int) ($row->cooldown ?? 0);
            if ($cooldown > 0 && $remaining > 0) {
                $this->execPrepared(
                    'UPDATE inventory_items SET
                        cooldown_until = DATE_ADD(NOW(), INTERVAL ? SECOND),
                        updated_at = NOW()
                     WHERE id = ?',
                    [$cooldown, $inventoryItemId],
                );
            }

            if ($isRandomMode && $instanceId > 0 && ($maintenanceRule['durability_enabled'] ?? false) === true) {
                $qualitySnapshot = $this->applyDurabilityLossOnInstance(
                    $characterId,
                    $instanceId,
                    $maintenanceRule,
                    $durabilityLossOnUse,
                );
            }

            $this->commit();
            return [
                'success' => true,
                'item_id' => (int) $row->item_id,
                'inventory_item_id' => $inventoryItemId,
                'remaining_quantity' => $remaining,
                'effect' => $effectResult,
                'ammo' => $ammoConsumption,
                'quality' => $qualitySnapshot,
                'conflict_mode' => $conflictMode,
                'mechanics_mode' => $isRandomMode ? 'random' : 'narrative',
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function reloadItem(int $characterId, int $instanceId = 0, int $inventoryItemId = 0): array
    {
        if ($characterId <= 0) {
            $this->failCharacterInvalid();
        }
        if ($instanceId <= 0 && $inventoryItemId <= 0) {
            $this->failItemInvalid();
        }

        $conflictMode = $this->resolveConflictModeForCharacter($characterId);
        if ($conflictMode !== 'random') {
            $this->failAmmoReloadDisabledInNarrative();
        }

        $this->begin();
        try {
            if ($instanceId > 0) {
                $row = $this->firstPrepared(
                    'SELECT
                        cii.id AS character_item_instance_id,
                        cii.item_id,
                        cii.meta_json AS instance_meta_json
                     FROM character_item_instances cii
                     WHERE cii.id = ?
                       AND cii.character_id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$instanceId, $characterId],
                );
            } else {
                $row = $this->firstPrepared(
                    'SELECT
                        cii.id AS character_item_instance_id,
                        cii.item_id,
                        cii.meta_json AS instance_meta_json
                     FROM inventory_items ii
                     INNER JOIN character_item_instances cii
                       ON cii.id = ii.legacy_instance_id
                      AND cii.character_id = ii.owner_id
                     WHERE ii.id = ?
                       AND ii.owner_type = "player"
                       AND ii.owner_id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$inventoryItemId, $characterId],
                );
            }

            if (empty($row)) {
                $this->failItemNotFound();
            }

            $itemId = (int) ($row->item_id ?? 0);
            $ammoRule = $this->resolveAmmoRuleForItem($itemId);
            if (($ammoRule['requires_ammo'] ?? false) !== true) {
                $this->failAmmoReloadNotSupported();
            }

            $ammoItemId = (int) ($ammoRule['ammo_item_id'] ?? 0);
            $ammoPerUse = max(1, (int) ($ammoRule['ammo_per_use'] ?? 1));
            $ammoMagazineSize = max(0, (int) ($ammoRule['ammo_magazine_size'] ?? 0));

            if ($ammoItemId <= 0) {
                $this->failAmmoRequired();
            }
            if ($ammoMagazineSize <= 0) {
                $this->failAmmoReloadNotSupported();
            }
            if ($ammoPerUse > $ammoMagazineSize) {
                $ammoPerUse = $ammoMagazineSize;
            }

            $instanceMeta = $this->parseInstanceMeta($row->instance_meta_json ?? null);
            $loadedAmmo = $this->getLoadedAmmoFromInstanceMeta($instanceMeta);
            if ($loadedAmmo >= $ammoMagazineSize) {
                $this->failAmmoMagazineFull();
            }

            $missing = $ammoMagazineSize - $loadedAmmo;
            $availableAmmo = $this->availableAmmoQuantity($characterId, $ammoItemId);
            if ($availableAmmo <= 0) {
                $this->failAmmoNotEnough(
                    'Nessuna munizione disponibile (' . $this->ammoItemName($ammoItemId) . ')',
                );
            }

            $reloaded = min($missing, $availableAmmo);
            if ($reloaded <= 0) {
                $this->failAmmoNotEnough();
            }

            $this->consumeAmmoQuantity($characterId, $ammoItemId, $reloaded);
            $newLoaded = $loadedAmmo + $reloaded;
            if ($newLoaded > $ammoMagazineSize) {
                $newLoaded = $ammoMagazineSize;
            }

            $instanceMeta = $this->setLoadedAmmoInInstanceMeta($instanceMeta, $newLoaded);
            $this->updateInstanceMetaJson(
                $characterId,
                (int) ($row->character_item_instance_id ?? 0),
                $instanceMeta,
            );

            $this->commit();
            return [
                'success' => true,
                'character_item_instance_id' => (int) ($row->character_item_instance_id ?? 0),
                'item_id' => $itemId,
                'ammo' => array_merge(
                    $this->resolveAmmoUiPayload($ammoRule, $newLoaded),
                    [
                        'reloaded' => $reloaded,
                        'remaining_inventory' => max(0, $availableAmmo - $reloaded),
                    ],
                ),
                'conflict_mode' => $conflictMode,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function maintainItem(int $characterId, int $instanceId = 0, int $inventoryItemId = 0): array
    {
        if ($characterId <= 0) {
            $this->failCharacterInvalid();
        }
        if ($instanceId <= 0 && $inventoryItemId <= 0) {
            $this->failItemInvalid();
        }

        $this->begin();
        try {
            if ($instanceId > 0) {
                $row = $this->firstPrepared(
                    'SELECT
                        cii.id AS character_item_instance_id,
                        cii.item_id,
                        cii.slot AS instance_slot,
                        cii.durability
                     FROM character_item_instances cii
                     WHERE cii.id = ?
                       AND cii.character_id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$instanceId, $characterId],
                );
            } else {
                $row = $this->firstPrepared(
                    'SELECT
                        cii.id AS character_item_instance_id,
                        cii.item_id,
                        cii.slot AS instance_slot,
                        cii.durability
                     FROM inventory_items ii
                     INNER JOIN character_item_instances cii
                       ON cii.id = ii.legacy_instance_id
                      AND cii.character_id = ii.owner_id
                     WHERE ii.id = ?
                       AND ii.owner_type = "player"
                       AND ii.owner_id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$inventoryItemId, $characterId],
                );
            }

            if (empty($row)) {
                $this->failItemNotFound();
            }

            $slotId = $this->equipmentSlotIdByKey((string) ($row->instance_slot ?? ''));
            $maintenanceRule = $this->resolveMaintenanceRuleForItem((int) ($row->item_id ?? 0), $slotId, true);
            if (($maintenanceRule['durability_enabled'] ?? false) !== true) {
                $this->failItemMaintenanceNotSupported();
            }

            $qualityBefore = $this->buildQualitySnapshot($maintenanceRule, $row->durability ?? null);
            $max = (int) ($qualityBefore['quality_max'] ?? 100);
            $current = (int) ($qualityBefore['quality_current'] ?? 0);
            if ($current < 0) {
                $current = 0;
            }
            if ($current > $max) {
                $current = $max;
            }

            if ($current < $max) {
                $this->execPrepared(
                    'UPDATE character_item_instances SET
                        durability = ?,
                        date_updated = NOW()
                     WHERE id = ?
                       AND character_id = ?
                     LIMIT 1',
                    [$max, (int) ($row->character_item_instance_id ?? 0), $characterId],
                );
            }

            $qualityAfter = $this->buildQualitySnapshot($maintenanceRule, $max);

            $this->commit();
            return [
                'success' => true,
                'character_item_instance_id' => (int) ($row->character_item_instance_id ?? 0),
                'item_id' => (int) ($row->item_id ?? 0),
                'quality' => [
                    'before' => $qualityBefore,
                    'after' => $qualityAfter,
                ],
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }
}
