<?php

declare(strict_types=1);

namespace App\Services;

trait ConflictServiceReadQueriesTrait
{
    private function getConflictRow(int $conflictId)
    {
        if ($conflictId <= 0) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT '
            . $this->conflictSelectColumns('c') . ', '
            . '(SELECT COUNT(*) FROM conflict_participants cp WHERE cp.conflict_id = c.id) AS participants_count, '
            . '(SELECT COUNT(*) FROM conflict_roll_log crl WHERE crl.conflict_id = c.id) AS rolls_count '
            . 'FROM conflicts c '
            . 'WHERE c.id = ? '
            . 'LIMIT 1',
            [$conflictId],
        );

        return $row ?: null;
    }

    private function getParticipantRows(int $conflictId): array
    {
        $participantTypeExpr = $this->hasColumn('conflict_participants', 'participant_type')
            ? 'cp.participant_type'
            : "'character'";
        $participantIdExpr = $this->hasColumn('conflict_participants', 'participant_id')
            ? 'cp.participant_id'
            : 'cp.character_id';
        $teamKeyExpr = $this->hasColumn('conflict_participants', 'team_key')
            ? 'cp.team_key'
            : 'NULL';
        $joinedAtExpr = $this->hasColumn('conflict_participants', 'joined_at')
            ? 'cp.joined_at'
            : 'cp.created_at';
        $leftAtExpr = $this->hasColumn('conflict_participants', 'left_at')
            ? 'cp.left_at'
            : 'NULL';

        $rows = $this->fetchPrepared(
            'SELECT cp.id, cp.conflict_id, cp.character_id, cp.participant_role, cp.is_active, cp.created_at, '
            . $participantTypeExpr . ' AS participant_type, '
            . $participantIdExpr . ' AS participant_id, '
            . $teamKeyExpr . ' AS team_key, '
            . $joinedAtExpr . ' AS joined_at, '
            . $leftAtExpr . ' AS left_at, '
            . 'c.name AS character_name, c.surname AS character_surname, c.avatar AS character_avatar, c.gender AS character_gender '
            . 'FROM conflict_participants cp '
            . 'LEFT JOIN characters c ON c.id = cp.character_id '
            . 'WHERE cp.conflict_id = ? '
            . 'ORDER BY cp.id ASC',
            [$conflictId],
        );

        return $rows ?: [];
    }

    private function getActionRows(int $conflictId): array
    {
        $actorTypeExpr = $this->hasColumn('conflict_actions', 'actor_type')
            ? 'ca.actor_type'
            : "'character'";
        $actionKindExpr = $this->hasColumn('conflict_actions', 'action_kind')
            ? 'ca.action_kind'
            : 'NULL';
        $actionModeExpr = $this->hasColumn('conflict_actions', 'action_mode')
            ? 'ca.action_mode'
            : 'NULL';
        $chatMessageExpr = $this->hasColumn('conflict_actions', 'chat_message_id')
            ? 'ca.chat_message_id'
            : 'NULL';
        $resolutionTypeExpr = $this->hasColumn('conflict_actions', 'resolution_type')
            ? 'ca.resolution_type'
            : 'NULL';
        $resolutionStatusExpr = $this->hasColumn('conflict_actions', 'resolution_status')
            ? 'ca.resolution_status'
            : 'NULL';
        $resolvedAtExpr = $this->hasColumn('conflict_actions', 'resolved_at')
            ? 'ca.resolved_at'
            : 'NULL';

        $rows = $this->fetchPrepared(
            'SELECT ca.id, ca.conflict_id, ca.actor_id, ca.action_type, ca.action_body, ca.meta_json, ca.created_at, '
            . $actorTypeExpr . ' AS actor_type, '
            . $actionKindExpr . ' AS action_kind, '
            . $actionModeExpr . ' AS action_mode, '
            . $chatMessageExpr . ' AS chat_message_id, '
            . $resolutionTypeExpr . ' AS resolution_type, '
            . $resolutionStatusExpr . ' AS resolution_status, '
            . $resolvedAtExpr . ' AS resolved_at, '
            . 'c.name AS actor_name '
            . 'FROM conflict_actions ca '
            . 'LEFT JOIN characters c ON c.id = ca.actor_id '
            . 'WHERE ca.conflict_id = ? '
            . 'ORDER BY ca.created_at DESC, ca.id DESC',
            [$conflictId],
        );

        $rows = $rows ?: [];
        if (empty($rows) || !$this->hasTable('conflict_action_targets')) {
            return $rows;
        }

        $ids = [];
        foreach ($rows as $row) {
            $actionId = (int) ($row->id ?? 0);
            if ($actionId > 0) {
                $ids[] = $actionId;
            }
        }

        if (empty($ids)) {
            return $rows;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $targetsRows = $this->fetchPrepared(
            'SELECT id, conflict_action_id, target_type, target_id, team_key, created_at '
            . 'FROM conflict_action_targets '
            . 'WHERE conflict_action_id IN (' . $placeholders . ') '
            . 'ORDER BY id ASC',
            $ids,
        );

        $targetsMap = [];
        foreach ($targetsRows ?: [] as $targetRow) {
            $actionId = (int) ($targetRow->conflict_action_id ?? 0);
            if ($actionId <= 0) {
                continue;
            }
            if (!array_key_exists($actionId, $targetsMap)) {
                $targetsMap[$actionId] = [];
            }
            $targetsMap[$actionId][] = $targetRow;
        }

        foreach ($rows as $row) {
            $actionId = (int) ($row->id ?? 0);
            $row->targets = $targetsMap[$actionId] ?? [];
        }

        return $rows;
    }

    private function getRollRows(int $conflictId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT id, conflict_id, actor_id, roll_type, die_used, base_roll, modifiers, final_result, critical_flag, margin, meta_json, timestamp '
            . 'FROM conflict_roll_log '
            . 'WHERE conflict_id = ? '
            . 'ORDER BY timestamp DESC, id DESC',
            [$conflictId],
        );

        return $rows ?: [];
    }

    private function parseDateTime($value): ?int
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $ts = strtotime($text);
        if ($ts === false) {
            return null;
        }

        return $ts;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInactivityInfo($conflict): array
    {
        $settings = $this->settingsService->getSettings();
        $warningHours = (int) ($settings[ConflictSettingsService::KEY_INACTIVITY_WARNING_HOURS] ?? 72);
        if ($warningHours < 1) {
            $warningHours = 72;
        }

        $isWarning = false;
        $hours = null;
        $last = $this->parseDateTime($conflict->last_activity_at ?? null);
        $status = strtolower((string) ($conflict->status ?? ''));

        if ($last !== null && !in_array($status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true)) {
            $hours = (float) ((time() - $last) / 3600);
            if ($hours >= $warningHours) {
                $isWarning = true;
            }
        }

        return [
            'is_warning' => $isWarning,
            'inactive_hours' => $hours !== null ? round($hours, 2) : null,
            'warning_threshold_hours' => $warningHours,
        ];
    }

    private function ensureConflictExists(int $conflictId)
    {
        $row = $this->getConflictRow($conflictId);
        if (empty($row)) {
            $this->failValidation('Conflitto non trovato', 'conflict_not_found');
        }

        return $row;
    }

    private function isParticipant(int $conflictId, int $characterId): bool
    {
        if ($conflictId <= 0 || $characterId <= 0) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT id '
            . 'FROM conflict_participants '
            . 'WHERE conflict_id = ? '
            . 'AND character_id = ? '
            . 'LIMIT 1',
            [$conflictId, $characterId],
        );

        return !empty($row);
    }

    private function detectOverlapWarnings(int $locationId): array
    {
        if ($locationId <= 0) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT id, status '
            . 'FROM conflicts '
            . 'WHERE location_id = ? '
            . 'AND status IN (?, ?, ?, ?) '
            . 'ORDER BY created_at DESC '
            . 'LIMIT 30',
            [$locationId, self::STATUS_PROPOSAL, self::STATUS_OPEN, self::STATUS_ACTIVE, self::STATUS_AWAITING],
        );

        $warnings = [];
        foreach ($rows ?: [] as $row) {
            $warnings[] = [
                'conflict_id' => (int) ($row->id ?? 0),
                'status' => (string) ($row->status ?? ''),
                'error_code' => 'conflict_overlap_detected',
            ];
        }

        return $warnings;
    }

    /**
     * @param array<string, mixed>|object $payload
     * @return array<string, mixed>
     */
    public function listConflicts($payload, int $viewerCharacterId = 0, bool $isStaff = false): array
    {
        $this->runInactivityMaintenance();

        $data = $this->payloadToArray($payload);
        $query = [];
        if (isset($data['query'])) {
            if (is_object($data['query'])) {
                $query = (array) $data['query'];
            } elseif (is_array($data['query'])) {
                $query = $data['query'];
            }
        }
        $source = !empty($query) ? $query : $data;

        $status = trim((string) ($source['status'] ?? ''));
        $locationId = (int) ($source['location_id'] ?? 0);
        $origin = trim((string) ($source['origin'] ?? ($source['conflict_origin'] ?? '')));

        $where = ['1 = 1'];
        $params = [];
        if ($status !== '') {
            $where[] = 'c.status = ?';
            $params[] = $this->normalizeStatus($status, self::STATUS_OPEN);
        }
        if ($locationId > 0) {
            $where[] = 'c.location_id = ?';
            $params[] = $locationId;
        }
        if ($origin !== '' && $this->hasColumn('conflicts', 'conflict_origin')) {
            $where[] = 'c.conflict_origin = ?';
            $params[] = $this->normalizeConflictOrigin($origin);
        }

        if (!$isStaff && $viewerCharacterId > 0) {
            $where[] = '('
                . 'c.opened_by = ?'
                . ' OR EXISTS ('
                . 'SELECT 1 FROM conflict_participants cpv '
                . 'WHERE cpv.conflict_id = c.id AND cpv.character_id = ?'
                . ')'
                . ')';
            $params[] = $viewerCharacterId;
            $params[] = $viewerCharacterId;
        }

        $rows = $this->fetchPrepared(
            'SELECT '
            . $this->conflictSelectColumns('c') . ', '
            . '(SELECT COUNT(*) FROM conflict_participants cp WHERE cp.conflict_id = c.id) AS participants_count, '
            . '(SELECT COUNT(*) FROM conflict_roll_log crl WHERE crl.conflict_id = c.id) AS rolls_count '
            . 'FROM conflicts c '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'ORDER BY c.created_at DESC, c.id DESC '
            . 'LIMIT 300',
            $params,
        );

        $dataset = [];
        foreach ($rows ?: [] as $row) {
            $info = $this->buildInactivityInfo($row);
            $row->inactivity_warning = !empty($info['is_warning']) ? 1 : 0;
            $row->inactivity_hours = $info['inactive_hours'];
            $row->inactivity_warning_hours = $info['warning_threshold_hours'];
            $row->overlap_warnings = $this->detectOverlapWarnings((int) ($row->location_id ?? 0));
            $dataset[] = $row;
        }

        return ['dataset' => $dataset];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConflict(int $conflictId): array
    {
        if ($conflictId <= 0) {
            $this->failValidation('Conflitto non valido', 'conflict_not_found');
        }

        $conflict = $this->ensureConflictExists($conflictId);
        $participants = $this->getParticipantRows($conflictId);
        $actions = $this->getActionRows($conflictId);
        $rolls = $this->getRollRows($conflictId);
        $inactivityInfo = $this->buildInactivityInfo($conflict);

        return [
            'conflict' => $conflict,
            'participants' => $participants,
            'actions' => $actions,
            'rolls' => $rolls,
            'inactivity' => $inactivityInfo,
            'overlap_warnings' => $this->detectOverlapWarnings((int) ($conflict->location_id ?? 0)),
        ];
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function locationFeed($payload, int $viewerCharacterId, bool $isStaff = false): array
    {
        $this->runInactivityMaintenance();

        $data = $this->payloadToArray($payload);
        $locationId = (int) ($data['location_id'] ?? 0);
        if ($locationId <= 0) {
            $this->failValidation('Location non valida', 'location_invalid');
        }

        $rows = $this->fetchPrepared(
            'SELECT '
            . $this->conflictSelectColumns('c') . ', '
            . '(SELECT COUNT(*) FROM conflict_participants cp WHERE cp.conflict_id = c.id) AS participants_count, '
            . '(SELECT COUNT(*) FROM conflict_roll_log crl WHERE crl.conflict_id = c.id) AS rolls_count '
            . 'FROM conflicts c '
            . 'WHERE c.location_id = ? '
            . 'AND c.status IN (?, ?, ?, ?) '
            . 'ORDER BY c.created_at DESC, c.id DESC '
            . 'LIMIT 100',
            [$locationId, self::STATUS_PROPOSAL, self::STATUS_OPEN, self::STATUS_ACTIVE, self::STATUS_AWAITING],
        );

        $proposals = [];
        $active = [];

        foreach ($rows ?: [] as $row) {
            $conflictId = (int) ($row->id ?? 0);
            $isParticipant = $viewerCharacterId > 0 ? $this->isParticipant($conflictId, $viewerCharacterId) : false;
            $row->viewer_is_participant = $isParticipant ? 1 : 0;
            $row->viewer_can_respond_proposal = (
                strtolower((string) ($row->status ?? '')) === self::STATUS_PROPOSAL
                && ($isParticipant || $isStaff)
            ) ? 1 : 0;

            $lastAction = $this->firstPrepared(
                'SELECT action_type, action_body, created_at '
                . 'FROM conflict_actions '
                . 'WHERE conflict_id = ? '
                . 'ORDER BY created_at DESC, id DESC '
                . 'LIMIT 1',
                [$conflictId],
            );
            $row->last_action = $lastAction ?: null;

            $info = $this->buildInactivityInfo($row);
            $row->inactivity_warning = !empty($info['is_warning']) ? 1 : 0;
            $row->inactivity_hours = $info['inactive_hours'];

            if (strtolower((string) ($row->status ?? '')) === self::STATUS_PROPOSAL) {
                $proposals[] = $row;
            } else {
                $active[] = $row;
            }
        }

        return [
            'location_id' => $locationId,
            'active' => $active,
            'proposals' => $proposals,
            'counts' => [
                'active' => count($active),
                'proposals' => count($proposals),
            ],
            'overlap_warnings' => $this->detectOverlapWarnings($locationId),
            'settings' => [
                'overlap_policy' => (string) ($this->settingsService->getSettings()[ConflictSettingsService::KEY_OVERLAP_POLICY] ?? 'warn_only'),
            ],
        ];
    }
}

