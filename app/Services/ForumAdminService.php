<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class ForumAdminService
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
            'id' => 'f.id',
            'f.id' => 'f.id',
            'name' => 'f.name',
            'f.name' => 'f.name',
            'type' => 'ft.title',
            'date_created' => 'f.date_created',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0] ?? '');
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'f.name';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $name = isset($query->name) ? trim((string) $query->name) : '';
        $typeId = isset($query->type) && (int) $query->type > 0 ? (int) $query->type : 0;
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 30)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'f.name|ASC';
        $orderBy = $this->normalizeOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];
        if ($name !== '') {
            $where[] = 'f.name LIKE ?';
            $params[] = '%' . $name . '%';
        }
        if ($typeId > 0) {
            $where[] = 'f.type = ?';
            $params[] = $typeId;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total
             FROM forums f
             LEFT JOIN forum_types ft ON f.type = ft.id
             ' . $whereClause,
            $params,
        );
        $total = !empty($countRow) ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT f.id, f.name, f.description, f.type, f.date_created,
                    ft.title AS type_title,
                    (SELECT COUNT(t.id) FROM forum_threads t WHERE t.father_id IS NULL AND t.forum_id = f.id) AS count_thread
             FROM forums f
             LEFT JOIN forum_types ft ON f.type = ft.id
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

    public function getTypes(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, title, is_on_game FROM forum_types ORDER BY title ASC',
        );
        return $rows ?: [];
    }

    public function create(object $data): void
    {
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $type = (int) ($data->type ?? 0);

        if ($name === '' || $type <= 0) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO forums (name, description, type, date_created) VALUES (?, ?, ?, NOW())',
            [$name, $description !== '' ? $description : null, $type],
        );
        AuditLogService::writeEvent('forums.create', ['name' => $name], 'admin');
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $type = (int) ($data->type ?? 0);

        if ($id <= 0 || $name === '' || $type <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE forums
             SET name = ?, description = ?, type = ?
             WHERE id = ?',
            [$name, $description !== '' ? $description : null, $type, $id],
        );
        AuditLogService::writeEvent('forums.update', ['id' => $id], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM forums WHERE id = ?', [$id]);
        AuditLogService::writeEvent('forums.delete', ['id' => $id], 'admin');
    }
}
