<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class ItemRarityAdminService
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

    public function list(object $data): array
    {
        $query = $data->query ?? (object) [];
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(1, (int) ($data->results_page ?? $data->results ?? 30));
        $orderBy = $data->orderBy ?? 'sort_order|ASC';
        $offset = ($page - 1) * $resultsPage;

        [$orderCol, $orderDir] = array_pad(explode('|', $orderBy, 2), 2, 'ASC');
        $allowed = ['id', 'code', 'name', 'sort_order', 'is_active'];
        if (!in_array($orderCol, $allowed, true)) {
            $orderCol = 'sort_order';
        }
        $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

        $where = ['1=1'];
        $params = [];
        if (!empty($query->name)) {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $query->name . '%';
        }
        if (isset($query->is_active) && $query->is_active !== '') {
            $where[] = 'is_active = ?';
            $params[] = (int) $query->is_active;
        }

        $whereSql = implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            "SELECT COUNT(*) AS tot FROM item_rarities WHERE {$whereSql}",
            $params,
        );
        $total = $countRow ? (int) $countRow->tot : 0;

        $rows = $this->fetchPrepared(
            "SELECT id, code, name, description, color_hex, sort_order, is_active, date_created
             FROM item_rarities
             WHERE {$whereSql}
             ORDER BY {$orderCol} {$orderDir}
             LIMIT ? OFFSET ?",
            array_merge($params, [$resultsPage, $offset]),
        );

        return [
            'dataset' => $rows,
            'properties' => [
                'query' => $query,
                'page' => $page,
                'results_page' => $resultsPage,
                'orderBy' => $orderBy,
                'tot' => ['count' => $total],
            ],
        ];
    }

    public function create(object $data): void
    {
        $this->execPrepared(
            'INSERT INTO item_rarities
                (code, name, description, color_hex, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (string) ($data->code ?? ''),
                (string) ($data->name ?? ''),
                isset($data->description) && trim((string) $data->description) !== '' ? (string) $data->description : null,
                (string) ($data->color_hex ?? '#6c757d'),
                (int) ($data->sort_order ?? 0),
                (int) ($data->is_active ?? 1),
            ],
        );
    }

    public function update(object $data): void
    {
        $this->execPrepared(
            'UPDATE item_rarities SET
                code = ?,
                name = ?,
                description = ?,
                color_hex = ?,
                sort_order = ?,
                is_active = ?
             WHERE id = ?',
            [
                (string) ($data->code ?? ''),
                (string) ($data->name ?? ''),
                isset($data->description) && trim((string) $data->description) !== '' ? (string) $data->description : null,
                (string) ($data->color_hex ?? '#6c757d'),
                (int) ($data->sort_order ?? 0),
                (int) ($data->is_active ?? 1),
                (int) ($data->id ?? 0),
            ],
        );
    }

    public function delete(int $id): void
    {
        $this->execPrepared('DELETE FROM item_rarities WHERE id = ?', [$id]);
    }
}
