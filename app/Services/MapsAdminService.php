<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class MapsAdminService
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

    private function getMapRow(int $mapId)
    {
        if ($mapId <= 0) {
            return null;
        }

        return $this->firstPrepared(
            'SELECT id, name, parent_map_id FROM maps WHERE id = ? LIMIT 1',
            [$mapId],
        );
    }

    private function assertNoCircularParenting(int $currentId, ?int $parentMapId): void
    {
        if ($currentId <= 0 || $parentMapId === null || $parentMapId <= 0) {
            return;
        }

        $visited = [$currentId => true];
        $cursorId = $parentMapId;

        while ($cursorId > 0) {
            if (isset($visited[$cursorId])) {
                throw AppError::validation(
                    'Gerarchia mappe ciclica non consentita',
                    [],
                    'map_cycle_invalid',
                );
            }

            $visited[$cursorId] = true;
            $cursor = $this->getMapRow($cursorId);
            if (empty($cursor)) {
                break;
            }

            $cursorId = isset($cursor->parent_map_id) ? (int) $cursor->parent_map_id : 0;
        }
    }

    private function normalizeParentMapId($value, int $currentId = 0): ?int
    {
        $parentMapId = (int) $value;
        if ($parentMapId <= 0) {
            return null;
        }

        if ($currentId > 0 && $parentMapId === $currentId) {
            throw AppError::validation(
                'Una mappa non puo contenere se stessa',
                [],
                'map_parent_self_invalid',
            );
        }

        $parent = $this->getMapRow($parentMapId);
        if (empty($parent)) {
            throw AppError::validation(
                'Mappa padre non valida',
                [],
                'map_parent_invalid',
            );
        }

        $this->assertNoCircularParenting($currentId, $parentMapId);

        return $parentMapId;
    }

    public function resolveNextPosition($requested): int
    {
        $position = (int) $requested;
        if ($position > 0) {
            return $position;
        }

        $row = $this->firstPrepared(
            'SELECT COALESCE(MAX(position), 0) AS max_position FROM maps',
            [],
        );
        $max = (!empty($row) && isset($row->max_position)) ? (int) $row->max_position : 0;
        return $max + 1;
    }

    private function clearInitialFlag(): void
    {
        $this->execPrepared('UPDATE maps SET initial = 0', []);
    }

    public function adminList(
        string $nameLike = '',
        string $renderMode = '',
        string $initial = '',
        string $mobile = '',
        int $results = 20,
        int $page = 1,
        string $sort = 'position|ASC'
    ): array {
        $where = [];
        $params = [];

        if ($nameLike !== '') {
            $where[] = 'm.`name` LIKE ?';
            $params[] = '%' . $nameLike . '%';
        }
        if ($renderMode !== '') {
            $where[] = 'm.`render_mode` = ?';
            $params[] = $renderMode;
        }
        if ($initial !== '') {
            $where[] = 'm.`initial` = ?';
            $params[] = ((int) $initial === 1) ? 1 : 0;
        }
        if ($mobile !== '') {
            $where[] = 'm.`mobile` = ?';
            $params[] = ((int) $mobile === 1) ? 1 : 0;
        }

        $whereClause = ($where !== []) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $parts = explode('|', $sort);
        $allowedFields = ['id', 'name', 'position', 'render_mode', 'initial', 'mobile', 'parent_map_id'];
        $sortField = in_array($parts[0], $allowedFields, true) ? $parts[0] : 'position';
        $sortDir = strtoupper($parts[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $results = max(1, min(500, $results));
        $page = max(1, $page);
        $offset = ($page - 1) * $results;

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM `maps` m ' . $whereClause,
            $params,
        );
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT m.*, p.name AS parent_map_name
             FROM `maps` m
             LEFT JOIN `maps` p ON p.id = m.parent_map_id '
            . $whereClause
            . ' ORDER BY m.`' . $sortField . '` ' . $sortDir
            . ' LIMIT ? OFFSET ?',
            array_merge($params, [$results, $offset]),
        );

        return [
            'dataset' => $rows,
            'properties' => [
                'query' => [
                    'name' => $nameLike,
                    'render_mode' => $renderMode,
                    'initial' => $initial,
                    'mobile' => $mobile,
                ],
                'page' => $page,
                'results_page' => $results,
                'orderBy' => $sortField . '|' . $sortDir,
                'tot' => ['count' => $total],
            ],
        ];
    }

    public function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw AppError::validation('Nome mappa obbligatorio', [], 'map_name_required');
        }

        $renderMode = $this->normalizeRenderMode($data['render_mode'] ?? 'grid');
        $position = $this->resolveNextPosition($data['position'] ?? null);
        $parentMapId = $this->normalizeParentMapId($data['parent_map_id'] ?? null);
        $initial = $this->toFlag($data['initial'] ?? 0);
        $mobile = $this->toFlag($data['mobile'] ?? 0);

        if ($initial === 1) {
            $this->clearInitialFlag();
        }

        $this->execPrepared(
            'INSERT INTO maps
             (name, description, status, initial, position, parent_map_id, mobile, icon, image, render_mode, meteo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name,
                $this->toNullableText($data['description'] ?? null),
                $this->toNullableText($data['status'] ?? null),
                $initial,
                $position,
                $parentMapId,
                $mobile,
                $this->toNullableText($data['icon'] ?? null),
                $this->toNullableText($data['image'] ?? null),
                $renderMode,
                $this->toNullableText($data['meteo'] ?? null),
            ],
        );

        $newId = (int) $this->db->lastInsertId();
        AuditLogService::writeEvent('maps.create', ['id' => $newId, 'name' => $name], 'admin');
        return $newId;
    }

    public function update(int $id, array $data): void
    {
        if ($id <= 0) {
            throw AppError::validation('ID mappa non valido', [], 'map_id_invalid');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw AppError::validation('Nome mappa obbligatorio', [], 'map_name_required');
        }

        $renderMode = $this->normalizeRenderMode($data['render_mode'] ?? 'grid');
        $position = $this->resolveNextPosition($data['position'] ?? null);
        $parentMapId = $this->normalizeParentMapId($data['parent_map_id'] ?? null, $id);
        $initial = $this->toFlag($data['initial'] ?? 0);
        $mobile = $this->toFlag($data['mobile'] ?? 0);

        if ($initial === 1) {
            $this->clearInitialFlag();
        }

        $this->execPrepared(
            'UPDATE maps
             SET name = ?, description = ?, status = ?, initial = ?, position = ?,
                 parent_map_id = ?, mobile = ?, icon = ?, image = ?, render_mode = ?, meteo = ?
             WHERE id = ?',
            [
                $name,
                $this->toNullableText($data['description'] ?? null),
                $this->toNullableText($data['status'] ?? null),
                $initial,
                $position,
                $parentMapId,
                $mobile,
                $this->toNullableText($data['icon'] ?? null),
                $this->toNullableText($data['image'] ?? null),
                $renderMode,
                $this->toNullableText($data['meteo'] ?? null),
                $id,
            ],
        );
        AuditLogService::writeEvent('maps.update', ['id' => $id], 'admin');
    }

    public function assertCanDelete(int $id): void
    {
        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS total FROM locations WHERE map_id = ?',
            [$id],
        );
        $count = (!empty($row) && isset($row->total)) ? (int) $row->total : 0;
        if ($count > 0) {
            throw AppError::validation(
                'Impossibile eliminare la mappa: ci sono luoghi associati',
                [],
                'map_has_locations',
            );
        }

        $children = $this->firstPrepared(
            'SELECT COUNT(*) AS total FROM maps WHERE parent_map_id = ?',
            [$id],
        );
        $childCount = (!empty($children) && isset($children->total)) ? (int) $children->total : 0;
        if ($childCount > 0) {
            throw AppError::validation(
                'Impossibile eliminare la mappa: contiene sottomappe collegate',
                [],
                'map_has_child_maps',
            );
        }
    }

    private function normalizeRenderMode($value): string
    {
        $mode = strtolower(trim((string) $value));
        return ($mode === 'visual') ? 'visual' : 'grid';
    }

    private function toNullableText($value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return ($text !== '') ? $text : null;
    }

    private function toFlag($value): int
    {
        return ((int) $value === 1) ? 1 : 0;
    }
}