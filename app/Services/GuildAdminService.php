<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class GuildAdminService
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
            'id' => 'g.id',
            'g.id' => 'g.id',
            'name' => 'g.name',
            'g.name' => 'g.name',
            'alignment_name' => 'ga.name',
            'is_visible' => 'g.is_visible',
            'g.is_visible' => 'g.is_visible',
        ];

        $parts = explode('|', $raw);
        $field = trim($parts[0] ?? '');
        $dir = strtoupper(trim($parts[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $col = $allowed[$field] ?? 'g.name';

        return $col . ' ' . $dir;
    }

    public function list(object $data): array
    {
        $query = (isset($data->query) && is_object($data->query)) ? $data->query : (object) [];
        $name = isset($query->name) ? trim((string) $query->name) : '';
        $alignmentId = isset($query->alignment_id) && $query->alignment_id !== '' ? (int) $query->alignment_id : 0;
        $isVisible = (isset($query->is_visible) && $query->is_visible !== '') ? (int) $query->is_visible : -1;
        $page = max(1, (int) ($data->page ?? 1));
        $resultsPage = max(5, min(100, (int) ($data->results ?? 20)));
        $orderByRaw = isset($data->orderBy) ? (string) $data->orderBy : 'g.name|ASC';
        $orderBy = $this->normalizeOrderBy($orderByRaw);

        $where = ['1=1'];
        $params = [];

        if ($name !== '') {
            $where[] = 'g.name LIKE ?';
            $params[] = '%' . $name . '%';
        }
        if ($alignmentId > 0) {
            $where[] = 'g.alignment_id = ?';
            $params[] = $alignmentId;
        }
        if ($isVisible !== -1) {
            $where[] = 'g.is_visible = ?';
            $params[] = $isVisible;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS total
             FROM guilds g
             LEFT JOIN guild_alignments ga ON g.alignment_id = ga.id
             ' . $whereClause,
            $params,
        );
        $total = !empty($countRow) ? (int) $countRow->total : 0;

        $offset = ($page - 1) * $resultsPage;

        $rows = $this->fetchPrepared(
            'SELECT g.id, g.name, g.alignment_id, g.image, g.icon, g.website_url, g.is_visible,
                    g.leader_character_id, g.date_created,
                    ga.name AS alignment_name
             FROM guilds g
             LEFT JOIN guild_alignments ga ON g.alignment_id = ga.id
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

    public function create(object $data): int
    {
        $name = trim((string) ($data->name ?? ''));
        $alignmentId = isset($data->alignment_id) && (int) $data->alignment_id > 0 ? (int) $data->alignment_id : null;
        $image = trim((string) ($data->image ?? ''));
        $icon = trim((string) ($data->icon ?? ''));
        $websiteUrl = trim((string) ($data->website_url ?? ''));
        $isVisible = isset($data->is_visible) ? (int) (bool) $data->is_visible : 0;

        $this->execPrepared(
            'INSERT INTO guilds
            (name, alignment_id, image, icon, website_url, is_visible, date_created)
            VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $name,
                $alignmentId !== null ? $alignmentId : null,
                $image !== '' ? $image : null,
                $icon !== '' ? $icon : '/assets/imgs/defaults-images/default-icon.png',
                $websiteUrl !== '' ? $websiteUrl : null,
                $isVisible,
            ],
        );

        $newId = (int) $this->db->lastInsertId();
        AuditLogService::writeEvent('guilds.create', ['id' => $newId, 'name' => $name], 'admin');
        return $newId;
    }

    public function update(object $data): void
    {
        $id = (int) ($data->id ?? 0);
        $name = trim((string) ($data->name ?? ''));
        $alignmentId = isset($data->alignment_id) && (int) $data->alignment_id > 0 ? (int) $data->alignment_id : null;
        $image = trim((string) ($data->image ?? ''));
        $icon = trim((string) ($data->icon ?? ''));
        $websiteUrl = trim((string) ($data->website_url ?? ''));
        $isVisible = isset($data->is_visible) ? (int) (bool) $data->is_visible : 0;

        if ($id <= 0 || $name === '') {
            return;
        }

        $this->execPrepared(
            'UPDATE guilds SET
                name = ?,
                alignment_id = ?,
                image = ?,
                icon = ?,
                website_url = ?,
                is_visible = ?
             WHERE id = ?',
            [
                $name,
                $alignmentId !== null ? $alignmentId : null,
                $image !== '' ? $image : null,
                $icon !== '' ? $icon : '/assets/imgs/defaults-images/default-icon.png',
                $websiteUrl !== '' ? $websiteUrl : null,
                $isVisible,
                $id,
            ],
        );
        AuditLogService::writeEvent('guilds.update', ['id' => $id], 'admin');
    }

    public function delete(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $this->execPrepared('DELETE FROM guild_roles WHERE guild_id = ?', [$id]);
        $this->execPrepared('DELETE FROM guilds WHERE id = ?', [$id]);
        AuditLogService::writeEvent('guilds.delete', ['id' => $id], 'admin');
    }

    public function getRoles(int $guildId): array
    {
        if ($guildId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, guild_id, name, image, monthly_salary, is_leader, is_officer, is_default
             FROM guild_roles
             WHERE guild_id = ?
             ORDER BY is_leader DESC, is_officer DESC, is_default DESC, name ASC',
            [$guildId],
        );

        return $rows ?: [];
    }

    public function deleteRole(int $id): void
    {
        if ($id <= 0) {
            return;
        }

        $this->execPrepared('DELETE FROM guild_roles WHERE id = ?', [$id]);
    }
}
