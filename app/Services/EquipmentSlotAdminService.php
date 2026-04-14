<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class EquipmentSlotAdminService
{
    private const CORE_SLOT_KEYS = [
        'amulet',
        'helm',
        'weapon_1',
        'weapon_2',
        'gloves',
        'armor',
        'ring_1',
        'ring_2',
        'boots',
    ];

    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    private function execPreparedCount(string $sql, array $params = []): int
    {
        $this->execPrepared($sql, $params);
        $row = $this->firstPrepared('SELECT ROW_COUNT() AS n');
        return (int) ($row->n ?? 0);
    }

    private function normalizeInt($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private function normalizeBool($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default ? 1 : 0;
        }

        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['1', 'true', 'yes', 'si', 'on'], true)) {
            return 1;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        return ((int) $value > 0) ? 1 : 0;
    }

    private function normalizeText($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return ($text === '') ? null : $text;
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
            // best effort
        }
    }

    private function getSlotById(int $id)
    {
        if ($id <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, `key`, group_key, is_active
             FROM equipment_slots
             WHERE id = ?
             LIMIT 1',
            [$id],
        );
    }

    private function isCoreSlotKey(?string $key): bool
    {
        $slotKey = strtolower(trim((string) $key));
        if ($slotKey === '') {
            return false;
        }

        return in_array($slotKey, self::CORE_SLOT_KEYS, true);
    }

    private function remapItemsEquipSlot(string $slotKey, string $groupKey): int
    {
        $slotKey = trim($slotKey);
        $groupKey = trim($groupKey);
        if ($slotKey === '') {
            return 0;
        }

        if ($groupKey !== '' && $groupKey !== $slotKey) {
            $groupHasActiveSlot = $this->firstPrepared(
                'SELECT id
                 FROM equipment_slots
                 WHERE is_active = 1
                   AND group_key = ?
                   AND `key` <> ?
                 LIMIT 1',
                [$groupKey, $slotKey],
            );

            if (!empty($groupHasActiveSlot)) {
                return $this->execPreparedCount(
                    'UPDATE items
                     SET equip_slot = ?
                     WHERE equip_slot = ?',
                    [$groupKey, $slotKey],
                );
            }
        }

        return $this->execPreparedCount(
            'UPDATE items
             SET equip_slot = NULL
             WHERE equip_slot = ?',
            [$slotKey],
        );
    }

    private function detachSlotReferences(int $slotId): array
    {
        $slotId = (int) $slotId;
        if ($slotId <= 0) {
            return [
                'rules_removed' => 0,
                'equipment_removed' => 0,
                'instances_unequipped' => 0,
            ];
        }

        $instanceRows = $this->fetchPrepared(
            'SELECT ce.character_item_instance_id
             FROM character_equipment ce
             WHERE ce.slot_id = ?
               AND ce.character_item_instance_id IS NOT NULL',
            [$slotId],
        );

        $instanceIds = [];
        foreach ($instanceRows as $row) {
            $instanceId = (int) ($row->character_item_instance_id ?? 0);
            if ($instanceId > 0) {
                $instanceIds[] = $instanceId;
            }
        }
        $instanceIds = array_values(array_unique($instanceIds));

        $instancesUnequipped = 0;
        if (!empty($instanceIds)) {
            $placeholders = implode(',', array_fill(0, count($instanceIds), '?'));
            $instancesUnequipped = $this->execPreparedCount(
                'UPDATE character_item_instances
                 SET is_equipped = 0,
                     slot = NULL,
                     date_updated = NOW()
                 WHERE id IN (' . $placeholders . ')',
                array_map('intval', $instanceIds),
            );
        }

        $equipmentRemoved = $this->execPreparedCount(
            'DELETE FROM character_equipment
             WHERE slot_id = ?',
            [$slotId],
        );

        $rulesRemoved = $this->execPreparedCount(
            'DELETE FROM item_equipment_rules
             WHERE slot_id = ?',
            [$slotId],
        );

        return [
            'rules_removed' => (int) $rulesRemoved,
            'equipment_removed' => (int) $equipmentRemoved,
            'instances_unequipped' => (int) $instancesUnequipped,
        ];
    }

    public function listActive(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT `key`, name FROM equipment_slots WHERE is_active = 1 ORDER BY sort_order ASC',
        );
        return $rows ?: [];
    }

    public function create(object $data): void
    {
        $key = $this->normalizeText($data->key ?? null);
        $name = $this->normalizeText($data->name ?? null);
        if ($key === null || $name === null) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO equipment_slots
            (`key`, name, description, icon, group_key, sort_order, is_active, max_equipped, date_created)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $key,
                $name,
                $this->normalizeText($data->description ?? null),
                $this->normalizeText($data->icon ?? null),
                $this->normalizeText($data->group_key ?? null) ?? $key,
                $this->normalizeInt($data->sort_order ?? 0, 0),
                $this->normalizeBool($data->is_active ?? 1, 1),
                $this->normalizeInt($data->max_equipped ?? 1, 1),
            ],
        );
        AuditLogService::writeEvent('equipment_slots.create', ['key' => $key, 'name' => $name], 'admin');
    }

    public function update(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0) {
            return;
        }

        $existing = $this->getSlotById($id);
        if (empty($existing)) {
            return;
        }

        $key = $this->normalizeText($data->key ?? null);
        $name = $this->normalizeText($data->name ?? null);
        if ($key === null || $name === null) {
            return;
        }
        $oldKey = trim((string) ($existing->key ?? ''));
        $oldGroupKey = trim((string) ($existing->group_key ?? ''));

        if ($this->isCoreSlotKey($oldKey) && $oldKey !== $key) {
            throw AppError::validation(
                'La chiave degli slot core non puo essere modificata',
                [],
                'slot_core_key_locked',
            );
        }

        $groupKey = $this->normalizeText($data->group_key ?? null) ?? $key;
        if ($this->isCoreSlotKey($oldKey) && $oldGroupKey !== '' && $groupKey !== $oldGroupKey) {
            throw AppError::validation(
                'Il gruppo degli slot core non puo essere modificato',
                [],
                'slot_core_group_locked',
            );
        }
        $isActive = $this->normalizeBool($data->is_active ?? 1, 1);

        $this->begin();
        try {
            $this->execPrepared(
                'UPDATE equipment_slots SET
                    `key` = ?,
                    name = ?,
                    description = ?,
                    icon = ?,
                    group_key = ?,
                    sort_order = ?,
                    is_active = ?,
                    max_equipped = ?,
                    date_updated = NOW()
                 WHERE id = ?',
                [
                    $key,
                    $name,
                    $this->normalizeText($data->description ?? null),
                    $this->normalizeText($data->icon ?? null),
                    $groupKey,
                    $this->normalizeInt($data->sort_order ?? 0, 0),
                    $isActive,
                    $this->normalizeInt($data->max_equipped ?? 1, 1),
                    $id,
                ],
            );

            if ($oldKey !== '' && $oldKey !== $key) {
                $this->execPrepared(
                    'UPDATE character_item_instances cii
                     INNER JOIN character_equipment ce
                        ON ce.character_item_instance_id = cii.id
                     SET cii.slot = ?,
                         cii.date_updated = NOW()
                     WHERE ce.slot_id = ?',
                    [$key, $id],
                );

                $this->execPrepared(
                    'UPDATE items
                     SET equip_slot = ?
                     WHERE equip_slot = ?',
                    [$key, $oldKey],
                );
            }

            $wasActive = (int) ($existing->is_active ?? 0) === 1;
            if ($wasActive && $isActive === 0) {
                $this->detachSlotReferences($id);
                $this->remapItemsEquipSlot($key, $groupKey);
            }

            $this->commit();
            AuditLogService::writeEvent('equipment_slots.update', ['id' => $id], 'admin');
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function delete(object $data): array
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0) {
            throw AppError::validation('Slot non valido', [], 'slot_invalid');
        }

        $slot = $this->getSlotById($id);
        if (empty($slot)) {
            throw AppError::validation('Slot non trovato', [], 'slot_not_found');
        }

        $slotKey = trim((string) ($slot->key ?? ''));
        $slotGroupKey = trim((string) ($slot->group_key ?? ''));
        if ($this->isCoreSlotKey($slotKey)) {
            throw AppError::validation(
                'Gli slot core non possono essere eliminati. Puoi disattivarli.',
                [],
                'slot_core_locked',
            );
        }
        $this->begin();
        try {
            $impact = $this->detachSlotReferences($id);

            $itemsUpdated = $this->remapItemsEquipSlot($slotKey, $slotGroupKey);

            $slotDeleted = $this->execPreparedCount(
                'DELETE FROM equipment_slots
                 WHERE id = ?
                 LIMIT 1',
                [$id],
            );

            $this->commit();
            AuditLogService::writeEvent('equipment_slots.delete', ['id' => $id, 'key' => $slotKey], 'admin');

            return [
                'slot_deleted' => (int) $slotDeleted,
                'slot_key' => $slotKey,
                'rules_removed' => (int) ($impact['rules_removed'] ?? 0),
                'equipment_removed' => (int) ($impact['equipment_removed'] ?? 0),
                'instances_unequipped' => (int) ($impact['instances_unequipped'] ?? 0),
                'items_updated' => (int) $itemsUpdated,
            ];
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }
}
