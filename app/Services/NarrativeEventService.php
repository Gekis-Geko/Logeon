<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class NarrativeEventService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NarrativeVisibilityService|null */
    private $visibilityService = null;
    /** @var NarrativeTagService|null */
    private $tagService = null;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<int,mixed> $params
     * @return mixed
     */
    private function firstPrepared(string $sql, array $params = [])
    {
        return $this->db->fetchOnePrepared($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,mixed>
     */
    private function fetchPrepared(string $sql, array $params = []): array
    {
        return $this->db->fetchAllPrepared($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     */
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

    private function decodeRow(array $row): array
    {
        if (isset($row['entity_refs']) && is_string($row['entity_refs'])) {
            $decoded = json_decode($row['entity_refs'], true);
            $row['entity_refs'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['entity_refs'] = [];
        }
        if (isset($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta_json'] = is_array($decoded) ? $decoded : (object) [];
        }
        return $row;
    }

    private function visibilityService(): NarrativeVisibilityService
    {
        if ($this->visibilityService instanceof NarrativeVisibilityService) {
            return $this->visibilityService;
        }

        $this->visibilityService = new NarrativeVisibilityService();
        return $this->visibilityService;
    }

    private function tagService(): NarrativeTagService
    {
        if ($this->tagService instanceof NarrativeTagService) {
            return $this->tagService;
        }
        $this->tagService = new NarrativeTagService($this->db);
        return $this->tagService;
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    private static $validScopes = ['local', 'regional', 'global', 'guild', 'faction'];
    private static $validVisibility = ['public', 'private', 'staff_only', 'hidden'];
    private static $validEntityTypes = ['character', 'faction', 'location', 'conflict', 'event'];

    /** Valid impact levels: 0 = zero, 1 = limited, 2 = high (staff only) */
    private static $validImpactLevels = [0, 1, 2];

    private function validateScope(string $scope): string
    {
        return in_array($scope, self::$validScopes, true) ? $scope : 'local';
    }

    private function validateImpactLevel(int $level): int
    {
        return in_array($level, self::$validImpactLevels, true) ? $level : 0;
    }

    private function validateVisibility(string $vis): string
    {
        return in_array($vis, self::$validVisibility, true) ? $vis : 'public';
    }

    private function validateEntityRefs(array $refs): array
    {
        $clean = [];
        foreach ($refs as $ref) {
            $ref = (array) $ref;
            $type = strtolower(trim((string) ($ref['entity_type'] ?? '')));
            $id = (int) ($ref['entity_id'] ?? 0);
            $role = strtolower(trim((string) ($ref['role'] ?? 'participant')));
            if (!in_array($type, self::$validEntityTypes, true) || $id <= 0) {
                continue;
            }
            $clean[] = ['entity_type' => $type, 'entity_id' => $id, 'role' => $role];
        }
        return $clean;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Create a new narrative event. Returns the full event record.
     * Events are immutable after creation — only visibility can be changed.
     */
    private static $validModes = ['point', 'scene'];

    public function createEvent(array $params): array
    {
        $title = trim((string) ($params['title'] ?? ''));
        $eventType = trim((string) ($params['event_type'] ?? 'manual'));
        $scope = $this->validateScope(strtolower(trim((string) ($params['scope'] ?? 'local'))));
        $impactLevel = $this->validateImpactLevel((int) ($params['impact_level'] ?? 0));
        $description = trim((string) ($params['description'] ?? ''));
        $entityRefs = $this->validateEntityRefs((array) ($params['entity_refs'] ?? []));
        $locationId = (int) ($params['location_id'] ?? 0);
        $visibility = $this->validateVisibility(strtolower(trim((string) ($params['visibility'] ?? 'public'))));
        $tags = trim((string) ($params['tags'] ?? ''));
        $sourceSystem = trim((string) ($params['source_system'] ?? ''));
        $sourceRefId = (int) ($params['source_ref_id'] ?? 0);
        $metaJson = $params['meta_json'] ?? null;
        $createdBy = (int) ($params['created_by'] ?? 0);

        // event_mode: manual events are scenes (open lifecycle); system events are points
        $rawMode = trim((string) ($params['event_mode'] ?? ''));
        $eventMode = in_array($rawMode, self::$validModes, true) ? $rawMode
            : ($sourceSystem === '' || $sourceSystem === 'manual' ? 'scene' : 'point');

        // scenes start open; points are always closed
        $status = ($eventMode === 'scene') ? 'open' : 'closed';

        if ($title === '') {
            throw AppError::validation('Il titolo è obbligatorio', [], 'event_title_required');
        }
        if ($eventType === '') {
            $eventType = 'manual';
        }

        $refsEncoded = json_encode($entityRefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $metaEncoded = ($metaJson !== null)
            ? json_encode(is_array($metaJson) ? $metaJson : (array) json_decode((string) $metaJson, true), JSON_UNESCAPED_UNICODE)
            : '{}';

        $this->execPrepared(
            'INSERT INTO `narrative_events`
                (`title`,`event_type`,`event_mode`,`status`,`scope`,`impact_level`,`description`,`entity_refs`,`location_id`,`visibility`,`tags`,`source_system`,`source_ref_id`,`meta_json`,`created_by`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $title,
                $eventType,
                $eventMode,
                $status,
                $scope,
                $impactLevel,
                $description !== '' ? $description : null,
                $refsEncoded,
                $locationId > 0 ? $locationId : null,
                $visibility,
                $tags !== '' ? $tags : null,
                $sourceSystem !== '' ? $sourceSystem : null,
                $sourceRefId > 0 ? $sourceRefId : null,
                $metaEncoded,
                $createdBy > 0 ? $createdBy : null,
            ],
        );
        $newId = (int) $this->db->lastInsertId();
        AuditLogService::writeEvent('narrative_events.create', ['id' => $newId, 'title' => $title, 'impact_level' => $impactLevel, 'event_mode' => $eventMode], 'admin');

        \Core\Hooks::fire('narrative.event.created', $newId, $eventType, $entityRefs);

        return $this->getEvent($newId);
    }

    /**
     * Chiude una scena narrativa aperta.
     * Può chiudere: il creatore, qualsiasi staff con privilegio superiore.
     *
     * @param bool $isPrivileged true per staff/superuser (bypass check creatore)
     */
    public function closeScene(int $eventId, int $actorCharacterId, bool $isPrivileged = false): array
    {
        $event = $this->getEvent($eventId);

        if (($event['event_mode'] ?? '') !== 'scene') {
            throw AppError::validation('Questo evento non è una scena narrativa.', [], 'not_a_scene');
        }
        if (($event['status'] ?? '') === 'closed') {
            throw AppError::validation('La scena è già chiusa.', [], 'scene_already_closed');
        }

        $createdBy = (int) ($event['created_by'] ?? 0);
        if (!$isPrivileged && $createdBy !== $actorCharacterId) {
            throw AppError::unauthorized('Solo chi ha aperto la scena può chiuderla.', [], 'scene_close_forbidden');
        }

        $this->execPrepared(
            'UPDATE `narrative_events`
             SET `status` = \'closed\', `closed_at` = NOW(), `closed_by` = ?
             WHERE `id` = ?',
            [$actorCharacterId > 0 ? $actorCharacterId : null, $eventId],
        );

        AuditLogService::writeEvent('narrative_events.close', ['id' => $eventId, 'closed_by' => $actorCharacterId], 'admin');
        \Core\Hooks::fire('narrative.scene.closed', $eventId, $actorCharacterId);

        return $this->getEvent($eventId);
    }

    /**
     * Restituisce le scene aperte visibili per una location.
     * - scope=local: solo location_id corrisponde
     * - scope=regional: stessa mappa della location corrente
     * - scope=global: sempre incluse
     *
     * @return array<int,array<string,mixed>>
     */
    public function listActiveScenes(int $locationId = 0): array
    {
        if ($locationId > 0) {
            // Ottieni map_id della location corrente
            $locRow = $this->firstPrepared(
                'SELECT `map_id` FROM `locations` WHERE `id` = ? LIMIT 1',
                [$locationId],
            );
            $mapId = (int) ($locRow->map_id ?? 0);

            $rows = $this->fetchPrepared(
                'SELECT ne.*, c.name AS creator_name, c.surname AS creator_surname
                 FROM `narrative_events` ne
                 LEFT JOIN `characters` c ON c.id = ne.created_by
                 WHERE ne.`event_mode` = \'scene\'
                   AND ne.`status` = \'open\'
                   AND ne.`visibility` NOT IN (\'hidden\', \'staff_only\')
                   AND (
                     (ne.`scope` = \'local\' AND ne.`location_id` = ?)
                     OR (ne.`scope` = \'regional\' AND ne.`location_id` IN (
                           SELECT id FROM `locations` WHERE `map_id` = ? AND `date_deleted` IS NULL
                        ))
                     OR ne.`scope` = \'global\'
                   )
                 ORDER BY ne.`created_at` DESC',
                [$locationId, $mapId > 0 ? $mapId : -1],
            );
        } else {
            $rows = $this->fetchPrepared(
                'SELECT ne.*, c.name AS creator_name, c.surname AS creator_surname
                 FROM `narrative_events` ne
                 LEFT JOIN `characters` c ON c.id = ne.created_by
                 WHERE ne.`event_mode` = \'scene\'
                   AND ne.`status` = \'open\'
                   AND ne.`visibility` NOT IN (\'hidden\', \'staff_only\')
                 ORDER BY ne.`created_at` DESC',
                [],
            );
        }

        return array_map(function ($row): array {
            return $this->decodeRow($this->rowToArray($row));
        }, $rows ?: []);
    }

    /**
     * Restituisce le location della stessa mappa (per invio messaggi di sistema regionali).
     *
     * @return int[]
     */
    public function getLocationIdsInSameMap(int $locationId): array
    {
        $locRow = $this->firstPrepared(
            'SELECT `map_id` FROM `locations` WHERE `id` = ? LIMIT 1',
            [$locationId],
        );
        $mapId = (int) ($locRow->map_id ?? 0);
        if ($mapId <= 0) {
            return [$locationId];
        }

        $rows = $this->fetchPrepared(
            'SELECT `id` FROM `locations` WHERE `map_id` = ? AND `date_deleted` IS NULL',
            [$mapId],
        );

        return array_map(static function ($r): int {
            return (int) ($r->id ?? 0);
        }, $rows ?: []);
    }

    /**
     * Attach additional entity references to an existing event.
     */
    public function attachEntities(int $eventId, array $refs): array
    {
        $event = $this->getEvent($eventId);
        $existing = $event['entity_refs'] ?? [];
        $newRefs = $this->validateEntityRefs($refs);

        // Merge, avoid exact duplicates
        foreach ($newRefs as $ref) {
            $found = false;
            foreach ($existing as $ex) {
                if ($ex['entity_type'] === $ref['entity_type']
                    && (int) $ex['entity_id'] === (int) $ref['entity_id']
                    && $ex['role'] === $ref['role']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $existing[] = $ref;
                \Core\Hooks::fire('narrative.event.entity_attached', $eventId, $ref['entity_type'], (int) $ref['entity_id'], $ref['role']);
            }
        }

        $encoded = json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->execPrepared(
            'UPDATE `narrative_events` SET `entity_refs` = ? WHERE `id` = ?',
            [$encoded, $eventId],
        );

        return $this->getEvent($eventId);
    }

    /**
     * Update visibility of an event (the only mutable field).
     */
    public function updateVisibility(int $eventId, string $visibility): array
    {
        $vis = $this->validateVisibility(strtolower(trim($visibility)));
        $event = $this->getEvent($eventId);
        $old = $event['visibility'] ?? 'public';

        $this->execPrepared(
            'UPDATE `narrative_events` SET `visibility` = ? WHERE `id` = ?',
            [$vis, $eventId],
        );

        if ($old !== $vis) {
            \Core\Hooks::fire('narrative.event.visibility_changed', $eventId, $old, $vis);
        }

        return $this->getEvent($eventId);
    }

    /**
     * Sync narrative tags for an event.
     */
    public function syncTags(int $eventId, array $tagIds, int $actorCharacterId = 0): array
    {
        $this->getEvent($eventId); // ensure exists
        $this->tagService()->syncAssignments(
            NarrativeTagService::ENTITY_NARRATIVE_EVENT,
            $eventId,
            array_map('intval', $tagIds),
            $actorCharacterId,
        );
        return $this->getEvent($eventId);
    }

    /**
     * Get a single event by ID. Throws if not found.
     */
    public function getEvent(int $eventId): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM `narrative_events` WHERE `id` = ? LIMIT 1',
            [$eventId],
        );
        if (empty($row)) {
            throw AppError::notFound('Evento narrativo non trovato', [], 'event_not_found');
        }
        $event = $this->decodeRow($this->rowToArray($row));
        $event['narrative_tags'] = $this->tagService()->listAssignments(
            NarrativeTagService::ENTITY_NARRATIVE_EVENT,
            (int) ($event['id'] ?? 0),
            false,
        );
        $event['narrative_tag_ids'] = array_map(static function ($tag): int {
            return (int) ($tag['id'] ?? 0);
        }, is_array($event['narrative_tags']) ? $event['narrative_tags'] : []);
        return $event;
    }

    private function isEventOwner(array $event, int $viewerCharacterId): bool
    {
        if ($viewerCharacterId <= 0) {
            return false;
        }

        return (int) ($event['created_by'] ?? 0) === $viewerCharacterId;
    }

    public function canViewEvent(array $event, int $viewerCharacterId = 0, bool $isStaff = false): bool
    {
        $visibility = (string) ($event['visibility'] ?? NarrativeVisibilityService::VISIBILITY_PUBLIC);
        $isOwner = $this->isEventOwner($event, $viewerCharacterId);
        return $this->visibilityService()->canView($visibility, $isStaff, $isOwner);
    }

    public function getEventForViewer(int $eventId, int $viewerCharacterId = 0, bool $isStaff = false): array
    {
        $event = $this->getEvent($eventId);
        if (!$this->canViewEvent($event, $viewerCharacterId, $isStaff)) {
            throw AppError::notFound('Evento non trovato', [], 'event_not_found');
        }

        return $event;
    }

    /**
     * Public list: only public events, paginated.
     */
    public function list(array $filters = [], int $limit = 20, int $page = 1): array
    {
        return $this->listForViewer($filters, 0, false, $limit, $page);
    }

    public function listForViewer(
        array $filters = [],
        int $viewerCharacterId = 0,
        bool $isStaff = false,
        int $limit = 20,
        int $page = 1,
    ): array {
        $limit = max(1, min(50, $limit));
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        if ($isStaff) {
            // staff can list every visibility tier
        } elseif ($viewerCharacterId > 0) {
            $where[] = "(e.`visibility` = 'public' OR (e.`visibility` = 'private' AND e.`created_by` = ?))";
            $params[] = $viewerCharacterId;
        } else {
            $where[] = "e.`visibility` = 'public'";
        }

        if (!empty($filters['location_id'])) {
            $where[] = 'e.`location_id` = ?';
            $params[] = (int) $filters['location_id'];
        }
        if (!empty($filters['event_type'])) {
            $where[] = 'e.`event_type` = ?';
            $params[] = (string) $filters['event_type'];
        }
        if (!empty($filters['scope'])) {
            $where[] = 'e.`scope` = ?';
            $params[] = (string) $filters['scope'];
        }
        if (!empty($filters['source_system'])) {
            $sourceFilter = (string) $filters['source_system'];
            if ($sourceFilter === 'manual') {
                $where[] = "(e.`source_system` = 'manual' OR e.`source_system` IS NULL)";
            } else {
                $where[] = 'e.`source_system` = ?';
                $params[] = $sourceFilter;
            }
        }

        $tagIds = $this->tagService()->parseTagIds($filters['tag_ids'] ?? []);
        if (!empty($tagIds)) {
            $entityIds = $this->tagService()->filterEntityIdsByTagIds(
                NarrativeTagService::ENTITY_NARRATIVE_EVENT,
                $tagIds,
                false,
            );
            if (empty($entityIds)) {
                return ['total' => 0, 'page' => $page, 'limit' => $limit, 'rows' => []];
            }
            $where[] = 'e.`id` IN (' . implode(',', array_map('intval', $entityIds)) . ')';
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM `narrative_events` e ' . $whereClause,
            $params,
        );
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT * FROM `narrative_events` e ' . $whereClause
            . ' ORDER BY e.`created_at` DESC LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $dataset = array_map(function ($row) {
            return $this->decodeRow($this->rowToArray($row));
        }, $rows);
        $dataset = $this->tagService()->attachTagsToRows(
            NarrativeTagService::ENTITY_NARRATIVE_EVENT,
            $dataset,
            'id',
            'narrative_tags',
            false,
        );

        return ['total' => $total, 'page' => $page, 'limit' => $limit, 'rows' => $dataset];
    }

    /**
     * Admin list: all events, supports all filters.
     */
    public function adminList(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'created_at|DESC'): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['event_type'])) {
            $where[] = '`event_type` = ?';
            $params[] = (string) $filters['event_type'];
        }
        if (!empty($filters['scope'])) {
            $where[] = '`scope` = ?';
            $params[] = (string) $filters['scope'];
        }
        if (!empty($filters['visibility'])) {
            $where[] = '`visibility` = ?';
            $params[] = (string) $filters['visibility'];
        }
        if (!empty($filters['location_id'])) {
            $where[] = '`location_id` = ?';
            $params[] = (int) $filters['location_id'];
        }
        if (!empty($filters['source_system'])) {
            $where[] = '`source_system` = ?';
            $params[] = (string) $filters['source_system'];
        }
        if (!empty($filters['search'])) {
            $like = '%' . $filters['search'] . '%';
            $where[] = '(`title` LIKE ? OR `description` LIKE ? OR `tags` LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $tagIds = $this->tagService()->parseTagIds($filters['tag_ids'] ?? []);
        if (!empty($tagIds)) {
            $entityIds = $this->tagService()->filterEntityIdsByTagIds(
                NarrativeTagService::ENTITY_NARRATIVE_EVENT,
                $tagIds,
                false,
            );
            if (empty($entityIds)) {
                return ['total' => 0, 'page' => $page, 'limit' => $limit, 'rows' => []];
            }
            $where[] = '`id` IN (' . implode(',', array_map('intval', $entityIds)) . ')';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Parse sort
        $sortParts = explode('|', $sort);
        $sortField = in_array($sortParts[0] ?? '', ['id', 'title', 'event_type', 'scope', 'visibility', 'created_at'], true)
            ? ($sortParts[0] ?? 'created_at') : 'created_at';
        $sortDir = strtoupper($sortParts[1] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $offset = max(0, ($page - 1) * $limit);

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM `narrative_events` ' . $whereClause,
            $params,
        );
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT * FROM `narrative_events` ' . $whereClause
            . ' ORDER BY `' . $sortField . '` ' . $sortDir
            . ' LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $dataset = array_map(function ($row) {
            return $this->decodeRow($this->rowToArray($row));
        }, $rows);
        $dataset = $this->tagService()->attachTagsToRows(
            NarrativeTagService::ENTITY_NARRATIVE_EVENT,
            $dataset,
            'id',
            'narrative_tags',
            true,
        );

        return ['total' => $total, 'page' => $page, 'limit' => $limit, 'rows' => $dataset];
    }

    /**
     * Admin delete: hard delete by ID.
     */
    public function adminDelete(int $eventId): void
    {
        $this->getEvent($eventId); // throws if not found
        $this->execPrepared('DELETE FROM `narrative_events` WHERE `id` = ?', [$eventId]);
        AuditLogService::writeEvent('narrative_events.delete', ['id' => $eventId], 'admin');
    }
}
