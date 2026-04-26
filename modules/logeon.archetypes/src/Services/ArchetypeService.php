<?php

declare(strict_types=1);

namespace Modules\Logeon\Archetypes\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class ArchetypeService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function castBools(array $row): array
    {
        foreach (['is_active', 'is_selectable'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        return $row;
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'archetype';
    }

    private function uniqueSlug(string $base, int $excludeId = 0): string
    {
        $slug = $base;
        $counter = 1;

        while (true) {
            $sql = 'SELECT COUNT(*) AS n FROM `archetypes` WHERE `slug` = ?';
            $params = [$slug];
            if ($excludeId > 0) {
                $sql .= ' AND `id` != ?';
                $params[] = $excludeId;
            }

            $existing = $this->firstPrepared($sql, $params);
            if ((int) ($existing->n ?? 0) === 0) {
                return $slug;
            }

            $slug = $base . '-' . (++$counter);
        }
    }

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    public function getConfig(): array
    {
        $row = $this->firstPrepared('SELECT * FROM `archetype_configs` WHERE `id` = 1 LIMIT 1');
        if (empty($row)) {
            return [
                'archetypes_enabled' => 1,
                'archetype_required' => 0,
                'multiple_archetypes_allowed' => 0,
            ];
        }

        return [
            'archetypes_enabled' => (int) ($row->archetypes_enabled ?? 1),
            'archetype_required' => (int) ($row->archetype_required ?? 0),
            'multiple_archetypes_allowed' => (int) ($row->multiple_archetypes_allowed ?? 0),
        ];
    }

    public function updateConfig(object $data): array
    {
        $current = $this->getConfig();

        $enabled = isset($data->archetypes_enabled) ? ((int) $data->archetypes_enabled === 1 ? 1 : 0) : null;
        $required = isset($data->archetype_required) ? ((int) $data->archetype_required === 1 ? 1 : 0) : null;
        $multiple = isset($data->multiple_archetypes_allowed) ? ((int) $data->multiple_archetypes_allowed === 1 ? 1 : 0) : null;

        if ($enabled === null && $required === null && $multiple === null) {
            return $current;
        }

        $nextEnabled = ($enabled !== null) ? $enabled : (int) ($current['archetypes_enabled'] ?? 1);
        $nextRequired = ($required !== null) ? $required : (int) ($current['archetype_required'] ?? 0);
        $nextMultiple = ($multiple !== null) ? $multiple : (int) ($current['multiple_archetypes_allowed'] ?? 0);

        // When archetypes are disabled, dependent switches are always off.
        if ($nextEnabled !== 1) {
            $nextRequired = 0;
            $nextMultiple = 0;
        }

        $this->execPrepared(
            'UPDATE `archetype_configs`
             SET `archetypes_enabled` = ?,
                 `archetype_required` = ?,
                 `multiple_archetypes_allowed` = ?
             WHERE `id` = 1',
            [(int) $nextEnabled, (int) $nextRequired, (int) $nextMultiple],
        );

        return [
            'archetypes_enabled' => (int) $nextEnabled,
            'archetype_required' => (int) $nextRequired,
            'multiple_archetypes_allowed' => (int) $nextMultiple,
        ];
    }

    // -------------------------------------------------------------------------
    // Public (game-facing)
    // -------------------------------------------------------------------------

    public function publicList(): array
    {
        $config = $this->getConfig();
        if (!ArchetypeConfigAccessor::isEnabled($config)) {
            return [
                'config' => $config,
                'dataset' => [],
            ];
        }

        $rows = $this->fetchPrepared(
            'SELECT `id`, `name`, `slug`, `icon`, `description`, `lore_text`, `sort_order`
             FROM `archetypes`
             WHERE `is_active` = 1 AND `is_selectable` = 1
             ORDER BY `sort_order` ASC, `name` ASC',
        );

        $list = array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);

        return [
            'config' => $config,
            'dataset' => $list,
        ];
    }

    // -------------------------------------------------------------------------
    // Character archetypes
    // -------------------------------------------------------------------------

    public function getCharacterArchetypes(int $characterId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT a.id, a.name, a.slug, a.icon, a.description, a.lore_text, ca.assigned_at
             FROM `character_archetypes` ca
             INNER JOIN `archetypes` a ON a.id = ca.archetype_id
             WHERE ca.character_id = ?
             ORDER BY ca.assigned_at ASC',
            [(int) $characterId],
        );

        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    public function assignArchetype(int $characterId, int $archetypeId, bool $multipleAllowed = false): void
    {
        if ($archetypeId <= 0) {
            return;
        }

        $archetype = $this->firstPrepared(
            'SELECT `id` FROM `archetypes` WHERE `id` = ? AND `is_active` = 1 LIMIT 1',
            [(int) $archetypeId],
        );
        if (empty($archetype)) {
            throw AppError::notFound('Archetipo non trovato', [], 'archetype_not_found');
        }

        if (!$multipleAllowed) {
            $this->execPrepared('DELETE FROM `character_archetypes` WHERE `character_id` = ?', [(int) $characterId]);
        }

        $this->execPrepared(
            'INSERT IGNORE INTO `character_archetypes` (`character_id`, `archetype_id`)
             VALUES (?, ?)',
            [(int) $characterId, (int) $archetypeId],
        );
    }

    public function removeArchetype(int $characterId, int $archetypeId): void
    {
        $this->execPrepared(
            'DELETE FROM `character_archetypes`
             WHERE `character_id` = ?
               AND `archetype_id` = ?',
            [(int) $characterId, (int) $archetypeId],
        );
    }

    public function clearCharacterArchetypes(int $characterId): void
    {
        $this->execPrepared('DELETE FROM `character_archetypes` WHERE `character_id` = ?', [(int) $characterId]);
    }

    /**
     * @param  array<int> $archetypeIds
     * @return array<int>
     */
    public function validateSelectableArchetypes(array $archetypeIds): array
    {
        if (empty($archetypeIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($archetypeIds), '?'));
        $selectableRows = $this->fetchPrepared(
            'SELECT `id`
             FROM `archetypes`
             WHERE `is_active` = 1
               AND `is_selectable` = 1
               AND `id` IN (' . $placeholders . ')',
            array_values(array_map('intval', $archetypeIds)),
        );

        $selectableIds = [];
        foreach ($selectableRows as $row) {
            $selectableIds[] = (int) ($row->id ?? 0);
        }

        foreach ($archetypeIds as $selectedId) {
            if (!in_array((int) $selectedId, $selectableIds, true)) {
                throw AppError::validation('Archetipo non selezionabile', [], 'archetype_not_selectable');
            }
        }

        return $archetypeIds;
    }

    // -------------------------------------------------------------------------
    // Admin - CRUD
    // -------------------------------------------------------------------------

    public function adminList(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'sort_order|ASC'): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(`name` LIKE ? OR `slug` LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = '`is_active` = ?';
            $params[] = ((int) $filters['is_active'] === 1 ? 1 : 0);
        }
        if (isset($filters['is_selectable']) && $filters['is_selectable'] !== '') {
            $where[] = '`is_selectable` = ?';
            $params[] = ((int) $filters['is_selectable'] === 1 ? 1 : 0);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortParts = explode('|', $sort, 2);
        $sortFieldCandidate = $sortParts[0];
        $sortField = in_array($sortFieldCandidate, ['id', 'name', 'slug', 'sort_order', 'is_active', 'is_selectable', 'created_at'], true)
            ? $sortFieldCandidate : 'sort_order';
        $sortDir = strtoupper($sortParts[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $offset = max(0, ($page - 1) * $limit);

        $totalRow = $this->firstPrepared('SELECT COUNT(*) AS n FROM `archetypes` ' . $whereClause, $params);
        $total = (int) ($totalRow->n ?? 0);
        $rows = $this->fetchPrepared(
            'SELECT * FROM `archetypes` ' . $whereClause
            . ' ORDER BY `' . $sortField . '` ' . $sortDir
            . ' LIMIT ? OFFSET ?',
            array_merge($params, [(int) $limit, (int) $offset]),
        );

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => array_map(function ($r) {
                return $this->castBools($this->rowToArray($r));
            }, $rows),
        ];
    }

    public function adminGet(int $id): array
    {
        $row = $this->firstPrepared('SELECT * FROM `archetypes` WHERE `id` = ? LIMIT 1', [(int) $id]);
        if (empty($row)) {
            throw AppError::notFound('Archetipo non trovato', [], 'archetype_not_found');
        }
        return $this->castBools($this->rowToArray($row));
    }

    public function adminCreate(object $data): array
    {
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $loreText = trim((string) ($data->lore_text ?? ''));
        $isActive = isset($data->is_active) ? ((int) $data->is_active === 1 ? 1 : 0) : 1;
        $isSelectable = isset($data->is_selectable) ? ((int) $data->is_selectable === 1 ? 1 : 0) : 1;
        $sortOrder = max(0, (int) ($data->sort_order ?? 0));
        $icon = trim((string) ($data->icon ?? ''));

        if ($name === '') {
            throw AppError::validation('Il nome e obbligatorio', [], 'archetype_name_required');
        }

        $slugBase = $this->generateSlug($name);
        $slug = $this->uniqueSlug($slugBase);

        $this->execPrepared(
            'INSERT INTO `archetypes` (`name`, `slug`, `description`, `lore_text`, `icon`, `is_active`, `is_selectable`, `sort_order`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name,
                $slug,
                $description !== '' ? $description : null,
                $loreText !== '' ? $loreText : null,
                $icon !== '' ? $icon : null,
                $isActive,
                $isSelectable,
                $sortOrder,
            ],
        );

        $newId = (int) $this->db->lastInsertId();
        AuditLogService::writeEvent('archetypes.create', ['id' => $newId, 'name' => $name], 'admin');
        return $this->adminGet($newId);
    }

    public function adminUpdate(object $data): array
    {
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('ID archetipo obbligatorio', [], 'archetype_id_required');
        }
        $this->adminGet($id); // ensure exists

        $fields = [];
        $params = [];

        if (isset($data->name)) {
            $name = trim((string) $data->name);
            if ($name === '') {
                throw AppError::validation('Il nome e obbligatorio', [], 'archetype_name_required');
            }
            $fields[] = '`name` = ?';
            $params[] = $name;
            $slugBase = $this->generateSlug($name);
            $fields[] = '`slug` = ?';
            $params[] = $this->uniqueSlug($slugBase, $id);
        }
        if (isset($data->description)) {
            $desc = trim((string) $data->description);
            $fields[] = '`description` = ?';
            $params[] = ($desc !== '' ? $desc : null);
        }
        if (isset($data->lore_text)) {
            $lore = trim((string) $data->lore_text);
            $fields[] = '`lore_text` = ?';
            $params[] = ($lore !== '' ? $lore : null);
        }
        if (isset($data->is_active)) {
            $fields[] = '`is_active` = ?';
            $params[] = ((int) $data->is_active === 1 ? 1 : 0);
        }
        if (isset($data->is_selectable)) {
            $fields[] = '`is_selectable` = ?';
            $params[] = ((int) $data->is_selectable === 1 ? 1 : 0);
        }
        if (isset($data->sort_order)) {
            $fields[] = '`sort_order` = ?';
            $params[] = max(0, (int) $data->sort_order);
        }
        if (isset($data->icon)) {
            $ic = trim((string) $data->icon);
            $fields[] = '`icon` = ?';
            $params[] = ($ic !== '' ? $ic : null);
        }

        if (!empty($fields)) {
            $params[] = (int) $id;
            $this->execPrepared(
                'UPDATE `archetypes` SET ' . implode(', ', $fields) . ' WHERE `id` = ?',
                $params,
            );
            AuditLogService::writeEvent('archetypes.update', ['id' => $id], 'admin');
        }

        return $this->adminGet($id);
    }

    public function adminDelete(int $id): void
    {
        $this->adminGet($id); // ensure exists

        $inUseRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM `character_archetypes` WHERE `archetype_id` = ?',
            [(int) $id],
        );
        $inUse = (int) ($inUseRow->n ?? 0);

        if ($inUse > 0) {
            throw AppError::validation(
                'L\'archetipo e assegnato a ' . $inUse . ' personaggio/i. Rimuovi le assegnazioni prima di eliminarlo.',
                [],
                'archetype_in_use',
            );
        }

        $this->execPrepared('DELETE FROM `archetypes` WHERE `id` = ?', [(int) $id]);
        AuditLogService::writeEvent('archetypes.delete', ['id' => $id], 'admin');
    }
}
