<?php

declare(strict_types=1);

namespace Modules\Logeon\SocialStatus\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class SocialStatusAdminService
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
            'id' => 'id',
            'name' => 'name',
            'min' => 'min',
            'max' => 'max',
            'shop_discount' => 'shop_discount',
            'quest_tier' => 'quest_tier',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0]);
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'min';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $name = isset($query->name) ? trim((string) $query->name) : '';
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 50)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'min|ASC';
        $orderBy = $this->normalizeOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];

        if ($name !== '') {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $name . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total FROM social_status ' . $whereClause,
            $params,
        );
        $total = !empty($countRow) ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT id, name, description, icon, min, max,
                    shop_discount, unlock_home, quest_tier
             FROM social_status
             ' . $whereClause . '
             ORDER BY ' . $orderBy . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$resultsPage, $offset]),
        );

        return [
            'dataset' => $rows ?: [],
            'properties' => [
                'query' => $query,
                'page' => $page,
                'results_page' => $resultsPage,
                'orderBy' => $orderByRaw,
                'tot' => ['count' => $total],
            ],
        ];
    }

    public function create(object $data): void
    {
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $icon = trim((string) ($data->icon ?? ''));
        $min = (int) ($data->min ?? 0);
        $max = (int) ($data->max ?? 0);
        $shopDiscount = max(0, min(100, (int) ($data->shop_discount ?? 0)));
        $unlockHome = isset($data->unlock_home) ? (int) (bool) $data->unlock_home : 0;
        $questTier = max(0, (int) ($data->quest_tier ?? 0));

        $this->execPrepared(
            'INSERT INTO social_status
                (name, description, icon, min, max, shop_discount, unlock_home, quest_tier)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, $description, $icon, $min, $max, $shopDiscount, $unlockHome, $questTier],
        );
        AuditLogService::writeEvent('social_status.create', ['name' => $name], 'admin');
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $icon = trim((string) ($data->icon ?? ''));
        $min = (int) ($data->min ?? 0);
        $max = (int) ($data->max ?? 0);
        $shopDiscount = max(0, min(100, (int) ($data->shop_discount ?? 0)));
        $unlockHome = isset($data->unlock_home) ? (int) (bool) $data->unlock_home : 0;
        $questTier = max(0, (int) ($data->quest_tier ?? 0));

        if ($id <= 0 || $name === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE social_status SET
                name = ?,
                description = ?,
                icon = ?,
                min = ?,
                max = ?,
                shop_discount = ?,
                unlock_home = ?,
                quest_tier = ?
             WHERE id = ?',
            [$name, $description, $icon, $min, $max, $shopDiscount, $unlockHome, $questTier, $id],
        );
        AuditLogService::writeEvent('social_status.update', ['id' => $id], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM social_status WHERE id = ?', [$id]);
        AuditLogService::writeEvent('social_status.delete', ['id' => $id], 'admin');
    }
}

