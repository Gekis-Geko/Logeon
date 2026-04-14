<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class InventoryCapacityService
{
    /** @var DbAdapterInterface */
    private $db;

    /** @var array<string,int> */
    private $settingsCache = [];

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function failValidation(string $message, string $errorCode): void
    {
        throw AppError::validation($message, [], $errorCode);
    }

    private function getConfigInt(string $section, string $key, int $fallback): int
    {
        if (!defined('CONFIG') || !is_array(CONFIG)) {
            return $fallback;
        }

        if (!isset(CONFIG[$section]) || !is_array(CONFIG[$section])) {
            return $fallback;
        }

        $raw = CONFIG[$section][$key] ?? null;
        if ($raw === null || $raw === '') {
            return $fallback;
        }

        return (int) $raw;
    }

    private function getSettingInt(string $key, int $fallback): int
    {
        if (isset($this->settingsCache[$key])) {
            return $this->settingsCache[$key];
        }

        $value = $fallback;
        try {
            $row = $this->firstPrepared(
                'SELECT value FROM sys_settings WHERE `key` = ? LIMIT 1',
                [$key],
            );

            if (!empty($row) && isset($row->value) && is_numeric((string) $row->value)) {
                $value = (int) $row->value;
            }
        } catch (\Throwable $e) {
            $value = $fallback;
        }

        $this->settingsCache[$key] = $value;
        return $value;
    }

    public function getCapacityMax(): int
    {
        $fallback = $this->getConfigInt('inventory', 'capacity_max', 30);
        $value = $this->getSettingInt('inventory_capacity_max', $fallback);

        return ($value > 0) ? $value : $fallback;
    }

    public function getStackMax(): int
    {
        $fallback = $this->getConfigInt('inventory', 'stack_max', 50);
        $value = $this->getSettingInt('inventory_stack_max', $fallback);

        return ($value >= 0) ? $value : $fallback;
    }

    public function getUsedSlots(int $characterId): int
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT
                (
                    SELECT COALESCE(SUM(
                        CASE
                            WHEN COALESCE(i.stackable, i.is_stackable) = 1 THEN 1
                            ELSE GREATEST(ii.quantity, 1)
                        END
                    ), 0)
                    FROM inventory_items ii
                    LEFT JOIN items i ON i.id = ii.item_id
                    WHERE ii.owner_type = "player"
                      AND ii.owner_id = ?
                      AND ii.legacy_instance_id IS NULL
                )
                +
                (
                    SELECT COUNT(*)
                    FROM character_item_instances cii
                    WHERE cii.character_id = ?
                ) AS used_slots',
            [$characterId, $characterId],
        );

        return (!empty($row) && isset($row->used_slots)) ? (int) $row->used_slots : 0;
    }

    public function getCapacitySnapshot(int $characterId): array
    {
        $max = $this->getCapacityMax();
        $used = $this->getUsedSlots($characterId);
        $free = $max - $used;

        if ($free < 0) {
            $free = 0;
        }

        return [
            'max' => $max,
            'used' => $used,
            'free' => $free,
        ];
    }

    public function getStackQuantity(int $characterId, int $itemId): int
    {
        $characterId = (int) $characterId;
        $itemId = (int) $itemId;
        if ($characterId <= 0 || $itemId <= 0) {
            return 0;
        }

        $row = $this->firstPrepared(
            'SELECT COALESCE(SUM(quantity), 0) AS quantity
            FROM inventory_items
            WHERE owner_type = "player"
              AND owner_id = ?
              AND legacy_instance_id IS NULL
              AND item_id = ?',
            [$characterId, $itemId],
        );

        return (!empty($row) && isset($row->quantity)) ? (int) $row->quantity : 0;
    }

    private function getItemStackLimit(int $itemId, int $fallback): int
    {
        if ($itemId <= 0) {
            return $fallback;
        }

        try {
            $row = $this->firstPrepared(
                'SELECT max_stack FROM items WHERE id = ? LIMIT 1',
                [$itemId],
            );
            $itemMax = (!empty($row) && isset($row->max_stack)) ? (int) $row->max_stack : 0;
            if ($itemMax > 0) {
                if ($fallback > 0) {
                    return min($itemMax, $fallback);
                }
                return $itemMax;
            }
        } catch (\Throwable $e) {
            // Fallback to global setting.
        }

        return $fallback;
    }

    public function requiredSlotsForAdd(int $characterId, int $itemId, int $quantity, bool $isStackable): int
    {
        $characterId = (int) $characterId;
        $itemId = (int) $itemId;
        $quantity = (int) $quantity;

        if ($characterId <= 0 || $itemId <= 0 || $quantity <= 0) {
            return 0;
        }

        if ($isStackable) {
            $currentQty = $this->getStackQuantity($characterId, $itemId);
            return ($currentQty > 0) ? 0 : 1;
        }

        return $quantity;
    }

    public function assertCanAddItem(int $characterId, int $itemId, int $quantity, bool $isStackable): array
    {
        $characterId = (int) $characterId;
        $itemId = (int) $itemId;
        $quantity = (int) $quantity;

        if ($characterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }
        if ($itemId <= 0) {
            $this->failValidation('Oggetto non valido', 'item_invalid');
        }
        if ($quantity <= 0) {
            $this->failValidation('Quantita non valida', 'quantity_invalid');
        }

        if ($isStackable) {
            $stackMax = $this->getItemStackLimit($itemId, $this->getStackMax());
            if ($stackMax > 0) {
                $currentQty = $this->getStackQuantity($characterId, $itemId);
                if (($currentQty + $quantity) > $stackMax) {
                    $this->failValidation('Hai raggiunto il limite massimo per questo oggetto.', 'inventory_stack_limit_reached');
                }
            }
        }

        $requiredSlots = $this->requiredSlotsForAdd($characterId, $itemId, $quantity, $isStackable);
        $snapshot = $this->getCapacitySnapshot($characterId);

        if ($requiredSlots > 0 && ($snapshot['used'] + $requiredSlots) > $snapshot['max']) {
            $this->failValidation('Inventario pieno. Libera spazio prima di aggiungere nuovi oggetti.', 'inventory_capacity_reached');
        }

        return [
            'capacity_max' => $snapshot['max'],
            'capacity_used' => $snapshot['used'],
            'capacity_free' => $snapshot['free'],
            'required_slots' => $requiredSlots,
        ];
    }
}
