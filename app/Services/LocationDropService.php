<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class LocationDropService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var InventoryCapacityService|null */
    private $inventoryCapacityService = null;

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

    public function resolveLocationId(int $characterId, ?int $locationId, ?int $sessionLocationId): int
    {
        if (!empty($locationId)) {
            return (int) $locationId;
        }

        if (!empty($sessionLocationId)) {
            return (int) $sessionLocationId;
        }

        $row = $this->firstPrepared(
            'SELECT last_location FROM characters WHERE id = ?',
            [$characterId],
        );

        return !empty($row) && !empty($row->last_location) ? (int) $row->last_location : 0;
    }

    public function listByLocation(int $locationId): array
    {
        if ($locationId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT d.id,
                d.location_id,
                d.item_id,
                d.quantity,
                d.is_stackable,
                d.dropped_by,
                d.date_created,
                i.name AS item_name,
                i.description AS item_description,
                COALESCE(NULLIF(i.icon, ""), i.image) AS item_image,
                i.type AS item_type,
                c.name AS dropped_by_name,
                c.surname AS dropped_by_surname
            FROM location_item_drops d
            LEFT JOIN items i ON d.item_id = i.id
            LEFT JOIN characters c ON d.dropped_by = c.id
            WHERE d.location_id = ?
            ORDER BY d.date_created DESC',
            [$locationId],
        );

        return !empty($rows) ? $rows : [];
    }

    public function findCharacterItemInstance(int $instanceId, int $characterId)
    {
        if ($instanceId <= 0 || $characterId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT
                cii.id,
                cii.item_id,
                cii.is_equipped,
                cii.durability,
                cii.meta_json,
                COALESCE(i.droppable, 1) AS droppable
            FROM character_item_instances cii
            LEFT JOIN items i ON i.id = cii.item_id
            WHERE cii.id = ?
                AND cii.character_id = ?',
            [$instanceId, $characterId],
        );
    }

    public function dropCharacterItemInstance(int $locationId, int $characterId, object $instance): void
    {
        $this->execPrepared(
            'INSERT INTO location_item_drops SET
                location_id = ?,
                dropped_by = ?,
                item_id = ?,
                quantity = 1,
                is_stackable = 0,
                durability = ?,
                meta_json = ?,
                date_created = NOW(),
                date_updated = NULL',
            [$locationId, $characterId, $instance->item_id, $instance->durability, $instance->meta_json],
        );

        $this->execPrepared(
            'DELETE FROM character_item_instances WHERE id = ?',
            [$instance->id],
        );
    }

    public function findCharacterItemByName(int $characterId, string $name): ?array
    {
        if ($characterId <= 0 || trim($name) === '') {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT ii.id, ii.item_id, ii.quantity, ii.durability, ii.metadata_json,
                    COALESCE(i.stackable, i.is_stackable) AS item_is_stackable,
                    COALESCE(i.droppable, 1) AS droppable,
                    i.name AS item_name
             FROM inventory_items ii
             LEFT JOIN items i ON i.id = ii.item_id
             WHERE ii.owner_type = "player"
               AND ii.owner_id = ?
               AND i.name = ?
             LIMIT 1',
            [$characterId, $name],
        );

        if (!empty($row)) {
            return ['type' => 'stack', 'row' => $row];
        }

        $instance = $this->firstPrepared(
            'SELECT cii.id, cii.item_id, cii.is_equipped, cii.durability, cii.meta_json,
                    COALESCE(i.droppable, 1) AS droppable,
                    i.name AS item_name
             FROM character_item_instances cii
             LEFT JOIN items i ON i.id = cii.item_id
             WHERE cii.character_id = ?
               AND i.name = ?
               AND cii.is_equipped = 0
             LIMIT 1',
            [$characterId, $name],
        );

        if (!empty($instance)) {
            return ['type' => 'instance', 'row' => $instance];
        }

        return null;
    }

    public function findCharacterItemStack(int $characterItemId, int $characterId)
    {
        if ($characterItemId <= 0 || $characterId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT
                ii.id,
                ii.item_id,
                ii.quantity,
                ii.durability,
                ii.metadata_json,
                COALESCE(i.stackable, i.is_stackable) AS item_is_stackable,
                COALESCE(i.droppable, 1) AS droppable
            FROM inventory_items ii
            LEFT JOIN items i ON i.id = ii.item_id
            WHERE ii.id = ?
                AND ii.owner_type = "player"
                AND ii.owner_id = ?',
            [$characterItemId, $characterId],
        );
    }

    public function dropCharacterItemStack(int $locationId, int $characterId, object $stack, int $quantity): void
    {
        if ($quantity < 1) {
            $quantity = 1;
        }

        $isStackable = ((int) ($stack->item_is_stackable ?? 1) === 1) ? 1 : 0;
        $metaJson = isset($stack->metadata_json) ? trim((string) $stack->metadata_json) : '';
        if ($metaJson === '') {
            $metaJson = null;
        }
        $durability = isset($stack->durability) ? $stack->durability : null;

        $this->execPrepared(
            'INSERT INTO location_item_drops SET
                location_id = ?,
                dropped_by = ?,
                item_id = ?,
                quantity = ?,
                is_stackable = ?,
                durability = ?,
                meta_json = ?,
                date_created = NOW(),
                date_updated = NULL',
            [$locationId, $characterId, $stack->item_id, $quantity, $isStackable, $durability, $metaJson],
        );

        $available = (int) $stack->quantity;
        if ($quantity >= $available) {
            $this->execPrepared(
                'DELETE FROM inventory_items WHERE id = ?',
                [$stack->id],
            );
            return;
        }

        $this->execPrepared(
            'UPDATE inventory_items SET
                quantity = quantity - ?,
                updated_at = NOW()
            WHERE id = ?',
            [$quantity, $stack->id],
        );
    }

    public function findDropById(int $dropId)
    {
        if ($dropId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, location_id, item_id, quantity, is_stackable, durability, meta_json
            FROM location_item_drops
            WHERE id = ?',
            [$dropId],
        );
    }

    public function pickupDropStack(int $characterId, object $drop): void
    {
        $qty = (int) $drop->quantity;
        if ($qty < 1) {
            $qty = 1;
        }

        $this->inventoryCapacityService()->assertCanAddItem($characterId, (int) $drop->item_id, $qty, true);

        $row = $this->firstPrepared(
            'SELECT id, quantity FROM inventory_items
            WHERE owner_type = "player"
                AND owner_id = ?
                AND item_id = ?',
            [$characterId, $drop->item_id],
        );

        if (!empty($row)) {
            $this->execPrepared(
                'UPDATE inventory_items SET
                    quantity = quantity + ?,
                    updated_at = NOW()
                WHERE id = ?',
                [$qty, $row->id],
            );
            return;
        }

        $this->execPrepared(
            'INSERT INTO inventory_items SET
                owner_type = "player",
                owner_id = ?,
                item_id = ?,
                quantity = ?,
                metadata_json = "{}",
                created_at = NOW()',
            [$characterId, $drop->item_id, $qty],
        );
    }

    public function pickupDropInstance(int $characterId, object $drop): void
    {
        $qty = (int) ($drop->quantity ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }

        $item = $this->firstPrepared(
            'SELECT COALESCE(is_equippable, 0) AS is_equippable
             FROM items
             WHERE id = ?
             LIMIT 1',
            [$drop->item_id],
        );
        $isEquippable = (!empty($item) && (int) ($item->is_equippable ?? 0) === 1);

        $this->inventoryCapacityService()->assertCanAddItem($characterId, (int) $drop->item_id, $qty, false);

        if ($isEquippable) {
            for ($i = 0; $i < $qty; $i++) {
                $this->execPrepared(
                    'INSERT INTO character_item_instances SET
                        character_id = ?,
                        item_id = ?,
                        is_equipped = 0,
                        slot = NULL,
                        durability = ?,
                        meta_json = ?,
                        date_created = NOW(),
                        date_updated = NULL',
                    [$characterId, $drop->item_id, ($drop->durability ?? null), ($drop->meta_json ?? null)],
                );
            }
            return;
        }

        $existing = $this->firstPrepared(
            'SELECT id FROM inventory_items
             WHERE owner_type = "player"
               AND owner_id = ?
               AND item_id = ?
             LIMIT 1',
            [$characterId, $drop->item_id],
        );

        $metaJson = isset($drop->meta_json) ? trim((string) $drop->meta_json) : '';
        if ($metaJson === '') {
            $metaJson = '{}';
        }

        if (!empty($existing)) {
            $this->execPrepared(
                'UPDATE inventory_items SET
                    quantity = quantity + ?,
                    updated_at = NOW()
                 WHERE id = ?',
                [$qty, $existing->id],
            );
            return;
        }

        $this->execPrepared(
            'INSERT INTO inventory_items SET
                owner_type = "player",
                owner_id = ?,
                item_id = ?,
                quantity = ?,
                durability = ?,
                metadata_json = ?,
                created_at = NOW()',
            [$characterId, $drop->item_id, $qty, ($drop->durability ?? null), $metaJson],
        );
    }

    public function deleteDrop(int $dropId): void
    {
        if ($dropId <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM location_item_drops WHERE id = ?',
            [$dropId],
        );
    }
}
