<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class ConflictService
{
    use ConflictServiceReadQueriesTrait;

    public const STATUS_PROPOSAL = 'proposal';
    public const STATUS_OPEN = 'open';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_AWAITING = 'awaiting_resolution';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    /** @var DbAdapterInterface */
    private $db;

    /** @var ConflictSettingsService */
    private $settingsService;

    /** @var ConflictResolverFactory */
    private $resolverFactory;

    /** @var NarrativeDomainService|null */
    private $narrativeDomainService = null;

    /** @var NotificationService|null */
    private $notificationService = null;

    /** @var array<string, bool> */
    private $tableExistsCache = [];

    /** @var array<string, bool> */
    private $columnExistsCache = [];

    public function __construct(
        DbAdapterInterface $db = null,
        ConflictSettingsService $settingsService = null,
        ConflictResolverFactory $resolverFactory = null,
        NarrativeDomainService $narrativeDomainService = null,
        NotificationService $notificationService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->settingsService = $settingsService ?: new ConflictSettingsService($this->db);
        $this->resolverFactory = $resolverFactory ?: new ConflictResolverFactory($this->db, $this->settingsService);
        $this->narrativeDomainService = $narrativeDomainService;
        $this->notificationService = $notificationService;
    }

    public function setNarrativeDomainService(NarrativeDomainService $service = null)
    {
        $this->narrativeDomainService = $service;
        return $this;
    }

    public function setNotificationService(NotificationService $service = null)
    {
        $this->notificationService = $service;
        return $this;
    }

    private function narrativeDomainService(): NarrativeDomainService
    {
        if ($this->narrativeDomainService instanceof NarrativeDomainService) {
            return $this->narrativeDomainService;
        }

        $this->narrativeDomainService = new NarrativeDomainService($this->db);
        return $this->narrativeDomainService;
    }

    private function notificationService(): NotificationService
    {
        if ($this->notificationService instanceof NotificationService) {
            return $this->notificationService;
        }

        $this->notificationService = new NotificationService($this->db);
        return $this->notificationService;
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

    /**
     * @return array<string, mixed>
     */
    private function payloadToArray($payload): array
    {
        if (is_object($payload)) {
            /** @var array<string, mixed> $converted */
            $converted = (array) $payload;
            return $converted;
        }
        if (is_array($payload)) {
            /** @var array<string, mixed> $payload */
            return $payload;
        }

        return [];
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * @return array<int, array{user_id:int, character_id:int}>
     */
    private function conflictNotificationRecipients(int $conflictId, int $excludeCharacterId = 0): array
    {
        if ($conflictId <= 0) {
            return [];
        }

        $where = [
            'cp.conflict_id = ?',
            'cp.is_active = 1',
            'c.user_id IS NOT NULL',
            'c.user_id > 0',
        ];
        $params = [$conflictId];
        if ($excludeCharacterId > 0) {
            $where[] = 'cp.character_id <> ?';
            $params[] = $excludeCharacterId;
        }

        $rows = $this->fetchPrepared(
            'SELECT DISTINCT c.user_id AS user_id, cp.character_id AS character_id '
            . 'FROM conflict_participants cp '
            . 'INNER JOIN characters c ON c.id = cp.character_id '
            . 'WHERE ' . implode(' AND ', $where),
            $params,
        );

        $dataset = [];
        foreach ($rows ?: [] as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $characterId = (int) ($row->character_id ?? 0);
            if ($userId <= 0 || $characterId <= 0) {
                continue;
            }
            $dataset[] = [
                'user_id' => $userId,
                'character_id' => $characterId,
            ];
        }

        return $dataset;
    }

    private function notifyConflictParticipants(
        int $conflictId,
        int $locationId,
        string $title,
        string $message,
        string $stage,
        int $excludeCharacterId = 0,
    ): void {
        $recipients = $this->conflictNotificationRecipients($conflictId, $excludeCharacterId);
        if (empty($recipients)) {
            return;
        }

        $normalizedStage = trim(strtolower($stage));
        if ($normalizedStage === '') {
            $normalizedStage = 'update';
        }

        foreach ($recipients as $recipient) {
            $dedupKey = 'conflict:' . $conflictId . ':' . $normalizedStage . ':char:' . (int) $recipient['character_id'];
            $this->notificationService()->mergeOrCreateSystemUpdate(
                (int) $recipient['user_id'],
                (int) $recipient['character_id'],
                $dedupKey,
                $title,
                [
                    'message' => $message,
                    'source_type' => 'conflict',
                    'source_id' => $conflictId,
                    'source_meta_json' => $this->toJson([
                        'conflict_id' => $conflictId,
                        'location_id' => $locationId > 0 ? $locationId : null,
                        'stage' => $normalizedStage,
                    ]),
                    'action_url' => '/game/maps',
                ],
            );
        }
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }

        try {
            $dbName = (string) DB['mysql']['db_name'];

            $row = $this->firstPrepared(
                'SELECT 1 AS ok
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                 LIMIT 1',
                [$dbName, $table],
            );
            $this->tableExistsCache[$table] = !empty($row);
        } catch (\Throwable $error) {
            $this->tableExistsCache[$table] = false;
        }

        return $this->tableExistsCache[$table];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, $this->columnExistsCache)) {
            return $this->columnExistsCache[$cacheKey];
        }

        if (!$this->hasTable($table)) {
            $this->columnExistsCache[$cacheKey] = false;
            return false;
        }

        try {
            $dbName = (string) DB['mysql']['db_name'];

            $row = $this->firstPrepared(
                'SELECT 1 AS ok
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ?
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1',
                [$dbName, $table, $column],
            );
            $this->columnExistsCache[$cacheKey] = !empty($row);
        } catch (\Throwable $error) {
            $this->columnExistsCache[$cacheKey] = false;
        }

        return $this->columnExistsCache[$cacheKey];
    }

    private function failValidation(string $message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation($message, [], $errorCode);
    }

    private function failUnauthorized(string $message = 'Operazione non autorizzata', string $errorCode = 'unauthorized'): void
    {
        throw AppError::unauthorized($message, [], $errorCode);
    }

    /**
     * @return array<int, string>
     */
    private function allowedStatuses(): array
    {
        $statuses = [
            self::STATUS_OPEN,
            self::STATUS_ACTIVE,
            self::STATUS_AWAITING,
            self::STATUS_RESOLVED,
            self::STATUS_CLOSED,
        ];

        if ($this->hasColumn('conflicts', 'status')) {
            $statuses[] = self::STATUS_PROPOSAL;
        }

        return $statuses;
    }

    private function rollModeLabel(string $mode): string
    {
        $map = [
            'single_roll' => 'singolo',
            'single_roll_with_modifiers' => 'singolo con modificatori',
            'opposed_roll' => 'contrapposto',
            'threshold_roll' => 'soglia',
        ];
        return $map[$mode] ?? $mode;
    }

    private function statusLabel(string $status): string
    {
        $map = [
            self::STATUS_PROPOSAL => 'Proposta',
            self::STATUS_OPEN => 'Aperto',
            self::STATUS_ACTIVE => 'Attivo',
            self::STATUS_AWAITING => 'In attesa di risoluzione',
            self::STATUS_RESOLVED => 'Risolto',
            self::STATUS_CLOSED => 'Chiuso',
        ];
        return $map[$status] ?? $status;
    }

    private function normalizeStatus($status, string $default = self::STATUS_OPEN): string
    {
        $value = strtolower(trim((string) $status));
        $allowed = $this->allowedStatuses();
        if (!in_array($value, $allowed, true)) {
            return $default;
        }

        if ($value === self::STATUS_PROPOSAL && !$this->hasColumn('conflicts', 'proposal_expires_at')) {
            return $default;
        }

        return $value;
    }

    private function normalizeResolutionMode($mode): string
    {
        $value = strtolower(trim((string) $mode));
        if (!in_array($value, [ConflictSettingsService::MODE_NARRATIVE, ConflictSettingsService::MODE_RANDOM], true)) {
            $value = (string) $this->settingsService->mode();
        }
        return $value;
    }

    private function normalizeResolutionAuthority($authority): string
    {
        $value = strtolower(trim((string) $authority));
        $allowed = ['players', 'master', 'mixed', 'deferred_review'];
        if (!in_array($value, $allowed, true)) {
            $value = 'mixed';
        }

        return $value;
    }

    private function normalizeConflictOrigin($origin, string $default = 'admin'): string
    {
        $value = strtolower(trim((string) $origin));
        $allowed = ['chat', 'admin', 'system'];
        if (!in_array($value, $allowed, true)) {
            $value = $default;
        }

        return $value;
    }

    private function normalizeActionType($actionType): string
    {
        $value = strtolower(trim((string) $actionType));
        $allowed = ['action', 'note', 'verdict', 'system'];
        if (!in_array($value, $allowed, true)) {
            $value = 'action';
        }

        return $value;
    }

    private function normalizeParticipantRole($role): string
    {
        $value = strtolower(trim((string) $role));
        $allowed = ['actor', 'target', 'support', 'witness', 'other'];
        if (!in_array($value, $allowed, true)) {
            $value = 'actor';
        }

        return $value;
    }

    private function normalizeParticipantType($type): string
    {
        $value = strtolower(trim((string) $type));
        $allowed = ['character', 'npc', 'faction', 'group'];
        if (!in_array($value, $allowed, true)) {
            $value = 'character';
        }

        return $value;
    }

    private function normalizeActorType($type): string
    {
        $value = strtolower(trim((string) $type));
        $allowed = ['character', 'npc', 'system'];
        if (!in_array($value, $allowed, true)) {
            $value = 'character';
        }

        return $value;
    }

    private function resolveLastActivityExpr(string $alias): string
    {
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            return $alias . '.last_activity_at';
        }

        return 'COALESCE(' . $alias . '.updated_at, ' . $alias . '.created_at)';
    }

    private function resolveProposalExpiresExpr(string $alias): string
    {
        if ($this->hasColumn('conflicts', 'proposal_expires_at')) {
            return $alias . '.proposal_expires_at';
        }

        return 'NULL';
    }

    private function resolveConflictOriginExpr(string $alias): string
    {
        if ($this->hasColumn('conflicts', 'conflict_origin')) {
            return $alias . '.conflict_origin';
        }

        return "'admin'";
    }

    private function conflictSelectColumns(string $alias = 'c'): string
    {
        $columns = [
            $alias . '.id',
            $alias . '.location_id',
            $alias . '.opened_by',
            $alias . '.resolution_mode',
            $alias . '.resolution_authority',
            $alias . '.status',
            $alias . '.outcome_summary',
            $alias . '.verdict_text',
            $alias . '.verdict_meta_json',
            $alias . '.participants_snapshot_json',
            $alias . '.resolved_by',
            $alias . '.created_at',
            $alias . '.resolved_at',
            $alias . '.closing_timestamp',
            $alias . '.updated_at',
            $this->resolveConflictOriginExpr($alias) . ' AS conflict_origin',
            $this->resolveProposalExpiresExpr($alias) . ' AS proposal_expires_at',
            $this->resolveLastActivityExpr($alias) . ' AS last_activity_at',
        ];

        return implode(', ', $columns);
    }

    private function toJson($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return '{}';
        }

        return $json;
    }

    private function normalizeNarrativeScope($value): string
    {
        $scope = strtolower(trim((string) $value));
        if (!in_array($scope, ['local', 'regional', 'global'], true)) {
            $scope = 'local';
        }

        return $scope;
    }

    private function normalizeNarrativeVisibility($value): string
    {
        $visibility = strtolower(trim((string) $value));
        if (!in_array($visibility, ['public', 'private', 'staff_only', 'hidden'], true)) {
            $visibility = 'public';
        }

        return $visibility;
    }

    private function normalizeNarrativeRows($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function narrativeErrorCodeFromThrowable(\Throwable $error): string
    {
        if ($error instanceof AppError) {
            $code = trim((string) $error->errorCode());
            if ($code !== '') {
                return $code;
            }
        }

        return 'narrative_domain_error';
    }

    private function extractNarrativeStateRows(array $payload): array
    {
        if (array_key_exists('states', $payload)) {
            return $this->normalizeNarrativeRows($payload['states']);
        }

        return $this->normalizeNarrativeRows($payload['narrative_states'] ?? []);
    }

    private function extractLifecycleTransitionRows(array $payload): array
    {
        if (array_key_exists('lifecycle_transitions', $payload)) {
            return $this->normalizeNarrativeRows($payload['lifecycle_transitions']);
        }

        return $this->normalizeNarrativeRows($payload['transitions'] ?? []);
    }

    private function buildConflictEntityRefs($conflict, array $participants): array
    {
        $refs = [];
        $seen = [];

        $pushRef = function (string $type, int $id, string $role) use (&$refs, &$seen): void {
            if ($id <= 0) {
                return;
            }
            if (!in_array($type, ['character', 'faction', 'location', 'conflict', 'event'], true)) {
                return;
            }
            $role = trim($role);
            if ($role === '') {
                $role = 'participant';
            }
            $key = $type . ':' . $id . ':' . $role;
            if (array_key_exists($key, $seen)) {
                return;
            }
            $seen[$key] = true;
            $refs[] = [
                'entity_type' => $type,
                'entity_id' => $id,
                'role' => $role,
            ];
        };

        $conflictId = (int) ($conflict->id ?? 0);
        $locationId = (int) ($conflict->location_id ?? 0);

        $pushRef('conflict', $conflictId, 'subject');
        $pushRef('location', $locationId, 'scene');

        foreach ($participants as $participant) {
            $type = strtolower(trim((string) ($participant->participant_type ?? 'character')));
            $role = trim((string) ($participant->participant_role ?? 'participant'));
            $participantId = (int) ($participant->participant_id ?? 0);
            $characterId = (int) ($participant->character_id ?? 0);

            if ($participantId <= 0 && $characterId > 0) {
                $participantId = $characterId;
            }
            if ($participantId <= 0) {
                continue;
            }

            if (!in_array($type, ['character', 'faction', 'location', 'conflict', 'event'], true)) {
                if ($characterId > 0) {
                    $type = 'character';
                    $participantId = $characterId;
                } else {
                    continue;
                }
            }

            $pushRef($type, $participantId, $role);
        }

        return $refs;
    }

    private function buildConflictNarrativeActionKey(int $conflictId, string $stage, array $seed = []): string
    {
        $base = 'conflict:' . $conflictId . ':' . $stage;
        if (empty($seed)) {
            return $base;
        }

        $hash = sha1($this->toJson($seed));
        return $base . ':' . substr($hash, 0, 24);
    }

    private function processConflictNarrativeAction(
        string $stage,
        $conflict,
        int $actorCharacterId,
        array $payload = [],
    ): array {
        $conflictId = (int) ($conflict->id ?? 0);
        if ($conflictId <= 0) {
            return [
                'status' => 'skipped',
                'reason' => 'conflict_not_found',
            ];
        }

        $participants = $this->getParticipantRows($conflictId);
        $summary = trim((string) ($payload['summary'] ?? ''));
        $verdict = trim((string) ($payload['verdict'] ?? ''));
        $note = trim((string) ($payload['note'] ?? ''));

        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            if ($stage === 'conflict_resolved') {
                $title = 'Conflitto #' . $conflictId . ' risolto';
            } elseif ($stage === 'conflict_closed') {
                $title = 'Conflitto #' . $conflictId . ' chiuso';
            } else {
                $title = 'Conflitto #' . $conflictId . ' aggiornato';
            }
        }

        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '') {
            $parts = [];
            if ($summary !== '') {
                $parts[] = $summary;
            }
            if ($verdict !== '') {
                $parts[] = $verdict;
            }
            if ($note !== '') {
                $parts[] = $note;
            }
            $description = !empty($parts) ? implode(' | ', $parts) : ('Aggiornamento conflitto #' . $conflictId);
        }

        $meta = [];
        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $meta = $payload['meta'];
        }
        $meta = array_merge([
            'event_type' => $stage,
            'conflict_id' => $conflictId,
            'location_id' => (int) ($conflict->location_id ?? 0),
            'resolution_mode' => (string) ($conflict->resolution_mode ?? ''),
            'status' => (string) ($conflict->status ?? ''),
            'participants_count' => count($participants),
        ], $meta);

        $states = $this->normalizeNarrativeRows($payload['states'] ?? []);
        $lifecycleTransitions = $this->normalizeNarrativeRows($payload['lifecycle_transitions'] ?? []);

        $seed = [
            'stage' => $stage,
            'status' => (string) ($conflict->status ?? ''),
            'summary' => $summary,
            'verdict' => $verdict,
            'note' => $note,
            'states_count' => count($states),
            'lifecycle_count' => count($lifecycleTransitions),
            'meta' => $meta,
        ];
        $actionKey = trim((string) ($payload['action_key'] ?? ''));
        if ($actionKey === '') {
            $actionKey = $this->buildConflictNarrativeActionKey($conflictId, $stage, $seed);
        }

        $scope = $this->normalizeNarrativeScope(
            $payload['scope'] ?? ((int) ($conflict->location_id ?? 0) > 0 ? 'local' : 'regional'),
        );
        $visibility = $this->normalizeNarrativeVisibility($payload['visibility'] ?? 'public');

        try {
            return $this->narrativeDomainService()->processAction([
                'action_key' => $actionKey,
                'source_system' => 'conflict',
                'source_ref_id' => $conflictId,
                'actor_character_id' => $actorCharacterId > 0 ? $actorCharacterId : 0,
                'event_type' => $stage,
                'title' => $title,
                'description' => $description,
                'scope' => $scope,
                'location_id' => (int) ($conflict->location_id ?? 0),
                'visibility' => $visibility,
                'entity_refs' => $this->buildConflictEntityRefs($conflict, $participants),
                'meta_json' => $meta,
                'states' => $states,
                'lifecycle_transitions' => $lifecycleTransitions,
            ]);
        } catch (\Throwable $error) {
            return [
                'status' => 'error',
                'action_key' => $actionKey,
                'event_id' => null,
                'event' => null,
                'applied_states' => [],
                'lifecycle_transitions' => [],
                'inconsistencies' => [[
                    'stage' => 'narrative_domain',
                    'error_code' => $this->narrativeErrorCodeFromThrowable($error),
                    'message' => $error->getMessage(),
                ]],
            ];
        }
    }






    /**
     * @return array<string, mixed>
     */


    private function runProposalExpiryMaintenance(): array
    {
        if (!$this->hasTable('conflicts') || !$this->hasColumn('conflicts', 'proposal_expires_at')) {
            return ['escalated_ids' => []];
        }

        $rows = $this->fetchPrepared(
            'SELECT c.id '
            . 'FROM conflicts c '
            . 'WHERE c.status = ? '
            . 'AND c.proposal_expires_at IS NOT NULL '
            . 'AND c.proposal_expires_at < NOW() '
            . 'ORDER BY c.proposal_expires_at ASC '
            . 'LIMIT 200',
            [self::STATUS_PROPOSAL],
        );

        $escalated = [];
        foreach ($rows ?: [] as $row) {
            $conflictId = (int) ($row->id ?? 0);
            if ($conflictId <= 0) {
                continue;
            }

            if ($this->hasColumn('conflicts', 'last_activity_at')) {
                $this->execPrepared(
                    'UPDATE conflicts
                     SET status = ?, last_activity_at = NOW()
                     WHERE id = ?
                       AND status = ?
                     LIMIT 1',
                    [self::STATUS_AWAITING, $conflictId, self::STATUS_PROPOSAL],
                );
            } else {
                $this->execPrepared(
                    'UPDATE conflicts
                     SET status = ?
                     WHERE id = ?
                       AND status = ?
                     LIMIT 1',
                    [self::STATUS_AWAITING, $conflictId, self::STATUS_PROPOSAL],
                );
            }

            $this->insertActionRow($conflictId, [
                'actor_id' => null,
                'actor_type' => 'system',
                'action_type' => 'system',
                'action_kind' => 'proposal_expired',
                'action_mode' => 'system',
                'action_body' => 'Proposta scaduta: conflitto inoltrato alla revisione staff.',
                'resolution_type' => 'proposal',
                'resolution_status' => 'expired',
                'meta_json' => $this->toJson([
                    'event_type' => 'conflict_proposal_expired_escalated',
                    'error_code' => 'conflict_inactivity_escalated',
                ]),
            ]);

            $escalated[] = $conflictId;
        }

        return ['escalated_ids' => $escalated];
    }

    private function runInactivityMaintenance(): array
    {
        if (!$this->hasTable('conflicts')) {
            return [
                'archived_ids' => [],
                'escalated_proposal_ids' => [],
            ];
        }

        $proposalMaintenance = $this->runProposalExpiryMaintenance();

        $settings = $this->settingsService->getSettings();
        $archiveDays = (int) ($settings[ConflictSettingsService::KEY_INACTIVITY_ARCHIVE_DAYS] ?? 7);
        if ($archiveDays < 1) {
            $archiveDays = 7;
        }

        $lastActivityExpr = $this->resolveLastActivityExpr('c');
        $rows = $this->fetchPrepared(
            'SELECT c.id '
            . 'FROM conflicts c '
            . 'WHERE c.status IN (?, ?, ?) '
            . 'AND ' . $lastActivityExpr . ' IS NOT NULL '
            . 'AND ' . $lastActivityExpr . ' < DATE_SUB(NOW(), INTERVAL ? DAY) '
            . 'LIMIT 200',
            [self::STATUS_OPEN, self::STATUS_ACTIVE, self::STATUS_AWAITING, $archiveDays],
        );

        $archived = [];
        foreach ($rows ?: [] as $row) {
            $conflictId = (int) ($row->id ?? 0);
            if ($conflictId <= 0) {
                continue;
            }
            $this->archiveConflict($conflictId);
            $archived[] = $conflictId;
        }

        return [
            'archived_ids' => $archived,
            'escalated_proposal_ids' => $proposalMaintenance['escalated_ids'] ?? [],
        ];
    }

    private function archiveConflict(int $conflictId): void
    {
        $hasLastActivity = $this->hasColumn('conflicts', 'last_activity_at');
        $hasResolvedAt = $this->hasColumn('conflicts', 'resolved_at');

        $setParts = ['status = ?', 'closing_timestamp = NOW()'];
        $params = [self::STATUS_CLOSED];
        if ($hasLastActivity) {
            $setParts[] = 'last_activity_at = NOW()';
        }
        if ($hasResolvedAt) {
            $setParts[] = 'resolved_at = COALESCE(resolved_at, NOW())';
        }
        $params[] = $conflictId;

        $this->execPrepared(
            'UPDATE conflicts SET ' . implode(', ', $setParts) . ' WHERE id = ? LIMIT 1',
            $params,
        );

        $this->insertActionRow($conflictId, [
            'actor_id' => null,
            'actor_type' => 'system',
            'action_type' => 'system',
            'action_kind' => 'auto_archive',
            'action_mode' => 'maintenance',
            'action_body' => 'Conflitto archiviato automaticamente per inattivita.',
            'meta_json' => $this->toJson([
                'error_code' => 'conflict_auto_archived',
                'event_type' => 'conflict_auto_archived',
            ]),
        ], []);
    }



    private function ensureWritable($conflict, int $actorCharacterId, bool $isStaff): void
    {
        if ($isStaff) {
            return;
        }

        if ($actorCharacterId <= 0) {
            $this->failUnauthorized('Operazione non autorizzata', 'conflict_write_forbidden');
        }

        if ($this->isParticipant((int) $conflict->id, $actorCharacterId)) {
            return;
        }

        $openedBy = (int) ($conflict->opened_by ?? 0);
        if ($openedBy === $actorCharacterId) {
            return;
        }

        $this->failUnauthorized('Operazione non autorizzata', 'conflict_write_forbidden');
    }

    private function ensureStaff(bool $isStaff): void
    {
        if (!$isStaff) {
            $this->failUnauthorized('Operazione riservata allo staff', 'conflict_staff_required');
        }
    }

    private function updateLastActivity(int $conflictId): void
    {
        if ($conflictId <= 0 || !$this->hasColumn('conflicts', 'last_activity_at')) {
            return;
        }

        $this->execPrepared(
            'UPDATE conflicts SET last_activity_at = NOW() '
            . 'WHERE id = ? LIMIT 1',
            [$conflictId],
        );
    }


    private function upsertParticipantRow(int $conflictId, array $participant): void
    {
        $characterId = (int) ($participant['character_id'] ?? 0);
        if ($characterId <= 0) {
            return;
        }

        $isActive = ((int) ($participant['is_active'] ?? 1) === 1) ? 1 : 0;
        $role = $this->normalizeParticipantRole($participant['participant_role'] ?? 'actor');
        $type = $this->normalizeParticipantType($participant['participant_type'] ?? 'character');
        $participantId = (int) ($participant['participant_id'] ?? $characterId);
        $teamKey = trim((string) ($participant['team_key'] ?? ''));
        if ($teamKey === '') {
            $teamKey = null;
        }

        $columns = ['conflict_id', 'character_id', 'participant_role', 'is_active'];
        $valuesSql = ['?', '?', '?', '?'];
        $params = [$conflictId, $characterId, $role, $isActive];
        if ($this->hasColumn('conflict_participants', 'participant_type')) {
            $columns[] = 'participant_type';
            $valuesSql[] = '?';
            $params[] = $type;
        }
        if ($this->hasColumn('conflict_participants', 'participant_id')) {
            $columns[] = 'participant_id';
            $valuesSql[] = '?';
            $params[] = $participantId > 0 ? $participantId : null;
        }
        if ($this->hasColumn('conflict_participants', 'team_key')) {
            $columns[] = 'team_key';
            $valuesSql[] = '?';
            $params[] = $teamKey !== null ? $teamKey : null;
        }
        if ($this->hasColumn('conflict_participants', 'joined_at')) {
            $columns[] = 'joined_at';
            $valuesSql[] = 'NOW()';
        }
        if ($this->hasColumn('conflict_participants', 'left_at')) {
            $columns[] = 'left_at';
            $valuesSql[] = ($isActive === 1) ? 'NULL' : 'NOW()';
        }

        $updates = [
            'participant_role = VALUES(participant_role)',
            'is_active = VALUES(is_active)',
        ];
        if ($this->hasColumn('conflict_participants', 'participant_type')) {
            $updates[] = 'participant_type = VALUES(participant_type)';
        }
        if ($this->hasColumn('conflict_participants', 'participant_id')) {
            $updates[] = 'participant_id = VALUES(participant_id)';
        }
        if ($this->hasColumn('conflict_participants', 'team_key')) {
            $updates[] = 'team_key = VALUES(team_key)';
        }
        if ($this->hasColumn('conflict_participants', 'joined_at')) {
            $updates[] = 'joined_at = COALESCE(joined_at, VALUES(joined_at))';
        }
        if ($this->hasColumn('conflict_participants', 'left_at')) {
            $updates[] = 'left_at = VALUES(left_at)';
        }

        $this->execPrepared(
            'INSERT INTO conflict_participants (' . implode(', ', $columns) . ') '
            . 'VALUES (' . implode(', ', $valuesSql) . ') '
            . 'ON DUPLICATE KEY UPDATE '
            . implode(', ', $updates),
            $params,
        );
    }

    private function insertActionTargets(int $actionId, array $targets): void
    {
        if ($actionId <= 0 || !$this->hasTable('conflict_action_targets') || empty($targets)) {
            return;
        }

        foreach ($targets as $target) {
            if (!is_array($target)) {
                continue;
            }

            $targetType = strtolower(trim((string) ($target['target_type'] ?? 'character')));
            $allowedTargetTypes = ['character', 'npc', 'faction', 'group', 'team', 'self', 'scene'];
            if (!in_array($targetType, $allowedTargetTypes, true)) {
                $targetType = 'character';
            }

            $targetId = isset($target['target_id']) ? (int) $target['target_id'] : null;
            $teamKey = trim((string) ($target['team_key'] ?? ''));
            if ($teamKey === '') {
                $teamKey = null;
            }

            $this->execPrepared(
                'INSERT INTO conflict_action_targets SET '
                . 'conflict_action_id = ?, '
                . 'target_type = ?, '
                . 'target_id = ?, '
                . 'team_key = ?',
                [$actionId, $targetType, $targetId, $teamKey],
            );
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,array<string,mixed>> $targets
     */
    private function insertActionRow(int $conflictId, array $payload, array $targets = []): int
    {
        $actionType = $this->normalizeActionType($payload['action_type'] ?? 'action');
        $actionBody = trim((string) ($payload['action_body'] ?? ''));
        if ($actionBody === '') {
            $actionBody = '-';
        }

        $actorId = isset($payload['actor_id']) ? (int) $payload['actor_id'] : 0;
        $actorType = $this->normalizeActorType($payload['actor_type'] ?? 'character');

        $columns = ['conflict_id', 'actor_id', 'action_type', 'action_body', 'meta_json'];
        $valuesSql = ['?', '?', '?', '?', '?'];
        $params = [
            $conflictId,
            $actorId > 0 ? $actorId : null,
            $actionType,
            $actionBody,
            array_key_exists('meta_json', $payload)
                ? ($payload['meta_json'] === null ? null : (string) $payload['meta_json'])
                : null,
        ];
        if ($this->hasColumn('conflict_actions', 'actor_type')) {
            $columns[] = 'actor_type';
            $valuesSql[] = '?';
            $params[] = $actorType;
        }
        if ($this->hasColumn('conflict_actions', 'action_kind')) {
            $actionKind = trim((string) ($payload['action_kind'] ?? ''));
            $columns[] = 'action_kind';
            $valuesSql[] = '?';
            $params[] = $actionKind !== '' ? $actionKind : null;
        }
        if ($this->hasColumn('conflict_actions', 'action_mode')) {
            $actionMode = trim((string) ($payload['action_mode'] ?? ''));
            $columns[] = 'action_mode';
            $valuesSql[] = '?';
            $params[] = $actionMode !== '' ? $actionMode : null;
        }
        if ($this->hasColumn('conflict_actions', 'chat_message_id')) {
            $chatMessageId = isset($payload['chat_message_id']) ? (int) $payload['chat_message_id'] : 0;
            $columns[] = 'chat_message_id';
            $valuesSql[] = '?';
            $params[] = $chatMessageId > 0 ? $chatMessageId : null;
        }
        if ($this->hasColumn('conflict_actions', 'resolution_type')) {
            $resolutionType = trim((string) ($payload['resolution_type'] ?? ''));
            $columns[] = 'resolution_type';
            $valuesSql[] = '?';
            $params[] = $resolutionType !== '' ? $resolutionType : null;
        }
        if ($this->hasColumn('conflict_actions', 'resolution_status')) {
            $resolutionStatus = trim((string) ($payload['resolution_status'] ?? ''));
            $columns[] = 'resolution_status';
            $valuesSql[] = '?';
            $params[] = $resolutionStatus !== '' ? $resolutionStatus : null;
        }
        if ($this->hasColumn('conflict_actions', 'resolved_at')) {
            $resolvedAt = trim((string) ($payload['resolved_at'] ?? ''));
            $columns[] = 'resolved_at';
            $valuesSql[] = '?';
            $params[] = $resolvedAt !== '' ? $resolvedAt : null;
        }

        $this->execPrepared(
            'INSERT INTO conflict_actions (' . implode(', ', $columns) . ') '
            . 'VALUES (' . implode(', ', $valuesSql) . ')',
            $params,
        );
        $actionId = (int) $this->db->lastInsertId();

        if ($actionId > 0) {
            $this->insertActionTargets($actionId, $targets);
        }

        $this->updateLastActivity($conflictId);

        return $actionId;
    }

    /**
     * @param array<string, mixed>|object $payload
     * @return array<string, mixed>
     */


    /**
     * @return array<string, mixed>
     */


    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function openConflict($payload, int $openedBy): array
    {
        $data = $this->payloadToArray($payload);

        $locationId = (int) ($data['location_id'] ?? 0);
        $resolutionMode = $this->normalizeResolutionMode($data['resolution_mode'] ?? $this->settingsService->mode());
        $resolutionAuthority = $this->normalizeResolutionAuthority($data['resolution_authority'] ?? 'mixed');
        $status = $this->normalizeStatus($data['status'] ?? self::STATUS_OPEN, self::STATUS_OPEN);
        if ($status === self::STATUS_PROPOSAL) {
            $status = self::STATUS_OPEN;
        }

        $origin = $this->normalizeConflictOrigin($data['conflict_origin'] ?? 'admin', 'admin');

        $columns = ['`opened_by`', '`resolution_mode`', '`resolution_authority`', '`status`', '`created_at`'];
        $valuesSql = ['?', '?', '?', '?', 'NOW()'];
        $params = [$openedBy, $resolutionMode, $resolutionAuthority, $status];

        if ($locationId > 0) {
            $columns[] = '`location_id`';
            $valuesSql[] = '?';
            $params[] = $locationId;
        } else {
            $columns[] = '`location_id`';
            $valuesSql[] = 'NULL';
        }
        if ($this->hasColumn('conflicts', 'conflict_origin')) {
            $columns[] = '`conflict_origin`';
            $valuesSql[] = '?';
            $params[] = $origin;
        }
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            $columns[] = '`last_activity_at`';
            $valuesSql[] = 'NOW()';
        }

        $this->execPrepared(
            'INSERT INTO conflicts (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $valuesSql) . ')',
            $params,
        );
        $conflictId = (int) $this->db->lastInsertId();
        if ($conflictId <= 0) {
            $this->failValidation('Impossibile aprire il conflitto', 'conflict_open_failed');
        }

        $this->upsertParticipantRow($conflictId, [
            'character_id' => $openedBy,
            'participant_role' => 'actor',
            'participant_type' => 'character',
            'participant_id' => $openedBy,
            'is_active' => 1,
        ]);

        $openingNote = trim((string) ($data['opening_note'] ?? ''));
        if ($openingNote !== '') {
            $this->insertActionRow($conflictId, [
                'actor_id' => $openedBy,
                'actor_type' => 'character',
                'action_type' => 'note',
                'action_kind' => 'opening_note',
                'action_mode' => 'manual',
                'action_body' => $openingNote,
            ]);
        }

        return $this->getConflict($conflictId);
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function proposeConflict($payload, int $openedBy, bool $isStaff = false): array
    {
        $data = $this->payloadToArray($payload);

        $locationId = (int) ($data['location_id'] ?? 0);
        if ($locationId <= 0) {
            $this->failValidation('Location non valida', 'location_invalid');
        }

        $targetId = (int) ($data['target_id'] ?? 0);
        if ($targetId <= 0 && !$isStaff) {
            $this->failValidation('Bersaglio proposta richiesto', 'conflict_target_required');
        }

        $summary = trim((string) ($data['summary'] ?? $data['body'] ?? $data['reason'] ?? ''));
        if ($summary === '') {
            $summary = 'Proposta conflitto in location';
        }

        $resolutionMode = $this->normalizeResolutionMode($data['resolution_mode'] ?? $this->settingsService->mode());
        $resolutionAuthority = $this->normalizeResolutionAuthority($data['resolution_authority'] ?? 'mixed');
        $origin = $this->normalizeConflictOrigin($data['conflict_origin'] ?? 'chat', 'chat');

        $proposalHours = (int) ($data['proposal_hours'] ?? 24);
        if ($proposalHours < 1) {
            $proposalHours = 1;
        }
        if ($proposalHours > 168) {
            $proposalHours = 168;
        }

        $columns = ['`location_id`', '`opened_by`', '`resolution_mode`', '`resolution_authority`', '`status`', '`created_at`'];
        $valuesSql = ['?', '?', '?', '?', '?', 'NOW()'];
        $params = [
            $locationId,
            $openedBy,
            $resolutionMode,
            $resolutionAuthority,
            $this->hasColumn('conflicts', 'proposal_expires_at') ? self::STATUS_PROPOSAL : self::STATUS_OPEN,
        ];

        if ($this->hasColumn('conflicts', 'conflict_origin')) {
            $columns[] = '`conflict_origin`';
            $valuesSql[] = '?';
            $params[] = $origin;
        }
        if ($this->hasColumn('conflicts', 'proposal_expires_at')) {
            $columns[] = '`proposal_expires_at`';
            $valuesSql[] = 'DATE_ADD(NOW(), INTERVAL ' . (int) $proposalHours . ' HOUR)';
        }
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            $columns[] = '`last_activity_at`';
            $valuesSql[] = 'NOW()';
        }

        $this->execPrepared(
            'INSERT INTO conflicts (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $valuesSql) . ')',
            $params,
        );
        $conflictId = (int) $this->db->lastInsertId();
        if ($conflictId <= 0) {
            $this->failValidation('Proposta conflitto non creata', 'conflict_proposal_failed');
        }

        $this->upsertParticipantRow($conflictId, [
            'character_id' => $openedBy,
            'participant_role' => 'actor',
            'participant_type' => 'character',
            'participant_id' => $openedBy,
            'is_active' => 1,
        ]);

        if ($targetId > 0) {
            $this->upsertParticipantRow($conflictId, [
                'character_id' => $targetId,
                'participant_role' => 'target',
                'participant_type' => 'character',
                'participant_id' => $targetId,
                'is_active' => 1,
            ]);
        }

        $this->insertActionRow($conflictId, [
            'actor_id' => $openedBy,
            'actor_type' => 'character',
            'action_type' => 'note',
            'action_kind' => 'proposal_created',
            'action_mode' => 'chat',
            'action_body' => $summary,
            'meta_json' => $this->toJson([
                'event_type' => 'conflict_proposal_created',
                'location_id' => $locationId,
                'target_id' => $targetId > 0 ? $targetId : null,
                'error_code' => $this->detectOverlapWarnings($locationId) ? 'conflict_overlap_detected' : '',
            ]),
        ], $targetId > 0 ? [[
            'target_type' => 'character',
            'target_id' => $targetId,
        ]] : []);

        $detail = $this->getConflict($conflictId);
        $detail['proposal'] = [
            'conflict_id' => $conflictId,
            'target_id' => $targetId,
            'summary' => $summary,
            'error_code' => '',
        ];

        $this->notifyConflictParticipants(
            $conflictId,
            $locationId,
            'Nuova proposta conflitto',
            'E stata avviata una proposta di conflitto nella location corrente.',
            'proposal_created',
            $openedBy,
        );

        return $detail;
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function respondProposal($payload, int $actorCharacterId, bool $isStaff = false): array
    {
        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            $this->failValidation('Conflitto non valido', 'conflict_proposal_not_found');
        }

        $response = strtolower(trim((string) ($data['response'] ?? $data['decision'] ?? '')));
        if (!in_array($response, ['accept', 'reject', 'escalate'], true)) {
            $this->failValidation('Risposta proposta non valida', 'conflict_proposal_invalid_response');
        }

        $conflict = $this->ensureConflictExists($conflictId);
        $status = strtolower((string) ($conflict->status ?? ''));
        if ($status !== self::STATUS_PROPOSAL && $this->hasColumn('conflicts', 'proposal_expires_at')) {
            $this->failValidation('La proposta non e piu disponibile', 'conflict_proposal_not_found');
        }

        if (!$isStaff && !$this->isParticipant($conflictId, $actorCharacterId)) {
            $this->failUnauthorized('Operazione non autorizzata', 'conflict_proposal_forbidden');
        }

        if ($this->hasColumn('conflicts', 'proposal_expires_at')) {
            $expiresAt = $this->parseDateTime($conflict->proposal_expires_at ?? null);
            if ($expiresAt !== null && $expiresAt < time()) {
                if ($this->hasColumn('conflicts', 'last_activity_at')) {
                    $this->execPrepared(
                        'UPDATE conflicts
                         SET status = ?, last_activity_at = NOW()
                         WHERE id = ?
                         LIMIT 1',
                        [self::STATUS_AWAITING, $conflictId],
                    );
                } else {
                    $this->execPrepared(
                        'UPDATE conflicts
                         SET status = ?
                         WHERE id = ?
                         LIMIT 1',
                        [self::STATUS_AWAITING, $conflictId],
                    );
                }

                $this->insertActionRow($conflictId, [
                    'actor_id' => null,
                    'actor_type' => 'system',
                    'action_type' => 'system',
                    'action_kind' => 'proposal_expired',
                    'action_mode' => 'system',
                    'action_body' => 'Proposta scaduta: conflitto inoltrato alla revisione staff.',
                    'resolution_type' => 'proposal',
                    'resolution_status' => 'expired',
                    'meta_json' => $this->toJson([
                        'event_type' => 'conflict_proposal_expired_escalated',
                        'error_code' => 'conflict_inactivity_escalated',
                    ]),
                ]);

                $this->failValidation('La proposta e scaduta ed e stata inoltrata allo staff', 'conflict_proposal_expired');
            }
        }

        $nextStatus = self::STATUS_OPEN;
        $resolutionStatus = 'accepted';
        $actionText = 'Proposta accettata.';

        if ($response === 'reject') {
            $nextStatus = self::STATUS_CLOSED;
            $resolutionStatus = 'rejected';
            $actionText = 'Proposta rifiutata.';
        } elseif ($response === 'escalate') {
            $nextStatus = self::STATUS_AWAITING;
            $resolutionStatus = 'escalated';
            $actionText = 'Proposta escalata allo staff.';
        }

        $setParts = ['status = ?'];
        $params = [$nextStatus];
        if ($nextStatus === self::STATUS_CLOSED) {
            $setParts[] = 'closing_timestamp = NOW()';
        }
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            $setParts[] = 'last_activity_at = NOW()';
        }
        $params[] = $conflictId;

        $this->execPrepared(
            'UPDATE conflicts SET ' . implode(', ', $setParts) . ' WHERE id = ? LIMIT 1',
            $params,
        );

        $this->insertActionRow($conflictId, [
            'actor_id' => $actorCharacterId,
            'actor_type' => 'character',
            'action_type' => 'system',
            'action_kind' => 'proposal_response',
            'action_mode' => 'manual',
            'action_body' => $actionText,
            'resolution_type' => 'proposal',
            'resolution_status' => $resolutionStatus,
            'resolved_at' => in_array($resolutionStatus, ['rejected', 'accepted'], true) ? $this->now() : null,
            'meta_json' => $this->toJson([
                'event_type' => 'conflict_proposal_responded',
                'response' => $response,
                'error_code' => '',
            ]),
        ]);

        $detail = $this->getConflict($conflictId);
        if ($nextStatus === self::STATUS_CLOSED) {
            $updatedConflict = !empty($detail['conflict']) ? $detail['conflict'] : $this->ensureConflictExists($conflictId);
            $detail['narrative'] = $this->processConflictNarrativeAction(
                'conflict_closed',
                $updatedConflict,
                $actorCharacterId,
                [
                    'note' => $actionText,
                    'states' => $this->extractNarrativeStateRows($data),
                    'lifecycle_transitions' => $this->extractLifecycleTransitionRows($data),
                    'meta' => [
                        'source' => 'proposal_response',
                        'response' => $response,
                        'resolution_status' => $resolutionStatus,
                    ],
                ],
            );
        }

        $statusMessage = $actionText;
        if ($response === 'accept') {
            $statusMessage = 'La proposta di conflitto e stata accettata.';
        } elseif ($response === 'reject') {
            $statusMessage = 'La proposta di conflitto e stata rifiutata.';
        } elseif ($response === 'escalate') {
            $statusMessage = 'La proposta di conflitto e stata inoltrata allo staff.';
        }

        $this->notifyConflictParticipants(
            $conflictId,
            (int) ($conflict->location_id ?? 0),
            'Aggiornamento proposta conflitto',
            $statusMessage,
            'proposal_' . $response,
            $actorCharacterId,
        );

        return $detail;
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */


    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function upsertParticipants($payload, int $actorCharacterId, bool $isStaff): array
    {
        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            $this->failValidation('Conflitto non valido', 'conflict_not_found');
        }

        $conflict = $this->ensureConflictExists($conflictId);
        $openedBy = (int) ($conflict->opened_by ?? 0);
        if (!$isStaff && $openedBy !== $actorCharacterId) {
            $this->failUnauthorized('Solo apertura conflitto o staff possono gestire i partecipanti', 'conflict_participants_forbidden');
        }

        $participants = $data['participants'] ?? [];
        if (!is_array($participants)) {
            $this->failValidation('Lista partecipanti non valida', 'conflict_participants_invalid');
        }

        foreach ($participants as $participant) {
            if (!is_object($participant) && !is_array($participant)) {
                continue;
            }
            $entry = is_object($participant) ? (array) $participant : $participant;
            $this->upsertParticipantRow($conflictId, $entry);
        }

        $this->insertActionRow($conflictId, [
            'actor_id' => $actorCharacterId,
            'actor_type' => 'character',
            'action_type' => 'system',
            'action_kind' => 'participants_upsert',
            'action_mode' => 'manual',
            'action_body' => 'Partecipanti aggiornati.',
            'meta_json' => $this->toJson([
                'event_type' => 'conflict_participants_upsert',
                'count' => count($participants),
            ]),
        ]);

        return $this->getConflict($conflictId);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function normalizeTargets(array $data): array
    {
        $targets = [];

        if (isset($data['targets']) && is_array($data['targets'])) {
            foreach ($data['targets'] as $target) {
                if (is_object($target)) {
                    $target = (array) $target;
                }
                if (!is_array($target)) {
                    continue;
                }

                $targetType = strtolower(trim((string) ($target['target_type'] ?? 'character')));
                $targetId = isset($target['target_id']) ? (int) $target['target_id'] : null;
                $teamKey = trim((string) ($target['team_key'] ?? ''));
                if ($teamKey === '') {
                    $teamKey = null;
                }

                $targets[] = [
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'team_key' => $teamKey,
                ];
            }
        }

        if (empty($targets)) {
            $targetType = trim((string) ($data['target_type'] ?? ''));
            $targetId = (int) ($data['target_id'] ?? 0);
            $teamKey = trim((string) ($data['team_key'] ?? ''));
            if ($targetType !== '' || $targetId > 0 || $teamKey !== '') {
                $targets[] = [
                    'target_type' => $targetType !== '' ? $targetType : 'character',
                    'target_id' => $targetId > 0 ? $targetId : null,
                    'team_key' => $teamKey !== '' ? $teamKey : null,
                ];
            }
        }

        if (empty($targets) && isset($data['target_ids']) && is_array($data['target_ids'])) {
            foreach ($data['target_ids'] as $targetId) {
                $targetId = (int) $targetId;
                if ($targetId <= 0) {
                    continue;
                }
                $targets[] = [
                    'target_type' => 'character',
                    'target_id' => $targetId,
                    'team_key' => null,
                ];
            }
        }

        return $targets;
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function executeAction($payload, int $actorCharacterId, bool $isStaff): array
    {
        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            $this->failValidation('Conflitto non valido', 'conflict_not_found');
        }

        $conflict = $this->ensureConflictExists($conflictId);
        $this->ensureWritable($conflict, $actorCharacterId, $isStaff);

        $actionBody = trim((string) ($data['action_body'] ?? $data['body'] ?? ''));
        $actionType = $this->normalizeActionType($data['action_type'] ?? 'action');
        if ($actionBody === '' && $actionType === 'action') {
            $this->failValidation('Testo azione richiesto', 'conflict_action_body_required');
        }

        $actorType = $this->normalizeActorType($data['actor_type'] ?? 'character');
        $actorId = (int) ($data['actor_id'] ?? 0);
        if ($actorType === 'character') {
            if ($actorId <= 0) {
                $actorId = $actorCharacterId;
            }
            if (!$isStaff && $actorId !== $actorCharacterId) {
                $this->failUnauthorized('Operazione non autorizzata', 'conflict_action_forbidden');
            }
        } else {
            $actorId = 0;
        }

        $targets = $this->normalizeTargets($data);

        $this->insertActionRow($conflictId, [
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'action_type' => $actionType,
            'action_kind' => trim((string) ($data['action_kind'] ?? '')),
            'action_mode' => trim((string) ($data['action_mode'] ?? '')),
            'chat_message_id' => isset($data['chat_message_id']) ? (int) $data['chat_message_id'] : 0,
            'resolution_type' => trim((string) ($data['resolution_type'] ?? '')),
            'resolution_status' => trim((string) ($data['resolution_status'] ?? '')),
            'resolved_at' => trim((string) ($data['resolved_at'] ?? '')),
            'action_body' => $actionBody !== '' ? $actionBody : '-',
            'meta_json' => array_key_exists('meta_json', $data)
                ? (is_string($data['meta_json']) ? $data['meta_json'] : $this->toJson($data['meta_json']))
                : $this->toJson([
                    'event_type' => 'conflict_action_execute',
                    'targets_count' => count($targets),
                ]),
        ], $targets);

        $nextStatus = trim((string) ($data['next_status'] ?? ''));
        if ($nextStatus !== '') {
            $this->setStatus([
                'conflict_id' => $conflictId,
                'status' => $nextStatus,
                'note' => 'Aggiornamento stato da azione',
                'silent' => 1,
            ], $actorCharacterId, $isStaff);
        }

        return $this->getConflict($conflictId);
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function addAction($payload, int $actorCharacterId, bool $isStaff): array
    {
        $data = $this->payloadToArray($payload);
        if (!array_key_exists('action_kind', $data)) {
            $data['action_kind'] = 'legacy_action_add';
        }
        if (!array_key_exists('action_mode', $data)) {
            $data['action_mode'] = 'legacy';
        }
        if (!array_key_exists('targets', $data)) {
            $targets = [];
            $targetId = (int) ($data['target_id'] ?? 0);
            if ($targetId > 0) {
                $targets[] = [
                    'target_type' => 'character',
                    'target_id' => $targetId,
                ];
            }
            $data['targets'] = $targets;
        }

        return $this->executeAction($data, $actorCharacterId, $isStaff);
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function setStatus($payload, int $actorCharacterId, bool $isStaff): array
    {
        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            $this->failValidation('Conflitto non valido', 'conflict_not_found');
        }

        $conflict = $this->ensureConflictExists($conflictId);
        $previousStatus = strtolower(trim((string) ($conflict->status ?? '')));
        $openedBy = (int) ($conflict->opened_by ?? 0);
        if (!$isStaff && $openedBy !== $actorCharacterId) {
            $this->ensureWritable($conflict, $actorCharacterId, $isStaff);
        }

        $status = $this->normalizeStatus($data['status'] ?? '', self::STATUS_OPEN);
        $setParts = ['status = ?'];
        $params = [$status];

        if ($status === self::STATUS_RESOLVED) {
            $setParts[] = 'resolved_at = NOW()';
        }
        if ($status === self::STATUS_CLOSED) {
            $setParts[] = 'closing_timestamp = NOW()';
            if ($this->hasColumn('conflicts', 'resolved_at')) {
                $setParts[] = 'resolved_at = COALESCE(resolved_at, NOW())';
            }
        }
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            $setParts[] = 'last_activity_at = NOW()';
        }

        $params[] = $conflictId;
        $this->execPrepared(
            'UPDATE conflicts SET '
            . implode(', ', $setParts)
            . ' WHERE id = ? LIMIT 1',
            $params,
        );

        $silent = ((int) ($data['silent'] ?? 0) === 1);
        if (!$silent) {
            $note = trim((string) ($data['note'] ?? ''));
            if ($note === '') {
                $note = 'Stato conflitto aggiornato a ' . $this->statusLabel($status) . '.';
            }

            $this->insertActionRow($conflictId, [
                'actor_id' => $actorCharacterId > 0 ? $actorCharacterId : null,
                'actor_type' => $actorCharacterId > 0 ? 'character' : 'system',
                'action_type' => 'system',
                'action_kind' => 'status_set',
                'action_mode' => 'manual',
                'action_body' => $note,
                'meta_json' => $this->toJson([
                    'event_type' => 'conflict_status_set',
                    'status' => $status,
                ]),
            ]);
        }

        $detail = $this->getConflict($conflictId);
        if (in_array($status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true) && $previousStatus !== $status) {
            $updatedConflict = !empty($detail['conflict']) ? $detail['conflict'] : $this->ensureConflictExists($conflictId);
            $eventType = $status === self::STATUS_RESOLVED ? 'conflict_resolved' : 'conflict_closed';
            $detail['narrative'] = $this->processConflictNarrativeAction(
                $eventType,
                $updatedConflict,
                $actorCharacterId,
                [
                    'note' => trim((string) ($data['note'] ?? '')),
                    'summary' => trim((string) ($data['outcome_summary'] ?? '')),
                    'verdict' => trim((string) ($data['verdict_text'] ?? '')),
                    'states' => $this->extractNarrativeStateRows($data),
                    'lifecycle_transitions' => $this->extractLifecycleTransitionRows($data),
                    'meta' => [
                        'source' => 'status_set',
                        'status' => $status,
                    ],
                ],
            );
        }

        if ($previousStatus !== $status) {
            $this->notifyConflictParticipants(
                $conflictId,
                (int) ($conflict->location_id ?? 0),
                'Stato conflitto aggiornato',
                'Nuovo stato: ' . $this->statusLabel($status) . '.',
                'status_' . $status,
                $actorCharacterId,
            );
        }

        return $detail;
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function performRoll($payload, int $actorCharacterId, bool $isStaff): array
    {
        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            $this->failValidation('Conflitto non valido', 'conflict_not_found');
        }

        $conflict = $this->ensureConflictExists($conflictId);
        $this->ensureWritable($conflict, $actorCharacterId, $isStaff);

        $actorId = (int) ($data['actor_id'] ?? 0);
        if ($actorId <= 0) {
            $actorId = $actorCharacterId;
        }
        if ($actorId <= 0) {
            $this->failValidation('Attore non valido', 'conflict_actor_required');
        }
        if (!$isStaff && $actorId !== $actorCharacterId) {
            $this->failUnauthorized('Operazione non autorizzata', 'conflict_roll_forbidden');
        }

        $mode = $this->normalizeResolutionMode($conflict->resolution_mode ?? $this->settingsService->mode());
        $resolver = $this->resolverFactory->forMode($mode);

        $rollPayload = $data;
        $rollPayload['conflict_id'] = $conflictId;
        $rollPayload['actor_id'] = $actorId;

        $result = $resolver->performRoll($rollPayload);

        $this->insertActionRow($conflictId, [
            'actor_id' => $actorId,
            'actor_type' => 'character',
            'action_type' => 'note',
            'action_kind' => 'roll',
            'action_mode' => $mode,
            'action_body' => 'Tiro registrato (' . $this->rollModeLabel($mode) . ')',
            'meta_json' => $this->toJson([
                'event_type' => 'conflict_roll',
                'mode' => $mode,
                'roll' => $result,
            ]),
        ]);

        $next = $this->normalizeStatus($conflict->status ?? self::STATUS_OPEN, self::STATUS_OPEN);
        if ($next === self::STATUS_OPEN) {
            $this->setStatus([
                'conflict_id' => $conflictId,
                'status' => self::STATUS_ACTIVE,
                'silent' => 1,
            ], $actorCharacterId, $isStaff);
        } else {
            $this->updateLastActivity($conflictId);
        }

        $detail = $this->getConflict($conflictId);
        $detail['roll'] = $result;
        return $detail;
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function resolveConflict($payload, int $actorCharacterId, bool $isStaff): array
    {
        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            $this->failValidation('Conflitto non valido', 'conflict_not_found');
        }

        $conflict = $this->ensureConflictExists($conflictId);
        $this->ensureWritable($conflict, $actorCharacterId, $isStaff);

        $outcomeSummary = trim((string) ($data['outcome_summary'] ?? ''));
        $verdictText = trim((string) ($data['verdict_text'] ?? ''));
        if ($outcomeSummary === '' && $verdictText === '') {
            $this->failValidation('Inserisci almeno un testo di risoluzione', 'conflict_resolution_required');
        }

        $mode = $this->normalizeResolutionMode($conflict->resolution_mode ?? $this->settingsService->mode());
        $resolver = $this->resolverFactory->forMode($mode);
        $resolutionResult = $resolver->resolveConflict([
            'conflict_id' => $conflictId,
            'outcome_summary' => $outcomeSummary,
            'verdict_text' => $verdictText,
            'resolution_authority' => $conflict->resolution_authority ?? null,
        ]);

        $setParts = [
            'status = ?',
            'outcome_summary = ?',
            'verdict_text = ?',
            'verdict_meta_json = ?',
            'resolved_by = ?',
            'resolved_at = NOW()',
        ];
        $params = [
            self::STATUS_RESOLVED,
            $outcomeSummary !== '' ? $outcomeSummary : null,
            $verdictText !== '' ? $verdictText : null,
            $this->toJson($resolutionResult),
            $actorCharacterId,
        ];
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            $setParts[] = 'last_activity_at = NOW()';
        }

        $params[] = $conflictId;
        $this->execPrepared(
            'UPDATE conflicts SET '
            . implode(', ', $setParts)
            . ' WHERE id = ? LIMIT 1',
            $params,
        );

        $this->insertActionRow($conflictId, [
            'actor_id' => $actorCharacterId,
            'actor_type' => 'character',
            'action_type' => 'verdict',
            'action_kind' => 'resolve',
            'action_mode' => $mode,
            'action_body' => $outcomeSummary !== '' ? $outcomeSummary : 'Conflitto risolto.',
            'resolution_type' => $mode,
            'resolution_status' => 'resolved',
            'resolved_at' => $this->now(),
            'meta_json' => $this->toJson([
                'event_type' => 'conflict_resolved',
                'resolution' => $resolutionResult,
            ]),
        ]);

        $updatedConflict = $this->ensureConflictExists($conflictId);
        $narrativeResult = $this->processConflictNarrativeAction(
            'conflict_resolved',
            $updatedConflict,
            $actorCharacterId,
            [
                'summary' => $outcomeSummary,
                'verdict' => $verdictText,
                'states' => $this->extractNarrativeStateRows($data),
                'lifecycle_transitions' => $this->extractLifecycleTransitionRows($data),
                'meta' => [
                    'resolution_mode' => $mode,
                    'resolution' => $resolutionResult,
                ],
            ],
        );

        $detail = $this->getConflict($conflictId);
        $detail['narrative'] = $narrativeResult;

        $summaryMessage = $outcomeSummary !== '' ? $outcomeSummary : 'Conflitto risolto.';
        $this->notifyConflictParticipants(
            $conflictId,
            (int) ($conflict->location_id ?? 0),
            'Conflitto risolto',
            $summaryMessage,
            'resolved',
            $actorCharacterId,
        );

        return $detail;
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function closeConflict($payload, int $actorCharacterId, bool $isStaff): array
    {
        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        if ($conflictId <= 0) {
            $this->failValidation('Conflitto non valido', 'conflict_not_found');
        }

        $conflict = $this->ensureConflictExists($conflictId);
        $openedBy = (int) ($conflict->opened_by ?? 0);
        if (!$isStaff && $openedBy !== $actorCharacterId) {
            $this->ensureWritable($conflict, $actorCharacterId, $isStaff);
        }

        $mode = $this->normalizeResolutionMode($conflict->resolution_mode ?? $this->settingsService->mode());
        $resolver = $this->resolverFactory->forMode($mode);
        $closeInfo = $resolver->closeConflict([
            'conflict_id' => $conflictId,
            'closed_by' => $actorCharacterId,
        ]);

        $setParts = [
            'status = ?',
            'closing_timestamp = NOW()',
        ];
        $params = [self::STATUS_CLOSED];
        if ($this->hasColumn('conflicts', 'last_activity_at')) {
            $setParts[] = 'last_activity_at = NOW()';
        }

        $params[] = $conflictId;
        $this->execPrepared(
            'UPDATE conflicts SET '
            . implode(', ', $setParts)
            . ' WHERE id = ? LIMIT 1',
            $params,
        );

        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            $note = 'Conflitto chiuso.';
        }

        $this->insertActionRow($conflictId, [
            'actor_id' => $actorCharacterId > 0 ? $actorCharacterId : null,
            'actor_type' => $actorCharacterId > 0 ? 'character' : 'system',
            'action_type' => 'system',
            'action_kind' => 'close',
            'action_mode' => $mode,
            'action_body' => $note,
            'resolution_status' => 'closed',
            'resolved_at' => $this->now(),
            'meta_json' => $this->toJson([
                'event_type' => 'conflict_closed',
                'close_info' => $closeInfo,
            ]),
        ]);

        $updatedConflict = $this->ensureConflictExists($conflictId);
        $narrativeResult = $this->processConflictNarrativeAction(
            'conflict_closed',
            $updatedConflict,
            $actorCharacterId,
            [
                'note' => $note,
                'states' => $this->extractNarrativeStateRows($data),
                'lifecycle_transitions' => $this->extractLifecycleTransitionRows($data),
                'meta' => [
                    'resolution_mode' => $mode,
                    'close_info' => $closeInfo,
                ],
            ],
        );

        $detail = $this->getConflict($conflictId);
        $detail['narrative'] = $narrativeResult;

        $this->notifyConflictParticipants(
            $conflictId,
            (int) ($conflict->location_id ?? 0),
            'Conflitto chiuso',
            $note,
            'closed',
            $actorCharacterId,
        );

        return $detail;
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function forceOpenConflict($payload, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureStaff($isStaff);

        $data = $this->payloadToArray($payload);
        $data['status'] = self::STATUS_OPEN;
        $data['note'] = trim((string) ($data['note'] ?? 'Apertura forzata conflitto da staff.'));
        return $this->setStatus($data, $actorCharacterId, true);
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function forceCloseConflict($payload, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureStaff($isStaff);

        $data = $this->payloadToArray($payload);
        $data['note'] = trim((string) ($data['note'] ?? 'Chiusura forzata conflitto da staff.'));
        return $this->closeConflict($data, $actorCharacterId, true);
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function editConflictLog($payload, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureStaff($isStaff);

        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        $actionId = (int) ($data['action_id'] ?? 0);
        $actionBody = trim((string) ($data['action_body'] ?? ''));

        if ($conflictId <= 0 || $actionId <= 0) {
            $this->failValidation('Dati log non validi', 'conflict_log_invalid');
        }
        if ($actionBody === '') {
            $this->failValidation('Testo log richiesto', 'conflict_log_body_required');
        }

        $this->execPrepared(
            'UPDATE conflict_actions '
            . 'SET action_body = ? '
            . 'WHERE id = ? '
            . 'AND conflict_id = ? '
            . 'LIMIT 1',
            [$actionBody, $actionId, $conflictId],
        );

        $this->insertActionRow($conflictId, [
            'actor_id' => $actorCharacterId,
            'actor_type' => 'character',
            'action_type' => 'system',
            'action_kind' => 'edit_conflict_log',
            'action_mode' => 'staff',
            'action_body' => 'Log conflitto aggiornato da staff.',
            'meta_json' => $this->toJson([
                'event_type' => 'conflict_log_edited',
                'edited_action_id' => $actionId,
            ]),
        ]);

        return $this->getConflict($conflictId);
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string,mixed>
     */
    public function overrideRoll($payload, int $actorCharacterId, bool $isStaff): array
    {
        $this->ensureStaff($isStaff);

        $data = $this->payloadToArray($payload);
        $conflictId = (int) ($data['conflict_id'] ?? 0);
        $targetActorId = (int) ($data['actor_id'] ?? 0);
        $rollType = trim((string) ($data['roll_type'] ?? 'single_roll'));
        $dieUsed = trim((string) ($data['die_used'] ?? 'd20'));
        $baseRoll = (int) ($data['base_roll'] ?? 0);
        $modifiers = (float) ($data['modifiers'] ?? 0);
        $finalResult = (float) ($data['final_result'] ?? 0);
        $criticalFlag = trim((string) ($data['critical_flag'] ?? 'none'));
        $margin = isset($data['margin']) ? (float) $data['margin'] : null;

        if ($conflictId <= 0 || $targetActorId <= 0) {
            $this->failValidation('Dati tiro non validi', 'conflict_roll_invalid');
        }

        if ($baseRoll <= 0) {
            $baseRoll = 1;
        }

        if (!in_array($criticalFlag, ['none', 'success', 'failure'], true)) {
            $criticalFlag = 'none';
        }

        $meta = $this->toJson([
            'event_type' => 'conflict_roll_override',
            'override_by' => $actorCharacterId,
            'note' => trim((string) ($data['note'] ?? '')),
        ]);

        $this->execPrepared(
            'INSERT INTO conflict_roll_log SET '
            . 'conflict_id = ?, '
            . 'actor_id = ?, '
            . 'roll_type = ?, '
            . 'die_used = ?, '
            . 'base_roll = ?, '
            . 'modifiers = ?, '
            . 'final_result = ?, '
            . 'critical_flag = ?, '
            . 'margin = ?, '
            . 'meta_json = ?, '
            . 'timestamp = NOW()',
            [
                $conflictId,
                $targetActorId,
                $rollType,
                $dieUsed,
                $baseRoll,
                $modifiers,
                $finalResult,
                $criticalFlag,
                $margin,
                $meta,
            ],
        );

        $this->insertActionRow($conflictId, [
            'actor_id' => $actorCharacterId,
            'actor_type' => 'character',
            'action_type' => 'system',
            'action_kind' => 'override_roll',
            'action_mode' => 'staff',
            'action_body' => 'Override tiro conflitto applicato.',
            'meta_json' => $meta,
        ]);

        return $this->getConflict($conflictId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        return $this->settingsService->getSettings();
    }

    /**
     * @param array<string,mixed>|object $payload
     * @return array<string, mixed>
     */
    public function updateSettings($payload): array
    {
        return $this->settingsService->updateSettings($payload);
    }
}

