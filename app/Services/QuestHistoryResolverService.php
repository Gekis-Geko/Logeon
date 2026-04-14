<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class QuestHistoryResolverService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var QuestResolverService */
    private $resolver;
    /** @var QuestClosureService */
    private $closureService;
    /** @var QuestRewardService */
    private $rewardService;

    public function __construct(
        DbAdapterInterface $db = null,
        QuestResolverService $resolver = null,
        QuestClosureService $closureService = null,
        QuestRewardService $rewardService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->resolver = $resolver ?: new QuestResolverService($this->db);
        $this->closureService = $closureService ?: new QuestClosureService($this->db, $this->resolver);
        $this->rewardService = $rewardService ?: new QuestRewardService($this->db);
    }

    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    private function placeholders(int $count): string
    {
        if ($count <= 0) {
            return '';
        }

        return implode(',', array_fill(0, $count, '?'));
    }

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function normalizeStatusFilter(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['locked', 'available', 'active', 'completed', 'failed', 'cancelled', 'expired'];
        return in_array($status, $allowed, true) ? $status : '';
    }

    private function normalizeIntensityLevel($value, string $fallback = 'STANDARD', bool $nullable = false): ?string
    {
        $raw = strtoupper(trim((string) $value));
        if ($raw === '') {
            return $nullable ? null : $fallback;
        }
        $allowed = ['CHILL', 'SOFT', 'STANDARD', 'HIGH', 'CRITICAL'];
        if (in_array($raw, $allowed, true)) {
            return $raw;
        }
        return $nullable ? null : $fallback;
    }

    private function normalizeIntensityVisibility($value): string
    {
        $raw = strtolower(trim((string) $value));
        return in_array($raw, ['visible', 'hidden'], true) ? $raw : 'visible';
    }

    private function normalizeDateTime($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    private function buildAssigneeLabel(string $assigneeType, int $assigneeId): string
    {
        $assigneeType = strtolower(trim($assigneeType));
        if ($assigneeType === 'world') {
            return 'Mondo';
        }
        if ($assigneeType === 'character' && $assigneeId > 0) {
            $row = $this->firstPrepared(
                'SELECT name, surname FROM characters WHERE id = ? LIMIT 1',
                [$assigneeId],
            );
            if (!empty($row)) {
                $label = trim((string) ($row->name ?? '') . ' ' . (string) ($row->surname ?? ''));
                if ($label !== '') {
                    return $label;
                }
            }
            return 'Personaggio #' . $assigneeId;
        }
        if ($assigneeType === 'faction' && $assigneeId > 0) {
            $row = $this->firstPrepared(
                'SELECT name FROM factions WHERE id = ? LIMIT 1',
                [$assigneeId],
            );
            if (!empty($row) && isset($row->name)) {
                return 'Fazione: ' . (string) $row->name;
            }
            return 'Fazione #' . $assigneeId;
        }
        if ($assigneeType === 'guild' && $assigneeId > 0) {
            $row = $this->firstPrepared(
                'SELECT name FROM guilds WHERE id = ? LIMIT 1',
                [$assigneeId],
            );
            if (!empty($row) && isset($row->name)) {
                return 'Gilda: ' . (string) $row->name;
            }
            return 'Gilda #' . $assigneeId;
        }

        return ucfirst($assigneeType) . ($assigneeId > 0 ? (' #' . $assigneeId) : '');
    }

    private function hasViewerAccess(array $instance, int $viewerCharacterId, bool $isStaff, array $viewerFactionIds, array $viewerGuildIds): bool
    {
        if ($isStaff) {
            return true;
        }

        $assigneeType = strtolower(trim((string) ($instance['assignee_type'] ?? 'character')));
        $assigneeId = (int) ($instance['assignee_id'] ?? 0);

        if ($assigneeType === 'world') {
            return true;
        }
        if ($assigneeType === 'character') {
            return $assigneeId > 0 && $assigneeId === $viewerCharacterId;
        }
        if ($assigneeType === 'faction') {
            return $assigneeId > 0 && in_array($assigneeId, $viewerFactionIds, true);
        }
        if ($assigneeType === 'guild') {
            return $assigneeId > 0 && in_array($assigneeId, $viewerGuildIds, true);
        }

        return false;
    }

    private function decorateHistoryRow(array $row, int $viewerCharacterId, bool $isStaff): array
    {
        $instanceId = (int) ($row['id'] ?? 0);
        $status = strtolower(trim((string) ($row['current_status'] ?? '')));

        $report = $this->closureService->getByInstance($instanceId);
        if ($report === null && in_array($status, ['completed', 'failed', 'cancelled', 'expired'], true)) {
            $report = $this->closureService->ensureMinimalReport($instanceId, $status, 0, 'history_fallback');
        }

        $rewards = $this->rewardService->listByInstance($instanceId, $viewerCharacterId, $isStaff, 100, 1);
        $rewardRows = isset($rewards['rows']) && is_array($rewards['rows']) ? $rewards['rows'] : [];

        $summaryPublic = '';
        $summaryPrivate = '';
        $outcomeLabel = '';
        $closureType = '';
        $closedAt = null;
        $playerVisible = 0;
        $staffNotes = null;

        if (is_array($report)) {
            $summaryPublic = (string) ($report['summary_public'] ?? '');
            $summaryPrivate = (string) ($report['summary_private'] ?? '');
            $outcomeLabel = (string) ($report['outcome_label'] ?? '');
            $closureType = (string) ($report['closure_type'] ?? '');
            $closedAt = $report['closed_at'] ?? null;
            $playerVisible = (int) ($report['player_visible'] ?? 0) === 1 ? 1 : 0;
            $staffNotes = $report['staff_notes'] ?? null;
        }

        if ($summaryPublic === '' && !$isStaff) {
            $summaryPublic = 'Report di chiusura non disponibile.';
        }
        if ($outcomeLabel === '') {
            $outcomeLabel = 'Chiusura tecnica';
        }

        $definitionIntensity = $this->normalizeIntensityLevel($row['definition_intensity_level'] ?? 'STANDARD', 'STANDARD');
        $instanceIntensity = $this->normalizeIntensityLevel($row['instance_intensity_level'] ?? null, 'STANDARD', true);
        $intensityVisibility = $this->normalizeIntensityVisibility($row['intensity_visibility'] ?? 'visible');
        $effectiveIntensity = $instanceIntensity !== null ? $instanceIntensity : $definitionIntensity;
        if (!$isStaff && $intensityVisibility === 'hidden') {
            $definitionIntensity = null;
            $instanceIntensity = null;
            $effectiveIntensity = null;
        }

        return [
            'quest_instance_id' => $instanceId,
            'quest_definition_id' => (int) ($row['quest_definition_id'] ?? 0),
            'quest_title' => (string) ($row['quest_title'] ?? ''),
            'quest_slug' => (string) ($row['quest_slug'] ?? ''),
            'quest_type' => (string) ($row['quest_type'] ?? ''),
            'status' => $status,
            'assignee_type' => (string) ($row['assignee_type'] ?? ''),
            'assignee_id' => (int) ($row['assignee_id'] ?? 0),
            'assignee_label' => $this->buildAssigneeLabel((string) ($row['assignee_type'] ?? ''), (int) ($row['assignee_id'] ?? 0)),
            'started_at' => $row['started_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'failed_at' => $row['failed_at'] ?? null,
            'date_created' => $row['date_created'] ?? null,
            'closure_type' => $closureType,
            'outcome_label' => $outcomeLabel,
            'summary_public' => $summaryPublic,
            'summary_private' => $isStaff ? $summaryPrivate : null,
            'staff_notes' => $isStaff ? $staffNotes : null,
            'closed_at' => $closedAt,
            'player_visible' => $playerVisible,
            'intensity_level' => $effectiveIntensity,
            'definition_intensity_level' => $definitionIntensity,
            'instance_intensity_level' => $instanceIntensity,
            'intensity_visibility' => $intensityVisibility,
            'rewards' => $rewardRows,
        ];
    }

    public function listForViewer(int $viewerCharacterId, bool $isStaff, array $filters = [], int $limit = 20, int $page = 1): array
    {
        $this->resolver->ensureEnabled();

        $viewerCharacterId = (int) $viewerCharacterId;
        if ($viewerCharacterId <= 0) {
            throw AppError::validation('Personaggio non valido', [], 'quest_history_forbidden');
        }

        $viewerFactionIds = $this->resolver->viewerFactionIds($viewerCharacterId);
        $viewerGuildIds = $this->resolver->viewerGuildIds($viewerCharacterId);

        $limit = max(1, min(100, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        $status = $this->normalizeStatusFilter((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'i.current_status = ?';
            $params[] = $status;
        } else {
            $where[] = 'i.current_status IN ("completed","failed","cancelled","expired")';
        }

        $from = $this->normalizeDateTime($filters['from'] ?? ($filters['period_from'] ?? null));
        if ($from !== null) {
            $where[] = 'COALESCE(r.closed_at, i.completed_at, i.failed_at, i.date_updated, i.date_created) >= ?';
            $params[] = $from;
        }

        $to = $this->normalizeDateTime($filters['to'] ?? ($filters['period_to'] ?? null));
        if ($to !== null) {
            $where[] = 'COALESCE(r.closed_at, i.completed_at, i.failed_at, i.date_updated, i.date_created) <= ?';
            $params[] = $to;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(d.title LIKE ? OR d.slug LIKE ? OR r.outcome_label LIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        if (!$isStaff) {
            $allowed = [];
            $allowed[] = '(i.assignee_type = "world")';
            $allowed[] = '(i.assignee_type = "character" AND i.assignee_id = ?)';
            $params[] = $viewerCharacterId;

            if (!empty($viewerFactionIds)) {
                $allowed[] = '(i.assignee_type = "faction" AND i.assignee_id IN (' . $this->placeholders(count($viewerFactionIds)) . '))';
                foreach ($viewerFactionIds as $factionId) {
                    $params[] = (int) $factionId;
                }
            }
            if (!empty($viewerGuildIds)) {
                $allowed[] = '(i.assignee_type = "guild" AND i.assignee_id IN (' . $this->placeholders(count($viewerGuildIds)) . '))';
                foreach ($viewerGuildIds as $guildId) {
                    $params[] = (int) $guildId;
                }
            }

            $where[] = '(' . implode(' OR ', $allowed) . ')';
            $where[] = 'd.visibility <> "staff_only"';
            $where[] = '(r.player_visible = 1 OR r.player_visible IS NULL)';
        }

        $whereSql = ($where === []) ? '' : ('WHERE ' . implode(' AND ', $where));

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM quest_instances i
             INNER JOIN quest_definitions d ON d.id = i.quest_definition_id
             LEFT JOIN quest_closure_reports r ON r.quest_instance_id = i.id
             ' . $whereSql,
            $params,
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT i.*, d.title AS quest_title, d.slug AS quest_slug, d.quest_type,
                    d.intensity_level AS definition_intensity_level,
                    d.intensity_visibility,
                    r.id AS closure_report_id, r.player_visible, r.outcome_label, r.closed_at
             FROM quest_instances i
             INNER JOIN quest_definitions d ON d.id = i.quest_definition_id
             LEFT JOIN quest_closure_reports r ON r.quest_instance_id = i.id
             ' . $whereSql . '
             ORDER BY COALESCE(r.closed_at, i.completed_at, i.failed_at, i.date_updated, i.date_created) DESC, i.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $rows = is_array($rows) ? $rows : [];
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            if (!$this->hasViewerAccess($item, $viewerCharacterId, $isStaff, $viewerFactionIds, $viewerGuildIds)) {
                continue;
            }
            $out[] = $this->decorateHistoryRow($item, $viewerCharacterId, $isStaff);
        }

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $out,
        ];
    }

    public function getForViewer(int $instanceId, int $viewerCharacterId, bool $isStaff): array
    {
        $this->resolver->ensureEnabled();

        $instanceId = (int) $instanceId;
        $viewerCharacterId = (int) $viewerCharacterId;
        if ($instanceId <= 0 || $viewerCharacterId <= 0) {
            throw AppError::validation('Richiesta non valida', [], 'quest_history_forbidden');
        }

        $detail = $this->resolver->getInstanceDetail($instanceId);
        $instance = isset($detail['instance']) && is_array($detail['instance']) ? $detail['instance'] : [];

        $viewerFactionIds = $this->resolver->viewerFactionIds($viewerCharacterId);
        $viewerGuildIds = $this->resolver->viewerGuildIds($viewerCharacterId);

        if (!$this->hasViewerAccess($instance, $viewerCharacterId, $isStaff, $viewerFactionIds, $viewerGuildIds)) {
            throw AppError::unauthorized('Non puoi consultare questa quest', [], 'quest_history_forbidden');
        }

        $historyRow = $this->decorateHistoryRow([
            'id' => (int) ($instance['id'] ?? 0),
            'quest_definition_id' => (int) ($instance['quest_definition_id'] ?? 0),
            'quest_title' => (string) (($detail['definition']['title'] ?? '') ?: ''),
            'quest_slug' => (string) (($detail['definition']['slug'] ?? '') ?: ''),
            'quest_type' => (string) (($detail['definition']['quest_type'] ?? '') ?: ''),
            'definition_intensity_level' => (string) (($detail['definition']['intensity_level'] ?? '') ?: 'STANDARD'),
            'intensity_visibility' => (string) (($detail['definition']['intensity_visibility'] ?? '') ?: 'visible'),
            'instance_intensity_level' => (string) (($detail['instance']['instance_intensity_level'] ?? ($detail['instance']['intensity_level'] ?? '')) ?: ''),
            'assignee_type' => (string) ($instance['assignee_type'] ?? ''),
            'assignee_id' => (int) ($instance['assignee_id'] ?? 0),
            'current_status' => (string) ($instance['current_status'] ?? ''),
            'started_at' => $instance['started_at'] ?? null,
            'completed_at' => $instance['completed_at'] ?? null,
            'failed_at' => $instance['failed_at'] ?? null,
            'date_created' => $instance['date_created'] ?? null,
        ], $viewerCharacterId, $isStaff);

        $logs = $this->resolver->listLogs([
            'quest_instance_id' => $instanceId,
        ], 100, 1);

        return [
            'history' => $historyRow,
            'definition' => $detail['definition'] ?? null,
            'instance' => $detail['instance'] ?? null,
            'steps' => isset($detail['steps']) && is_array($detail['steps']) ? $detail['steps'] : [],
            'links' => isset($detail['links']) && is_array($detail['links']) ? $detail['links'] : [],
            'logs' => isset($logs['rows']) && is_array($logs['rows']) ? $logs['rows'] : [],
        ];
    }
}
