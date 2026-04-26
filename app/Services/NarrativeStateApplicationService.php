<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class NarrativeStateApplicationService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NarrativeStateService|null */
    private $narrativeStateService = null;
    /** @var NarrativeStateConflictService|null */
    private $narrativeStateConflictService = null;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    public function setNarrativeStateService(NarrativeStateService $service = null)
    {
        $this->narrativeStateService = $service;
        return $this;
    }

    public function setNarrativeStateConflictService(NarrativeStateConflictService $service = null)
    {
        $this->narrativeStateConflictService = $service;
        return $this;
    }

    private function narrativeStateService(): NarrativeStateService
    {
        if ($this->narrativeStateService instanceof NarrativeStateService) {
            return $this->narrativeStateService;
        }

        $this->narrativeStateService = new NarrativeStateService($this->db);
        return $this->narrativeStateService;
    }

    private function narrativeStateConflictService(): NarrativeStateConflictService
    {
        if ($this->narrativeStateConflictService instanceof NarrativeStateConflictService) {
            return $this->narrativeStateConflictService;
        }

        $this->narrativeStateConflictService = new NarrativeStateConflictService($this->db);
        return $this->narrativeStateConflictService;
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

    private function execPreparedCount(string $sql, array $params = []): int
    {
        $this->execPrepared($sql, $params);
        $row = $this->firstPrepared('SELECT ROW_COUNT() AS count');
        return (int) ($row->count ?? 0);
    }

    private function begin(): void
    {
        $this->db->query('START TRANSACTION');
    }

    private function commit(): void
    {
        $this->db->query('COMMIT');
    }

    private function rollback(): void
    {
        try {
            $this->db->query('ROLLBACK');
        } catch (\Throwable $error) {
            // no-op
        }
    }

    private function failValidation(string $message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation($message, [], $errorCode);
    }

    private function normalizeInt($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    private function normalizeFloat($value, float $default = 0.0): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $raw = str_replace(',', '.', trim((string) $value));
        if (!is_numeric($raw)) {
            return $default;
        }
        return (float) $raw;
    }

    private function normalizeEnum($value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || !in_array($value, $allowed, true)) {
            return $fallback;
        }
        return $value;
    }

    private function normalizeNullableText($value): ?string
    {
        $value = trim((string) $value);
        return ($value === '') ? null : $value;
    }

    private function resolveDuration($durationValue, $durationUnit): array
    {
        $value = $this->normalizeInt($durationValue, 0);
        $unit = $this->normalizeEnum($durationUnit, ['turn', 'minute', 'hour', 'day', 'scene'], 'scene');

        if ($value < 1) {
            $value = 0;
        }

        if ($unit === 'scene' || $unit === 'turn' || $value <= 0) {
            return [
                'value' => ($value > 0 ? $value : null),
                'unit' => $unit,
                'expires_at' => null,
            ];
        }

        $expiresAt = null;
        try {
            $dt = new \DateTimeImmutable('now');
            if ($unit === 'minute') {
                $dt = $dt->modify('+' . $value . ' minutes');
            } elseif ($unit === 'hour') {
                $dt = $dt->modify('+' . $value . ' hours');
            } elseif ($unit === 'day') {
                $dt = $dt->modify('+' . $value . ' days');
            }
            $expiresAt = $dt->format('Y-m-d H:i:s');
        } catch (\Throwable $error) {
            $expiresAt = null;
        }

        return [
            'value' => $value,
            'unit' => $unit,
            'expires_at' => $expiresAt,
        ];
    }

    private function targetSceneCondition($sceneId): array
    {
        $sceneId = $this->normalizeInt($sceneId, 0);
        if ($sceneId <= 0) {
            return [
                'sql' => 'scene_id IS NULL',
                'params' => [],
            ];
        }
        return [
            'sql' => 'scene_id = ?',
            'params' => [$sceneId],
        ];
    }

    private function fetchAppliedById(int $id)
    {
        return $this->firstPrepared(
            'SELECT
                ans.id,
                ans.state_id,
                ans.source_ability_id,
                ans.source_event_id,
                ans.scene_id,
                ans.target_type,
                ans.target_id,
                ans.applier_character_id,
                ans.intensity,
                ans.stacks,
                ans.duration_value,
                ans.duration_unit,
                ans.status,
                ans.visibility,
                ans.meta_json,
                ans.started_at,
                ans.expires_at,
                ans.removed_at,
                ans.removal_reason,
                ns.code AS state_code,
                ns.name AS state_name,
                ns.conflict_group AS state_conflict_group,
                ns.priority AS state_priority
             FROM applied_narrative_states ans
             INNER JOIN narrative_states ns ON ns.id = ans.state_id
             WHERE ans.id = ?
             LIMIT 1',
            [$id],
        );
    }

    private function updateExistingState(
        int $appliedStateId,
        int $stacks,
        float $intensity,
        array $duration,
        ?string $metaJson,
    ): void {
        $this->execPrepared(
            'UPDATE applied_narrative_states SET
                stacks = ?,
                intensity = ?,
                duration_value = ?,
                duration_unit = ?,
                started_at = NOW(),
                expires_at = ?,
                meta_json = ?,
                status = "active",
                removed_at = NULL,
                removal_reason = NULL,
                date_updated = NOW()
             WHERE id = ?
             LIMIT 1',
            [
                $stacks,
                $intensity,
                $duration['value'] ?? null,
                $duration['unit'] ?? null,
                $duration['expires_at'] ?? null,
                $metaJson,
                $appliedStateId,
            ],
        );
    }

    private function insertState(
        int $stateId,
        int $sourceAbilityId,
        int $sourceEventId,
        int $sceneId,
        string $targetType,
        int $targetId,
        int $applierCharacterId,
        float $intensity,
        int $stacks,
        array $duration,
        ?string $metaJson,
        string $visibility,
    ): int {
        $this->execPrepared(
            'INSERT INTO applied_narrative_states
            (state_id, source_ability_id, source_event_id, scene_id, target_type, target_id, applier_character_id, intensity, stacks, duration_value, duration_unit, status, visibility, meta_json, started_at, expires_at, date_created)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", ?, ?, NOW(), ?, NOW())',
            [
                $stateId,
                ($sourceAbilityId > 0) ? $sourceAbilityId : null,
                ($sourceEventId > 0) ? $sourceEventId : null,
                ($sceneId > 0) ? $sceneId : null,
                $targetType,
                $targetId,
                $applierCharacterId,
                $intensity,
                $stacks,
                $duration['value'] ?? null,
                $duration['unit'] ?? null,
                $visibility,
                $metaJson,
                $duration['expires_at'] ?? null,
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    private function removeExistingSameState(array $rows): void
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if (!empty($ids)) {
            $this->narrativeStateConflictService()->markRemovedByIds($ids, 'state_replaced');
        }
    }

    public function applyState(array $payload): array
    {
        $stateId = $this->normalizeInt($payload['state_id'] ?? 0, 0);
        $stateCode = trim((string) ($payload['state_code'] ?? $payload['state'] ?? ''));
        $state = $this->narrativeStateService()->getByIdOrCode($stateId, $stateCode, true);

        $targetType = $this->normalizeEnum(
            $payload['target_type'] ?? 'character',
            ['character', 'scene', 'location', 'faction', 'conflict', 'event'],
            'character',
        );
        $targetId = $this->normalizeInt($payload['target_id'] ?? 0, 0);
        $sceneId = $this->normalizeInt($payload['scene_id'] ?? 0, 0);
        $applierCharacterId = $this->normalizeInt($payload['applier_character_id'] ?? 0, 0);
        $sourceAbilityId = $this->normalizeInt($payload['source_ability_id'] ?? 0, 0);
        $sourceEventId = $this->normalizeInt($payload['source_event_id'] ?? 0, 0);
        $visibility = $this->normalizeEnum(
            $payload['visibility'] ?? 'public',
            ['public', 'private', 'staff_only', 'hidden'],
            'public',
        );

        $scope = strtolower(trim((string) ($state->scope ?? 'character')));
        // Scope check only applies to classic character/scene targets
        if ($scope === 'character' && in_array($targetType, ['scene', 'location', 'faction', 'conflict'], true)) {
            $this->failValidation('Stato applicabile solo al personaggio', 'state_apply_failed');
        }
        if ($scope === 'scene' && $targetType === 'character') {
            $this->failValidation('Stato applicabile solo alla scena', 'state_apply_failed');
        }

        if ($targetType === 'scene') {
            if ($targetId <= 0) {
                $targetId = $sceneId;
            }
            if ($sceneId <= 0) {
                $sceneId = $targetId;
            }
        }

        if ($targetId <= 0) {
            $this->failValidation('Target stato non valido', 'state_apply_failed');
        }
        if ($applierCharacterId <= 0) {
            $this->failValidation('Applicatore stato non valido', 'state_apply_failed');
        }

        $intensity = $this->normalizeFloat($payload['intensity'] ?? null, 1.0);
        if ($intensity <= 0) {
            $intensity = 1.0;
        }

        $duration = $this->resolveDuration(
            $payload['duration_value'] ?? null,
            $payload['duration_unit'] ?? null,
        );
        $metaJson = $this->normalizeNullableText($payload['meta_json'] ?? null);
        if ($metaJson === null) {
            $metaJson = '{}';
        }

        $stackMode = $this->normalizeEnum($state->stack_mode ?? 'replace', ['replace', 'stack', 'refresh'], 'replace');
        $maxStacks = $this->normalizeInt($state->max_stacks ?? 1, 1);
        if ($maxStacks < 1) {
            $maxStacks = 1;
        }

        $this->begin();
        try {
            $conflicts = $this->narrativeStateConflictService()->evaluate($state, $targetType, $targetId, $sceneId);
            $sameState = is_array($conflicts['same_state'] ?? null) ? $conflicts['same_state'] : [];

            $action = 'insert';
            $appliedStateId = 0;

            if (!empty($sameState)) {
                $first = $sameState[0];
                $appliedStateId = (int) ($first->id ?? 0);

                if ($stackMode === 'stack' && $appliedStateId > 0) {
                    $nextStacks = (int) ($first->stacks ?? 1) + 1;
                    if ($nextStacks > $maxStacks) {
                        $nextStacks = $maxStacks;
                    }
                    $currentIntensity = $this->normalizeFloat($first->intensity ?? 0, 0.0);
                    if ($currentIntensity > $intensity) {
                        $intensity = $currentIntensity;
                    }
                    $this->updateExistingState($appliedStateId, $nextStacks, $intensity, $duration, $metaJson);
                    $action = 'stacked';
                } elseif ($stackMode === 'refresh' && $appliedStateId > 0) {
                    $currentStacks = (int) ($first->stacks ?? 1);
                    if ($currentStacks < 1) {
                        $currentStacks = 1;
                    }
                    $this->updateExistingState($appliedStateId, $currentStacks, $intensity, $duration, $metaJson);
                    $action = 'refreshed';
                } else {
                    $this->removeExistingSameState($sameState);
                    $appliedStateId = 0;
                }
            }

            if ($appliedStateId <= 0) {
                $appliedStateId = $this->insertState(
                    (int) $state->id,
                    $sourceAbilityId,
                    $sourceEventId,
                    $sceneId,
                    $targetType,
                    $targetId,
                    $applierCharacterId,
                    $intensity,
                    1,
                    $duration,
                    $metaJson,
                    $visibility,
                );
                if ($appliedStateId <= 0) {
                    $this->failValidation('Applicazione stato non riuscita', 'state_apply_failed');
                }
            }

            $this->commit();

            $applied = $this->fetchAppliedById($appliedStateId);
            if (empty($applied)) {
                $this->failValidation('Applicazione stato non riuscita', 'state_apply_failed');
            }

            return [
                'status' => 'ok',
                'action' => $action,
                'applied_state' => $applied,
            ];
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function removeState(array $payload): array
    {
        $appliedStateId = $this->normalizeInt($payload['applied_state_id'] ?? 0, 0);
        $reason = $this->normalizeNullableText($payload['reason'] ?? null) ?? 'manual_remove';

        $this->begin();
        try {
            $affected = 0;
            if ($appliedStateId > 0) {
                $affected = $this->execPreparedCount(
                    'UPDATE applied_narrative_states SET
                        status = "removed",
                        removed_at = NOW(),
                        removal_reason = ?,
                        date_updated = NOW()
                     WHERE id = ?
                       AND status = "active"
                     LIMIT 1',
                    [$reason, $appliedStateId],
                );
            } else {
                $stateId = $this->normalizeInt($payload['state_id'] ?? 0, 0);
                $stateCode = trim((string) ($payload['state_code'] ?? $payload['state'] ?? ''));
                $targetType = $this->normalizeEnum(
                    $payload['target_type'] ?? 'character',
                    ['character', 'scene', 'location', 'faction', 'conflict', 'event'],
                    'character',
                );
                $targetId = $this->normalizeInt($payload['target_id'] ?? 0, 0);
                $sceneId = $this->normalizeInt($payload['scene_id'] ?? 0, 0);

                if ($targetId <= 0) {
                    $this->failValidation('Target stato non valido', 'state_remove_failed');
                }

                $state = $this->narrativeStateService()->findByIdOrCode($stateId, $stateCode, false);
                if (empty($state)) {
                    $this->failValidation('Stato narrativo non trovato', 'state_not_found');
                }

                $sceneCondition = $this->targetSceneCondition($sceneId);
                $whereSql = 'state_id = ?
                       AND target_type = ?
                       AND target_id = ?
                       AND ' . $sceneCondition['sql'] . '
                       AND status = "active"';
                $whereParams = array_merge(
                    [(int) $state->id, $targetType, $targetId],
                    $sceneCondition['params'],
                );

                $affected = $this->execPreparedCount(
                    'UPDATE applied_narrative_states SET
                        status = "removed",
                        removed_at = NOW(),
                        removal_reason = ?,
                        date_updated = NOW()
                     WHERE ' . $whereSql,
                    array_merge([$reason], $whereParams),
                );
            }
            if ($affected <= 0) {
                $this->failValidation('Rimozione stato non riuscita', 'state_remove_failed');
            }

            $this->commit();
            return [
                'status' => 'ok',
                'removed_count' => $affected,
            ];
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function getActiveForCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT
                ans.id            AS applied_id,
                ans.state_id,
                ans.stacks,
                ans.intensity,
                ans.started_at    AS applied_at,
                ans.expires_at,
                ns.code,
                ns.name,
                ns.description,
                ns.category,
                ns.scope,
                ns.stack_mode,
                ns.max_stacks,
                ns.conflict_group,
                ns.priority
             FROM applied_narrative_states ans
             INNER JOIN narrative_states ns ON ns.id = ans.state_id
             WHERE ans.target_type = "character"
               AND ans.target_id = ?
               AND ans.status = "active"
               AND ns.is_active = 1
               AND ns.visible_to_players = 1
             ORDER BY ns.priority DESC, ans.started_at ASC',
            [$characterId],
        );
        return $rows ?: [];
    }
}
