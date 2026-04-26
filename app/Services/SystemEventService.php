<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class SystemEventService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var DbAdapterInterface */
    private $db;
    /** @var SystemEventResolverService */
    private $resolverService;
    /** @var SystemEventEffectService */
    private $effectService;
    /** @var SystemEventParticipationService */
    private $participationService;
    /** @var SystemEventRewardService */
    private $rewardService;
    /** @var NarrativeTagService|null */
    private $tagService = null;

    public function __construct(
        DbAdapterInterface $db = null,
        SystemEventResolverService $resolverService = null,
        SystemEventEffectService $effectService = null,
        SystemEventParticipationService $participationService = null,
        SystemEventRewardService $rewardService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->resolverService = $resolverService ?: new SystemEventResolverService($this->db);
        $this->effectService = $effectService ?: new SystemEventEffectService($this->db);
        $this->participationService = $participationService ?: new SystemEventParticipationService($this->db);
        $this->rewardService = $rewardService ?: new SystemEventRewardService($this->db);
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
        } catch (\Throwable $e) {
            // no-op: rollback best effort
        }
    }

    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    private function decodeEvent(array $row): array
    {
        if (isset($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta_json'] = is_array($decoded) ? $decoded : (object) [];
        }
        return $row;
    }

    private function getConfig(string $key, string $fallback = ''): string
    {
        $row = $this->firstPrepared(
            'SELECT `value`
             FROM sys_configs
             WHERE `key` = ?
             LIMIT 1',
            [$key],
        );
        if (empty($row)) {
            return $fallback;
        }
        return trim((string) ($row->value ?? $fallback));
    }

    private function tagService(): NarrativeTagService
    {
        if ($this->tagService instanceof NarrativeTagService) {
            return $this->tagService;
        }
        $this->tagService = new NarrativeTagService($this->db);
        return $this->tagService;
    }

    public function isEnabled(): bool
    {
        $raw = strtolower($this->getConfig('system_events_enabled', '1'));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public function ensureEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw AppError::validation('Funzionalita Eventi di sistema disattivata', [], 'system_event_feature_disabled');
        }
    }

    private function runLazyMaintenance(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $this->resolverService->runMaintenance(false);
        } catch (\Throwable $e) {
            // Non bloccare le API list/get se la manutenzione fallisce.
        }
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

    private function normalizeEnum(string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim($value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function validateSchedule(?string $startsAt, ?string $endsAt): void
    {
        if ($startsAt !== null && $endsAt !== null) {
            if (strtotime($endsAt) <= strtotime($startsAt)) {
                throw AppError::validation('Intervallo temporale evento non valido', [], 'system_event_schedule_invalid');
            }
        }
    }

    public function getById(int $eventId): array
    {
        $row = $this->firstPrepared(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM system_event_participations p WHERE p.system_event_id = e.id AND p.status = "joined") AS participants_count
             FROM system_events e
             WHERE e.id = ?
             LIMIT 1',
            [$eventId],
        );
        if (empty($row)) {
            throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
        }
        $event = $this->decodeEvent($this->rowToArray($row));
        $event['narrative_tags'] = $this->tagService()->listAssignments(
            NarrativeTagService::ENTITY_SYSTEM_EVENT,
            (int) ($event['id'] ?? 0),
            false,
        );
        $event['narrative_tag_ids'] = array_map(static function ($tag): int {
            return (int) ($tag['id'] ?? 0);
        }, $event['narrative_tags']);
        return $event;
    }

    public function listForGame(array $filters, int $viewerCharacterId, bool $isStaff = false, array $viewerFactionIds = [], int $limit = 20, int $page = 1): array
    {
        $this->ensureEnabled();
        $this->runLazyMaintenance();

        $limit = max(1, min(100, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        $params = [];
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '') {
            $status = $this->normalizeEnum($status, [
                self::STATUS_DRAFT,
                self::STATUS_SCHEDULED,
                self::STATUS_ACTIVE,
                self::STATUS_COMPLETED,
                self::STATUS_CANCELLED,
            ], self::STATUS_ACTIVE);
            $where[] = 'e.status = ?';
            $params[] = $status;
        } else {
            $where[] = "e.status IN ('scheduled','active','completed')";
        }

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $where[] = 'e.type = ?';
            $params[] = $type;
        }

        $scopeType = strtolower(trim((string) ($filters['scope_type'] ?? '')));
        if ($scopeType !== '') {
            $scopeType = $this->normalizeEnum($scopeType, ['global', 'map', 'location', 'faction', 'character'], 'global');
            $where[] = 'e.scope_type = ?';
            $params[] = $scopeType;
        }

        $scopeId = (int) ($filters['scope_id'] ?? 0);
        if ($scopeId > 0) {
            $where[] = 'e.scope_id = ?';
            $params[] = $scopeId;
        }

        $participantMode = strtolower(trim((string) ($filters['participant_mode'] ?? '')));
        if ($participantMode !== '') {
            $participantMode = $this->normalizeEnum($participantMode, ['character', 'faction'], 'character');
            $where[] = 'e.participant_mode = ?';
            $params[] = $participantMode;
        }

        $tagIds = $this->tagService()->parseTagIds($filters['tag_ids'] ?? []);
        if (!empty($tagIds)) {
            $entityIds = $this->tagService()->filterEntityIdsByTagIds(
                NarrativeTagService::ENTITY_SYSTEM_EVENT,
                $tagIds,
                false,
            );
            if (empty($entityIds)) {
                return ['total' => 0, 'page' => $page, 'limit' => $limit, 'rows' => []];
            }
            $where[] = 'e.id IN (' . implode(',', array_map('intval', $entityIds)) . ')';
        }

        if ($isStaff) {
            // Staff: nessun filtro ulteriore sulla visibilita.
        } else {
            $visibilityClauses = ["e.visibility = 'public'"];
            if ($viewerCharacterId > 0) {
                $privateChar = "(e.visibility = 'private' AND EXISTS (
                    SELECT 1 FROM system_event_participations p
                    WHERE p.system_event_id = e.id
                      AND p.status = 'joined'
                      AND p.participant_mode = 'character'
                      AND p.character_id = ?
                ))";
                $visibilityClauses[] = $privateChar;
                $params[] = $viewerCharacterId;

                $factionIds = [];
                foreach ($viewerFactionIds as $factionId) {
                    $factionId = (int) $factionId;
                    if ($factionId > 0) {
                        $factionIds[] = $factionId;
                    }
                }
                if (!empty($factionIds)) {
                    $factionPlaceholders = implode(',', array_fill(0, count($factionIds), '?'));
                    $privateFaction = "(e.visibility = 'private' AND EXISTS (
                        SELECT 1 FROM system_event_participations p
                        WHERE p.system_event_id = e.id
                          AND p.status = 'joined'
                          AND p.participant_mode = 'faction'
                          AND p.faction_id IN (' . $factionPlaceholders . ')
                    ))";
                    $visibilityClauses[] = $privateFaction;
                    $params = array_merge($params, $factionIds);
                }
            }

            $where[] = '(' . implode(' OR ', $visibilityClauses) . ')';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM system_events e
             ' . $whereClause,
            $params,
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM system_event_participations p WHERE p.system_event_id = e.id AND p.status = "joined") AS participants_count
             FROM system_events e
             ' . $whereClause . '
             ORDER BY FIELD(e.status, "active","scheduled","completed","cancelled","draft"),
                      e.starts_at ASC,
                      e.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $out = [];
        foreach ($rows as $row) {
            $event = $this->decodeEvent($this->rowToArray($row));
            $event['viewer_joined'] = $this->participationService->viewerHasJoined(
                (int) ($event['id'] ?? 0),
                $viewerCharacterId,
                $viewerFactionIds,
            ) ? 1 : 0;
            $out[] = $event;
        }
        $out = $this->tagService()->attachTagsToRows(
            NarrativeTagService::ENTITY_SYSTEM_EVENT,
            $out,
            'id',
            'narrative_tags',
            false,
        );

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $out,
        ];
    }

    public function listForHomepageFeed(int $limit = 6): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $limit = max(1, min(20, (int) $limit));

        try {
            $rows = $this->fetchPrepared(
                'SELECT e.id, e.title, e.description, e.type, e.status, e.starts_at, e.ends_at, e.date_created
                 FROM system_events e
                 WHERE e.visibility = "public"
                   AND e.show_on_homepage_feed = 1
                   AND e.status IN ("scheduled","active")
                 ORDER BY FIELD(e.status, "active","scheduled"), COALESCE(e.starts_at, e.date_created) ASC, e.id DESC
                 LIMIT ?',
                [$limit],
            );
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeEvent($this->rowToArray($row));
        }

        return $out;
    }

    public function getForGame(int $eventId, int $viewerCharacterId, bool $isStaff = false, array $viewerFactionIds = []): array
    {
        $this->ensureEnabled();
        $this->runLazyMaintenance();

        $event = $this->getById($eventId);
        $visibility = strtolower(trim((string) ($event['visibility'] ?? 'public')));

        if (!$isStaff) {
            $allowed = ($visibility === 'public');
            if (!$allowed && $visibility === 'private') {
                $allowed = $this->participationService->viewerHasJoined($eventId, $viewerCharacterId, $viewerFactionIds);
            }
            if (!$allowed) {
                throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
            }
        }

        $event['effects'] = $this->effectService->listByEvent($eventId);
        $event['participations'] = $this->participationService->listByEvent($eventId);
        $event['viewer_joined'] = $this->participationService->viewerHasJoined($eventId, $viewerCharacterId, $viewerFactionIds) ? 1 : 0;

        return $event;
    }

    public function listForAdmin(array $filters, int $limit = 20, int $page = 1, string $sort = 'id|DESC'): array
    {
        $this->ensureEnabled();
        $this->runLazyMaintenance();

        $limit = max(1, min(200, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        $type = trim((string) ($filters['type'] ?? ''));
        if ($type !== '') {
            $where[] = 'e.type = ?';
            $params[] = $type;
        }
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '') {
            $status = $this->normalizeEnum($status, [
                self::STATUS_DRAFT,
                self::STATUS_SCHEDULED,
                self::STATUS_ACTIVE,
                self::STATUS_COMPLETED,
                self::STATUS_CANCELLED,
            ], self::STATUS_DRAFT);
            $where[] = 'e.status = ?';
            $params[] = $status;
        }
        $visibility = strtolower(trim((string) ($filters['visibility'] ?? '')));
        if ($visibility !== '') {
            $visibility = $this->normalizeEnum($visibility, ['public', 'staff_only', 'private'], 'public');
            $where[] = 'e.visibility = ?';
            $params[] = $visibility;
        }
        $scopeType = strtolower(trim((string) ($filters['scope_type'] ?? '')));
        if ($scopeType !== '') {
            $scopeType = $this->normalizeEnum($scopeType, ['global', 'map', 'location', 'faction', 'character'], 'global');
            $where[] = 'e.scope_type = ?';
            $params[] = $scopeType;
        }
        $participantMode = strtolower(trim((string) ($filters['participant_mode'] ?? '')));
        if ($participantMode !== '') {
            $participantMode = $this->normalizeEnum($participantMode, ['character', 'faction'], 'character');
            $where[] = 'e.participant_mode = ?';
            $params[] = $participantMode;
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(e.title LIKE ? OR e.description LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $tagIds = $this->tagService()->parseTagIds($filters['tag_ids'] ?? []);
        if (!empty($tagIds)) {
            $entityIds = $this->tagService()->filterEntityIdsByTagIds(
                NarrativeTagService::ENTITY_SYSTEM_EVENT,
                $tagIds,
                false,
            );
            if (empty($entityIds)) {
                return ['total' => 0, 'page' => $page, 'limit' => $limit, 'rows' => []];
            }
            $where[] = 'e.id IN (' . implode(',', array_map('intval', $entityIds)) . ')';
        }

        $allowedSortFields = ['id', 'title', 'type', 'status', 'visibility', 'show_on_homepage_feed', 'scope_type', 'participant_mode', 'starts_at', 'ends_at', 'date_created'];
        $sortChunks = explode('|', (string) $sort);
        $sortField = in_array($sortChunks[0], $allowedSortFields, true) ? $sortChunks[0] : 'id';
        $sortDirection = (strtoupper($sortChunks[1] ?? 'DESC') === 'ASC') ? 'ASC' : 'DESC';

        $whereClause = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM system_events e
             ' . $whereClause,
            $params,
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM system_event_participations p WHERE p.system_event_id = e.id AND p.status = "joined") AS participants_count
             FROM system_events e
             ' . $whereClause . '
             ORDER BY e.' . $sortField . ' ' . $sortDirection . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeEvent($this->rowToArray($row));
        }
        $out = $this->tagService()->attachTagsToRows(
            NarrativeTagService::ENTITY_SYSTEM_EVENT,
            $out,
            'id',
            'narrative_tags',
            true,
        );

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $out,
        ];
    }

    public function create(array $data, int $actorCharacterId = 0): array
    {
        $this->ensureEnabled();

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw AppError::validation('Titolo evento obbligatorio', [], 'system_event_schedule_invalid');
        }

        $description = trim((string) ($data['description'] ?? ''));
        $type = trim((string) ($data['type'] ?? 'general'));
        if ($type === '') {
            $type = 'general';
        }
        $status = $this->normalizeEnum(
            strtolower(trim((string) ($data['status'] ?? self::STATUS_DRAFT))),
            [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_DRAFT,
        );
        $visibilityDefault = $this->getConfig('system_events_default_visibility', 'public');
        $visibility = $this->normalizeEnum(
            strtolower(trim((string) ($data['visibility'] ?? $visibilityDefault))),
            ['public', 'staff_only', 'private'],
            'public',
        );
        $showOnHomepageFeed = isset($data['show_on_homepage_feed'])
            ? ((int) ((int) $data['show_on_homepage_feed'] === 1))
            : 0;
        $scopeType = $this->normalizeEnum(
            strtolower(trim((string) ($data['scope_type'] ?? 'global'))),
            ['global', 'map', 'location', 'faction', 'character'],
            'global',
        );
        $scopeId = (int) ($data['scope_id'] ?? 0);
        if ($scopeType === 'global') {
            $scopeId = 0;
        }
        $participantMode = $this->normalizeEnum(
            strtolower(trim((string) ($data['participant_mode'] ?? 'character'))),
            ['character', 'faction'],
            'character',
        );
        $startsAt = $this->normalizeDateTime($data['starts_at'] ?? null);
        $endsAt = $this->normalizeDateTime($data['ends_at'] ?? null);
        $this->validateSchedule($startsAt, $endsAt);

        if ($status === self::STATUS_SCHEDULED && $startsAt === null) {
            throw AppError::validation('Data inizio obbligatoria per evento programmato', [], 'system_event_schedule_invalid');
        }
        if ($status === self::STATUS_ACTIVE && $startsAt === null) {
            $startsAt = date('Y-m-d H:i:s');
        }

        $recurrence = $this->normalizeEnum(
            strtolower(trim((string) ($data['recurrence'] ?? 'none'))),
            ['none', 'daily', 'weekly', 'monthly'],
            'none',
        );
        $nextRunAt = ($status === self::STATUS_SCHEDULED) ? $startsAt : null;

        $metaJson = json_encode((array) ($data['meta_json'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($metaJson)) {
            $metaJson = '{}';
        }

        $this->execPrepared(
            'INSERT INTO system_events
             (title, description, type, status, visibility, show_on_homepage_feed, scope_type, scope_id, participant_mode, starts_at, ends_at, recurrence, next_run_at, last_activity_at, meta_json, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)',
            [
                $title,
                $description !== '' ? $description : null,
                $type,
                $status,
                $visibility,
                $showOnHomepageFeed,
                $scopeType,
                ($scopeId > 0 ? $scopeId : null),
                $participantMode,
                $startsAt,
                $endsAt,
                $recurrence,
                $nextRunAt,
                $metaJson,
                ($actorCharacterId > 0 ? $actorCharacterId : null),
                ($actorCharacterId > 0 ? $actorCharacterId : null),
            ],
        );

        $id = (int) $this->db->lastInsertId();
        if ($id <= 0) {
            throw AppError::validation('Creazione evento fallita', [], 'system_event_schedule_invalid');
        }

        if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_COMPLETED], true)) {
            $this->resolverService->handleStatusTransition($id, self::STATUS_DRAFT, $status, true);
        }

        if (!empty($data['tag_ids']) && is_array($data['tag_ids'])) {
            $this->tagService()->syncAssignments('system_event', $id, array_map('intval', $data['tag_ids']), $actorCharacterId);
        }
        AuditLogService::writeEvent('system_events.create', ['id' => $id, 'title' => $title], 'admin');

        return $this->getById($id);
    }

    public function update(int $eventId, array $data, int $actorCharacterId = 0): array
    {
        $this->ensureEnabled();
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
        }

        $current = $this->getById($eventId);
        $fields = [];
        $params = [];

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                throw AppError::validation('Titolo evento obbligatorio', [], 'system_event_schedule_invalid');
            }
            $fields[] = 'title = ?';
            $params[] = $title;
        }
        if (array_key_exists('description', $data)) {
            $description = trim((string) $data['description']);
            $fields[] = 'description = ?';
            $params[] = ($description !== '' ? $description : null);
        }
        if (array_key_exists('type', $data)) {
            $type = trim((string) $data['type']);
            if ($type === '') {
                $type = 'general';
            }
            $fields[] = 'type = ?';
            $params[] = $type;
        }
        if (array_key_exists('visibility', $data)) {
            $visibility = $this->normalizeEnum(
                strtolower(trim((string) $data['visibility'])),
                ['public', 'staff_only', 'private'],
                'public',
            );
            $fields[] = 'visibility = ?';
            $params[] = $visibility;
        }
        if (array_key_exists('show_on_homepage_feed', $data)) {
            $showOnHomepageFeed = ((int) ((int) $data['show_on_homepage_feed'] === 1));
            $fields[] = 'show_on_homepage_feed = ?';
            $params[] = $showOnHomepageFeed;
        }
        if (array_key_exists('scope_type', $data)) {
            $scopeType = $this->normalizeEnum(
                strtolower(trim((string) $data['scope_type'])),
                ['global', 'map', 'location', 'faction', 'character'],
                'global',
            );
            $fields[] = 'scope_type = ?';
            $params[] = $scopeType;
            if ($scopeType === 'global') {
                $fields[] = 'scope_id = NULL';
            }
        }
        if (array_key_exists('scope_id', $data)) {
            $scopeId = (int) $data['scope_id'];
            $fields[] = 'scope_id = ?';
            $params[] = ($scopeId > 0 ? $scopeId : null);
        }
        if (array_key_exists('participant_mode', $data)) {
            $participantMode = $this->normalizeEnum(
                strtolower(trim((string) $data['participant_mode'])),
                ['character', 'faction'],
                'character',
            );
            $fields[] = 'participant_mode = ?';
            $params[] = $participantMode;
        }

        $newStarts = array_key_exists('starts_at', $data)
            ? $this->normalizeDateTime($data['starts_at'])
            : $this->normalizeDateTime($current['starts_at'] ?? null);
        $newEnds = array_key_exists('ends_at', $data)
            ? $this->normalizeDateTime($data['ends_at'])
            : $this->normalizeDateTime($current['ends_at'] ?? null);
        $this->validateSchedule($newStarts, $newEnds);

        if (array_key_exists('starts_at', $data)) {
            $fields[] = 'starts_at = ?';
            $params[] = $newStarts;
        }
        if (array_key_exists('ends_at', $data)) {
            $fields[] = 'ends_at = ?';
            $params[] = $newEnds;
        }

        if (array_key_exists('recurrence', $data)) {
            $recurrence = $this->normalizeEnum(
                strtolower(trim((string) $data['recurrence'])),
                ['none', 'daily', 'weekly', 'monthly'],
                'none',
            );
            $fields[] = 'recurrence = ?';
            $params[] = $recurrence;
        }

        if (array_key_exists('meta_json', $data)) {
            $metaJson = json_encode((array) $data['meta_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($metaJson)) {
                $metaJson = '{}';
            }
            $fields[] = 'meta_json = ?';
            $params[] = $metaJson;
        }

        if (array_key_exists('status', $data)) {
            $status = $this->normalizeEnum(
                strtolower(trim((string) $data['status'])),
                [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
                self::STATUS_DRAFT,
            );
            if ($status === self::STATUS_SCHEDULED && $newStarts === null) {
                throw AppError::validation('Data inizio obbligatoria per evento programmato', [], 'system_event_schedule_invalid');
            }
            if ($status !== (string) ($current['status'] ?? '')) {
                $this->setStatus($eventId, $status, $actorCharacterId, false);
            }
        }

        if (!empty($fields)) {
            $fields[] = 'updated_by = ?';
            $params[] = ($actorCharacterId > 0 ? $actorCharacterId : null);
            $fields[] = 'last_activity_at = NOW()';
            $params[] = $eventId;
            $this->execPrepared('UPDATE system_events SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        }

        if (array_key_exists('tag_ids', $data)) {
            $tagIds = is_array($data['tag_ids']) ? array_map('intval', $data['tag_ids']) : [];
            $this->tagService()->syncAssignments('system_event', $eventId, $tagIds, $actorCharacterId);
        }
        AuditLogService::writeEvent('system_events.update', ['id' => $eventId], 'admin');

        return $this->getById($eventId);
    }

    public function delete(int $eventId): array
    {
        $this->ensureEnabled();
        $eventId = (int) $eventId;
        if ($eventId <= 0) {
            throw AppError::notFound('Evento di sistema non trovato', [], 'system_event_not_found');
        }
        $this->getById($eventId);

        $this->begin();
        try {
            $this->execPrepared('DELETE FROM system_event_reward_logs WHERE system_event_id = ?', [$eventId]);
            $this->execPrepared('DELETE FROM system_event_participations WHERE system_event_id = ?', [$eventId]);
            $this->execPrepared('DELETE FROM system_event_effects WHERE system_event_id = ?', [$eventId]);
            $this->execPrepared('DELETE FROM system_event_quest_links WHERE system_event_id = ?', [$eventId]);
            $this->execPrepared('DELETE FROM system_events WHERE id = ?', [$eventId]);
            $this->commit();
            AuditLogService::writeEvent('system_events.delete', ['id' => $eventId], 'admin');
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['deleted' => 1];
    }

    public function setStatus(int $eventId, string $status, int $actorCharacterId = 0, bool $force = false): array
    {
        $this->ensureEnabled();
        $event = $this->getById($eventId);
        $oldStatus = (string) ($event['status'] ?? self::STATUS_DRAFT);
        $newStatus = $this->normalizeEnum(
            strtolower(trim($status)),
            [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_DRAFT,
        );

        if (!$force && !$this->canTransition($oldStatus, $newStatus)) {
            throw AppError::validation('Transizione stato evento non valida', [], 'system_event_invalid_state');
        }
        if ($oldStatus === $newStatus) {
            return $event;
        }

        $nextRunAt = null;
        if ($newStatus === self::STATUS_SCHEDULED) {
            $startsAt = $this->normalizeDateTime($event['starts_at'] ?? null);
            if ($startsAt === null) {
                throw AppError::validation('Data inizio obbligatoria per evento programmato', [], 'system_event_schedule_invalid');
            }
            $nextRunAt = $startsAt;
        }

        $this->execPrepared(
            'UPDATE system_events SET
                status = ?,
                next_run_at = ?,
                updated_by = ?,
                last_activity_at = NOW()
             WHERE id = ?',
            [$newStatus, $nextRunAt, ($actorCharacterId > 0 ? $actorCharacterId : null), $eventId],
        );

        $this->resolverService->handleStatusTransition((int) $eventId, $oldStatus, $newStatus, true);
        return $this->getById((int) $eventId);
    }

    private function canTransition(string $from, string $to): bool
    {
        $map = [
            self::STATUS_DRAFT => [self::STATUS_SCHEDULED, self::STATUS_ACTIVE, self::STATUS_CANCELLED],
            self::STATUS_SCHEDULED => [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_CANCELLED],
            self::STATUS_ACTIVE => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
            self::STATUS_COMPLETED => [self::STATUS_SCHEDULED],
            self::STATUS_CANCELLED => [self::STATUS_DRAFT, self::STATUS_SCHEDULED],
        ];
        if (!isset($map[$from])) {
            return false;
        }
        return in_array($to, $map[$from], true);
    }

    public function maintenanceRun(bool $force = true): array
    {
        $this->ensureEnabled();
        return $this->resolverService->runMaintenance($force);
    }

    public function listEffects(int $eventId): array
    {
        $this->ensureEnabled();
        $this->getById($eventId);
        return $this->effectService->listByEvent($eventId);
    }

    public function upsertEffect(int $eventId, array $data, int $actorCharacterId = 0): array
    {
        $this->ensureEnabled();
        $this->getById($eventId);
        return $this->effectService->upsert($eventId, $data, $actorCharacterId);
    }

    public function deleteEffect(int $eventId, int $effectId): array
    {
        $this->ensureEnabled();
        $this->getById($eventId);
        return $this->effectService->delete($effectId, $eventId);
    }

    public function listParticipations(int $eventId): array
    {
        $this->ensureEnabled();
        $this->getById($eventId);
        return $this->participationService->listByEvent($eventId);
    }

    public function joinParticipation(int $eventId, array $data, int $actorCharacterId, bool $isStaff = false): array
    {
        $this->ensureEnabled();
        $event = $this->getById($eventId);
        return $this->participationService->join($event, $data, $actorCharacterId, $isStaff);
    }

    public function leaveParticipation(int $eventId, array $data, int $actorCharacterId, bool $isStaff = false): array
    {
        $this->ensureEnabled();
        $event = $this->getById($eventId);
        return $this->participationService->leave($event, $data, $actorCharacterId, $isStaff);
    }

    public function adminUpsertParticipation(int $eventId, array $data, int $actorCharacterId = 0): array
    {
        $this->ensureEnabled();
        $this->getById($eventId);
        return $this->participationService->adminUpsert($eventId, $data, $actorCharacterId);
    }

    public function adminRemoveParticipation(int $eventId, array $data): array
    {
        $this->ensureEnabled();
        $this->getById($eventId);
        return $this->participationService->adminRemove($eventId, $data);
    }

    public function assignReward(int $eventId, array $data, int $actorCharacterId = 0): array
    {
        $this->ensureEnabled();
        $this->getById($eventId);

        $characterId = (int) ($data['character_id'] ?? 0);
        $currencyId = (int) ($data['currency_id'] ?? 0);
        $amount = (int) ($data['amount'] ?? 0);
        $source = trim((string) ($data['source'] ?? 'manual'));
        if ($source === '') {
            $source = 'manual';
        }
        $meta = is_array($data['meta_json'] ?? null) ? $data['meta_json'] : [];
        $participationId = (int) ($data['participation_id'] ?? 0);

        return $this->rewardService->assignCurrencyReward(
            $eventId,
            $characterId,
            $currencyId,
            $amount,
            $actorCharacterId,
            $source,
            $meta,
            $participationId,
        );
    }

    public function rewardLog(int $eventId, int $limit = 50, int $page = 1): array
    {
        $this->ensureEnabled();
        $this->getById($eventId);
        return $this->rewardService->listRewardLogs($eventId, $limit, $page);
    }

    public function viewerFactionIds(int $viewerCharacterId): array
    {
        return $this->participationService->viewerFactionIds($viewerCharacterId);
    }
}
