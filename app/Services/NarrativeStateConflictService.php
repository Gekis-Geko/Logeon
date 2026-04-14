<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class NarrativeStateConflictService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function execPrepared(string $sql, array $params = []): void
    {
        $this->db->executePrepared($sql, $params);
    }

    /**
     * @return array{sql:string,params:array<int,mixed>}
     */
    private function sceneWhereClause($sceneId): array
    {
        $sceneId = ($sceneId === null) ? null : (int) $sceneId;
        if ($sceneId === null || $sceneId <= 0) {
            return [
                'sql' => ' AND ans.scene_id IS NULL',
                'params' => [],
            ];
        }
        return [
            'sql' => ' AND ans.scene_id = ?',
            'params' => [$sceneId],
        ];
    }

    private function activeRows(string $targetType, int $targetId, $sceneId): array
    {
        $targetType = trim((string) $targetType);
        $targetId = (int) $targetId;
        if ($targetType === '' || $targetId <= 0) {
            return [];
        }

        $sceneWhere = $this->sceneWhereClause($sceneId);
        $rows = $this->fetchPrepared(
            'SELECT
                ans.id,
                ans.state_id,
                ans.stacks,
                ans.intensity,
                ans.duration_value,
                ans.duration_unit,
                ans.scene_id,
                ans.target_type,
                ans.target_id,
                ns.conflict_group,
                ns.priority
             FROM applied_narrative_states ans
             INNER JOIN narrative_states ns ON ns.id = ans.state_id
             WHERE ans.status = "active"
               AND ans.target_type = ?
               AND ans.target_id = ?'
               . $sceneWhere['sql'] . '
             ORDER BY ns.priority DESC, ans.id ASC',
            array_merge([$targetType, $targetId], $sceneWhere['params']),
        );

        return $rows ?: [];
    }

    public function markRemovedByIds(array $ids, string $reason = 'conflict_replaced'): void
    {
        $list = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $list[] = $id;
            }
        }
        if (empty($list)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($list), '?'));
        $this->execPrepared(
            'UPDATE applied_narrative_states SET
                status = "removed",
                removed_at = NOW(),
                removal_reason = ?,
                date_updated = NOW()
             WHERE id IN (' . $placeholders . ')
               AND status = "active"',
            array_merge([$reason], $list),
        );
    }

    public function evaluate($state, string $targetType, int $targetId, $sceneId): array
    {
        $rows = $this->activeRows($targetType, $targetId, $sceneId);
        if (empty($rows)) {
            return [
                'same_state' => [],
                'conflicts_removed' => [],
            ];
        }

        $stateId = (int) ($state->id ?? 0);
        $newConflictGroup = trim((string) ($state->conflict_group ?? ''));
        $newPriority = (int) ($state->priority ?? 0);

        $sameState = [];
        $conflicts = [];

        foreach ($rows as $row) {
            $rowStateId = (int) ($row->state_id ?? 0);
            if ($rowStateId === $stateId) {
                $sameState[] = $row;
                continue;
            }

            $rowConflictGroup = trim((string) ($row->conflict_group ?? ''));
            if ($newConflictGroup !== '' && $rowConflictGroup !== '' && $rowConflictGroup === $newConflictGroup) {
                $conflicts[] = $row;
            }
        }

        if (!empty($conflicts)) {
            $toRemove = [];
            foreach ($conflicts as $row) {
                $existingPriority = (int) ($row->priority ?? 0);
                if ($existingPriority > $newPriority) {
                    throw AppError::validation(
                        'Conflitto stato narrativo: priorita insufficiente',
                        [],
                        'state_conflict_blocked',
                    );
                }
                $toRemove[] = (int) ($row->id ?? 0);
            }
            $this->markRemovedByIds($toRemove, 'conflict_replaced');
        }

        return [
            'same_state' => $sameState,
            'conflicts_removed' => $conflicts,
        ];
    }
}
