<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class ShopInventoryAdminService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(?DbAdapterInterface $db = null)
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

    private function normalizeOrderBy(string $raw): string
    {
        $allowed = [
            'si.id' => 'si.id',
            'id' => 'si.id',
            'shop_name' => 's.name',
            'item_name' => 'i.name',
            'price' => 'si.price',
            'si.price' => 'si.price',
            'is_active' => 'si.is_active',
            'si.is_active' => 'si.is_active',
            'is_promo' => 'si.is_promo',
            'si.is_promo' => 'si.is_promo',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0] ?? '');
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'si.id';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $shopId = isset($query->shop_id) ? (int) $query->shop_id : 0;
        $itemName = isset($query->item_name) ? trim((string) $query->item_name) : '';
        $isActive = (isset($query->is_active) && $query->is_active !== '' && $query->is_active !== null)
                       ? (int) $query->is_active : -1;
        $isPromo = (isset($query->is_promo) && $query->is_promo !== '' && $query->is_promo !== null)
                       ? (int) $query->is_promo : -1;

        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 20)));
        $orderBy = $this->normalizeOrderBy((string) ($data->orderBy ?? 'si.id|ASC'));
        $offset = ($page - 1) * $resultsPage;

        $where = [];
        $params = [];
        if ($shopId > 0) {
            $where[] = 'si.shop_id = ?';
            $params[] = $shopId;
        }
        if ($itemName !== '') {
            $where[] = 'i.name LIKE ?';
            $params[] = '%' . $itemName . '%';
        }
        if ($isActive >= 0) {
            $where[] = 'si.is_active = ?';
            $params[] = $isActive;
        }
        if ($isPromo >= 0) {
            $where[] = 'si.is_promo = ?';
            $params[] = $isPromo;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $joins = 'FROM shop_inventory si
                  JOIN items i ON i.id = si.item_id
                  JOIN shops s ON s.id = si.shop_id
                  JOIN currencies c ON c.id = si.currency_id';

        $countRow = $this->firstPrepared('SELECT COUNT(*) AS n ' . $joins . ' ' . $whereClause, $params);
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT
                si.id, si.shop_id, si.item_id, si.currency_id,
                s.name AS shop_name,
                i.name AS item_name, i.icon AS item_icon,
                c.name AS currency_name, c.symbol AS currency_symbol, c.code AS currency_code,
                si.price, si.stock, si.per_character_limit, si.per_day_limit,
                si.is_active, si.is_promo, si.promo_discount,
                si.date_created, si.date_updated
             ' . $joins . '
             ' . $whereClause . '
             ORDER BY ' . $orderBy . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$resultsPage, $offset]),
        );

        return [
            'query' => $data,
            'page' => $page,
            'results_page' => $resultsPage,
            'orderBy' => $orderBy,
            'tot' => ['count' => $total],
            'dataset' => $rows,
        ];
    }

    private function normalizePromo(object $data): array
    {
        $isPromo = !empty($data->is_promo) ? 1 : 0;
        $promoDiscount = isset($data->promo_discount) ? (int) $data->promo_discount : 0;
        return [$isPromo, $promoDiscount];
    }

    public function create(object $data): void
    {
        [$isPromo, $promoDiscount] = $this->normalizePromo($data);
        $stock = (isset($data->stock) && $data->stock !== '') ? (int) $data->stock : null;
        $perCharacterLimit = (isset($data->per_character_limit) && $data->per_character_limit !== '') ? (int) $data->per_character_limit : null;
        $perDayLimit = (isset($data->per_day_limit) && $data->per_day_limit !== '') ? (int) $data->per_day_limit : null;

        $this->execPrepared(
            'INSERT INTO shop_inventory
                (shop_id, item_id, currency_id, price, stock, per_character_limit, per_day_limit, is_active, is_promo, promo_discount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) ($data->shop_id ?? 0),
                (int) ($data->item_id ?? 0),
                (int) ($data->currency_id ?? 0),
                (int) ($data->price ?? 0),
                $stock,
                $perCharacterLimit,
                $perDayLimit,
                (int) ($data->is_active ?? 0),
                $isPromo,
                $promoDiscount,
            ],
        );
        AuditLogService::writeEvent('shop_inventory.create', ['shop_id' => (int) ($data->shop_id ?? 0), 'item_id' => (int) ($data->item_id ?? 0)], 'admin');
    }

    public function update(object $data): void
    {
        [$isPromo, $promoDiscount] = $this->normalizePromo($data);
        $stock = (isset($data->stock) && $data->stock !== '') ? (int) $data->stock : null;
        $perCharacterLimit = (isset($data->per_character_limit) && $data->per_character_limit !== '') ? (int) $data->per_character_limit : null;
        $perDayLimit = (isset($data->per_day_limit) && $data->per_day_limit !== '') ? (int) $data->per_day_limit : null;

        $this->execPrepared(
            'UPDATE shop_inventory SET
                shop_id = ?,
                item_id = ?,
                currency_id = ?,
                price = ?,
                stock = ?,
                per_character_limit = ?,
                per_day_limit = ?,
                is_active = ?,
                is_promo = ?,
                promo_discount = ?
             WHERE id = ?',
            [
                (int) ($data->shop_id ?? 0),
                (int) ($data->item_id ?? 0),
                (int) ($data->currency_id ?? 0),
                (int) ($data->price ?? 0),
                $stock,
                $perCharacterLimit,
                $perDayLimit,
                (int) ($data->is_active ?? 0),
                $isPromo,
                $promoDiscount,
                (int) ($data->id ?? 0),
            ],
        );
        AuditLogService::writeEvent('shop_inventory.update', ['id' => (int) ($data->id ?? 0)], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM shop_inventory WHERE id = ?', [$id]);
        AuditLogService::writeEvent('shop_inventory.delete', ['id' => $id], 'admin');
    }
}
