<?php

declare(strict_types=1);

namespace App\Services;

use Core\Http\AppError;

class CharacterAttributesDefinitionService extends CharacterAttributesBaseService
{
    private const GROUPS = ['primary', 'secondary', 'narrative'];
    private const VALUE_TYPES = ['number'];
    private const ROUND_MODES = ['none', 'floor', 'ceil', 'round'];

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

    private function execPreparedCount(string $sql, array $params = []): int
    {
        $this->execPrepared($sql, $params);
        $row = $this->firstPrepared('SELECT ROW_COUNT() AS count');
        return (int) ($row->count ?? 0);
    }

    public function listDefinitions(object $payload): array
    {
        $query = (isset($payload->query) && is_object($payload->query)) ? $payload->query : (object) [];
        $page = $this->normalizePage($payload->page ?? 1);
        $results = $this->normalizeResults($payload->results ?? ($payload->results_page ?? 20), 20, 200);
        $offset = ($page - 1) * $results;

        $order = $this->normalizeOrderBy(
            $payload->orderBy ?? 'position|ASC',
            [
                'id' => 'd.id',
                'slug' => 'd.slug',
                'name' => 'd.name',
                'attribute_group' => 'd.attribute_group',
                'position' => 'd.position',
                'is_active' => 'd.is_active',
                'is_derived' => 'd.is_derived',
                'maps_to_core_health_max' => 'd.maps_to_core_health_max',
            ],
            'position',
            'ASC',
        );

        $whereParts = [];
        $params = [];
        if (isset($query->attribute_group)) {
            $group = strtolower(trim((string) $query->attribute_group));
            if (in_array($group, self::GROUPS, true)) {
                $whereParts[] = 'd.attribute_group = ?';
                $params[] = $group;
            }
        }

        if (isset($query->is_active) && $query->is_active !== '' && $query->is_active !== null) {
            $rawActive = trim((string) $query->is_active);
            if ($rawActive === '0' || $rawActive === '1') {
                $whereParts[] = 'd.is_active = ?';
                $params[] = (int) $rawActive;
            } elseif (strtolower($rawActive) === 'true' || strtolower($rawActive) === 'false') {
                $whereParts[] = 'd.is_active = ?';
                $params[] = $this->normalizeBool($rawActive, 1);
            }
        }

        if (isset($query->search)) {
            $search = trim((string) $query->search);
            if ($search !== '') {
                $whereParts[] = '(d.slug LIKE ? OR d.name LIKE ?)';
                $needle = '%' . $search . '%';
                $params[] = $needle;
                $params[] = $needle;
            }
        }

        $where = '';
        if (!empty($whereParts)) {
            $where = ' WHERE ' . implode(' AND ', $whereParts);
        }

        $rows = $this->fetchPrepared(
            'SELECT
                d.id,
                d.slug,
                d.name,
                d.description,
                d.attribute_group,
                d.value_type,
                d.position,
                d.min_value,
                d.max_value,
                d.default_value,
                d.fallback_value,
                d.round_mode,
                d.is_active,
                d.is_derived,
                d.allow_manual_override,
                d.visible_in_profile,
                d.visible_in_location,
                d.maps_to_core_health_max,
                d.date_created,
                d.date_updated
             FROM character_attribute_definitions d'
            . $where . '
             ORDER BY ' . $order['sql'] . ', d.id ' . $order['direction'] . '
             LIMIT ?, ?',
            array_merge($params, [$offset, $results]),
        );

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS count
             FROM character_attribute_definitions d' . $where,
            $params,
        );

        $totalCount = (!empty($countRow) && isset($countRow->count)) ? (int) $countRow->count : 0;

