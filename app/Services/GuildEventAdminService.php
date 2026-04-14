<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class GuildEventAdminService
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
            'id' => 'ge.id',
            'ge.id' => 'ge.id',
            'title' => 'ge.title',
            'ge.title' => 'ge.title',
            'guild_name' => 'g.name',
            'starts_at' => 'ge.starts_at',
            'ge.starts_at' => 'ge.starts_at',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0] ?? '');
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'ge.starts_at';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $guildId = isset($query->guild_id) && (int) $query->guild_id > 0 ? (int) $query->guild_id : 0;
        $title = isset($query->title) ? trim((string) $query->title) : '';
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 30)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'ge.starts_at|DESC';
        $orderBy = $this->normalizeOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];
        if ($guildId > 0) {
            $where[] = 'ge.guild_id = ?';
            $params[] = $guildId;
        }
        if ($title !== '') {
            $where[] = 'ge.title LIKE ?';
            $params[] = '%' . $title . '%';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total
             FROM guild_events ge
             LEFT JOIN guilds g ON ge.guild_id = g.id
             ' . $whereClause,
            $params,
        );
        $total = !empty($countRow) ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT ge.id, ge.guild_id, ge.title, ge.body_html,
                    ge.starts_at, ge.ends_at, ge.created_by, ge.date_created,
                    g.name AS guild_name,
                    CONCAT(c.name, \' \', c.surname) AS creator_name
             FROM guild_events ge
             LEFT JOIN guilds g ON ge.guild_id = g.id
             LEFT JOIN characters c ON ge.created_by = c.id
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
        $guildId = (int) ($data->guild_id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        $bodyHtml = trim((string) ($data->body_html ?? ''));
        $startsAt = trim((string) ($data->starts_at ?? ''));
        $endsAt = trim((string) ($data->ends_at ?? ''));

        if ($guildId <= 0 || $title === '' || $startsAt === '') {
            return;
        }

        $this->execPrepared(
            'INSERT INTO guild_events
                (guild_id, title, body_html, starts_at, ends_at, date_created)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$guildId, $title, $bodyHtml !== '' ? $bodyHtml : null, $startsAt, $endsAt !== '' ? $endsAt : null],
        );
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $guildId = (int) ($data->guild_id ?? 0);
        $title = trim((string) ($data->title ?? ''));
        $bodyHtml = trim((string) ($data->body_html ?? ''));
        $startsAt = trim((string) ($data->starts_at ?? ''));
        $endsAt = trim((string) ($data->ends_at ?? ''));

        if ($id <= 0 || $guildId <= 0 || $title === '' || $startsAt === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE guild_events SET
                guild_id = ?,
                title = ?,
                body_html = ?,
                starts_at = ?,
                ends_at = ?
             WHERE id = ?',
            [$guildId, $title, $bodyHtml !== '' ? $bodyHtml : null, $startsAt, $endsAt !== '' ? $endsAt : null, $id],
        );
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM guild_events WHERE id = ?', [$id]);
    }
}
