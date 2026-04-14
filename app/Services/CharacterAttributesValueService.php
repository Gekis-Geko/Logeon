<?php

declare(strict_types=1);

namespace App\Services;

use Core\Http\AppError;

class CharacterAttributesValueService extends CharacterAttributesBaseService
{
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

    public function ensureRowsForCharacter(int $characterId): void
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $this->execPrepared(
            'INSERT INTO character_attribute_values (
                character_id,
                attribute_id,
                base_value,
                override_value,
                effective_value,
                value_source,
                date_created
             )
             SELECT
                ?,
                d.id,
                d.default_value,
                NULL,
                NULL,
                "default",
                NOW()
             FROM character_attribute_definitions d
             LEFT JOIN character_attribute_values v
                ON v.character_id = ?
               AND v.attribute_id = d.id
             WHERE d.is_active = 1
               AND v.id IS NULL',
            [$characterId, $characterId],
        );
    }

    public function listCharacterValues(int $characterId): array
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $rows = $this->fetchPrepared(
            'SELECT
                d.id AS attribute_id,
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
                v.id AS value_id,
                v.base_value,
                v.override_value,
                v.effective_value,
                v.value_source,
                v.last_recomputed_at
             FROM character_attribute_definitions d
             LEFT JOIN character_attribute_values v
               ON v.attribute_id = d.id
              AND v.character_id = ?
             WHERE d.is_active = 1
             ORDER BY d.position ASC, d.id ASC',
            [$characterId],
        );

        return $rows ?: [];
    }

    public function valuesIndexByAttribute(int $characterId): array
    {
        $rows = $this->listCharacterValues($characterId);
        $index = [];
        foreach ($rows as $row) {
            $attributeId = (int) ($row->attribute_id ?? 0);
            if ($attributeId <= 0) {
                continue;
            }
            $index[$attributeId] = $row;
        }
        return $index;
    }

    public function updateManualValues(int $characterId, array $entries): array
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $updatedIds = [];
        $this->begin();
        try {
            foreach ($entries as $entry) {
                $row = is_object($entry) ? $entry : (object) $entry;
                $attributeId = (int) ($row->attribute_id ?? 0);
                if ($attributeId <= 0) {
                    continue;
                }

                $definition = $this->firstPrepared(
                    'SELECT
                        id,
                        is_active,
                        is_derived,
                        allow_manual_override,
                        min_value,
                        max_value
                     FROM character_attribute_definitions
                     WHERE id = ?
                     LIMIT 1',
                    [$attributeId],
                );
                if (empty($definition) || (int) ($definition->is_active ?? 0) !== 1) {
                    throw AppError::notFound('Attributo non trovato', [], 'attribute_definition_not_found');
                }

                $hasBase = property_exists($row, 'base_value');
                $hasOverride = property_exists($row, 'override_value');
                if (!$hasBase && !$hasOverride) {
                    continue;
                }

                $baseValue = null;
                if ($hasBase) {
                    $baseValue = $this->normalizeDecimalValue(
                        $row->base_value,
                        'valore base',
                        'attribute_range_invalid',
                        true,
                    );
                }

                $overrideValue = null;
                if ($hasOverride) {
                    $overrideValue = $this->normalizeDecimalValue(
                        $row->override_value,
                        'override attributo',
                        'attribute_range_invalid',
                        true,
                    );
                }

                $minValue = isset($definition->min_value) ? (float) $definition->min_value : null;
                $maxValue = isset($definition->max_value) ? (float) $definition->max_value : null;
                if ($hasBase && $baseValue !== null) {
                    if ($minValue !== null && $baseValue < $minValue) {
                        throw AppError::validation('Valore base fuori range', [], 'attribute_range_invalid');
                    }
                    if ($maxValue !== null && $baseValue > $maxValue) {
                        throw AppError::validation('Valore base fuori range', [], 'attribute_range_invalid');
                    }
                }
                if ($hasOverride && $overrideValue !== null) {
                    if ((int) ($definition->allow_manual_override ?? 0) !== 1) {
                        throw AppError::validation(
                            'Override non consentito per questo attributo',
                            [],
                            'attribute_override_not_allowed',
                        );
                    }
                    if ($minValue !== null && $overrideValue < $minValue) {
                        throw AppError::validation('Override fuori range', [], 'attribute_range_invalid');
                    }
                    if ($maxValue !== null && $overrideValue > $maxValue) {
                        throw AppError::validation('Override fuori range', [], 'attribute_range_invalid');
                    }
                }

                $current = $this->firstPrepared(
                    'SELECT id
                     FROM character_attribute_values
                     WHERE character_id = ?
                       AND attribute_id = ?
                     LIMIT 1
                     FOR UPDATE',
                    [$characterId, $attributeId],
                );

                if (empty($current)) {
                    $this->execPrepared(
                        'INSERT INTO character_attribute_values SET
                            character_id = ?,
                            attribute_id = ?,
                            base_value = ?,
                            override_value = ?,
                            value_source = "base",
                            date_created = NOW()',
                        [
                            $characterId,
                            $attributeId,
                            $hasBase ? $baseValue : null,
                            $hasOverride ? $overrideValue : null,
                        ],
                    );
                } else {
                    $updates = [];
                    $updateParams = [];
                    if ($hasBase) {
                        $updates[] = 'base_value = ?';
                        $updateParams[] = $baseValue;
                    }
                    if ($hasOverride) {
                        $updates[] = 'override_value = ?';
                        $updateParams[] = $overrideValue;
                    }

                    if ($updates !== []) {
                        $updateParams[] = (int) $current->id;
                        $this->execPrepared(
                            'UPDATE character_attribute_values SET
                                ' . implode(",\n                                ", $updates) . ',
                                date_updated = NOW()
                             WHERE id = ?',
                            $updateParams,
                        );
                    }
                }

                $updatedIds[] = $attributeId;
            }

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return [
            'updated_attribute_ids' => array_values(array_unique($updatedIds)),
            'updated_count' => count(array_unique($updatedIds)),
        ];
    }

    public function persistEffectiveValues(int $characterId, array $effectiveValues, array $sources = []): int
    {
        if ($characterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'character_invalid');
        }

        $updated = 0;
        $this->begin();
        try {
            foreach ($effectiveValues as $attributeId => $value) {
                $attributeId = (int) $attributeId;
                if ($attributeId <= 0) {
                    continue;
                }

                $source = isset($sources[$attributeId]) ? trim((string) $sources[$attributeId]) : 'derived';
                if (!in_array($source, ['base', 'default', 'override', 'derived', 'fallback'], true)) {
                    $source = 'derived';
                }

                $this->execPrepared(
                    'INSERT INTO character_attribute_values SET
                        character_id = ?,
                        attribute_id = ?,
                        effective_value = ?,
                        value_source = ?,
                        last_recomputed_at = NOW(),
                        date_created = NOW()
                     ON DUPLICATE KEY UPDATE
                        effective_value = VALUES(effective_value),
                        value_source = VALUES(value_source),
                        last_recomputed_at = VALUES(last_recomputed_at),
                        date_updated = NOW()',
                    [$characterId, $attributeId, $value, $source],
                );
                $updated++;
            }

            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return $updated;
    }

    public function groupedForUi(array $rows, string $context = 'profile'): array
    {
        $groups = [
            'primary' => [],
            'secondary' => [],
            'narrative' => [],
        ];

        $visibleField = ($context === 'location') ? 'visible_in_location' : 'visible_in_profile';

        foreach ($rows as $row) {
            $group = strtolower(trim((string) ($row->attribute_group ?? '')));
            if (!array_key_exists($group, $groups)) {
                $group = 'primary';
            }

            $isVisible = (int) ($row->{$visibleField} ?? 0) === 1;
            if (!$isVisible) {
                continue;
            }

            $effective = isset($row->effective_value) ? $row->effective_value : null;
            if ($effective === null || $effective === '') {
                $effective = $row->default_value ?? $row->fallback_value ?? null;
            }

            $groups[$group][] = [
                'attribute_id' => (int) ($row->attribute_id ?? 0),
                'slug' => (string) ($row->slug ?? ''),
                'name' => (string) ($row->name ?? ''),
                'description' => (string) ($row->description ?? ''),
                'is_derived' => (int) ($row->is_derived ?? 0) === 1 ? 1 : 0,
                'allow_manual_override' => (int) ($row->allow_manual_override ?? 0) === 1 ? 1 : 0,
                'value_type' => (string) ($row->value_type ?? 'number'),
                'base_value' => $row->base_value ?? null,
                'override_value' => $row->override_value ?? null,
                'effective_value' => $effective,
                'min_value' => $row->min_value ?? null,
                'max_value' => $row->max_value ?? null,
            ];
        }

        return $groups;
    }
}
