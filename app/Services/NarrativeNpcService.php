<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class NarrativeNpcService
{
    /** @var DbAdapterInterface */
    private $db;

    private static $validGroupTypes = ['guild', 'faction', 'none'];

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param mixed $row
     * @return array<string,mixed>
     */
    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function validateGroupType(string $type): string
    {
        return in_array($type, self::$validGroupTypes, true) ? $type : 'none';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API (game side)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Lista PNG attivi visibili in gioco, con filtri opzionali.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function publicList(array $filters = []): array
    {
        $where = ['`is_active` = 1'];
        $params = [];

        if (!empty($filters['group_type']) && $filters['group_type'] !== 'none') {
            $where[] = '`group_type` = ?';
            $params[] = (string) $filters['group_type'];
        }
        if (!empty($filters['group_id'])) {
            $where[] = '`group_id` = ?';
            $params[] = (int) $filters['group_id'];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $rows = $this->db->fetchAllPrepared(
            'SELECT `id`, `name`, `description`, `image`, `group_type`, `group_id`
             FROM `narrative_npcs` ' . $whereClause . ' ORDER BY `name` ASC LIMIT 100',
            $params,
        );
        return array_map([$this, 'rowToArray'], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin CRUD
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,mixed>,total:int,page:int,limit:int}
     */
    public function adminList(
        array $filters = [],
        int $limit = 25,
        int $page = 1,
        string $orderBy = 'name|ASC',
    ): array {
        $where = [];
        $params = [];

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = '`is_active` = ?';
            $params[] = (int) $filters['is_active'];
        }
        if (!empty($filters['group_type'])) {
            $where[] = '`group_type` = ?';
            $params[] = (string) $filters['group_type'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . $filters['search'] . '%';
            $where[] = '(`name` LIKE ? OR `description` LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $allowed = ['id', 'name', 'group_type', 'is_active', 'date_created'];
        $parts = explode('|', $orderBy);
        $sortField = in_array($parts[0], $allowed, true) ? $parts[0] : 'name';
        $sortDir = isset($parts[1]) && strtoupper($parts[1]) === 'DESC' ? 'DESC' : 'ASC';

        $limit = max(1, min(100, $limit));
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $countRow = $this->db->fetchOnePrepared(
            'SELECT COUNT(*) AS n FROM `narrative_npcs` ' . $whereClause,
            $params,
        );
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->db->fetchAllPrepared(
            'SELECT n.*,
                CASE
                    WHEN n.`group_type` = \'guild\' THEN (SELECT g.`name` FROM `guilds` g WHERE g.`id` = n.`group_id` LIMIT 1)
                    ELSE NULL
                END AS `group_name`
             FROM `narrative_npcs` n ' . $whereClause
            . ' ORDER BY n.`' . $sortField . '` ' . $sortDir
            . ' LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        foreach ($rows as $row) {
            if (isset($row->group_type) && $row->group_type === 'faction' && isset($row->group_id) && (int) $row->group_id > 0) {
                $row->group_name = FactionProviderRegistry::getNameById((int) $row->group_id);
            }
        }

        return [
            'rows' => array_map([$this, 'rowToArray'], $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function get(int $id): array
    {
        $row = $this->db->fetchOnePrepared(
            'SELECT * FROM `narrative_npcs` WHERE `id` = ? LIMIT 1',
            [$id],
        );
        if (empty($row)) {
            throw AppError::notFound('PNG non trovato', [], 'npc_not_found');
        }
        return $this->rowToArray($row);
    }

    /**
     * Cerca un PNG attivo per nome (case-insensitive, corrispondenza parziale).
     * Restituisce il primo risultato o lancia un'eccezione se non trovato.
     *
     * @return array<string,mixed>
     */
    public function getByName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            throw AppError::validation('Nome PNG non valido', [], 'npc_name_required');
        }

        // exact match first (case-insensitive)
        $row = $this->db->fetchOnePrepared(
            'SELECT `id`, `name`, `description`, `image`, `group_type`, `group_id`
             FROM `narrative_npcs`
             WHERE `is_active` = 1 AND LOWER(`name`) = LOWER(?)
             LIMIT 1',
            [$name],
        );

        if (!empty($row)) {
            return $this->rowToArray($row);
        }

        // partial match fallback
        $row = $this->db->fetchOnePrepared(
            'SELECT `id`, `name`, `description`, `image`, `group_type`, `group_id`
             FROM `narrative_npcs`
             WHERE `is_active` = 1 AND LOWER(`name`) LIKE LOWER(?)
             LIMIT 1',
            ['%' . $name . '%'],
        );

        if (empty($row)) {
            throw AppError::notFound('PNG "' . $name . '" non trovato o non attivo', [], 'npc_not_found');
        }

        return $this->rowToArray($row);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        $name = trim((string) ($params['name'] ?? ''));
        $desc = trim((string) ($params['description'] ?? ''));
        $image = trim((string) ($params['image'] ?? ''));
        $groupType = $this->validateGroupType((string) ($params['group_type'] ?? 'none'));
        $groupId = (int) ($params['group_id'] ?? 0);
        $isActive = isset($params['is_active']) ? (int) (bool) $params['is_active'] : 1;
        $createdBy = (int) ($params['created_by'] ?? 0);

        if ($name === '') {
            throw AppError::validation('Il nome è obbligatorio', [], 'npc_name_required');
        }

        $this->db->executePrepared(
            'INSERT INTO `narrative_npcs`
                (`name`, `description`, `image`, `group_type`, `group_id`, `is_active`, `created_by`)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $name,
                $desc !== '' ? $desc : null,
                $image !== '' ? $image : null,
                $groupType,
                ($groupType !== 'none' && $groupId > 0) ? $groupId : null,
                $isActive,
                $createdBy > 0 ? $createdBy : null,
            ],
        );
        return $this->get((int) $this->db->lastInsertId());
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function update(int $id, array $params): array
    {
        $this->get($id); // throws if not found

        $name = trim((string) ($params['name'] ?? ''));
        $desc = trim((string) ($params['description'] ?? ''));
        $image = trim((string) ($params['image'] ?? ''));
        $groupType = $this->validateGroupType((string) ($params['group_type'] ?? 'none'));
        $groupId = (int) ($params['group_id'] ?? 0);
        $isActive = isset($params['is_active']) ? (int) (bool) $params['is_active'] : 1;

        if ($name === '') {
            throw AppError::validation('Il nome è obbligatorio', [], 'npc_name_required');
        }

        $this->db->executePrepared(
            'UPDATE `narrative_npcs`
             SET `name` = ?, `description` = ?, `image` = ?,
                 `group_type` = ?, `group_id` = ?, `is_active` = ?
             WHERE `id` = ?',
            [
                $name,
                $desc !== '' ? $desc : null,
                $image !== '' ? $image : null,
                $groupType,
                ($groupType !== 'none' && $groupId > 0) ? $groupId : null,
                $isActive,
                $id,
            ],
        );
        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $this->get($id); // throws if not found
        $this->db->executePrepared('DELETE FROM `narrative_npcs` WHERE `id` = ?', [$id]);
    }
}
