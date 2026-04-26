<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class ForumTypeAdminService
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
            'title' => 'title',
            'is_on_game' => 'is_on_game',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0]);
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'title';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $title = isset($query->title) ? trim((string) $query->title) : '';
        $isOnGame = isset($query->is_on_game) && $query->is_on_game !== '' ? (int) $query->is_on_game : -1;
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 30)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'title|ASC';
        $orderBy = $this->normalizeOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];
        if ($title !== '') {
            $where[] = 'title LIKE ?';
            $params[] = '%' . $title . '%';
        }
        if ($isOnGame >= 0) {
            $where[] = 'is_on_game = ?';
            $params[] = $isOnGame;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared('SELECT COUNT(*) AS total FROM forum_types ' . $whereClause, $params);
        $total = !empty($countRow) ? (int) $countRow->total : 0;
        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT id, title, subtitle, is_on_game, date_created, date_updated
             FROM forum_types
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
        $title = trim((string) ($data->title ?? ''));
        $subtitle = trim((string) ($data->subtitle ?? ''));
        $isOnGame = (int) ($data->is_on_game ?? 0) > 0 ? 1 : 0;

        if ($title === '') {
            return;
        }

        $this->execPrepared(
            'INSERT INTO forum_types (title, subtitle, is_on_game, date_created, date_updated)
             VALUES (?, ?, ?, NOW(), NOW())',
            [$title, $subtitle !== '' ? $subtitle : null, $isOnGame],
        );
        AuditLogService::writeEvent('forum_types.create', ['title' => $title], 'admin');
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        $subtitle = trim((string) ($data->subtitle ?? ''));
        $isOnGame = (int) ($data->is_on_game ?? 0) > 0 ? 1 : 0;

        if ($id <= 0 || $title === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE forum_types
             SET title = ?,
                 subtitle = ?,
                 is_on_game = ?,
                 date_updated = NOW()
             WHERE id = ?',
            [$title, $subtitle !== '' ? $subtitle : null, $isOnGame, $id],
        );
        AuditLogService::writeEvent('forum_types.update', ['id' => $id], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM forum_types WHERE id = ?', [$id]);
        AuditLogService::writeEvent('forum_types.delete', ['id' => $id], 'admin');
    }
}
