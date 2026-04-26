<?php

declare(strict_types=1);

namespace App\Services;

use Core\CurrencyLogs;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Hooks;
use Core\Http\AppError;

class ShopService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var InventoryCapacityService|null */
    private $inventoryCapacityService = null;
    /** @var array|null cached hook result for item state SQL fragments */
    private $itemStateFragmentsCache = null;

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

    public function getInventoryCapacitySnapshot($characterId): array
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

    /**
     * Returns SQL SELECT and JOIN fragments for item-state name columns.
     * Reuses the same hook as InventoryService so a single module registration covers both:
     *   Hooks::add('inventory.query.item_state_fragments', function(array $f, string $alias): array { ... });
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

    private function narrativeStateSelectFragment(): string
    {
        $f = $this->resolveItemStateFragments('i');
        return (string) ($f['select'] ?? 'NULL AS applies_state_name, NULL AS removes_state_name');
    }

    private function narrativeStateJoinFragment(): string
    {
        $f = $this->resolveItemStateFragments('i');
        return (string) ($f['join'] ?? '');
    }

    public function beginTransaction(): void
    {
        $this->db->query('START TRANSACTION');
    }

    public function commitTransaction(): void
    {
        $this->db->query('COMMIT');
    }

    public function rollbackTransaction(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $e) {
            // no-op: rollback best effort
        }
    }

    public function rowCount(): int
    {
        $row = $this->firstPrepared('SELECT ROW_COUNT() AS affected');
        if (empty($row) || !isset($row->affected)) {
            return 0;
        }

        return (int) $row->affected;
    }

    public function loadShopItemForUpdate($shopItemId)
    {
        $shopItemId = (int) $shopItemId;
        if ($shopItemId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT
                si.id,
                si.shop_id,
                si.item_id,
                COALESCE(si.price, COALESCE(i.value, i.price)) AS price,
                COALESCE(i.value, i.price) AS base_price,
                si.currency_id,
                si.stock,
                si.per_character_limit,
                si.per_day_limit,
                si.is_promo,
                si.promo_discount,
                i.name,
                COALESCE(i.stackable, i.is_stackable) AS is_stackable,
                COALESCE(i.max_stack, 50) AS max_stack,
                COALESCE(i.is_equippable, 0) AS is_equippable,
                c.is_default
            FROM shop_inventory si
            LEFT JOIN items i ON i.id = si.item_id
            LEFT JOIN currencies c ON si.currency_id = c.id
            WHERE si.id = ? AND si.is_active = 1
            LIMIT 1
            FOR UPDATE',
            [$shopItemId],
        );
    }

    public function resolveActiveCurrencyForPurchase($currencyId)
    {
        $currencyId = (int) $currencyId;
        $currency = null;

        if ($currencyId > 0) {
            $currency = $this->firstPrepared(
                'SELECT id, is_default
                FROM currencies
                WHERE id = ? AND is_active = 1
                LIMIT 1
                FOR UPDATE',
                [$currencyId],
            );
        }

        if (empty($currency)) {
            $currency = $this->firstPrepared(
                'SELECT id, is_default
                FROM currencies
                WHERE is_default = 1 AND is_active = 1
                LIMIT 1
                FOR UPDATE',
            );
        }

        return !empty($currency) ? $currency : null;
    }

    public function getSellRatio(): float
    {
        if (defined('APP')) {
            return (float) APP['shop']['sell_ratio'];
        }

        return 0.5;
    }

    public function resolveShop($data)
    {
        if (!empty($data) && !empty($data->shop_id)) {
            return $this->firstPrepared(
                'SELECT * FROM shops WHERE id = ? AND is_active = 1',
                [(int) $data->shop_id],
            );
        }

        if (!empty($data) && !empty($data->location_id)) {
            return $this->firstPrepared(
                'SELECT * FROM shops WHERE location_id = ? AND is_active = 1 ORDER BY id DESC LIMIT 1',
                [(int) $data->location_id],
            );
        }

        return $this->firstPrepared(
            "SELECT * FROM shops WHERE type = 'global' AND is_active = 1 ORDER BY id ASC LIMIT 1",
        );
    }

    public function getSocialDiscount($characterId): int
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return 0;
        }

        $discount = (int) floor(SocialStatusProviderRegistry::getShopDiscount($characterId));
        if ($discount < 0) {
            $discount = 0;
        }
        if ($discount > 90) {
            $discount = 90;
        }

        return $discount;
    }

    public function getActiveCurrencies()
    {
        return $this->fetchPrepared(
            'SELECT id, code, name, symbol, image, is_default
            FROM currencies
            WHERE is_active = 1
            ORDER BY is_default DESC, name ASC',
        );
    }

    public function getDefaultCurrency()
    {
        return $this->firstPrepared(
            'SELECT id, code, name, symbol, image, is_default
            FROM currencies
            WHERE is_default = 1 AND is_active = 1
            LIMIT 1',
        );
    }

    public function getBalances($characterId, $currencies): array
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0 || empty($currencies)) {
            return [];
        }

        $balances = [];
        $defaultCurrencyIds = [];
        foreach ($currencies as $currency) {
            if ((int) $currency->is_default === 1) {
                $defaultCurrencyIds[(int) $currency->id] = true;
            }
        }

        // La valuta predefinita usa sempre characters.money.
        // Eventuali righe legacy in character_wallets per la valuta default
        // non devono sovrascrivere il saldo reale.
        $primaryDefaultCurrencyId = null;
        if (!empty($defaultCurrencyIds)) {
            $defaultCurrencyKeys = array_keys($defaultCurrencyIds);
            $primaryDefaultCurrencyId = (int) $defaultCurrencyKeys[0];
            $character = $this->firstPrepared(
                'SELECT money FROM characters WHERE id = ?',
                [$characterId],
            );
            $balances[$primaryDefaultCurrencyId] = (!empty($character)) ? (int) $character->money : 0;
        }

        $wallets = $this->fetchPrepared(
            'SELECT currency_id, balance FROM character_wallets WHERE character_id = ?',
            [$characterId],
        );

        if (!empty($wallets)) {
            foreach ($wallets as $wallet) {
                $walletCurrencyId = (int) $wallet->currency_id;
                if (isset($defaultCurrencyIds[$walletCurrencyId])) {
                    continue;
                }
                $balances[$walletCurrencyId] = (int) $wallet->balance;
            }
        }

        return $balances;
    }

    public function listItems($shopId, $characterId)
    {
        $shopId = (int) $shopId;
        $characterId = (int) $characterId;
        if ($shopId <= 0 || $characterId <= 0) {
            return [];
        }

        return $this->fetchPrepared(
            'SELECT
                si.id AS shop_item_id,
                si.item_id,
                COALESCE(si.price, COALESCE(i.value, i.price)) AS price,
                COALESCE(i.value, i.price) AS base_price,
                si.currency_id,
                si.stock,
                si.per_character_limit,
                si.per_day_limit,
                si.is_promo,
                si.promo_discount,
                i.name,
                i.description,
                COALESCE(NULLIF(i.icon, ""), i.image) AS image,
                i.type,
                COALESCE(i.stackable, i.is_stackable) AS is_stackable,
                COALESCE(i.max_stack, 50) AS max_stack,
                i.is_equippable,
                i.equip_slot,
                COALESCE(i.usable, 0) AS usable,
                COALESCE(i.consumable, 0) AS consumable,
                COALESCE(i.cooldown, 0) AS cooldown,
                i.applies_state_id,
                i.removes_state_id,
                i.state_intensity,
                i.state_duration_value,
                i.state_duration_unit,
                ' . $this->narrativeStateSelectFragment() . ',
                COALESCE(i.category_id, 0) AS category_id,
                c.code AS currency_code,
                c.symbol AS currency_symbol,
                c.image AS currency_image,
                c.is_default AS currency_is_default,
                COALESCE(cat.name, "Altro") AS category_name,
                (SELECT COALESCE(SUM(ii.quantity), 0)
                    FROM inventory_items ii
                    WHERE ii.owner_type = "player"
                      AND ii.owner_id = ?
                      AND ii.item_id = si.item_id
                ) AS owned_stack_qty,
                (SELECT COUNT(*)
                    FROM character_item_instances cii
                    WHERE cii.character_id = ? AND cii.item_id = si.item_id AND cii.is_equipped = 0
                ) AS owned_instance_qty,
                (SELECT COUNT(*)
                    FROM character_item_instances cii
                    WHERE cii.character_id = ? AND cii.item_id = si.item_id AND cii.is_equipped = 1
                ) AS owned_equipped_qty,
                (SELECT COALESCE(SUM(sp.quantity), 0)
                    FROM shop_purchases sp
                    WHERE sp.shop_id = si.shop_id AND sp.character_id = ? AND sp.item_id = si.item_id
                ) AS bought_total,
                (SELECT COALESCE(SUM(sp.quantity), 0)
                    FROM shop_purchases sp
                    WHERE sp.shop_id = si.shop_id AND sp.character_id = ? AND sp.item_id = si.item_id
                      AND DATE(sp.date_created) = CURDATE()
                ) AS bought_today
            FROM shop_inventory si
            LEFT JOIN items i ON i.id = si.item_id
            LEFT JOIN item_categories cat ON i.category_id = cat.id
            LEFT JOIN currencies c ON si.currency_id = c.id
            ' . $this->narrativeStateJoinFragment() . '
            WHERE si.shop_id = ? AND si.is_active = 1
            ORDER BY COALESCE(cat.name, "Altro") ASC, si.is_promo DESC, i.name ASC',
            [$characterId, $characterId, $characterId, $characterId, $characterId, $shopId],
        );
    }

    public function listCategories($shopId)
    {
        $shopId = (int) $shopId;
        if ($shopId <= 0) {
            return [];
        }

        return $this->fetchPrepared(
            'SELECT
                COALESCE(cat.id, 0) AS id,
                COALESCE(cat.name, "Altro") AS name
            FROM shop_inventory si
            LEFT JOIN items i ON i.id = si.item_id
            LEFT JOIN item_categories cat ON i.category_id = cat.id
            WHERE si.shop_id = ? AND si.is_active = 1
            GROUP BY COALESCE(cat.id, 0), COALESCE(cat.name, "Altro")
            ORDER BY COALESCE(cat.sort_order, 9999) ASC, name ASC',
            [$shopId],
        );
    }

    public function listSellables($characterId, $sellRatio)
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            return [];
        }

        $ratio = (float) $sellRatio;
        $stackSql = 'SELECT
                ii.id AS character_item_id,
                NULL AS character_item_instance_id,
                ii.item_id,
                ii.quantity,
                i.name,
                i.description,
                COALESCE(NULLIF(i.icon, ""), i.image) AS image,
                i.type,
                COALESCE(i.value, i.price) AS base_price,
                FLOOR(COALESCE(i.value, i.price) * ?) AS sell_price,
                0 AS is_equipped
            FROM inventory_items ii
            LEFT JOIN items i ON i.id = ii.item_id
            WHERE ii.owner_type = "player"
              AND ii.owner_id = ?
              AND COALESCE(i.value, i.price) > 0
              AND COALESCE(i.tradable, 1) = 1';

        $instSql = 'SELECT
                NULL AS character_item_id,
                cii.id AS character_item_instance_id,
                cii.item_id,
                1 AS quantity,
                i.name,
                i.description,
                COALESCE(NULLIF(i.icon, ""), i.image) AS image,
                i.type,
                COALESCE(i.value, i.price) AS base_price,
                FLOOR(COALESCE(i.value, i.price) * ?) AS sell_price,
                cii.is_equipped
            FROM character_item_instances cii
            LEFT JOIN items i ON i.id = cii.item_id
            WHERE cii.character_id = ?
              AND COALESCE(i.value, i.price) > 0
              AND COALESCE(i.stackable, i.is_stackable) = 0
              AND COALESCE(i.tradable, 1) = 1
              AND cii.is_equipped = 0';

        return $this->fetchPrepared(
            $stackSql . ' UNION ALL ' . $instSql . ' ORDER BY name ASC',
            [$ratio, $characterId, $ratio, $characterId],
        );
    }

    public function decorateCatalogItems($items, $discount, $sellRatio): array
    {
        if (empty($items)) {
            return [];
        }

        $discount = (int) $discount;
        $sellRatio = (float) $sellRatio;
        $dataset = [];

        foreach ($items as $item) {
            $item->price_original = (int) $item->price;
            $item->promo_discount = (int) $item->promo_discount;
            $item->price_after_promo = $item->price_original;
            if ($item->promo_discount > 0 && $item->price_after_promo > 0) {
                $promoPrice = (int) floor($item->price_after_promo * (100 - $item->promo_discount) / 100);
                if ($promoPrice < 0) {
                    $promoPrice = 0;
                }
                $item->price_after_promo = $promoPrice;
            }

            $item->price = $item->price_after_promo;
            $item->discount_percent = $discount;
            if ($discount > 0 && $item->price > 0) {
                $discounted = (int) floor($item->price * (100 - $discount) / 100);
                if ($discounted < 0) {
                    $discounted = 0;
                }
                $item->price = $discounted;
            }

            $item->owned_stack_qty = (int) $item->owned_stack_qty;
            $item->owned_instance_qty = (int) $item->owned_instance_qty;
            $item->owned_equipped_qty = (int) $item->owned_equipped_qty;
            // Keep sellable quantity aligned with backend sell flow branch:
            // stackable -> inventory_items, non-stackable -> item instances.
            if ((int) $item->is_stackable === 1) {
                $item->sellable_qty = $item->owned_stack_qty;
            } elseif ((int) $item->is_equippable === 1) {
                $item->sellable_qty = $item->owned_instance_qty;
            } else {
                // Non-equippable non-stackable items are primarily in inventory_items,
                // but include legacy instances to avoid hidden unsellable leftovers.
                $item->sellable_qty = $item->owned_stack_qty + $item->owned_instance_qty;
            }
            $item->sell_unit_price = (int) floor(((int) $item->base_price) * $sellRatio);
            $item->bought_total = (int) $item->bought_total;
            $item->bought_today = (int) $item->bought_today;
            $item->remaining_per_character = null;
            $item->remaining_per_day = null;
            $item->remaining_stack = null;

            $maxPurchase = null;
            if ($item->stock !== null) {
                $maxPurchase = (int) $item->stock;
            }
            if ($item->per_character_limit !== null) {
                $remaining = (int) $item->per_character_limit - (int) $item->bought_total;
                if ($remaining < 0) {
                    $remaining = 0;
                }
                $item->remaining_per_character = $remaining;
                $maxPurchase = ($maxPurchase === null) ? $remaining : min($maxPurchase, $remaining);
            }
            if ($item->per_day_limit !== null) {
                $remaining = (int) $item->per_day_limit - (int) $item->bought_today;
                if ($remaining < 0) {
                    $remaining = 0;
                }
                $item->remaining_per_day = $remaining;
                $maxPurchase = ($maxPurchase === null) ? $remaining : min($maxPurchase, $remaining);
            }
            if ((int) $item->is_stackable === 1) {
                $stackMax = (int) ($item->max_stack ?? 0);
                if ($stackMax > 0) {
                    $remainingStack = $stackMax - (int) $item->owned_stack_qty;
                    if ($remainingStack < 0) {
                        $remainingStack = 0;
                    }
                    $item->remaining_stack = $remainingStack;
                    $maxPurchase = ($maxPurchase === null) ? $remainingStack : min($maxPurchase, $remainingStack);
                }
            }
            if ($maxPurchase === null) {
                $maxPurchase = ((int) $item->is_stackable === 1) ? 99 : 10;
            }
            if ($maxPurchase < 0) {
                $maxPurchase = 0;
            }
            $item->max_purchase = (int) $maxPurchase;

            $dataset[] = $item;
        }

        return $dataset;
    }

    private function failValidation($message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation((string) $message, [], $errorCode);
    }

    private function failInsufficientFunds(): void
    {
        $this->failValidation('Fondi insufficienti', 'insufficient_funds');
    }

    public function buy($characterId, $data): array
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        if (empty($data->shop_item_id)) {
            $this->failValidation('Oggetto non valido', 'shop_item_invalid');
        }

        $shopItemId = (int) $data->shop_item_id;
        $qty = isset($data->quantity) ? (int) $data->quantity : 1;
        if ($qty < 1) {
            $qty = 1;
        }
        if ($qty > 99) {
            $this->failValidation('Quantita non valida', 'quantity_invalid');
        }

        $this->beginTransaction();
        try {
            $shopItem = $this->loadShopItemForUpdate($shopItemId);
            if (empty($shopItem)) {
                $this->failValidation('Oggetto non disponibile', 'shop_item_unavailable');
            }

            if (empty($shopItem->is_stackable) && $qty > 10) {
                $this->failValidation('Quantita non valida', 'quantity_invalid');
            }

            $isStackable = ((int) $shopItem->is_stackable === 1);
            $this->inventoryCapacityService()->assertCanAddItem($characterId, (int) $shopItem->item_id, $qty, $isStackable);

            if ($shopItem->stock !== null && (int) $shopItem->stock < $qty) {
                $this->failValidation('Stock insufficiente', 'stock_insufficient');
            }

            if (!empty($shopItem->per_character_limit)) {
                $limitTotal = $this->firstPrepared(
                    'SELECT COALESCE(SUM(quantity), 0) AS qty
                    FROM shop_purchases
                    WHERE shop_id = ?
                      AND character_id = ?
                      AND item_id = ?',
                    [(int) $shopItem->shop_id, $characterId, (int) $shopItem->item_id],
                );

                if (((int) $limitTotal->qty + $qty) > (int) $shopItem->per_character_limit) {
                    $this->failValidation('Limite massimo raggiunto', 'shop_limit_reached');
                }
            }

            if (!empty($shopItem->per_day_limit)) {
                $limitDay = $this->firstPrepared(
                    'SELECT COALESCE(SUM(quantity), 0) AS qty
                    FROM shop_purchases
                    WHERE shop_id = ?
                      AND character_id = ?
                      AND item_id = ?
                      AND DATE(date_created) = CURDATE()',
                    [(int) $shopItem->shop_id, $characterId, (int) $shopItem->item_id],
                );

                if (((int) $limitDay->qty + $qty) > (int) $shopItem->per_day_limit) {
                    $this->failValidation('Limite giornaliero raggiunto', 'shop_daily_limit_reached');
                }
            }

            $discount = $this->getSocialDiscount($characterId);
            $unitPrice = (int) $shopItem->price;
            $promoDiscount = (int) $shopItem->promo_discount;
            if (!empty($shopItem->is_promo) && $promoDiscount > 0 && $unitPrice > 0) {
                $unitPrice = (int) floor($unitPrice * (100 - $promoDiscount) / 100);
                if ($unitPrice < 0) {
                    $unitPrice = 0;
                }
            }
            if ($discount > 0 && $unitPrice > 0) {
                $unitPrice = (int) floor($unitPrice * (100 - $discount) / 100);
                if ($unitPrice < 0) {
                    $unitPrice = 0;
                }
            }

            $total = $unitPrice * $qty;
            $currency = $this->resolveActiveCurrencyForPurchase($shopItem->currency_id);
            if (empty($currency)) {
                $this->failValidation('Valuta non disponibile', 'currency_unavailable');
            }

            $currencyId = (int) $currency->id;
            $currencyIsDefault = ((int) $currency->is_default === 1);
            $balanceBefore = null;
            $balanceAfter = null;
            $account = null;

            if ($total > 0) {
                if ($currencyIsDefault) {
                    $character = $this->firstPrepared(
                        'SELECT money FROM characters WHERE id = ? LIMIT 1 FOR UPDATE',
                        [$characterId],
                    );

                    if (empty($character)) {
                        $this->failValidation('Personaggio non valido', 'character_invalid');
                    }

                    $balanceBefore = (int) $character->money;
                    if ($balanceBefore < $total) {
                        $this->failInsufficientFunds();
                    }

                    $this->execPrepared(
                        'UPDATE characters SET money = money - ?
                        WHERE id = ?',
                        [$total, $characterId],
                    );

                    $balanceAfter = $balanceBefore - $total;
                    $account = 'money';
                } else {
                    $wallet = $this->firstPrepared(
                        'SELECT balance FROM character_wallets
                         WHERE character_id = ? AND currency_id = ?
                         LIMIT 1 FOR UPDATE',
                        [$characterId, $currencyId],
                    );

                    $balanceBefore = (!empty($wallet)) ? (int) $wallet->balance : 0;
                    if ($balanceBefore < $total) {
                        $this->failInsufficientFunds();
                    }

                    $this->execPrepared(
                        'UPDATE character_wallets SET balance = balance - ?
                        WHERE character_id = ? AND currency_id = ?',
                        [$total, $characterId, $currencyId],
                    );

                    $balanceAfter = $balanceBefore - $total;
                    $account = 'wallet';
                }
            }

            if ($shopItem->stock !== null) {
                $this->execPrepared(
                    'UPDATE shop_inventory SET stock = stock - ?
                    WHERE id = ? AND stock IS NOT NULL',
                    [$qty, $shopItemId],
                );
            }

            if (!empty($shopItem->is_stackable)) {
                $this->execPrepared(
                    'UPDATE inventory_items SET
                        quantity = quantity + ?,
                        updated_at = NOW()
                    WHERE owner_type = "player"
                      AND owner_id = ?
                      AND item_id = ?
                    LIMIT 1',
                    [$qty, $characterId, (int) $shopItem->item_id],
                );

                if ($this->rowCount() < 1) {
                    $this->execPrepared(
                        'INSERT INTO inventory_items SET
                        owner_type = "player",
                        owner_id = ?,
                        item_id = ?,
                        quantity = ?,
                        metadata_json = "{}",
                        created_at = NOW()',
                        [$characterId, (int) $shopItem->item_id, $qty],
                    );
                }
            } else {
                if ((int) ($shopItem->is_equippable ?? 0) === 1) {
                    for ($i = 0; $i < $qty; $i++) {
                        $this->execPrepared(
                            'INSERT INTO character_item_instances (character_id, item_id, date_created) VALUES (?, ?, NOW())',
                            [$characterId, (int) $shopItem->item_id],
                        );
                    }
                } else {
                    $this->execPrepared(
                        'UPDATE inventory_items SET
                            quantity = quantity + ?,
                            updated_at = NOW()
                         WHERE owner_type = "player"
                           AND owner_id = ?
                           AND item_id = ?
                         LIMIT 1',
                        [$qty, $characterId, (int) $shopItem->item_id],
                    );
                    if ($this->rowCount() < 1) {
                        $this->execPrepared(
                            'INSERT INTO inventory_items SET
                                owner_type = "player",
                                owner_id = ?,
                                item_id = ?,
                                quantity = ?,
                                metadata_json = "{}",
                                created_at = NOW()',
                            [$characterId, (int) $shopItem->item_id, $qty],
                        );
                    }
                }
            }

            $this->execPrepared(
                'INSERT INTO shop_purchases SET
                    shop_id = ?,
                    character_id = ?,
                    item_id = ?,
                    currency_id = ?,
                    quantity = ?,
                    total_price = ?,
                    date_created = NOW()',
                [(int) $shopItem->shop_id, $characterId, (int) $shopItem->item_id, $currencyId, $qty, $total],
            );

            if ($total > 0 && !empty($currencyId)) {
                CurrencyLogs::write($characterId, $currencyId, $account, -$total, $balanceBefore, $balanceAfter, 'shop_buy', [
                    'shop_id' => $shopItem->shop_id,
                    'shop_item_id' => $shopItem->id,
                    'item_id' => $shopItem->item_id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ]);
            }

            $this->commitTransaction();
            return ['success' => true];
        } catch (\Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    public function sell($characterId, $data): array
    {
        $characterId = (int) $characterId;
        if ($characterId <= 0) {
            $this->failValidation('Personaggio non valido', 'character_invalid');
        }

        $qty = isset($data->quantity) ? (int) $data->quantity : 1;
        if ($qty < 1) {
            $qty = 1;
        }
        if ($qty > 99) {
            $this->failValidation('Quantita non valida', 'quantity_invalid');
        }

        $instanceId = !empty($data->character_item_instance_id) ? (int) $data->character_item_instance_id : 0;
        $characterItemId = !empty($data->character_item_id) ? (int) $data->character_item_id : 0;
        $itemId = !empty($data->item_id) ? (int) $data->item_id : 0;
        $isInstance = false;
        $isInstanceSingle = false;
        $instanceIds = [];
        $row = null;
        $unitPrice = 0;

        $this->beginTransaction();
        try {
            if ($instanceId) {
                $isInstance = true;
                $isInstanceSingle = true;
                $row = $this->firstPrepared(
                    'SELECT
                        cii.id AS character_item_instance_id,
                        cii.item_id,
                        1 AS quantity,
                        cii.is_equipped,
                        i.name,
                        COALESCE(i.value, i.price) AS base_price,
                        COALESCE(i.tradable, 1) AS tradable
                    FROM character_item_instances cii
                    LEFT JOIN items i ON i.id = cii.item_id
                    WHERE cii.character_id = ? AND cii.id = ?
                    LIMIT 1
                    FOR UPDATE',
                    [$characterId, $instanceId],
                );
            } else {
                if ($characterItemId) {
                    $row = $this->firstPrepared(
                        'SELECT
                            ii.id AS character_item_id,
                            ii.item_id,
                            ii.quantity,
                            i.name,
                            COALESCE(i.value, i.price) AS base_price,
                            COALESCE(i.tradable, 1) AS tradable
                        FROM inventory_items ii
                        LEFT JOIN items i ON i.id = ii.item_id
                        WHERE ii.owner_type = "player"
                          AND ii.owner_id = ?
                          AND ii.id = ?
                        LIMIT 1
                        FOR UPDATE',
                        [$characterId, $characterItemId],
                    );
                } elseif ($itemId) {
                    $item = $this->firstPrepared(
                        'SELECT
                            id,
                            COALESCE(stackable, is_stackable) AS is_stackable,
                            COALESCE(is_equippable, 0) AS is_equippable,
                            COALESCE(tradable, 1) AS tradable,
                            name,
                            COALESCE(value, price) AS price
                         FROM items
                         WHERE id = ? LIMIT 1',
                        [$itemId],
                    );
                    if (empty($item)) {
                        $this->failValidation('Oggetto non valido', 'shop_item_invalid');
                    }

                    if (!empty($item->is_stackable) || (int) ($item->is_equippable ?? 0) !== 1) {
                        $row = $this->firstPrepared(
                            'SELECT
                                ii.id AS character_item_id,
                                ii.item_id,
                                ii.quantity,
                                i.name,
                                COALESCE(i.value, i.price) AS base_price,
                                COALESCE(i.tradable, 1) AS tradable
                            FROM inventory_items ii
                            LEFT JOIN items i ON i.id = ii.item_id
                            WHERE ii.owner_type = "player"
                              AND ii.owner_id = ?
                              AND ii.item_id = ?
                            LIMIT 1
                            FOR UPDATE',
                            [$characterId, $itemId],
                        );
                    } else {
                        $isInstance = true;
                        $available = $this->fetchPrepared(
                            'SELECT id FROM character_item_instances
                            WHERE character_id = ? AND item_id = ? AND is_equipped = 0
                            LIMIT ?
                            FOR UPDATE',
                            [$characterId, $itemId, $qty],
                        );
                        if (empty($available) || count($available) < $qty) {
                            $this->failValidation('Quantita non disponibile', 'quantity_unavailable');
                        }

                        foreach ($available as $inst) {
                            $instanceIds[] = (int) $inst->id;
                        }

                        $row = (object) [
                            'item_id' => (int) $item->id,
                            'quantity' => (int) $qty,
                            'name' => $item->name,
                            'base_price' => (int) $item->price,
                            'tradable' => (int) ($item->tradable ?? 1),
                        ];
                    }
                } else {
                    $this->failValidation('Oggetto non valido', 'shop_item_invalid');
                }
            }

            if (empty($row)) {
                $this->failValidation('Oggetto non trovato', 'item_not_found');
            }
            if (isset($row->tradable) && (int) $row->tradable !== 1) {
                $this->failValidation('Oggetto non vendibile', 'item_not_sellable');
            }

            if ($isInstance) {
                if ($isInstanceSingle) {
                    $qty = 1;
                }
                if (isset($row->is_equipped) && !empty($row->is_equipped)) {
                    $this->failValidation('Oggetto equipaggiato', 'item_equipped');
                }
            } elseif ((int) $row->quantity < $qty) {
                $this->failValidation('Quantita non disponibile', 'quantity_unavailable');
            }

            $basePrice = (int) $row->base_price;
            if ($basePrice < 1) {
                $this->failValidation('Oggetto non vendibile', 'item_not_sellable');
            }

            $sellRatio = $this->getSellRatio();
            $unitPrice = (int) floor($basePrice * $sellRatio);
            if ($unitPrice < 1) {
                $this->failValidation('Prezzo di vendita non valido', 'sell_price_invalid');
            }

            $total = $unitPrice * $qty;
            $currencyId = CurrencyLogs::getDefaultCurrencyId();
            $moneyRow = $this->firstPrepared(
                'SELECT money FROM characters WHERE id = ? LIMIT 1 FOR UPDATE',
                [$characterId],
            );
            $moneyBefore = (!empty($moneyRow) && isset($moneyRow->money)) ? (int) $moneyRow->money : null;
            if ($moneyBefore === null) {
                $this->failValidation('Personaggio non valido', 'character_invalid');
            }

            $this->execPrepared(
                'UPDATE characters SET money = money + ?
                WHERE id = ?',
                [$total, $characterId],
            );
            if ($this->rowCount() < 1) {
                $exists = $this->firstPrepared(
                    'SELECT id FROM characters WHERE id = ? LIMIT 1',
                    [$characterId],
                );
                if (empty($exists)) {
                    $this->failValidation('Personaggio non valido', 'character_invalid');
                }
            }

            if ($isInstance) {
                if (!empty($instanceIds)) {
                    $inPlaceholders = implode(',', array_fill(0, count($instanceIds), '?'));
                    $deleteParams = array_merge([$characterId], array_map('intval', $instanceIds));
                    $this->execPrepared(
                        'DELETE FROM character_item_instances
                         WHERE character_id = ?
                           AND is_equipped = 0
                           AND id IN (' . $inPlaceholders . ')',
                        $deleteParams,
                    );
                    if ($this->rowCount() !== count($instanceIds)) {
                        $remaining = $this->firstPrepared(
                            'SELECT COUNT(*) AS total
                             FROM character_item_instances
                             WHERE character_id = ?
                               AND is_equipped = 0
                               AND id IN (' . $inPlaceholders . ')',
                            $deleteParams,
                        );
                        if ((int) ($remaining->total ?? 0) > 0) {
                            $this->failValidation('Quantita non disponibile', 'quantity_unavailable');
                        }
                    }
                } else {
                    $this->execPrepared(
                        'DELETE FROM character_item_instances
                         WHERE id = ?
                           AND character_id = ?
                           AND is_equipped = 0',
                        [(int) $row->character_item_instance_id, $characterId],
                    );
                    if ($this->rowCount() !== 1) {
                        $exists = $this->firstPrepared(
                            'SELECT id
                             FROM character_item_instances
                             WHERE id = ?
                               AND character_id = ?
                               AND is_equipped = 0
                             LIMIT 1',
                            [(int) $row->character_item_instance_id, $characterId],
                        );
                        if (!empty($exists)) {
                            $this->failValidation('Quantita non disponibile', 'quantity_unavailable');
                        } else {
                            $this->failValidation('Oggetto non trovato', 'item_not_found');
                        }
                    }
                }
            } elseif ((int) $row->quantity === $qty) {
                $this->execPrepared(
                    'DELETE FROM inventory_items
                     WHERE id = ?
                       AND owner_type = "player"
                       AND owner_id = ?',
                    [(int) $row->character_item_id, $characterId],
                );
                if ($this->rowCount() !== 1) {
                    $exists = $this->firstPrepared(
                        'SELECT id
                         FROM inventory_items
                         WHERE id = ?
                           AND owner_type = "player"
                           AND owner_id = ?
                         LIMIT 1',
                        [(int) $row->character_item_id, $characterId],
                    );
                    if (!empty($exists)) {
                        $this->failValidation('Quantita non disponibile', 'quantity_unavailable');
                    }
                }
            } else {
                $expectedQty = (int) $row->quantity - $qty;
                $this->execPrepared(
                    'UPDATE inventory_items SET
                        quantity = quantity - ?,
                        updated_at = NOW()
                     WHERE id = ?
                       AND owner_type = "player"
                       AND owner_id = ?
                       AND quantity >= ?',
                    [$qty, (int) $row->character_item_id, $characterId, $qty],
                );
                if ($this->rowCount() !== 1) {
                    $current = $this->firstPrepared(
                        'SELECT quantity
                         FROM inventory_items
                         WHERE id = ?
                           AND owner_type = "player"
                           AND owner_id = ?
                         LIMIT 1',
                        [(int) $row->character_item_id, $characterId],
                    );
                    $currentQty = (!empty($current) && isset($current->quantity)) ? (int) $current->quantity : -1;
                    if ($currentQty !== $expectedQty) {
                        $this->failValidation('Quantita non disponibile', 'quantity_unavailable');
                    }
                }
            }

            $shop = $this->resolveShop($data);
            $shopId = (!empty($shop)) ? (int) $shop->id : null;

            $this->execPrepared(
                'INSERT INTO shop_sales SET
                    shop_id = ?,
                    character_id = ?,
                    item_id = ?,
                    quantity = ?,
                    unit_price = ?,
                    total_price = ?,
                    date_created = NOW()',
                [$shopId, $characterId, (int) $row->item_id, $qty, $unitPrice, $total],
            );

            $moneyAfter = $moneyBefore + $total;
            if (!empty($currencyId) && $total > 0) {
                CurrencyLogs::write($characterId, $currencyId, 'money', $total, $moneyBefore, $moneyAfter, 'shop_sell', [
                    'shop_id' => $shopId,
                    'item_id' => $row->item_id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'total' => $total,
                ]);
            }

            $this->commitTransaction();

            return [
                'success' => true,
                'sold' => [
                    'item_id' => (int) $row->item_id,
                    'quantity' => (int) $qty,
                ],
            ];
        } catch (\Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }
}
