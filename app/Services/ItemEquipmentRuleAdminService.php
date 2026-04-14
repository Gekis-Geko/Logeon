<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class ItemEquipmentRuleAdminService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function normalizeInt($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return (int) $value;
    }

    private function normalizeBool($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $raw = strtolower(trim((string) $value));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    private function clampInt(int $value, int $min, int $max): int
    {
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }
        return $value;
    }

    private function parseMetadata($json): array
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

    private function encodeMetadata(array $meta): string
    {
        if (empty($meta)) {
            return '{}';
        }

        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE);
        return is_string($encoded) ? $encoded : '{}';
    }

    private function buildRuleMetadata(object $data, array $baseMeta = []): string
    {
        $meta = $baseMeta;

        $isTwoHanded = $this->normalizeBool($data->is_two_handed ?? 0) === 1;
        if ($isTwoHanded) {
            $meta['is_two_handed'] = 1;
            $meta['two_handed'] = 1;
        } else {
            unset($meta['is_two_handed'], $meta['two_handed']);
        }

        $requiresAmmo = $this->normalizeBool($data->requires_ammo ?? 0) === 1;
        if ($requiresAmmo) {
            $ammoItemId = $this->normalizeInt($data->ammo_item_id ?? 0, 0);
            if ($ammoItemId <= 0) {
                throw AppError::validation('Seleziona il tipo di munizione', [], 'ammo_required');
            }
            $ammoPerUse = $this->normalizeInt($data->ammo_per_use ?? 1, 1);
            if ($ammoPerUse < 1) {
                $ammoPerUse = 1;
            }
            $ammoMagazineSize = $this->normalizeInt($data->ammo_magazine_size ?? 0, 0);
            if ($ammoMagazineSize < 0) {
                $ammoMagazineSize = 0;
            }
            if ($ammoMagazineSize > 0 && $ammoPerUse > $ammoMagazineSize) {
                $ammoPerUse = $ammoMagazineSize;
            }

            $meta['requires_ammo'] = 1;
            $meta['ammo_required'] = 1;
            $meta['ammo_item_id'] = $ammoItemId;
            $meta['ammo_per_use'] = $ammoPerUse;
            $meta['ammo_magazine_size'] = $ammoMagazineSize;
        } else {
            unset(
                $meta['requires_ammo'],
                $meta['ammo_required'],
                $meta['ammo_item_id'],
                $meta['ammo_per_use'],
                $meta['ammo_magazine_size'],
            );
        }

        $durabilityEnabled = $this->normalizeBool($data->durability_enabled ?? ($data->quality_enabled ?? 1)) === 1;
        if ($durabilityEnabled) {
            $durabilityMax = $this->normalizeInt($data->durability_max ?? 100, 100);
            if ($durabilityMax < 1) {
                $durabilityMax = 100;
            }
            $durabilityMax = $this->clampInt($durabilityMax, 1, 9999);

            $durabilityLossOnUse = $this->normalizeInt($data->durability_loss_on_use ?? 1, 1);
            if ($durabilityLossOnUse < 0) {
                $durabilityLossOnUse = 0;
            }
            $durabilityLossOnUse = $this->clampInt($durabilityLossOnUse, 0, 9999);

            $durabilityLossOnEquip = $this->normalizeInt($data->durability_loss_on_equip ?? 1, 1);
            if ($durabilityLossOnEquip < 0) {
                $durabilityLossOnEquip = 0;
            }
            $durabilityLossOnEquip = $this->clampInt($durabilityLossOnEquip, 0, 9999);

            $meta['durability_enabled'] = 1;
            $meta['quality_enabled'] = 1;
            $meta['durability_max'] = $durabilityMax;
            $meta['durability_loss_on_use'] = $durabilityLossOnUse;
            $meta['durability_loss_on_equip'] = $durabilityLossOnEquip;
        } else {
            unset(
                $meta['durability_enabled'],
                $meta['quality_enabled'],
                $meta['durability_max'],
                $meta['durability_loss_on_use'],
                $meta['durability_loss_on_equip'],
            );
        }

        $jamEnabled = $this->normalizeBool($data->jam_enabled ?? 0) === 1;
        if ($jamEnabled) {
            if (!$requiresAmmo) {
                throw AppError::validation(
                    'Inceppamento disponibile solo per armi da distanza con munizioni',
                    [],
                    'jam_requires_ammo',
                );
            }
            $jamChance = $this->normalizeInt($data->jam_chance_percent ?? 5, 5);
            $jamChance = $this->clampInt($jamChance, 0, 100);

            $meta['jam_enabled'] = 1;
            $meta['jam_chance_percent'] = $jamChance;
        } else {
            unset($meta['jam_enabled'], $meta['jam_chance_percent']);
        }

        return $this->encodeMetadata($meta);
    }

    private function upsertRule(int $itemId, int $slotId, int $priority, string $metadataJson): void
    {
        $this->db->executePrepared(
            'INSERT INTO item_equipment_rules (
                item_id, slot_id, priority, metadata_json, date_created
             ) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                priority = VALUES(priority),
                metadata_json = VALUES(metadata_json),
                date_updated = NOW()',
            [$itemId, $slotId, $priority, $metadataJson],
        );
    }

    public function create(object $data): void
    {
        $itemId = $this->normalizeInt($data->item_id ?? 0, 0);
        $slotId = $this->normalizeInt($data->slot_id ?? 0, 0);
        if ($itemId <= 0 || $slotId <= 0) {
            return;
        }

        $priority = $this->normalizeInt($data->priority ?? 10, 10);
        $metadataJson = $this->buildRuleMetadata($data);
        $this->upsertRule($itemId, $slotId, $priority, $metadataJson);
        AuditLogService::writeEvent('item_equipment_rules.create', ['item_id' => $itemId, 'slot_id' => $slotId], 'admin');
    }

    public function update(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0) {
            return;
        }

        $itemId = $this->normalizeInt($data->item_id ?? 0, 0);
        $slotId = $this->normalizeInt($data->slot_id ?? 0, 0);
        if ($itemId <= 0 || $slotId <= 0) {
            return;
        }

        $priority = $this->normalizeInt($data->priority ?? 10, 10);

        $existing = $this->db->fetchOnePrepared(
            'SELECT id, item_id, slot_id, metadata_json
             FROM item_equipment_rules
             WHERE id = ?
             LIMIT 1',
            [$id],
        );
        if (empty($existing)) {
            return;
        }

        $existingMeta = $this->parseMetadata($existing->metadata_json ?? null);
        $metadataJson = $this->buildRuleMetadata($data, $existingMeta);
        $this->upsertRule($itemId, $slotId, $priority, $metadataJson);

        if ((int) ($existing->item_id ?? 0) !== $itemId || (int) ($existing->slot_id ?? 0) !== $slotId) {
            $this->db->executePrepared(
                'DELETE FROM item_equipment_rules
                 WHERE id = ?
                 LIMIT 1',
                [$id],
            );
        }
        AuditLogService::writeEvent('item_equipment_rules.update', ['id' => $id], 'admin');
    }
}