        return [
            'dataset' => $rows ?: [],
            'properties' => [
                'query' => $query,
                'page' => $page,
                'results' => $results,
                'results_page' => $results,
                'orderBy' => $order['raw'],
                'tot' => [
                    'count' => $totalCount,
                ],
            ],
        ];
    }

    public function getDefinitionById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT
                d.id,
                d.slug,
                d.name,
                d.description,
                d.attribute_group,
                d.value_type,
                d.position,
                d.min_value,
                d.max_value,
                d.default_value,
                d.fallback_value,
                d.round_mode,
                d.is_active,
                d.is_derived,
                d.allow_manual_override,
                d.visible_in_profile,
                d.visible_in_location,
                d.maps_to_core_health_max,
                d.date_created,
                d.date_updated
             FROM character_attribute_definitions d
             WHERE d.id = ?
             LIMIT 1',
            [$id],
        );

        return !empty($row) ? $row : null;
    }

    public function getActiveDefinitionBySlug(string $slug): ?object
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT *
             FROM character_attribute_definitions
             WHERE slug = ?
               AND is_active = 1
             LIMIT 1',
            [$slug],
        );

        return !empty($row) ? $row : null;
    }

    public function createDefinition(object $data, int $actorUserId = 0): array
    {
        $normalized = $this->normalizeDefinitionPayload($data, 0, false);
        $position = $normalized['position'];
        if ($position <= 0) {
            $position = $this->nextPosition();
        }

        $this->execPrepared(
            'INSERT INTO character_attribute_definitions
            (slug, name, description, attribute_group, value_type, position, min_value, max_value, default_value, fallback_value, round_mode, is_active, is_derived, allow_manual_override, visible_in_profile, visible_in_location, maps_to_core_health_max, created_by, updated_by, date_created)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $normalized['slug'],
                $normalized['name'],
                $normalized['description'],
                $normalized['attribute_group'],
                $normalized['value_type'],
                $position,
                $normalized['min_value'],
                $normalized['max_value'],
                $normalized['default_value'],
                $normalized['fallback_value'],
                $normalized['round_mode'],
                $normalized['is_active'],
                $normalized['is_derived'],
                $normalized['allow_manual_override'],
                $normalized['visible_in_profile'],
                $normalized['visible_in_location'],
                $normalized['maps_to_core_health_max'],
                $actorUserId > 0 ? $actorUserId : null,
                $actorUserId > 0 ? $actorUserId : null,
            ],
        );

        $definitionId = (int) $this->db->lastInsertId();
        if ($definitionId <= 0) {
            throw AppError::validation('Creazione attributo non riuscita', [], 'attribute_create_failed');
        }

        $row = $this->getDefinitionById($definitionId);
        return ['definition' => $row];
    }

    public function updateDefinition(int $id, object $data, int $actorUserId = 0): array
    {
        $current = $this->getDefinitionById($id);
        if (empty($current)) {
            throw AppError::notFound('Attributo non trovato', [], 'attribute_definition_not_found');
        }

        $normalized = $this->normalizeDefinitionPayload($data, $id, true, $current);

        $this->execPrepared(
            'UPDATE character_attribute_definitions SET
                slug = ?,
                name = ?,
                description = ?,
                attribute_group = ?,
                value_type = ?,
                position = ?,
                min_value = ?,
                max_value = ?,
                default_value = ?,
                fallback_value = ?,
                round_mode = ?,
                is_active = ?,
                is_derived = ?,
                allow_manual_override = ?,
                visible_in_profile = ?,
                visible_in_location = ?,
                maps_to_core_health_max = ?,
                updated_by = ?,
                date_updated = NOW()
             WHERE id = ?',
            [
                $normalized['slug'],
                $normalized['name'],
                $normalized['description'],
                $normalized['attribute_group'],
                $normalized['value_type'],
                $normalized['position'],
                $normalized['min_value'],
                $normalized['max_value'],
                $normalized['default_value'],
                $normalized['fallback_value'],
                $normalized['round_mode'],
                $normalized['is_active'],
                $normalized['is_derived'],
                $normalized['allow_manual_override'],
                $normalized['visible_in_profile'],
                $normalized['visible_in_location'],
                $normalized['maps_to_core_health_max'],
                $actorUserId > 0 ? $actorUserId : null,
                $id,
            ],
        );

        $row = $this->getDefinitionById($id);
        return ['definition' => $row];
    }

    public function deactivateDefinition(int $id, int $actorUserId = 0): array
    {
        $current = $this->getDefinitionById($id);
        if (empty($current)) {
            throw AppError::notFound('Attributo non trovato', [], 'attribute_definition_not_found');
        }

        $this->execPrepared(
            'UPDATE character_attribute_definitions SET
                is_active = 0,
                maps_to_core_health_max = 0,
                updated_by = ?,
                date_updated = NOW()
             WHERE id = ?',
            [
                $actorUserId > 0 ? $actorUserId : null,
                $id,
            ],
        );

        return ['definition' => $this->getDefinitionById($id)];
    }

    public function reorderDefinitions(array $orderedIds): array
    {
        $position = 1;
        $updated = 0;

        $this->begin();
        try {
            foreach ($orderedIds as $id) {
                $definitionId = (int) $id;
                if ($definitionId <= 0) {
                    continue;
                }

                $updated += $this->execPreparedCount(
                    'UPDATE character_attribute_definitions SET
                        position = ?,
                        date_updated = NOW()
                     WHERE id = ?',
                    [$position, $definitionId],
                );
                $position += 1;
            }
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['updated' => $updated];
    }

    public function listActiveDefinitions(): array
    {
        $rows = $this->fetchPrepared(
            'SELECT
                d.id,
                d.slug,
                d.name,
                d.description,
                d.attribute_group,
                d.value_type,
                d.position,
                d.min_value,
                d.max_value,
                d.default_value,
                d.fallback_value,
                d.round_mode,
                d.is_active,
                d.is_derived,
                d.allow_manual_override,
                d.visible_in_profile,
                d.visible_in_location,
                d.maps_to_core_health_max
             FROM character_attribute_definitions d
             WHERE d.is_active = 1
             ORDER BY d.position ASC, d.id ASC',
        );

        return $rows ?: [];
    }

    public function mappedHealthDefinition(): ?object
    {
        $row = $this->firstPrepared(
            'SELECT
                d.id,
                d.slug,
                d.name,
                d.min_value,
                d.max_value,
                d.round_mode,
                d.fallback_value
             FROM character_attribute_definitions d
             WHERE d.is_active = 1
               AND d.maps_to_core_health_max = 1
             LIMIT 1',
        );

        return !empty($row) ? $row : null;
    }

    private function nextPosition(): int
    {
        $row = $this->firstPrepared('SELECT COALESCE(MAX(position), 0) + 1 AS next_position FROM character_attribute_definitions');
        if (empty($row) || !isset($row->next_position)) {
            return 1;
        }

        $next = (int) $row->next_position;
        return ($next > 0) ? $next : 1;
    }

    private function normalizeDefinitionPayload(object $data, int $currentId, bool $isUpdate, object $fallback = null): array
    {
        $slug = $this->normalizeString($data->slug ?? ($fallback->slug ?? null));
        if ($slug === null) {
            throw AppError::validation('Slug attributo non valido', [], 'attribute_slug_conflict');
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,79}$/', $slug) !== 1) {
            throw AppError::validation('Slug attributo non valido', [], 'attribute_slug_conflict');
        }

        $slugConflict = $this->firstPrepared(
            'SELECT id
             FROM character_attribute_definitions
             WHERE slug = ?
               AND id <> ?
             LIMIT 1',
            [$slug, $currentId],
        );
        if (!empty($slugConflict)) {
            throw AppError::validation('Slug attributo gia in uso', [], 'attribute_slug_conflict');
        }

        $name = $this->normalizeString($data->name ?? ($fallback->name ?? null));
        if ($name === null) {
            throw AppError::validation('Nome attributo non valido', [], 'attribute_definition_invalid');
        }

        $description = $this->normalizeString($data->description ?? ($fallback->description ?? null));
        $group = strtolower(trim((string) ($data->attribute_group ?? ($fallback->attribute_group ?? 'primary'))));
        if (!in_array($group, self::GROUPS, true)) {
            throw AppError::validation('Gruppo attributo non valido', [], 'attribute_definition_invalid');
        }

        $valueType = strtolower(trim((string) ($data->value_type ?? ($fallback->value_type ?? 'number'))));
        if (!in_array($valueType, self::VALUE_TYPES, true)) {
            throw AppError::validation('Tipo valore attributo non valido', [], 'attribute_definition_invalid');
        }

        $minValue = $this->normalizeDecimalValue(
            property_exists($data, 'min_value') ? $data->min_value : ($fallback->min_value ?? null),
            'minimo attributo',
            'attribute_range_invalid',
            true,
        );
        $maxValue = $this->normalizeDecimalValue(
            property_exists($data, 'max_value') ? $data->max_value : ($fallback->max_value ?? null),
            'massimo attributo',
            'attribute_range_invalid',
            true,
        );
        if ($minValue !== null && $maxValue !== null && $minValue > $maxValue) {
            throw AppError::validation('Range attributo non valido', [], 'attribute_range_invalid');
        }

        $defaultValue = $this->normalizeDecimalValue(
            property_exists($data, 'default_value') ? $data->default_value : ($fallback->default_value ?? null),
            'default attributo',
            'attribute_default_out_of_range',
            true,
        );
        if ($defaultValue !== null) {
            if ($minValue !== null && $defaultValue < $minValue) {
                throw AppError::validation('Default attributo fuori range', [], 'attribute_default_out_of_range');
            }
            if ($maxValue !== null && $defaultValue > $maxValue) {
                throw AppError::validation('Default attributo fuori range', [], 'attribute_default_out_of_range');
            }
        }

        $fallbackValue = $this->normalizeDecimalValue(
            property_exists($data, 'fallback_value') ? $data->fallback_value : ($fallback->fallback_value ?? null),
            'fallback attributo',
            'attribute_default_out_of_range',
            true,
        );

        $roundMode = strtolower(trim((string) ($data->round_mode ?? ($fallback->round_mode ?? 'none'))));
        if (!in_array($roundMode, self::ROUND_MODES, true)) {
            $roundMode = 'none';
        }

        $position = (int) ($data->position ?? ($fallback->position ?? 0));
        if ($position < 0) {
            $position = 0;
        }

        $isActive = $this->normalizeBool($data->is_active ?? ($fallback->is_active ?? 1), 1);
        $isDerived = $this->normalizeBool($data->is_derived ?? ($fallback->is_derived ?? 0), 0);
        $allowManualOverride = $this->normalizeBool(
            $data->allow_manual_override ?? ($fallback->allow_manual_override ?? 0),
            0,
        );
        $visibleInProfile = $this->normalizeBool($data->visible_in_profile ?? ($fallback->visible_in_profile ?? 1), 1);
        $visibleInLocation = $this->normalizeBool($data->visible_in_location ?? ($fallback->visible_in_location ?? 0), 0);
        $mapsHealth = $this->normalizeBool($data->maps_to_core_health_max ?? ($fallback->maps_to_core_health_max ?? 0), 0);

        if ($mapsHealth === 1 && $isActive === 1) {
            $alreadyMapped = $this->firstPrepared(
                'SELECT id
                 FROM character_attribute_definitions
                 WHERE id <> ?
                   AND is_active = 1
                   AND maps_to_core_health_max = 1
                 LIMIT 1',
                [$currentId],
            );
            if (!empty($alreadyMapped)) {
                throw AppError::validation(
                    'Esiste gia un attributo attivo mappato su salute massima',
                    [],
                    'attribute_rule_invalid',
                );
            }
        }

        if ($isUpdate && $fallback !== null) {
            if (!property_exists($data, 'position')) {
                $position = (int) ($fallback->position ?? 0);
            }
        }

        return [
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'attribute_group' => $group,
            'value_type' => $valueType,
            'position' => $position,
            'min_value' => $minValue,
            'max_value' => $maxValue,
            'default_value' => $defaultValue,
            'fallback_value' => $fallbackValue,
            'round_mode' => $roundMode,
            'is_active' => $isActive,
            'is_derived' => $isDerived,
            'allow_manual_override' => $allowManualOverride,
            'visible_in_profile' => $visibleInProfile,
            'visible_in_location' => $visibleInLocation,
            'maps_to_core_health_max' => $mapsHealth,
        ];
    }
}
