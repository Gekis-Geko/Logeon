<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class ItemService
{
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

    private function normalizeBool($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default ? 1 : 0;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
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

    private function normalizeInt($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    private function normalizeNullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $int = (int) $value;
        return ($int > 0) ? $int : null;
    }

    private function normalizeNullableText($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return ($text === '') ? null : $text;
    }

    private function normalizeNullableWeight($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $weight = (float) $value;
        if ($weight < 0) {
            $weight = 0;
        }
        return $weight;
    }

    private function normalizeNullableFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }

    private function normalizeDurationUnit($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $unit = strtolower(trim((string) $value));
        $allowed = ['turn', 'minute', 'hour', 'day', 'scene'];
        if (!in_array($unit, $allowed, true)) {
            return null;
        }

        return $unit;
    }

    private function slugify(string $raw): string
    {
        $slug = strtolower(trim($raw));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            return 'item';
        }
        return $slug;
    }

    private function ensureUniqueSlug(string $slug, ?int $excludeId = null): string
    {
        $candidate = $slug;
        $suffix = 2;
        while (true) {
            $sql = 'SELECT COUNT(*) AS total FROM items WHERE slug = ?';
            $params = [$candidate];
            if (!empty($excludeId)) {
                $sql .= ' AND id <> ?';
                $params[] = (int) $excludeId;
            }
            $row = $this->firstPrepared($sql, $params);
            $exists = !empty($row) ? (int) ($row->total ?? 0) : 0;
            if ($exists === 0) {
                return $candidate;
            }
            $candidate = $slug . '-' . $suffix;
            $suffix++;
            if ($suffix > 9999) {
                return $candidate . '-' . time();
            }
        }
    }

    private function resolveRarityCode($rarity, $rarityId): string
    {
        $text = strtolower(trim((string) ($rarity ?? '')));
        if ($text !== '') {
            return $text;
        }

        $rid = $this->normalizeNullableInt($rarityId);
        if ($rid === null) {
            return 'common';
        }

        $row = $this->firstPrepared(
            'SELECT code FROM item_rarities WHERE id = ? LIMIT 1',
            [$rid],
        );
        if (!empty($row) && isset($row->code) && trim((string) $row->code) !== '') {
            return strtolower(trim((string) $row->code));
        }

        return 'common';
    }

    private function normalizePayload(object $data, ?int $id = null): array
    {
        $name = trim((string) ($data->name ?? ''));
        $baseSlug = $this->slugify((string) ($data->slug ?? $name));
        $slug = $this->ensureUniqueSlug($baseSlug, $id);

        $stackable = $this->normalizeBool($data->stackable ?? ($data->is_stackable ?? 1), 1);
        $maxStack = $this->normalizeInt($data->max_stack ?? 50, 50);
        if ($maxStack < 1) {
            $maxStack = 1;
        }
        if ($stackable === 0) {
            $maxStack = 1;
        }

        $value = $this->normalizeInt($data->value ?? ($data->price ?? 0), 0);
        if ($value < 0) {
            $value = 0;
        }

        $cooldown = $this->normalizeInt($data->cooldown ?? 0, 0);
        if ($cooldown < 0) {
            $cooldown = 0;
        }

        $isEquippable = $this->normalizeBool($data->is_equippable ?? 0, 0);
        $equipSlot = $this->normalizeNullableText($data->equip_slot ?? null);
        if ($isEquippable === 0) {
            $equipSlot = null;
        }

        $appliesStateId = $this->normalizeNullableInt($data->applies_state_id ?? null);
        $removesStateId = $this->normalizeNullableInt($data->removes_state_id ?? null);
        $stateIntensity = $this->normalizeNullableFloat($data->state_intensity ?? null);
        $stateDurationValue = $this->normalizeNullableInt($data->state_duration_value ?? null);
        $stateDurationUnit = $this->normalizeDurationUnit($data->state_duration_unit ?? null);
        if ($stateDurationValue === null) {
            $stateDurationUnit = null;
        }

        $icon = $this->normalizeNullableText($data->icon ?? ($data->image ?? null));
        $rarityId = $this->normalizeNullableInt($data->rarity_id ?? null);
        $rarity = $this->resolveRarityCode($data->rarity ?? null, $rarityId);

        return [
            'name' => $name,
            'slug' => $slug,
            'category_id' => $this->normalizeNullableInt($data->category_id ?? null),
            'description' => $this->normalizeNullableText($data->description ?? null),
            'icon' => $icon,
            'image' => $icon,
            'type' => $this->normalizeNullableText($data->type ?? null),
            'rarity_id' => $rarityId,
            'rarity' => $rarity,
            'stackable' => $stackable,
            'is_stackable' => $stackable,
            'max_stack' => $maxStack,
            'usable' => $this->normalizeBool($data->usable ?? 0, 0),
            'consumable' => $this->normalizeBool($data->consumable ?? 0, 0),
            'tradable' => $this->normalizeBool($data->tradable ?? 1, 1),
            'droppable' => $this->normalizeBool($data->droppable ?? 1, 1),
            'destroyable' => $this->normalizeBool($data->destroyable ?? 1, 1),
            'weight' => $this->normalizeNullableWeight($data->weight ?? null),
            'value' => $value,
            'price' => $value,
            'cooldown' => $cooldown,
            'script_effect' => $this->normalizeNullableText($data->script_effect ?? null),
            'is_equippable' => $isEquippable,
            'equip_slot' => $equipSlot,
            'applies_state_id' => $appliesStateId,
            'removes_state_id' => $removesStateId,
            'state_intensity' => $stateIntensity,
            'state_duration_value' => $stateDurationValue,
            'state_duration_unit' => $stateDurationUnit,
        ];
    }

    public function listRarityOptions(bool $onlyActive = true): array
    {
        $sql = 'SELECT id, code, name, color_hex, sort_order, is_active
             FROM item_rarities';
        if ($onlyActive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';
        $rows = $this->fetchPrepared($sql);

        if (!is_array($rows)) {
            return [];
        }

        return $rows;
    }

    public function listDistinctTypes(): array
    {
        $rows = $this->fetchPrepared(
            "SELECT DISTINCT type FROM items WHERE type IS NOT NULL AND type != '' ORDER BY type ASC",
        );

        $types = [];
        foreach ($rows as $row) {
            if (!empty($row->type)) {
                $types[] = $row->type;
            }
        }
        return $types;
    }

    public function create(object $data): void
    {
        $payload = $this->normalizePayload($data, null);

        $this->execPrepared(
            'INSERT INTO items
            (name, slug, category_id, description, icon, image, type, rarity_id, rarity, stackable, is_stackable, max_stack, usable, consumable, tradable, droppable, destroyable, weight, value, price, cooldown, script_effect, metadata_json, applies_state_id, removes_state_id, state_intensity, state_duration_value, state_duration_unit, is_equippable, equip_slot, created_at, updated_at, date_created)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "{}", ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
            [
                $payload['name'],
                $payload['slug'],
                $payload['category_id'],
                $payload['description'],
                $payload['icon'],
                $payload['image'],
                $payload['type'],
                $payload['rarity_id'],
                $payload['rarity'],
                $payload['stackable'],
                $payload['is_stackable'],
                $payload['max_stack'],
                $payload['usable'],
                $payload['consumable'],
                $payload['tradable'],
                $payload['droppable'],
                $payload['destroyable'],
                $payload['weight'],
                $payload['value'],
                $payload['price'],
                $payload['cooldown'],
                $payload['script_effect'],
                $payload['applies_state_id'],
                $payload['removes_state_id'],
                $payload['state_intensity'],
                $payload['state_duration_value'],
                $payload['state_duration_unit'],
                $payload['is_equippable'],
                $payload['equip_slot'],
            ],
        );
        AuditLogService::writeEvent('items.create', ['name' => $payload['name']], 'admin');
    }

    public function update(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0) {
            return;
        }
        $payload = $this->normalizePayload($data, $id);

        $this->execPrepared(
            'UPDATE items SET
                name = ?,
                slug = ?,
                category_id = ?,
                description = ?,
                icon = ?,
                image = ?,
                type = ?,
                rarity_id = ?,
                rarity = ?,
                stackable = ?,
                is_stackable = ?,
                max_stack = ?,
                usable = ?,
                consumable = ?,
                tradable = ?,
                droppable = ?,
                destroyable = ?,
                weight = ?,
                value = ?,
                price = ?,
                cooldown = ?,
                script_effect = ?,
                applies_state_id = ?,
                removes_state_id = ?,
                state_intensity = ?,
                state_duration_value = ?,
                state_duration_unit = ?,
                is_equippable = ?,
                equip_slot = ?,
                updated_at = NOW()
             WHERE id = ?',
            [
                $payload['name'],
                $payload['slug'],
                $payload['category_id'],
                $payload['description'],
                $payload['icon'],
                $payload['image'],
                $payload['type'],
                $payload['rarity_id'],
                $payload['rarity'],
                $payload['stackable'],
                $payload['is_stackable'],
                $payload['max_stack'],
                $payload['usable'],
                $payload['consumable'],
                $payload['tradable'],
                $payload['droppable'],
                $payload['destroyable'],
                $payload['weight'],
                $payload['value'],
                $payload['price'],
                $payload['cooldown'],
                $payload['script_effect'],
                $payload['applies_state_id'],
                $payload['removes_state_id'],
                $payload['state_intensity'],
                $payload['state_duration_value'],
                $payload['state_duration_unit'],
                $payload['is_equippable'],
                $payload['equip_slot'],
                $id,
            ],
        );
        AuditLogService::writeEvent('items.update', ['id' => $id], 'admin');
    }
}
