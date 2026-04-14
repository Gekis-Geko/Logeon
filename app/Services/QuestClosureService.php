<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class QuestClosureService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var QuestResolverService */
    private $resolver;
    /** @var QuestRewardService */
    private $rewardService;
    /** @var array<string,bool> */
    private $tableExistsCache = [];

    public function __construct(
        DbAdapterInterface $db = null,
        QuestResolverService $resolver = null,
        QuestRewardService $rewardService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->resolver = $resolver ?: new QuestResolverService($this->db);
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

    private function tableExists(string $table): bool
    {
        $table = trim($table);
        if ($table === '') {
            return false;
        }
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1',
            [$table],
        );
        $exists = !empty($row) && (int) ($row->n ?? 0) > 0;
        $this->tableExistsCache[$table] = $exists;
        return $exists;
    }

    public function schemaAvailable(): bool
    {
        return $this->tableExists('quest_closure_reports');
    }

    public function ensureSchema(): void
    {
        if (!$this->schemaAvailable()) {
            throw AppError::validation(
                'Tabella chiusure quest non disponibile. Allinea il database con database/logeon_db_core.sql.',
                [],
                'quest_closure_invalid',
            );
        }
    }

    private function normalizeClosureType(string $value): string
    {
        $value = strtolower(trim($value));
        $allowed = ['success', 'partial_success', 'failure', 'cancelled', 'unresolved'];
        if (in_array($value, $allowed, true)) {
            return $value;
        }
        return 'unresolved';
    }

    private function normalizeFinalStatus(string $value): string
    {
        $value = strtolower(trim($value));
        $allowed = ['completed', 'failed', 'cancelled', 'expired'];
        if (!in_array($value, $allowed, true)) {
            throw AppError::validation('Stato finale non valido', [], 'quest_closure_invalid');
        }
        return $value;
    }

    private function normalizeVisibilityFlag($value): int
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '' || in_array($raw, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }
        return 0;
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

    private function defaultClosureTypeByStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'completed') {
            return 'success';
        }
        if ($status === 'failed' || $status === 'expired') {
            return 'failure';
        }
        if ($status === 'cancelled') {
            return 'cancelled';
        }
        return 'unresolved';
    }

    private function defaultOutcomeLabelByStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === 'completed') {
            return 'Quest completata';
        }
        if ($status === 'failed') {
            return 'Quest fallita';
        }
        if ($status === 'cancelled') {
            return 'Quest annullata';
        }
        if ($status === 'expired') {
            return 'Quest scaduta';
        }
        return 'Chiusura quest';
    }

    private function ensureInstanceExists(int $instanceId): array
    {
        $row = $this->firstPrepared(
            'SELECT i.*, d.title AS quest_title, d.slug AS quest_slug
             FROM quest_instances i
             INNER JOIN quest_definitions d ON d.id = i.quest_definition_id
             WHERE i.id = ?
             LIMIT 1',
            [(int) $instanceId],
        );

        if (empty($row)) {
            throw AppError::notFound('Istanza quest non trovata', [], 'quest_not_found');
        }

        return $this->rowToArray($row);
    }

    private function decodeReport(array $row): array
    {
        $row['player_visible'] = (int) ($row['player_visible'] ?? 0) === 1 ? 1 : 0;
        return $row;
    }

    public function getByInstance(int $instanceId): ?array
    {
        if ($instanceId <= 0 || !$this->schemaAvailable()) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT r.*, c.name AS closed_by_name, c.surname AS closed_by_surname
             FROM quest_closure_reports r
             LEFT JOIN characters c ON c.id = r.closed_by
             WHERE r.quest_instance_id = ?
             LIMIT 1',
            [(int) $instanceId],
        );

        if (empty($row)) {
            return null;
        }

        return $this->decodeReport($this->rowToArray($row));
    }

    public function ensureMinimalReport(int $instanceId, string $finalStatus, int $actorCharacterId = 0, string $sourceType = 'legacy'): ?array
    {
        if (!$this->schemaAvailable()) {
            return null;
        }

        $instance = $this->ensureInstanceExists($instanceId);
        $status = strtolower(trim((string) ($instance['current_status'] ?? $finalStatus)));
        if (!in_array($status, ['completed', 'failed', 'cancelled', 'expired'], true)) {
            return null;
        }

        $existing = $this->getByInstance($instanceId);
        if ($existing !== null) {
            return $existing;
        }

        $summary = 'Report automatico generato da chiusura tecnica (' . ($sourceType !== '' ? $sourceType : 'legacy') . ').';

        $this->execPrepared(
            'INSERT INTO quest_closure_reports
            (quest_instance_id, closure_type, summary_public, summary_private, outcome_label, closed_by, closed_at, player_visible, staff_notes)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 1, ?)',
            [
                (int) $instanceId,
                $this->defaultClosureTypeByStatus($status),
                $summary,
                $summary,
                $this->defaultOutcomeLabelByStatus($status),
                $actorCharacterId > 0 ? $actorCharacterId : null,
                'Closure report automatico',
            ],
        );

        return $this->getByInstance($instanceId);
    }

    public function upsert(int $instanceId, array $payload, int $actorCharacterId = 0): array
    {
        $this->ensureSchema();

        if ($instanceId <= 0) {
            throw AppError::validation('Istanza quest non valida', [], 'quest_closure_invalid');
        }

        $instance = $this->ensureInstanceExists($instanceId);
        $currentStatus = strtolower(trim((string) ($instance['current_status'] ?? '')));
        if (!in_array($currentStatus, ['completed', 'failed', 'cancelled', 'expired'], true)) {
            throw AppError::validation('La quest deve essere chiusa prima di salvare il report', [], 'quest_closure_invalid');
        }

        $closureType = $this->normalizeClosureType((string) ($payload['closure_type'] ?? $this->defaultClosureTypeByStatus($currentStatus)));
        $summaryPublic = trim((string) ($payload['summary_public'] ?? ''));
        $summaryPrivate = trim((string) ($payload['summary_private'] ?? ''));
        $outcomeLabel = trim((string) ($payload['outcome_label'] ?? $this->defaultOutcomeLabelByStatus($currentStatus)));
        if ($outcomeLabel === '') {
            $outcomeLabel = $this->defaultOutcomeLabelByStatus($currentStatus);
        }

        $closedAt = $this->normalizeDateTime($payload['closed_at'] ?? null);
        $playerVisible = $this->normalizeVisibilityFlag($payload['player_visible'] ?? 1);
        $staffNotes = trim((string) ($payload['staff_notes'] ?? ''));

        $existing = $this->getByInstance($instanceId);
        if ($existing !== null) {
            $this->execPrepared(
                'UPDATE quest_closure_reports SET
                    closure_type = ?,
                    summary_public = ?,
                    summary_private = ?,
                    outcome_label = ?,
                    closed_by = ?,
                    closed_at = ?,
                    player_visible = ?,
                    staff_notes = ?
                 WHERE quest_instance_id = ?
                 LIMIT 1',
                [
                    $closureType,
                    $summaryPublic !== '' ? $summaryPublic : null,
                    $summaryPrivate !== '' ? $summaryPrivate : null,
                    $outcomeLabel,
                    $actorCharacterId > 0 ? $actorCharacterId : null,
                    $closedAt !== null ? $closedAt : null,
                    $playerVisible,
                    $staffNotes !== '' ? $staffNotes : null,
                    (int) $instanceId,
                ],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO quest_closure_reports
                (quest_instance_id, closure_type, summary_public, summary_private, outcome_label, closed_by, closed_at, player_visible, staff_notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $instanceId,
                    $closureType,
                    $summaryPublic !== '' ? $summaryPublic : null,
                    $summaryPrivate !== '' ? $summaryPrivate : null,
                    $outcomeLabel,
                    $actorCharacterId > 0 ? $actorCharacterId : null,
                    $closedAt !== null ? $closedAt : null,
                    $playerVisible,
                    $staffNotes !== '' ? $staffNotes : null,
                ],
            );
        }

        $report = $this->getByInstance($instanceId);
        if ($report === null) {
            throw AppError::validation('Impossibile salvare il report di chiusura', [], 'quest_closure_invalid');
        }

        return $report;
    }

    public function getForStaff(int $instanceId, int $viewerCharacterId = 0): array
    {
        $instance = $this->resolver->getInstanceDetail($instanceId);
        $report = $this->getByInstance($instanceId);
        if ($report === null && in_array((string) (($instance['instance']['current_status'] ?? '') ?: ''), ['completed', 'failed', 'cancelled', 'expired'], true)) {
            $report = $this->ensureMinimalReport($instanceId, (string) ($instance['instance']['current_status'] ?? ''), $viewerCharacterId, 'staff_closure_get');
        }

        $rewards = $this->rewardService->listByInstance($instanceId, $viewerCharacterId, true, 200, 1);

        return [
            'instance' => $instance['instance'] ?? null,
            'definition' => $instance['definition'] ?? null,
            'steps' => isset($instance['steps']) && is_array($instance['steps']) ? $instance['steps'] : [],
            'closure_report' => $report,
            'rewards' => isset($rewards['rows']) && is_array($rewards['rows']) ? $rewards['rows'] : [],
        ];
    }

    public function finalize(array $payload, int $actorCharacterId): array
    {
        $this->ensureSchema();

        $instanceId = (int) ($payload['quest_instance_id'] ?? $payload['instance_id'] ?? 0);
        if ($instanceId <= 0) {
            throw AppError::validation('Istanza quest non valida', [], 'quest_closure_invalid');
        }

        $finalStatus = $this->normalizeFinalStatus((string) ($payload['final_status'] ?? $payload['status'] ?? 'completed'));

        $this->resolver->setInstanceStatus($instanceId, $finalStatus, $actorCharacterId, 'staff_closure_finalize');
        $instanceDetail = $this->resolver->getInstanceDetail($instanceId);
        $instance = isset($instanceDetail['instance']) && is_array($instanceDetail['instance']) ? $instanceDetail['instance'] : [];
        $definition = isset($instanceDetail['definition']) && is_array($instanceDetail['definition']) ? $instanceDetail['definition'] : null;

        $closurePayload = [
            'closure_type' => $payload['closure_type'] ?? $this->defaultClosureTypeByStatus($finalStatus),
            'summary_public' => $payload['summary_public'] ?? null,
            'summary_private' => $payload['summary_private'] ?? null,
            'outcome_label' => $payload['outcome_label'] ?? $this->defaultOutcomeLabelByStatus($finalStatus),
            'closed_at' => $payload['closed_at'] ?? null,
            'player_visible' => $payload['player_visible'] ?? 1,
            'staff_notes' => $payload['staff_notes'] ?? null,
        ];
        $report = $this->upsert($instanceId, $closurePayload, $actorCharacterId);

        $assignedRewards = [];
        $rewards = isset($payload['rewards']) && is_array($payload['rewards']) ? $payload['rewards'] : [];
        foreach ($rewards as $reward) {
            $rewardPayload = is_array($reward) ? $reward : (array) $reward;
            $rewardPayload['quest_instance_id'] = $instanceId;
            $assignedRewards[] = $this->rewardService->assign($rewardPayload, $actorCharacterId, true);
        }

        $rewardRows = $this->rewardService->listByInstance($instanceId, $actorCharacterId, true, 200, 1);

        return [
            'instance' => $instance,
            'definition' => $definition,
            'closure_report' => $report,
            'assigned_rewards' => $assignedRewards,
            'rewards' => isset($rewardRows['rows']) && is_array($rewardRows['rows']) ? $rewardRows['rows'] : [],
            'intensity_level' => $instanceDetail['intensity_level'] ?? null,
            'definition_intensity_level' => $instanceDetail['definition_intensity_level'] ?? null,
            'instance_intensity_level' => $instanceDetail['instance_intensity_level'] ?? null,
            'intensity_visibility' => $instanceDetail['intensity_visibility'] ?? 'visible',
        ];
    }

    public function listForAdmin(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'closed_at|DESC'): array
    {
        $this->ensureSchema();

        $limit = max(1, min(200, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        $definitionId = (int) ($filters['quest_definition_id'] ?? 0);
        if ($definitionId > 0) {
            $where[] = 'i.quest_definition_id = ?';
            $params[] = $definitionId;
        }
        $instanceId = (int) ($filters['quest_instance_id'] ?? 0);
        if ($instanceId > 0) {
            $where[] = 'r.quest_instance_id = ?';
            $params[] = $instanceId;
        }
        $closureType = trim((string) ($filters['closure_type'] ?? ''));
        if ($closureType !== '') {
            $where[] = 'r.closure_type = ?';
            $params[] = $this->normalizeClosureType($closureType);
        }
        if (array_key_exists('player_visible', $filters) && (string) $filters['player_visible'] !== '') {
            $where[] = 'r.player_visible = ?';
            $params[] = (((int) $filters['player_visible'] === 1) ? 1 : 0);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = '%' . $search . '%';
            $where[] = '(d.title LIKE ? OR d.slug LIKE ? OR r.outcome_label LIKE ?)';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $allowedSort = ['id', 'quest_instance_id', 'closure_type', 'closed_at', 'date_created'];
        $sortChunks = explode('|', (string) $sort);
        $sortField = in_array($sortChunks[0] ?? '', $allowedSort, true) ? $sortChunks[0] : 'closed_at';
        $sortDir = strtoupper($sortChunks[1] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM quest_closure_reports r
             INNER JOIN quest_instances i ON i.id = r.quest_instance_id
             INNER JOIN quest_definitions d ON d.id = i.quest_definition_id
             ' . $whereSql,
            $params,
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT r.*, i.quest_definition_id, i.current_status, i.assignee_type, i.assignee_id,
                    d.title AS quest_title, d.slug AS quest_slug,
                    c.name AS closed_by_name, c.surname AS closed_by_surname
             FROM quest_closure_reports r
             INNER JOIN quest_instances i ON i.id = r.quest_instance_id
             INNER JOIN quest_definitions d ON d.id = i.quest_definition_id
             LEFT JOIN characters c ON c.id = r.closed_by
             ' . $whereSql . '
             ORDER BY r.' . $sortField . ' ' . $sortDir . ', r.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $rows = is_array($rows) ? $rows : [];
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeReport($this->rowToArray($row));
        }

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $out,
        ];
    }

    public function getForAdmin(int $closureId = 0, int $instanceId = 0): array
    {
        $this->ensureSchema();

        if ($instanceId <= 0 && $closureId <= 0) {
            throw AppError::validation('Chiusura quest non valida', [], 'quest_closure_invalid');
        }

        if ($instanceId <= 0 && $closureId > 0) {
            $row = $this->firstPrepared(
                'SELECT quest_instance_id FROM quest_closure_reports WHERE id = ? LIMIT 1',
                [(int) $closureId],
            );
            $instanceId = !empty($row) ? (int) ($row->quest_instance_id ?? 0) : 0;
        }

        if ($instanceId <= 0) {
            throw AppError::notFound('Chiusura quest non trovata', [], 'quest_closure_invalid');
        }

        return $this->getForStaff($instanceId, 0);
    }
}
