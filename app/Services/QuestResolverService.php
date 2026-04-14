<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class QuestResolverService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NarrativeEventService */
    private $narrativeEventService;
    /** @var NotificationService */
    private $notificationService;
    /** @var NarrativeTagService|null */
    private $tagService = null;

    /** @var int */
    private static $lastMaintenanceRunAt = 0;
    /** @var array<string,bool> */
    private $tableExistsCache = [];

    public function __construct(
        DbAdapterInterface $db = null,
        NarrativeEventService $narrativeEventService = null,
        NotificationService $notificationService = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->narrativeEventService = $narrativeEventService ?: new NarrativeEventService($this->db);
        $this->notificationService = $notificationService ?: new NotificationService($this->db);
    }

    private function tagService(): NarrativeTagService
    {
        if ($this->tagService instanceof NarrativeTagService) {
            return $this->tagService;
        }
        $this->tagService = new NarrativeTagService($this->db);
        return $this->tagService;
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

    private function parseJsonArray($value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return (array) $value;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value)) {
            $raw = trim($value);
            return $raw !== '' ? $raw : null;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return null;
        }
        return $encoded;
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

    private function getConfig(string $key, string $fallback = ''): string
    {
        $row = $this->firstPrepared(
            'SELECT `value` FROM sys_configs WHERE `key` = ? LIMIT 1',
            [$key],
        );

        if (empty($row)) {
            return $fallback;
        }

        return trim((string) ($row->value ?? $fallback));
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
            'SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1',
            [$table],
        );
        $exists = !empty($row);
        $this->tableExistsCache[$table] = $exists;
        return $exists;
    }

    public function isEnabled(): bool
    {
        $raw = strtolower($this->getConfig('quests_enabled', '1'));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    public function ensureEnabled(): void
    {
        if (!$this->isEnabled()) {
            throw AppError::validation('Funzionalita quest disattivata', [], 'quest_feature_disabled');
        }
    }

    private function runLazyMaintenance(): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        try {
            $this->maintenanceRun(false);
        } catch (\Throwable $error) {
            // Non bloccare le endpoint runtime su errore maintenance.
        }
    }

    private function normalizeScope(string $scope): string
    {
        $scope = strtolower(trim($scope));
        if ($scope === 'group') {
            $scope = 'guild';
        }
        $allowed = ['character', 'faction', 'guild', 'world', 'map', 'location'];
        return in_array($scope, $allowed, true) ? $scope : 'character';
    }

    private function normalizeAssigneeType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === 'group') {
            $type = 'guild';
        }
        $allowed = ['character', 'faction', 'guild', 'world'];
        return in_array($type, $allowed, true) ? $type : 'character';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        $allowed = ['locked', 'available', 'active', 'completed', 'failed', 'cancelled', 'expired'];
        return in_array($status, $allowed, true) ? $status : 'available';
    }

    private function normalizeDefinitionStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    private function normalizeVisibility(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['public', 'private', 'staff_only', 'hidden'], true) ? $value : 'public';
    }

    private function normalizeIntensityLevel(
        $value,
        string $fallback = 'STANDARD',
        bool $nullable = false,
        bool $strict = false,
    ): ?string {
        $raw = strtoupper(trim((string) $value));
        if ($raw === '') {
            return $nullable ? null : $fallback;
        }
        $allowed = ['CHILL', 'SOFT', 'STANDARD', 'HIGH', 'CRITICAL'];
        if (in_array($raw, $allowed, true)) {
            return $raw;
        }
        if ($strict) {
            throw AppError::validation('Livello intensita quest non valido', [], 'quest_definition_invalid');
        }
        return $nullable ? null : $fallback;
    }

    private function normalizeIntensityVisibility($value, string $fallback = 'visible', bool $strict = false): string
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return $fallback;
        }
        if (in_array($raw, ['visible', 'hidden'], true)) {
            return $raw;
        }
        if ($strict) {
            throw AppError::validation('Visibilita intensita non valida', [], 'quest_definition_invalid');
        }
        return $fallback;
    }

    private function resolveIntensityContext(array $definition, ?array $instance = null, bool $includeHiddenValues = true): array
    {
        $definitionLevel = $this->normalizeIntensityLevel($definition['intensity_level'] ?? 'STANDARD', 'STANDARD');
        $visibility = $this->normalizeIntensityVisibility($definition['intensity_visibility'] ?? 'visible', 'visible');
        $instanceLevel = null;
        if (is_array($instance)) {
            $instanceLevel = $this->normalizeIntensityLevel($instance['intensity_level'] ?? null, 'STANDARD', true);
        }
        $effective = $instanceLevel !== null ? $instanceLevel : $definitionLevel;

        if (!$includeHiddenValues && $visibility === 'hidden') {
            return [
                'definition_intensity_level' => null,
                'instance_intensity_level' => null,
                'effective_intensity_level' => null,
                'intensity_visibility' => 'hidden',
            ];
        }

        return [
            'definition_intensity_level' => $definitionLevel,
            'instance_intensity_level' => $instanceLevel,
            'effective_intensity_level' => $effective,
            'intensity_visibility' => $visibility,
        ];
    }

    private function decodeDefinition(array $row): array
    {
        $row['meta_json'] = $this->parseJsonArray($row['meta_json'] ?? null);
        $row['intensity_level'] = $this->normalizeIntensityLevel($row['intensity_level'] ?? 'STANDARD', 'STANDARD');
        $row['intensity_visibility'] = $this->normalizeIntensityVisibility($row['intensity_visibility'] ?? 'visible', 'visible');
        return $row;
    }

    private function decodeInstance(array $row): array
    {
        $row['meta_json'] = $this->parseJsonArray($row['meta_json'] ?? null);
        $row['intensity_level'] = $this->normalizeIntensityLevel($row['intensity_level'] ?? null, 'STANDARD', true);
        return $row;
    }

    private function decodeCondition(array $row): array
    {
        $row['condition_payload'] = $this->parseJsonArray($row['condition_payload'] ?? null);
        return $row;
    }

    private function decodeOutcome(array $row): array
    {
        $row['outcome_payload'] = $this->parseJsonArray($row['outcome_payload'] ?? null);
        return $row;
    }

    private function decodeLink(array $row): array
    {
        $row['meta_json'] = $this->parseJsonArray($row['meta_json'] ?? null);
        return $row;
    }

    private function decodeLog(array $row): array
    {
        $row['payload'] = $this->parseJsonArray($row['payload'] ?? null);
        return $row;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function ensureDefinitionExists(int $definitionId): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM quest_definitions WHERE id = ? LIMIT 1',
            [(int) $definitionId],
        );
        if (empty($row)) {
            throw AppError::notFound('Quest non trovata', [], 'quest_not_found');
        }
        return $this->decodeDefinition($this->rowToArray($row));
    }

    private function ensureInstanceExists(int $instanceId): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM quest_instances WHERE id = ? LIMIT 1',
            [(int) $instanceId],
        );
        if (empty($row)) {
            throw AppError::notFound('Istanza quest non trovata', [], 'quest_not_found');
        }
        return $this->decodeInstance($this->rowToArray($row));
    }

    private function getActiveStepInstance(int $instanceId): ?array
    {
        $row = $this->firstPrepared(
            'SELECT si.*, sd.step_key, sd.title AS step_title, sd.order_index
             FROM quest_step_instances si
             INNER JOIN quest_step_definitions sd ON sd.id = si.quest_step_definition_id
             WHERE si.quest_instance_id = ?
               AND si.progress_status = "active"
             ORDER BY sd.order_index ASC, si.id ASC
             LIMIT 1',
            [(int) $instanceId],
        );

        if (empty($row)) {
            return null;
        }
        return $this->rowToArray($row);
    }

    private function getAllStepInstances(int $instanceId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT si.*, sd.step_key, sd.title AS step_title, sd.description AS step_description, sd.order_index, sd.step_type
             FROM quest_step_instances si
             INNER JOIN quest_step_definitions sd ON sd.id = si.quest_step_definition_id
             WHERE si.quest_instance_id = ?
             ORDER BY sd.order_index ASC, si.id ASC',
            [(int) $instanceId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->rowToArray($row);
        }
        return $out;
    }

    private function buildAssigneeLabel(string $assigneeType, int $assigneeId): string
    {
        if ($assigneeType === 'world') {
            return 'Mondo';
        }
        if ($assigneeType === 'character' && $assigneeId > 0) {
            $row = $this->firstPrepared('SELECT name FROM characters WHERE id = ? LIMIT 1', [$assigneeId]);
            if (!empty($row) && isset($row->name)) {
                return 'PG: ' . (string) $row->name;
            }
            return 'PG #' . $assigneeId;
        }
        if ($assigneeType === 'faction' && $assigneeId > 0) {
            $row = $this->firstPrepared('SELECT name FROM factions WHERE id = ? LIMIT 1', [$assigneeId]);
            if (!empty($row) && isset($row->name)) {
                return 'Fazione: ' . (string) $row->name;
            }
            return 'Fazione #' . $assigneeId;
        }
        if ($assigneeType === 'guild' && $assigneeId > 0) {
            $row = $this->firstPrepared('SELECT name FROM guilds WHERE id = ? LIMIT 1', [$assigneeId]);
            if (!empty($row) && isset($row->name)) {
                return 'Gilda: ' . (string) $row->name;
            }
            return 'Gilda #' . $assigneeId;
        }

        return ucfirst($assigneeType) . ($assigneeId > 0 ? (' #' . $assigneeId) : '');
    }

    public function viewerFactionIds(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }
        if (!$this->tableExists('faction_memberships')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT DISTINCT faction_id
             FROM faction_memberships
             WHERE character_id = ?
               AND status = "active"',
            [(int) $characterId],
        );

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->faction_id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function viewerGuildIds(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }
        if (!$this->tableExists('character_guilds')) {
            return [];
        }

        $rows = $this->fetchPrepared(
            'SELECT DISTINCT guild_id
             FROM character_guilds
             WHERE character_id = ?
               AND is_active = 1',
            [(int) $characterId],
        );

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->guild_id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function insertProgressLog(
        int $instanceId,
        ?int $stepInstanceId,
        string $logType,
        string $sourceType,
        ?int $sourceId,
        array $payload,
        ?int $createdBy = null,
    ): int {
        $this->execPrepared(
            'INSERT INTO quest_progress_logs
             (quest_instance_id, step_instance_id, log_type, source_type, source_id, payload, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $instanceId,
                $stepInstanceId !== null ? (int) $stepInstanceId : null,
                $logType,
                $sourceType,
                $sourceId !== null ? (int) $sourceId : null,
                $this->jsonValue($payload),
                $createdBy !== null ? (int) $createdBy : null,
            ],
        );

        return (int) $this->db->lastInsertId();
    }

    private function canViewVisibility(string $visibility, bool $isStaff): bool
    {
        $visibility = $this->normalizeVisibility($visibility);
        if ($visibility === 'public') {
            return true;
        }
        if ($visibility === 'staff_only' && $isStaff) {
            return true;
        }
        if ($visibility === 'private') {
            return true;
        }
        return $isStaff;
    }

    public function listDefinitionsForAdmin(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'id|DESC'): array
    {
        $this->ensureEnabled();

        $limit = max(1, min(200, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'd.status = ?';
            $params[] = $this->normalizeDefinitionStatus($status);
        }
        $visibility = trim((string) ($filters['visibility'] ?? ''));
        if ($visibility !== '') {
            $where[] = 'd.visibility = ?';
            $params[] = $this->normalizeVisibility($visibility);
        }
        $scopeType = trim((string) ($filters['scope_type'] ?? ''));
        if ($scopeType !== '') {
            $where[] = 'd.scope_type = ?';
            $params[] = $this->normalizeScope($scopeType);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(d.title LIKE ? OR d.slug LIKE ? OR d.summary LIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        $tagIds = $this->tagService()->parseTagIds($filters['tag_ids'] ?? []);
        if (!empty($tagIds)) {
            $entityIds = $this->tagService()->filterEntityIdsByTagIds(
                NarrativeTagService::ENTITY_QUEST_DEFINITION,
                $tagIds,
                false,
            );
            if (empty($entityIds)) {
                return ['total' => 0, 'page' => $page, 'limit' => $limit, 'rows' => []];
            }
            $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
            $where[] = 'd.id IN (' . $placeholders . ')';
            foreach ($entityIds as $entityId) {
                $params[] = (int) $entityId;
            }
        }

        $allowedSort = ['id', 'slug', 'title', 'status', 'visibility', 'scope_type', 'sort_order', 'intensity_level', 'intensity_visibility', 'date_created'];
        $chunks = explode('|', (string) $sort);
        $sortField = in_array($chunks[0] ?? '', $allowedSort, true) ? $chunks[0] : 'sort_order';
        $sortDirection = strtoupper($chunks[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM quest_definitions d ' . $whereSql,
            $params,
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT d.*,
                    (SELECT COUNT(*) FROM quest_step_definitions s WHERE s.quest_definition_id = d.id AND s.is_active = 1) AS steps_count,
                    (SELECT COUNT(*) FROM quest_instances i WHERE i.quest_definition_id = d.id AND i.current_status IN ("available","active")) AS active_instances
             FROM quest_definitions d
             ' . $whereSql . '
             ORDER BY d.' . $sortField . ' ' . $sortDirection . ', d.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeDefinition($this->rowToArray($row));
        }
        $out = $this->tagService()->attachTagsToRows(
            NarrativeTagService::ENTITY_QUEST_DEFINITION,
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

    public function getDefinitionDetail(int $definitionId): array
    {
        $this->ensureEnabled();

        $definition = $this->ensureDefinitionExists($definitionId);
        $definition['narrative_tags'] = $this->tagService()->listAssignments(
            NarrativeTagService::ENTITY_QUEST_DEFINITION,
            (int) ($definition['id'] ?? 0),
            false,
        );
        $definition['narrative_tag_ids'] = array_map(static function ($tag): int {
            return (int) ($tag['id'] ?? 0);
        }, is_array($definition['narrative_tags']) ? $definition['narrative_tags'] : []);
        $definition['steps'] = $this->listSteps($definitionId);
        $definition['conditions'] = $this->listConditions(['quest_definition_id' => $definitionId]);
        $definition['outcomes'] = $this->listOutcomes($definitionId);
        $definition['links'] = $this->listLinks($definitionId, 0);

        return $definition;
    }

    public function createDefinition(array $payload, int $actorCharacterId): array
    {
        $this->ensureEnabled();

        $slug = strtolower(trim((string) ($payload['slug'] ?? '')));
        $title = trim((string) ($payload['title'] ?? ''));
        if ($slug === '' || $title === '') {
            throw AppError::validation('Slug e titolo sono obbligatori', [], 'quest_definition_invalid');
        }

        $exists = $this->firstPrepared(
            'SELECT id FROM quest_definitions WHERE slug = ? LIMIT 1',
            [$slug],
        );
        if (!empty($exists)) {
            throw AppError::validation('Slug quest gia esistente', [], 'quest_definition_invalid');
        }

        $visibility = $this->normalizeVisibility((string) ($payload['visibility'] ?? 'public'));
        $scopeType = $this->normalizeScope((string) ($payload['scope_type'] ?? 'character'));
        $status = $this->normalizeDefinitionStatus((string) ($payload['status'] ?? 'draft'));
        $intensityLevel = $this->normalizeIntensityLevel($payload['intensity_level'] ?? 'STANDARD', 'STANDARD', false, true);
        $intensityVisibility = $this->normalizeIntensityVisibility($payload['intensity_visibility'] ?? 'visible', 'visible', true);
        $availabilityType = strtolower(trim((string) ($payload['availability_type'] ?? 'manual_join')));
        if ($availabilityType === '') {
            $availabilityType = 'manual_join';
        }

        $summary = trim((string) ($payload['summary'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        $questType = trim((string) ($payload['quest_type'] ?? 'personal'));
        $scopeId = (int) ($payload['scope_id'] ?? 0);
        $sortOrder = (int) ($payload['sort_order'] ?? 0);

        $this->execPrepared(
            'INSERT INTO quest_definitions
            (slug, title, summary, description, quest_type, intensity_level, intensity_visibility, visibility, scope_type, scope_id, availability_type, status, sort_order, meta_json, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $slug,
                $title,
                $summary !== '' ? $summary : null,
                $description !== '' ? $description : null,
                $questType !== '' ? $questType : 'personal',
                $intensityLevel,
                $intensityVisibility,
                $visibility,
                $scopeType,
                $scopeId > 0 ? $scopeId : null,
                $availabilityType,
                $status,
                $sortOrder,
                $this->jsonValue($payload['meta_json'] ?? null),
                $actorCharacterId > 0 ? $actorCharacterId : null,
                $actorCharacterId > 0 ? $actorCharacterId : null,
            ],
        );

        $definitionId = (int) $this->db->lastInsertId();

        if ($definitionId > 0 && array_key_exists('tag_ids', $payload)) {
            $tagIds = is_array($payload['tag_ids']) ? array_map('intval', $payload['tag_ids']) : [];
            $this->tagService()->syncAssignments(NarrativeTagService::ENTITY_QUEST_DEFINITION, $definitionId, $tagIds, $actorCharacterId);
        }

        return $this->ensureDefinitionExists($definitionId);
    }

    public function updateDefinition(int $definitionId, array $payload, int $actorCharacterId): array
    {
        $this->ensureEnabled();
        $current = $this->ensureDefinitionExists($definitionId);

        $slug = strtolower(trim((string) ($payload['slug'] ?? $current['slug'] ?? '')));
        $title = trim((string) ($payload['title'] ?? $current['title'] ?? ''));
        if ($slug === '' || $title === '') {
            throw AppError::validation('Slug e titolo sono obbligatori', [], 'quest_definition_invalid');
        }

        $dup = $this->firstPrepared(
            'SELECT id FROM quest_definitions
             WHERE slug = ? AND id <> ?
             LIMIT 1',
            [$slug, (int) $definitionId],
        );
        if (!empty($dup)) {
            throw AppError::validation('Slug quest gia esistente', [], 'quest_definition_invalid');
        }

        $visibility = $this->normalizeVisibility((string) ($payload['visibility'] ?? $current['visibility'] ?? 'public'));
        $scopeType = $this->normalizeScope((string) ($payload['scope_type'] ?? $current['scope_type'] ?? 'character'));
        $status = $this->normalizeDefinitionStatus((string) ($payload['status'] ?? $current['status'] ?? 'draft'));
        $intensityLevel = $this->normalizeIntensityLevel(
            $payload['intensity_level'] ?? ($current['intensity_level'] ?? 'STANDARD'),
            'STANDARD',
            false,
            true,
        );
        $intensityVisibility = $this->normalizeIntensityVisibility(
            $payload['intensity_visibility'] ?? ($current['intensity_visibility'] ?? 'visible'),
            'visible',
            true,
        );

        $summary = trim((string) ($payload['summary'] ?? ($current['summary'] ?? '')));
        $description = trim((string) ($payload['description'] ?? ($current['description'] ?? '')));
        $questType = trim((string) ($payload['quest_type'] ?? ($current['quest_type'] ?? 'personal')));
        $availabilityType = trim((string) ($payload['availability_type'] ?? ($current['availability_type'] ?? 'manual_join')));
        if ($availabilityType === '') {
            $availabilityType = 'manual_join';
        }
        $scopeId = (int) ($payload['scope_id'] ?? ($current['scope_id'] ?? 0));
        $sortOrder = (int) ($payload['sort_order'] ?? ($current['sort_order'] ?? 0));

        $metaJson = array_key_exists('meta_json', $payload) ? $payload['meta_json'] : ($current['meta_json'] ?? null);

        $this->execPrepared(
            'UPDATE quest_definitions SET
                slug = ?,
                title = ?,
                summary = ?,
                description = ?,
                quest_type = ?,
                intensity_level = ?,
                intensity_visibility = ?,
                visibility = ?,
                scope_type = ?,
                scope_id = ?,
                availability_type = ?,
                status = ?,
                sort_order = ?,
                meta_json = ?,
                updated_by = ?
             WHERE id = ?
             LIMIT 1',
            [
                $slug,
                $title,
                $summary !== '' ? $summary : null,
                $description !== '' ? $description : null,
                $questType !== '' ? $questType : 'personal',
                $intensityLevel,
                $intensityVisibility,
                $visibility,
                $scopeType,
                $scopeId > 0 ? $scopeId : null,
                $availabilityType,
                $status,
                $sortOrder,
                $this->jsonValue($metaJson),
                $actorCharacterId > 0 ? $actorCharacterId : null,
                (int) $definitionId,
            ],
        );

        if (array_key_exists('tag_ids', $payload)) {
            $tagIds = is_array($payload['tag_ids']) ? array_map('intval', $payload['tag_ids']) : [];
            $this->tagService()->syncAssignments(NarrativeTagService::ENTITY_QUEST_DEFINITION, $definitionId, $tagIds, $actorCharacterId);
        }

        return $this->ensureDefinitionExists($definitionId);
    }

    public function setDefinitionStatus(int $definitionId, string $status, int $actorCharacterId): array
    {
        $this->ensureEnabled();
        $status = $this->normalizeDefinitionStatus($status);
        $this->ensureDefinitionExists($definitionId);

        $this->execPrepared(
            'UPDATE quest_definitions SET
                status = ?,
                updated_by = ?
             WHERE id = ?
             LIMIT 1',
            [
                $status,
                $actorCharacterId > 0 ? $actorCharacterId : null,
                (int) $definitionId,
            ],
        );

        return $this->ensureDefinitionExists($definitionId);
    }

    public function deleteDefinition(int $definitionId): array
    {
        $this->ensureEnabled();
        $this->ensureDefinitionExists($definitionId);

        $this->execPrepared('DELETE FROM quest_step_definitions WHERE quest_definition_id = ?', [(int) $definitionId]);
        $this->execPrepared('DELETE FROM quest_conditions WHERE quest_definition_id = ?', [(int) $definitionId]);
        $this->execPrepared('DELETE FROM quest_outcomes WHERE quest_definition_id = ?', [(int) $definitionId]);
        $this->execPrepared('DELETE FROM quest_event_links WHERE quest_definition_id = ?', [(int) $definitionId]);

        $instances = $this->fetchPrepared(
            'SELECT id FROM quest_instances WHERE quest_definition_id = ?',
            [(int) $definitionId],
        );
        foreach ($instances as $row) {
            $instanceId = (int) ($row->id ?? 0);
            if ($instanceId > 0) {
                $this->execPrepared('DELETE FROM quest_step_instances WHERE quest_instance_id = ?', [$instanceId]);
                $this->execPrepared('DELETE FROM quest_progress_logs WHERE quest_instance_id = ?', [$instanceId]);
                $this->execPrepared('DELETE FROM quest_event_links WHERE quest_instance_id = ?', [$instanceId]);
            }
        }
        $this->execPrepared('DELETE FROM quest_instances WHERE quest_definition_id = ?', [(int) $definitionId]);
        $this->execPrepared('DELETE FROM quest_definitions WHERE id = ? LIMIT 1', [(int) $definitionId]);

        return ['deleted' => 1, 'id' => $definitionId];
    }

    public function reorderDefinitions(array $items): array
    {
        $this->ensureEnabled();

        $updated = 0;
        foreach ($items as $index => $item) {
            $row = is_array($item) ? $item : (array) $item;
            $id = (int) ($row['id'] ?? $row['quest_definition_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $sort = array_key_exists('sort_order', $row) ? (int) $row['sort_order'] : (int) $index;
            $this->execPrepared(
                'UPDATE quest_definitions SET sort_order = ? WHERE id = ? LIMIT 1',
                [$sort, $id],
            );
            $updated++;
        }

        return ['updated' => $updated];
    }

    public function listSteps(int $definitionId): array
    {
        $this->ensureEnabled();

        $rows = $this->fetchPrepared(
            'SELECT * FROM quest_step_definitions
             WHERE quest_definition_id = ?
             ORDER BY order_index ASC, id ASC',
            [(int) $definitionId],
        );
        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['meta_json'] = $this->parseJsonArray($item['meta_json'] ?? null);
            $out[] = $item;
        }
        return $out;
    }

    public function upsertStep(int $definitionId, array $payload): array
    {
        $this->ensureEnabled();
        $this->ensureDefinitionExists($definitionId);

        $stepId = (int) ($payload['step_id'] ?? $payload['id'] ?? 0);
        $stepKey = strtolower(trim((string) ($payload['step_key'] ?? '')));
        $title = trim((string) ($payload['title'] ?? ''));

        if ($stepKey === '' || $title === '') {
            throw AppError::validation('Step key e titolo sono obbligatori', [], 'quest_definition_invalid');
        }

        $dupSql = 'SELECT id
             FROM quest_step_definitions
             WHERE quest_definition_id = ?
               AND step_key = ?';
        $dupParams = [(int) $definitionId, $stepKey];
        if ($stepId > 0) {
            $dupSql .= ' AND id <> ?';
            $dupParams[] = $stepId;
        }
        $dupSql .= ' LIMIT 1';
        $dup = $this->firstPrepared($dupSql, $dupParams);
        if (!empty($dup)) {
            throw AppError::validation('Step key gia presente per la quest', [], 'quest_definition_invalid');
        }

        $description = trim((string) ($payload['description'] ?? ''));
        $stepType = trim((string) ($payload['step_type'] ?? 'narrative_action'));
        $orderIndex = (int) ($payload['order_index'] ?? 0);
        $isOptional = ((int) ($payload['is_optional'] ?? 0) === 1) ? 1 : 0;
        $completionMode = trim((string) ($payload['completion_mode'] ?? 'automatic'));
        if ($completionMode === '') {
            $completionMode = 'automatic';
        }
        $branchSuccess = trim((string) ($payload['branch_on_success'] ?? ''));
        $branchFailure = trim((string) ($payload['branch_on_failure'] ?? ''));
        $visibilityMode = trim((string) ($payload['visibility_mode'] ?? 'visible'));
        if ($visibilityMode === '') {
            $visibilityMode = 'visible';
        }
        $isActive = ((int) ($payload['is_active'] ?? 1) === 1) ? 1 : 0;

        if ($stepId > 0) {
            $this->execPrepared(
                'UPDATE quest_step_definitions SET
                    step_key = ?,
                    title = ?,
                    description = ?,
                    step_type = ?,
                    order_index = ?,
                    is_optional = ?,
                    completion_mode = ?,
                    branch_on_success = ?,
                    branch_on_failure = ?,
                    visibility_mode = ?,
                    is_active = ?,
                    meta_json = ?
                 WHERE id = ?
                   AND quest_definition_id = ?
                 LIMIT 1',
                [
                    $stepKey,
                    $title,
                    $description !== '' ? $description : null,
                    $stepType !== '' ? $stepType : 'narrative_action',
                    $orderIndex,
                    $isOptional,
                    $completionMode,
                    $branchSuccess !== '' ? $branchSuccess : null,
                    $branchFailure !== '' ? $branchFailure : null,
                    $visibilityMode,
                    $isActive,
                    $this->jsonValue($payload['meta_json'] ?? null),
                    $stepId,
                    (int) $definitionId,
                ],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO quest_step_definitions
                (quest_definition_id, step_key, title, description, step_type, order_index, is_optional, completion_mode, branch_on_success, branch_on_failure, visibility_mode, is_active, meta_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $definitionId,
                    $stepKey,
                    $title,
                    $description !== '' ? $description : null,
                    $stepType !== '' ? $stepType : 'narrative_action',
                    $orderIndex,
                    $isOptional,
                    $completionMode,
                    $branchSuccess !== '' ? $branchSuccess : null,
                    $branchFailure !== '' ? $branchFailure : null,
                    $visibilityMode,
                    $isActive,
                    $this->jsonValue($payload['meta_json'] ?? null),
                ],
            );
            $stepId = (int) $this->db->lastInsertId();
        }

        $row = $this->firstPrepared(
            'SELECT * FROM quest_step_definitions WHERE id = ? LIMIT 1',
            [(int) $stepId],
        );

        return $this->rowToArray($row);
    }

    public function deleteStep(int $definitionId, int $stepId): array
    {
        $this->ensureEnabled();
        $this->ensureDefinitionExists($definitionId);

        $this->execPrepared(
            'DELETE FROM quest_conditions WHERE quest_step_definition_id = ?',
            [(int) $stepId],
        );
        $this->execPrepared(
            'DELETE FROM quest_step_definitions
             WHERE id = ?
               AND quest_definition_id = ?
             LIMIT 1',
            [(int) $stepId, (int) $definitionId],
        );

        return ['deleted' => 1, 'step_id' => $stepId];
    }

    public function reorderSteps(int $definitionId, array $items): array
    {
        $this->ensureEnabled();
        $this->ensureDefinitionExists($definitionId);

        $updated = 0;
        foreach ($items as $index => $item) {
            $row = is_array($item) ? $item : (array) $item;
            $id = (int) ($row['id'] ?? $row['step_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $order = array_key_exists('order_index', $row) ? (int) $row['order_index'] : (int) $index;
            $this->execPrepared(
                'UPDATE quest_step_definitions
                 SET order_index = ?
                 WHERE id = ?
                   AND quest_definition_id = ?
                 LIMIT 1',
                [$order, $id, (int) $definitionId],
            );
            $updated++;
        }

        return ['updated' => $updated];
    }

    public function listConditions(array $filters): array
    {
        $this->ensureEnabled();

        $where = [];
        $params = [];
        $definitionId = (int) ($filters['quest_definition_id'] ?? 0);
        if ($definitionId > 0) {
            $where[] = 'quest_definition_id = ?';
            $params[] = $definitionId;
        }
        $stepDefinitionId = (int) ($filters['quest_step_definition_id'] ?? 0);
        if ($stepDefinitionId > 0) {
            $where[] = 'quest_step_definition_id = ?';
            $params[] = $stepDefinitionId;
        }
        $type = trim((string) ($filters['condition_type'] ?? ''));
        if ($type !== '') {
            $where[] = 'condition_type = ?';
            $params[] = $type;
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
        $rows = $this->fetchPrepared(
            'SELECT * FROM quest_conditions ' . $whereSql . ' ORDER BY id ASC',
            $params,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeCondition($this->rowToArray($row));
        }

        return $out;
    }

    public function upsertCondition(array $payload): array
    {
        $this->ensureEnabled();

        $conditionId = (int) ($payload['condition_id'] ?? $payload['id'] ?? 0);
        $definitionId = (int) ($payload['quest_definition_id'] ?? 0);
        $stepDefinitionId = (int) ($payload['quest_step_definition_id'] ?? 0);
        $conditionType = trim((string) ($payload['condition_type'] ?? ''));

        if ($definitionId <= 0 && $stepDefinitionId <= 0) {
            throw AppError::validation('Serve una quest o uno step per la condizione', [], 'quest_condition_invalid');
        }
        if ($stepDefinitionId > 0 && $definitionId <= 0) {
            $row = $this->firstPrepared(
                'SELECT quest_definition_id FROM quest_step_definitions WHERE id = ? LIMIT 1',
                [$stepDefinitionId],
            );
            $definitionId = !empty($row) ? (int) ($row->quest_definition_id ?? 0) : 0;
        }
        if ($definitionId <= 0) {
            throw AppError::validation('Quest non valida per la condizione', [], 'quest_condition_invalid');
        }
        if ($conditionType === '') {
            throw AppError::validation('Tipo condizione obbligatorio', [], 'quest_condition_invalid');
        }

        $operator = strtolower(trim((string) ($payload['operator'] ?? 'eq')));
        $allowedOps = ['eq', 'ne', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'contains'];
        if (!in_array($operator, $allowedOps, true)) {
            throw AppError::validation('Operatore condizione non valido', [], 'quest_condition_invalid');
        }

        $evaluationMode = strtolower(trim((string) ($payload['evaluation_mode'] ?? 'all_required')));
        $allowedModes = ['all_required', 'any_required', 'blocking', 'optional'];
        if (!in_array($evaluationMode, $allowedModes, true)) {
            throw AppError::validation('Modalita condizione non valida', [], 'quest_condition_invalid');
        }

        $isActive = ((int) ($payload['is_active'] ?? 1) === 1) ? 1 : 0;

        if ($conditionId > 0) {
            $this->execPrepared(
                'UPDATE quest_conditions SET
                    quest_definition_id = ?,
                    quest_step_definition_id = ?,
                    condition_type = ?,
                    operator = ?,
                    condition_payload = ?,
                    evaluation_mode = ?,
                    is_active = ?
                 WHERE id = ?
                 LIMIT 1',
                [
                    $definitionId > 0 ? $definitionId : null,
                    $stepDefinitionId > 0 ? $stepDefinitionId : null,
                    $conditionType,
                    $operator,
                    $this->jsonValue($payload['condition_payload'] ?? null),
                    $evaluationMode,
                    $isActive,
                    $conditionId,
                ],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO quest_conditions
                (quest_definition_id, quest_step_definition_id, condition_type, operator, condition_payload, evaluation_mode, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $definitionId > 0 ? $definitionId : null,
                    $stepDefinitionId > 0 ? $stepDefinitionId : null,
                    $conditionType,
                    $operator,
                    $this->jsonValue($payload['condition_payload'] ?? null),
                    $evaluationMode,
                    $isActive,
                ],
            );
            $conditionId = (int) $this->db->lastInsertId();
        }

        $row = $this->firstPrepared(
            'SELECT * FROM quest_conditions WHERE id = ? LIMIT 1',
            [(int) $conditionId],
        );
        return $this->decodeCondition($this->rowToArray($row));
    }

    public function deleteCondition(int $conditionId): array
    {
        $this->ensureEnabled();
        $this->execPrepared('DELETE FROM quest_conditions WHERE id = ? LIMIT 1', [(int) $conditionId]);
        return ['deleted' => 1, 'condition_id' => $conditionId];
    }

    public function listOutcomes(int $definitionId): array
    {
        $this->ensureEnabled();
        $rows = $this->fetchPrepared(
            'SELECT * FROM quest_outcomes
             WHERE quest_definition_id = ?
             ORDER BY sort_order ASC, id ASC',
            [(int) $definitionId],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeOutcome($this->rowToArray($row));
        }

        return $out;
    }

    public function upsertOutcome(int $definitionId, array $payload): array
    {
        $this->ensureEnabled();
        $this->ensureDefinitionExists($definitionId);

        $outcomeId = (int) ($payload['outcome_id'] ?? $payload['id'] ?? 0);
        $triggerType = strtolower(trim((string) ($payload['trigger_type'] ?? 'quest_completed')));
        $outcomeType = strtolower(trim((string) ($payload['outcome_type'] ?? 'log_progress')));

        if ($triggerType === '' || $outcomeType === '') {
            throw AppError::validation('Trigger e tipo outcome sono obbligatori', [], 'quest_outcome_invalid');
        }

        $visibility = $this->normalizeVisibility((string) ($payload['visibility'] ?? 'hidden'));
        $requiresStaff = ((int) ($payload['requires_staff_confirmation'] ?? 0) === 1) ? 1 : 0;
        $sortOrder = (int) ($payload['sort_order'] ?? 0);
        $isActive = ((int) ($payload['is_active'] ?? 1) === 1) ? 1 : 0;

        if ($outcomeId > 0) {
            $this->execPrepared(
                'UPDATE quest_outcomes SET
                    trigger_type = ?,
                    outcome_type = ?,
                    outcome_payload = ?,
                    visibility = ?,
                    requires_staff_confirmation = ?,
                    sort_order = ?,
                    is_active = ?
                 WHERE id = ?
                   AND quest_definition_id = ?
                 LIMIT 1',
                [
                    $triggerType,
                    $outcomeType,
                    $this->jsonValue($payload['outcome_payload'] ?? null),
                    $visibility,
                    $requiresStaff,
                    $sortOrder,
                    $isActive,
                    $outcomeId,
                    (int) $definitionId,
                ],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO quest_outcomes
                (quest_definition_id, trigger_type, outcome_type, outcome_payload, visibility, requires_staff_confirmation, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $definitionId,
                    $triggerType,
                    $outcomeType,
                    $this->jsonValue($payload['outcome_payload'] ?? null),
                    $visibility,
                    $requiresStaff,
                    $sortOrder,
                    $isActive,
                ],
            );
            $outcomeId = (int) $this->db->lastInsertId();
        }

        $row = $this->firstPrepared(
            'SELECT * FROM quest_outcomes WHERE id = ? LIMIT 1',
            [(int) $outcomeId],
        );
        return $this->decodeOutcome($this->rowToArray($row));
    }

    public function deleteOutcome(int $definitionId, int $outcomeId): array
    {
        $this->ensureEnabled();
        $this->execPrepared(
            'DELETE FROM quest_outcomes
             WHERE id = ?
               AND quest_definition_id = ?
             LIMIT 1',
            [(int) $outcomeId, (int) $definitionId],
        );

        return ['deleted' => 1, 'outcome_id' => $outcomeId];
    }

    public function listLinks(int $definitionId = 0, int $instanceId = 0): array
    {
        $this->ensureEnabled();

        $where = [];
        $params = [];
        if ($definitionId > 0) {
            $where[] = 'quest_definition_id = ?';
            $params[] = (int) $definitionId;
        }
        if ($instanceId > 0) {
            $where[] = 'quest_instance_id = ?';
            $params[] = (int) $instanceId;
        }
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $rows = $this->fetchPrepared(
            'SELECT * FROM quest_event_links ' . $whereSql . ' ORDER BY id DESC',
            $params,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeLink($this->rowToArray($row));
        }

        return $out;
    }

    private function syncLegacySystemEventQuestLinks(int $systemEventId, int $questId): void
    {
        if ($systemEventId <= 0 || $questId <= 0) {
            return;
        }

        if (!$this->tableExists('system_event_quest_links')) {
            return;
        }

        $this->execPrepared(
            'INSERT INTO system_event_quest_links
            (system_event_id, quest_id, date_created)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                date_created = VALUES(date_created)',
            [(int) $systemEventId, (int) $questId],
        );
    }

    private function deleteLegacySystemEventQuestLink(int $systemEventId, int $questId): void
    {
        if ($systemEventId <= 0 || $questId <= 0) {
            return;
        }
        if (!$this->tableExists('system_event_quest_links')) {
            return;
        }
        $this->execPrepared(
            'DELETE FROM system_event_quest_links
             WHERE system_event_id = ?
               AND quest_id = ?
             LIMIT 1',
            [(int) $systemEventId, (int) $questId],
        );
    }

    public function upsertLink(array $payload, int $actorCharacterId): array
    {
        $this->ensureEnabled();

        $linkId = (int) ($payload['id'] ?? $payload['link_id'] ?? 0);
        $definitionId = (int) ($payload['quest_definition_id'] ?? 0);
        $instanceId = (int) ($payload['quest_instance_id'] ?? 0);
        $narrativeEventId = (int) ($payload['narrative_event_id'] ?? 0);
        $systemEventId = (int) ($payload['system_event_id'] ?? 0);
        $linkType = strtolower(trim((string) ($payload['link_type'] ?? 'contextualized_by')));

        if ($definitionId <= 0 && $instanceId <= 0) {
            throw AppError::validation('Link quest non valido', [], 'quest_definition_invalid');
        }

        if ($instanceId > 0 && $definitionId <= 0) {
            $inst = $this->ensureInstanceExists($instanceId);
            $definitionId = (int) ($inst['quest_definition_id'] ?? 0);
        }

        if ($definitionId > 0) {
            $this->ensureDefinitionExists($definitionId);
        }

        if ($linkId > 0) {
            $this->execPrepared(
                'UPDATE quest_event_links SET
                    quest_definition_id = ?,
                    quest_instance_id = ?,
                    narrative_event_id = ?,
                    system_event_id = ?,
                    link_type = ?,
                    meta_json = ?,
                    created_by = ?
                 WHERE id = ?
                 LIMIT 1',
                [
                    $definitionId > 0 ? $definitionId : null,
                    $instanceId > 0 ? $instanceId : null,
                    $narrativeEventId > 0 ? $narrativeEventId : null,
                    $systemEventId > 0 ? $systemEventId : null,
                    $linkType !== '' ? $linkType : 'contextualized_by',
                    $this->jsonValue($payload['meta_json'] ?? null),
                    $actorCharacterId > 0 ? $actorCharacterId : null,
                    $linkId,
                ],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO quest_event_links
                (quest_definition_id, quest_instance_id, narrative_event_id, system_event_id, link_type, meta_json, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $definitionId > 0 ? $definitionId : null,
                    $instanceId > 0 ? $instanceId : null,
                    $narrativeEventId > 0 ? $narrativeEventId : null,
                    $systemEventId > 0 ? $systemEventId : null,
                    $linkType !== '' ? $linkType : 'contextualized_by',
                    $this->jsonValue($payload['meta_json'] ?? null),
                    $actorCharacterId > 0 ? $actorCharacterId : null,
                ],
            );
            $linkId = (int) $this->db->lastInsertId();
        }

        $row = $this->firstPrepared('SELECT * FROM quest_event_links WHERE id = ? LIMIT 1', [(int) $linkId]);
        $decoded = $this->decodeLink($this->rowToArray($row));

        if ($systemEventId > 0) {
            $legacyQuestId = $definitionId > 0 ? $definitionId : $instanceId;
            $this->syncLegacySystemEventQuestLinks($systemEventId, $legacyQuestId);
        }

        return $decoded;
    }

    public function deleteLink(int $linkId): array
    {
        $this->ensureEnabled();

        $row = $this->firstPrepared('SELECT * FROM quest_event_links WHERE id = ? LIMIT 1', [(int) $linkId]);
        if (!empty($row)) {
            $link = $this->rowToArray($row);
            $systemEventId = (int) ($link['system_event_id'] ?? 0);
            $legacyQuestId = (int) (($link['quest_definition_id'] ?? 0) ?: ($link['quest_instance_id'] ?? 0));
            if ($systemEventId > 0 && $legacyQuestId > 0) {
                $this->deleteLegacySystemEventQuestLink($systemEventId, $legacyQuestId);
            }
        }

        $this->execPrepared('DELETE FROM quest_event_links WHERE id = ? LIMIT 1', [(int) $linkId]);
        return ['deleted' => 1, 'link_id' => $linkId];
    }

    private function createStepInstancesForNewInstance(int $instanceId, int $definitionId): void
    {
        $steps = $this->fetchPrepared(
            'SELECT *
             FROM quest_step_definitions
             WHERE quest_definition_id = ?
               AND is_active = 1
             ORDER BY order_index ASC, id ASC',
            [(int) $definitionId],
        );
        if (empty($steps)) {
            return;
        }

        $firstStepId = 0;
        foreach ($steps as $idx => $stepRow) {
            $step = $this->rowToArray($stepRow);
            $stepDefinitionId = (int) ($step['id'] ?? 0);
            if ($stepDefinitionId <= 0) {
                continue;
            }
            if ($idx === 0) {
                $firstStepId = $stepDefinitionId;
            }

            $this->execPrepared(
                'INSERT INTO quest_step_instances
                (quest_instance_id, quest_step_definition_id, progress_status, updated_at, meta_json)
                VALUES (?, ?, "locked", NOW(), NULL)',
                [(int) $instanceId, $stepDefinitionId],
            );
        }

        if ($firstStepId > 0) {
            $this->execPrepared(
                'UPDATE quest_step_instances
                 SET progress_status = "active", started_at = NOW(), updated_at = NOW()
                 WHERE quest_instance_id = ?
                   AND quest_step_definition_id = ?
                 LIMIT 1',
                [(int) $instanceId, $firstStepId],
            );
        }
    }

    private function findExistingActiveOrAvailableInstance(int $definitionId, string $assigneeType, ?int $assigneeId): ?array
    {
        $params = [(int) $definitionId, $assigneeType];
        $whereAssigneeId = 'assignee_id IS NULL';
        if ($assigneeId !== null && $assigneeId > 0) {
            $whereAssigneeId = 'assignee_id = ?';
            $params[] = (int) $assigneeId;
        }

        $row = $this->firstPrepared(
            'SELECT * FROM quest_instances
             WHERE quest_definition_id = ?
               AND assignee_type = ?
               AND ' . $whereAssigneeId . '
               AND current_status IN ("available","active")
             ORDER BY id DESC
             LIMIT 1',
            $params,
        );

        if (empty($row)) {
            return null;
        }
        return $this->decodeInstance($this->rowToArray($row));
    }

    private function createInstance(
        int $definitionId,
        string $assigneeType,
        ?int $assigneeId,
        string $status,
        string $sourceType,
        ?int $sourceId,
        ?int $assignedBy,
        ?string $notes = null,
        ?string $expiresAt = null,
        $metaJson = null,
        ?string $intensityLevel = null,
    ): array {
        $status = $this->normalizeStatus($status);
        $sourceType = trim($sourceType) !== '' ? trim($sourceType) : 'manual';
        $intensityLevel = $this->normalizeIntensityLevel($intensityLevel, 'STANDARD', true);

        $startedAt = null;
        $completedAt = null;
        $failedAt = null;
        if ($status === 'active') {
            $startedAt = $this->now();
        }
        if ($status === 'completed') {
            $completedAt = $this->now();
        }
        if ($status === 'failed') {
            $failedAt = $this->now();
        }

        $this->execPrepared(
            'INSERT INTO quest_instances
            (quest_definition_id, assignee_type, assignee_id, current_status, started_at, completed_at, failed_at, expires_at, intensity_level, source_type, source_id, assigned_by, notes, last_activity_at, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
            [
                (int) $definitionId,
                $assigneeType,
                $assigneeId !== null && $assigneeId > 0 ? $assigneeId : null,
                $status,
                $startedAt,
                $completedAt,
                $failedAt,
                $expiresAt,
                $intensityLevel,
                $sourceType,
                $sourceId !== null && $sourceId > 0 ? $sourceId : null,
                $assignedBy !== null && $assignedBy > 0 ? $assignedBy : null,
                $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                $this->jsonValue($metaJson),
            ],
        );

        $instanceId = (int) $this->db->lastInsertId();

        $this->createStepInstancesForNewInstance($instanceId, $definitionId);

        $instance = $this->ensureInstanceExists($instanceId);
        $this->insertProgressLog(
            $instanceId,
            null,
            'instance_created',
            $sourceType,
            $sourceId,
            [
                'status' => $status,
                'assignee_type' => $assigneeType,
                'assignee_id' => $assigneeId,
                'instance_intensity_level' => $intensityLevel,
            ],
            $assignedBy,
        );

        return $instance;
    }

    public function assignInstance(array $payload, int $actorCharacterId): array
    {
        $this->ensureEnabled();

        $definitionId = (int) ($payload['quest_definition_id'] ?? $payload['definition_id'] ?? $payload['id'] ?? 0);
        if ($definitionId <= 0) {
            throw AppError::validation('Quest non valida per assegnazione', [], 'quest_not_found');
        }

        $definition = $this->ensureDefinitionExists($definitionId);

        $assigneeType = $this->normalizeAssigneeType((string) ($payload['assignee_type'] ?? ($definition['scope_type'] ?? 'character')));
        $assigneeId = (int) ($payload['assignee_id'] ?? 0);
        if ($assigneeType === 'world') {
            $assigneeId = 0;
        }
        if ($assigneeType !== 'world' && $assigneeId <= 0) {
            throw AppError::validation('Assegnatario non valido', [], 'quest_participation_forbidden');
        }

        $status = $this->normalizeStatus((string) ($payload['status'] ?? 'available'));
        $sourceType = trim((string) ($payload['source_type'] ?? 'admin_assign'));
        $sourceId = (int) ($payload['source_id'] ?? 0);
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;
        $expiresAt = $this->normalizeDateTime($payload['expires_at'] ?? null);
        $instanceIntensityLevel = $this->normalizeIntensityLevel(
            $payload['intensity_level'] ?? null,
            'STANDARD',
            true,
            array_key_exists('intensity_level', $payload),
        );

        $existing = $this->findExistingActiveOrAvailableInstance(
            $definitionId,
            $assigneeType,
            $assigneeType === 'world' ? null : $assigneeId,
        );
        if (!empty($existing)) {
            throw AppError::validation('Esiste gia una istanza attiva/disponibile', [], 'quest_participation_conflict');
        }

        $instance = $this->createInstance(
            $definitionId,
            $assigneeType,
            $assigneeType === 'world' ? null : $assigneeId,
            $status,
            $sourceType,
            $sourceId > 0 ? $sourceId : null,
            $actorCharacterId > 0 ? $actorCharacterId : null,
            $notes,
            $expiresAt,
            $payload['meta_json'] ?? null,
            $instanceIntensityLevel,
        );

        $instance['definition'] = $definition;
        $instance['steps'] = $this->getAllStepInstances((int) $instance['id']);
        $instance['assignee_label'] = $this->buildAssigneeLabel($assigneeType, $assigneeId);
        $intensityContext = $this->resolveIntensityContext($definition, $instance, true);
        $instance['instance_intensity_level'] = $intensityContext['instance_intensity_level'];
        $instance['definition_intensity_level'] = $intensityContext['definition_intensity_level'];
        $instance['effective_intensity_level'] = $intensityContext['effective_intensity_level'];
        $instance['intensity_visibility'] = $intensityContext['intensity_visibility'];
        $instance['intensity_level'] = $intensityContext['effective_intensity_level'];

        return $instance;
    }

    private function canCharacterJoinAssignee(
        int $viewerCharacterId,
        string $assigneeType,
        int $candidateAssigneeId,
        array $viewerFactionIds,
        array $viewerGuildIds,
    ): bool {
        if ($assigneeType === 'world') {
            return true;
        }
        if ($assigneeType === 'character') {
            return $candidateAssigneeId === $viewerCharacterId;
        }
        if ($assigneeType === 'faction') {
            return in_array($candidateAssigneeId, $viewerFactionIds, true);
        }
        if ($assigneeType === 'guild') {
            return in_array($candidateAssigneeId, $viewerGuildIds, true);
        }

        return false;
    }

    public function listForGame(
        array $filters,
        int $viewerCharacterId,
        bool $isStaff,
        array $viewerFactionIds,
        array $viewerGuildIds,
        int $limit = 30,
        int $page = 1,
    ): array {
        $this->ensureEnabled();
        $this->runLazyMaintenance();

        $limit = max(1, min(100, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $statusFilter = strtolower(trim((string) ($filters['status'] ?? '')));
        $scopeFilter = strtolower(trim((string) ($filters['scope_type'] ?? '')));
        $tagIdsFilter = $this->tagService()->parseTagIds($filters['tag_ids'] ?? []);
        $allowedDefinitionIds = [];
        if (!empty($tagIdsFilter)) {
            $allowedRows = $this->tagService()->filterEntityIdsByTagIds(
                NarrativeTagService::ENTITY_QUEST_DEFINITION,
                $tagIdsFilter,
                false,
            );
            foreach ($allowedRows as $allowedId) {
                $allowedDefinitionIds[(int) $allowedId] = true;
            }
        }

        $where = ['d.status = "published"'];
        $whereParams = [];
        if ($scopeFilter !== '') {
            $where[] = 'd.scope_type = ?';
            $whereParams[] = $this->normalizeScope($scopeFilter);
        }

        if (!$isStaff) {
            $visibilityClauses = ['d.visibility = "public"'];
            $visibilityClauses[] = 'd.visibility = "private"';
            $where[] = '(' . implode(' OR ', $visibilityClauses) . ')';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $rows = $this->fetchPrepared(
            'SELECT d.*
             FROM quest_definitions d
             ' . $whereSql . '
             ORDER BY d.sort_order ASC, d.id ASC
             LIMIT ? OFFSET ?',
            array_merge($whereParams, [$limit, $offset]),
        );
        $resultRows = [];

        foreach ($rows as $row) {
            $definition = $this->decodeDefinition($this->rowToArray($row));
            $definitionId = (int) ($definition['id'] ?? 0);
            if ($definitionId <= 0) {
                continue;
            }
            if (!empty($allowedDefinitionIds) && empty($allowedDefinitionIds[$definitionId])) {
                continue;
            }

            $scopeType = $this->normalizeScope((string) ($definition['scope_type'] ?? 'character'));
            $scopeId = (int) ($definition['scope_id'] ?? 0);

            $joinAssigneeType = $this->normalizeAssigneeType($scopeType === 'map' || $scopeType === 'location' ? 'world' : $scopeType);
            $joinAssigneeId = 0;

            if ($joinAssigneeType === 'character') {
                $joinAssigneeId = $scopeId > 0 ? $scopeId : $viewerCharacterId;
            } elseif ($joinAssigneeType === 'faction') {
                $joinAssigneeId = $scopeId > 0 ? $scopeId : (int) ($viewerFactionIds[0] ?? 0);
            } elseif ($joinAssigneeType === 'guild') {
                $joinAssigneeId = $scopeId > 0 ? $scopeId : (int) ($viewerGuildIds[0] ?? 0);
            }

            if ($scopeType === 'character' && $scopeId > 0 && $scopeId !== $viewerCharacterId && !$isStaff) {
                continue;
            }
            if ($scopeType === 'faction' && $scopeId > 0 && !in_array($scopeId, $viewerFactionIds, true) && !$isStaff) {
                continue;
            }
            if ($scopeType === 'guild' && $scopeId > 0 && !in_array($scopeId, $viewerGuildIds, true) && !$isStaff) {
                continue;
            }

            $existing = $this->findExistingActiveOrAvailableInstance(
                $definitionId,
                $joinAssigneeType,
                $joinAssigneeType === 'world' ? null : $joinAssigneeId,
            );

            $rowStatus = 'available';
            $instanceId = 0;
            $viewerJoined = 0;
            if (!empty($existing)) {
                $rowStatus = (string) ($existing['current_status'] ?? 'available');
                $instanceId = (int) ($existing['id'] ?? 0);
                $viewerJoined = 1;
            }

            if ($statusFilter !== '' && $rowStatus !== $statusFilter) {
                continue;
            }

            $canJoin = ($viewerJoined === 0);
            $canLeave = ($viewerJoined === 1 && in_array($rowStatus, ['available', 'active'], true));
            if (!$this->canCharacterJoinAssignee($viewerCharacterId, $joinAssigneeType, $joinAssigneeId, $viewerFactionIds, $viewerGuildIds) && !$isStaff) {
                $canJoin = false;
                $canLeave = false;
            }
            $intensityContext = $this->resolveIntensityContext(
                $definition,
                !empty($existing) ? $existing : null,
                $isStaff,
            );

            $resultRows[] = [
                'id' => $definitionId,
                'slug' => (string) ($definition['slug'] ?? ''),
                'title' => (string) ($definition['title'] ?? ''),
                'summary' => (string) ($definition['summary'] ?? ''),
                'description' => (string) ($definition['description'] ?? ''),
                'quest_type' => (string) ($definition['quest_type'] ?? 'personal'),
                'visibility' => (string) ($definition['visibility'] ?? 'public'),
                'scope_type' => (string) ($definition['scope_type'] ?? 'character'),
                'scope_id' => $scopeId,
                'availability_type' => (string) ($definition['availability_type'] ?? 'manual_join'),
                'instance_id' => $instanceId,
                'viewer_joined' => $viewerJoined,
                'instance_status' => $rowStatus,
                'can_join' => $canJoin ? 1 : 0,
                'can_leave' => $canLeave ? 1 : 0,
                'assignee_type' => $joinAssigneeType,
                'assignee_id' => $joinAssigneeType === 'world' ? null : $joinAssigneeId,
                'intensity_level' => $intensityContext['effective_intensity_level'],
                'definition_intensity_level' => $intensityContext['definition_intensity_level'],
                'instance_intensity_level' => $intensityContext['instance_intensity_level'],
                'intensity_visibility' => $intensityContext['intensity_visibility'],
            ];
        }

        $resultRows = $this->tagService()->attachTagsToRows(
            NarrativeTagService::ENTITY_QUEST_DEFINITION,
            $resultRows,
            'id',
            'narrative_tags',
            false,
        );

        return [
            'rows' => array_values($resultRows),
            'total' => count($resultRows),
            'page' => $page,
            'limit' => $limit,
        ];
    }

    public function getForGame(
        int $definitionId,
        int $viewerCharacterId,
        bool $isStaff,
        array $viewerFactionIds,
        array $viewerGuildIds,
    ): array {
        $this->ensureEnabled();
        $this->runLazyMaintenance();

        $definition = $this->ensureDefinitionExists($definitionId);
        $definition['narrative_tags'] = $this->tagService()->listAssignments(
            NarrativeTagService::ENTITY_QUEST_DEFINITION,
            (int) ($definition['id'] ?? 0),
            false,
        );
        $definition['narrative_tag_ids'] = array_map(static function ($tag): int {
            return (int) ($tag['id'] ?? 0);
        }, is_array($definition['narrative_tags']) ? $definition['narrative_tags'] : []);
        $scopeType = $this->normalizeScope((string) ($definition['scope_type'] ?? 'character'));
        $scopeId = (int) ($definition['scope_id'] ?? 0);

        if (!$isStaff) {
            if (!$this->canViewVisibility((string) ($definition['visibility'] ?? 'public'), $isStaff)) {
                throw AppError::notFound('Quest non trovata', [], 'quest_not_found');
            }
            if ($scopeType === 'character' && $scopeId > 0 && $scopeId !== $viewerCharacterId) {
                throw AppError::notFound('Quest non trovata', [], 'quest_not_found');
            }
            if ($scopeType === 'faction' && $scopeId > 0 && !in_array($scopeId, $viewerFactionIds, true)) {
                throw AppError::notFound('Quest non trovata', [], 'quest_not_found');
            }
            if ($scopeType === 'guild' && $scopeId > 0 && !in_array($scopeId, $viewerGuildIds, true)) {
                throw AppError::notFound('Quest non trovata', [], 'quest_not_found');
            }
        }

        $assigneeType = $this->normalizeAssigneeType($scopeType === 'map' || $scopeType === 'location' ? 'world' : $scopeType);
        $assigneeId = 0;
        if ($assigneeType === 'character') {
            $assigneeId = $scopeId > 0 ? $scopeId : $viewerCharacterId;
        } elseif ($assigneeType === 'faction') {
            $assigneeId = $scopeId > 0 ? $scopeId : (int) ($viewerFactionIds[0] ?? 0);
        } elseif ($assigneeType === 'guild') {
            $assigneeId = $scopeId > 0 ? $scopeId : (int) ($viewerGuildIds[0] ?? 0);
        }

        $instance = $this->findExistingActiveOrAvailableInstance(
            $definitionId,
            $assigneeType,
            $assigneeType === 'world' ? null : $assigneeId,
        );

        $stepsDef = $this->listSteps($definitionId);
        $steps = [];
        if (!empty($instance)) {
            $steps = $this->getAllStepInstances((int) ($instance['id'] ?? 0));
        } else {
            foreach ($stepsDef as $stepDef) {
                $steps[] = [
                    'quest_step_definition_id' => (int) ($stepDef['id'] ?? 0),
                    'step_key' => (string) ($stepDef['step_key'] ?? ''),
                    'step_title' => (string) ($stepDef['title'] ?? ''),
                    'step_description' => (string) ($stepDef['description'] ?? ''),
                    'order_index' => (int) ($stepDef['order_index'] ?? 0),
                    'step_type' => (string) ($stepDef['step_type'] ?? 'narrative_action'),
                    'progress_status' => 'locked',
                ];
            }
        }

        $intensityContext = $this->resolveIntensityContext(
            $definition,
            !empty($instance) ? $instance : null,
            $isStaff,
        );
        $definition['intensity_visibility'] = $intensityContext['intensity_visibility'];
        $definition['intensity_level'] = $intensityContext['definition_intensity_level'];
        if (!empty($instance)) {
            $instance['intensity_level'] = $intensityContext['effective_intensity_level'];
            $instance['instance_intensity_level'] = $intensityContext['instance_intensity_level'];
            $instance['effective_intensity_level'] = $intensityContext['effective_intensity_level'];
            $instance['intensity_visibility'] = $intensityContext['intensity_visibility'];
        }

        return [
            'definition' => $definition,
            'instance' => $instance,
            'steps' => $steps,
            'can_join' => empty($instance) ? 1 : 0,
            'can_leave' => !empty($instance) && in_array((string) ($instance['current_status'] ?? ''), ['available', 'active'], true) ? 1 : 0,
            'intensity_level' => $intensityContext['effective_intensity_level'],
            'definition_intensity_level' => $intensityContext['definition_intensity_level'],
            'instance_intensity_level' => $intensityContext['instance_intensity_level'],
            'intensity_visibility' => $intensityContext['intensity_visibility'],
        ];
    }

    private function notifyQuestParticipation(string $action, array $definition, int $viewerCharacterId): void
    {
        $enabled = strtolower($this->getConfig('quests_auto_notify', '1'));
        if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)) {
            return;
        }

        if ($viewerCharacterId <= 0) {
            return;
        }

        $userRow = $this->firstPrepared('SELECT user_id FROM characters WHERE id = ? LIMIT 1', [$viewerCharacterId]);
        if (empty($userRow)) {
            return;
        }

        $title = $action === 'leave'
            ? 'Hai ritirato l\'adesione alla quest'
            : 'Hai aderito alla quest';
        $questTitle = trim((string) ($definition['title'] ?? 'Quest'));

        $this->notificationService->create(
            (int) ($userRow->user_id ?? 0),
            $viewerCharacterId,
            NotificationService::KIND_SYSTEM_UPDATE,
            'quest_update',
            $title,
            [
                'message' => $questTitle,
                'source_type' => 'quest',
                'source_id' => (int) ($definition['id'] ?? 0),
                'priority' => 'normal',
            ],
        );
    }

    public function joinForGame(int $definitionId, array $payload, int $viewerCharacterId, bool $isStaff): array
    {
        $this->ensureEnabled();

        $definition = $this->ensureDefinitionExists($definitionId);
        if ((string) ($definition['status'] ?? 'draft') !== 'published' && !$isStaff) {
            throw AppError::validation('La quest non e disponibile', [], 'quest_instance_invalid_state');
        }

        $scopeType = $this->normalizeScope((string) ($definition['scope_type'] ?? 'character'));
        $scopeId = (int) ($definition['scope_id'] ?? 0);

        $viewerFactionIds = $this->viewerFactionIds($viewerCharacterId);
        $viewerGuildIds = $this->viewerGuildIds($viewerCharacterId);

        $assigneeType = $this->normalizeAssigneeType($scopeType === 'map' || $scopeType === 'location' ? 'world' : $scopeType);
        $assigneeId = 0;

        if ($assigneeType === 'character') {
            $assigneeId = $scopeId > 0 ? $scopeId : $viewerCharacterId;
            if ($assigneeId !== $viewerCharacterId && !$isStaff) {
                throw AppError::validation('Non puoi aderire a questa quest', [], 'quest_participation_forbidden');
            }
        }

        if ($assigneeType === 'faction') {
            $assigneeId = (int) ($payload['faction_id'] ?? $scopeId);
            if ($assigneeId <= 0) {
                $assigneeId = (int) ($viewerFactionIds[0] ?? 0);
            }
            if ($assigneeId <= 0 || (!in_array($assigneeId, $viewerFactionIds, true) && !$isStaff)) {
                throw AppError::validation('Fazione non valida per adesione', [], 'quest_participation_forbidden');
            }
        }

        if ($assigneeType === 'guild') {
            $assigneeId = (int) ($payload['guild_id'] ?? $scopeId);
            if ($assigneeId <= 0) {
                $assigneeId = (int) ($viewerGuildIds[0] ?? 0);
            }
            if ($assigneeId <= 0 || (!in_array($assigneeId, $viewerGuildIds, true) && !$isStaff)) {
                throw AppError::validation('Gilda non valida per adesione', [], 'quest_participation_forbidden');
            }
        }

        if ($assigneeType === 'world') {
            $assigneeId = 0;
        }

        $existing = $this->findExistingActiveOrAvailableInstance(
            (int) $definition['id'],
            $assigneeType,
            $assigneeType === 'world' ? null : $assigneeId,
        );

        if (!empty($existing)) {
            $intensityContext = $this->resolveIntensityContext($definition, $existing, $isStaff);
            $existing['instance_intensity_level'] = $intensityContext['instance_intensity_level'];
            $existing['definition_intensity_level'] = $intensityContext['definition_intensity_level'];
            $existing['effective_intensity_level'] = $intensityContext['effective_intensity_level'];
            $existing['intensity_visibility'] = $intensityContext['intensity_visibility'];
            $existing['intensity_level'] = $intensityContext['effective_intensity_level'];
            return [
                'instance' => $existing,
                'definition' => $definition,
                'already_joined' => 1,
                'intensity_level' => $intensityContext['effective_intensity_level'],
                'definition_intensity_level' => $intensityContext['definition_intensity_level'],
                'instance_intensity_level' => $intensityContext['instance_intensity_level'],
                'intensity_visibility' => $intensityContext['intensity_visibility'],
            ];
        }

        $instance = $this->createInstance(
            (int) $definition['id'],
            $assigneeType,
            $assigneeType === 'world' ? null : $assigneeId,
            'active',
            'manual_join',
            null,
            $viewerCharacterId,
            null,
            null,
            ['joined_by' => $viewerCharacterId],
            null,
        );

        $intensityContext = $this->resolveIntensityContext($definition, $instance, $isStaff);
        $instance['instance_intensity_level'] = $intensityContext['instance_intensity_level'];
        $instance['definition_intensity_level'] = $intensityContext['definition_intensity_level'];
        $instance['effective_intensity_level'] = $intensityContext['effective_intensity_level'];
        $instance['intensity_visibility'] = $intensityContext['intensity_visibility'];
        $instance['intensity_level'] = $intensityContext['effective_intensity_level'];

        $this->notifyQuestParticipation('join', $definition, $viewerCharacterId);

        return [
            'instance' => $instance,
            'definition' => $definition,
            'already_joined' => 0,
            'intensity_level' => $intensityContext['effective_intensity_level'],
            'definition_intensity_level' => $intensityContext['definition_intensity_level'],
            'instance_intensity_level' => $intensityContext['instance_intensity_level'],
            'intensity_visibility' => $intensityContext['intensity_visibility'],
        ];
    }

    public function leaveForGame(int $definitionId, array $payload, int $viewerCharacterId, bool $isStaff): array
    {
        $this->ensureEnabled();

        $definition = $this->ensureDefinitionExists($definitionId);
        $scopeType = $this->normalizeScope((string) ($definition['scope_type'] ?? 'character'));
        $scopeId = (int) ($definition['scope_id'] ?? 0);

        $viewerFactionIds = $this->viewerFactionIds($viewerCharacterId);
        $viewerGuildIds = $this->viewerGuildIds($viewerCharacterId);

        $assigneeType = $this->normalizeAssigneeType($scopeType === 'map' || $scopeType === 'location' ? 'world' : $scopeType);
        $assigneeId = 0;
        if ($assigneeType === 'character') {
            $assigneeId = $scopeId > 0 ? $scopeId : $viewerCharacterId;
        } elseif ($assigneeType === 'faction') {
            $assigneeId = (int) ($payload['faction_id'] ?? $scopeId ?: ($viewerFactionIds[0] ?? 0));
        } elseif ($assigneeType === 'guild') {
            $assigneeId = (int) ($payload['guild_id'] ?? $scopeId ?: ($viewerGuildIds[0] ?? 0));
        }

        $instance = $this->findExistingActiveOrAvailableInstance(
            (int) $definition['id'],
            $assigneeType,
            $assigneeType === 'world' ? null : $assigneeId,
        );

        if (empty($instance)) {
            throw AppError::validation('Nessuna adesione attiva trovata', [], 'quest_participation_conflict');
        }

        if (!$isStaff && !$this->canCharacterJoinAssignee($viewerCharacterId, $assigneeType, $assigneeId, $viewerFactionIds, $viewerGuildIds)) {
            throw AppError::validation('Non puoi ritirare questa adesione', [], 'quest_participation_forbidden');
        }

        $instanceId = (int) ($instance['id'] ?? 0);

        $this->execPrepared(
            'UPDATE quest_instances SET
                current_status = "cancelled",
                last_activity_at = NOW(),
                date_updated = NOW()
             WHERE id = ?
               AND current_status IN ("available","active")
             LIMIT 1',
            [$instanceId],
        );

        $this->execPrepared(
            'UPDATE quest_step_instances SET
                progress_status = CASE WHEN progress_status IN ("active","pending") THEN "skipped" ELSE progress_status END,
                updated_at = NOW()
             WHERE quest_instance_id = ?',
            [$instanceId],
        );

        $this->insertProgressLog(
            $instanceId,
            null,
            'instance_cancelled',
            'manual_leave',
            $viewerCharacterId,
            ['quest_definition_id' => (int) $definition['id']],
            $viewerCharacterId,
        );

        $this->notifyQuestParticipation('leave', $definition, $viewerCharacterId);

        return [
            'instance_id' => $instanceId,
            'status' => 'cancelled',
        ];
    }

    public function listInstancesForStaff(array $filters, int $limit = 30, int $page = 1, string $sort = 'id|DESC'): array
    {
        $this->ensureEnabled();

        $limit = max(1, min(200, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $whereParams = [];
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'i.current_status = ?';
            $whereParams[] = $this->normalizeStatus($status);
        }
        $definitionId = (int) ($filters['quest_definition_id'] ?? 0);
        if ($definitionId > 0) {
            $where[] = 'i.quest_definition_id = ?';
            $whereParams[] = $definitionId;
        }
        $assigneeType = trim((string) ($filters['assignee_type'] ?? ''));
        if ($assigneeType !== '') {
            $where[] = 'i.assignee_type = ?';
            $whereParams[] = $this->normalizeAssigneeType($assigneeType);
        }
        $assigneeId = (int) ($filters['assignee_id'] ?? 0);
        if ($assigneeId > 0) {
            $where[] = 'i.assignee_id = ?';
            $whereParams[] = $assigneeId;
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $allowedSort = ['id', 'quest_definition_id', 'assignee_type', 'assignee_id', 'current_status', 'started_at', 'completed_at', 'date_created'];
        $chunks = explode('|', (string) $sort);
        $sortField = in_array($chunks[0] ?? '', $allowedSort, true) ? $chunks[0] : 'id';
        $sortDir = strtoupper($chunks[1] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM quest_instances i ' . $whereSql,
            $whereParams,
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT i.*, d.title AS quest_title, d.slug AS quest_slug,
                    d.intensity_level AS definition_intensity_level,
                    d.intensity_visibility
             FROM quest_instances i
             INNER JOIN quest_definitions d ON d.id = i.quest_definition_id
             ' . $whereSql . '
             ORDER BY i.' . $sortField . ' ' . $sortDir . ', i.id DESC
             LIMIT ? OFFSET ?',
            array_merge($whereParams, [$limit, $offset]),
        );
        $out = [];
        foreach ($rows as $row) {
            $item = $this->decodeInstance($this->rowToArray($row));
            $intensityContext = $this->resolveIntensityContext(
                [
                    'intensity_level' => $item['definition_intensity_level'] ?? 'STANDARD',
                    'intensity_visibility' => $item['intensity_visibility'] ?? 'visible',
                ],
                $item,
                true,
            );
            $item['assignee_label'] = $this->buildAssigneeLabel(
                (string) ($item['assignee_type'] ?? 'character'),
                (int) ($item['assignee_id'] ?? 0),
            );
            $item['definition_intensity_level'] = $intensityContext['definition_intensity_level'];
            $item['instance_intensity_level'] = $intensityContext['instance_intensity_level'];
            $item['effective_intensity_level'] = $intensityContext['effective_intensity_level'];
            $item['intensity_visibility'] = $intensityContext['intensity_visibility'];
            $item['intensity_level'] = $intensityContext['effective_intensity_level'];
            $stepsCountRow = $this->firstPrepared(
                'SELECT COUNT(*) AS n FROM quest_step_instances WHERE quest_instance_id = ?',
                [(int) ($item['id'] ?? 0)],
            );
            $item['steps_count'] = (int) ($stepsCountRow->n ?? 0);
            $out[] = $item;
        }

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $out,
        ];
    }

    public function getInstanceDetail(int $instanceId): array
    {
        $this->ensureEnabled();
        $instance = $this->ensureInstanceExists($instanceId);
        $definitionId = (int) ($instance['quest_definition_id'] ?? 0);
        $definition = $definitionId > 0 ? $this->ensureDefinitionExists($definitionId) : null;
        $intensityContext = $this->resolveIntensityContext($definition ?: [], $instance, true);
        if (is_array($definition)) {
            $definition['intensity_level'] = $intensityContext['definition_intensity_level'];
            $definition['intensity_visibility'] = $intensityContext['intensity_visibility'];
        }
        $instance['instance_intensity_level'] = $intensityContext['instance_intensity_level'];
        $instance['effective_intensity_level'] = $intensityContext['effective_intensity_level'];
        $instance['intensity_visibility'] = $intensityContext['intensity_visibility'];
        $instance['definition_intensity_level'] = $intensityContext['definition_intensity_level'];
        $instance['intensity_level'] = $intensityContext['effective_intensity_level'];

        return [
            'instance' => $instance,
            'definition' => $definition,
            'steps' => $this->getAllStepInstances($instanceId),
            'links' => $this->listLinks(0, $instanceId),
            'intensity_level' => $intensityContext['effective_intensity_level'],
            'definition_intensity_level' => $intensityContext['definition_intensity_level'],
            'instance_intensity_level' => $intensityContext['instance_intensity_level'],
            'intensity_visibility' => $intensityContext['intensity_visibility'],
        ];
    }

    private function resolveTransitionDateForStatus(string $status): array
    {
        $status = $this->normalizeStatus($status);
        $startedAt = null;
        $completedAt = null;
        $failedAt = null;

        if ($status === 'active') {
            $startedAt = $this->now();
        }
        if ($status === 'completed') {
            $completedAt = $this->now();
        }
        if ($status === 'failed') {
            $failedAt = $this->now();
        }

        return [
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'failed_at' => $failedAt,
        ];
    }

    private function applyOutcomes(int $definitionId, string $triggerType, array $context): array
    {
        $rows = $this->fetchPrepared(
            'SELECT * FROM quest_outcomes
             WHERE quest_definition_id = ?
               AND trigger_type = ?
               AND is_active = 1
             ORDER BY sort_order ASC, id ASC',
            [(int) $definitionId, $triggerType],
        );
        $applied = [];

        foreach ($rows as $row) {
            $outcome = $this->decodeOutcome($this->rowToArray($row));
            $payload = is_array($outcome['outcome_payload']) ? $outcome['outcome_payload'] : [];
            $outcomeType = strtolower(trim((string) ($outcome['outcome_type'] ?? '')));

            try {
                $result = $this->applySingleOutcome($outcomeType, $payload, $context);
                $applied[] = [
                    'outcome_id' => (int) ($outcome['id'] ?? 0),
                    'outcome_type' => $outcomeType,
                    'status' => 'applied',
                    'result' => $result,
                ];
            } catch (\Throwable $error) {
                $applied[] = [
                    'outcome_id' => (int) ($outcome['id'] ?? 0),
                    'outcome_type' => $outcomeType,
                    'status' => 'failed',
                    'error' => $error->getMessage(),
                ];
            }
        }

        return $applied;
    }

    private function applySingleOutcome(string $type, array $payload, array $context): array
    {
        $definitionId = (int) ($context['quest_definition_id'] ?? 0);
        $instanceId = (int) ($context['quest_instance_id'] ?? 0);
        $assigneeType = (string) ($context['assignee_type'] ?? 'character');
        $assigneeId = (int) ($context['assignee_id'] ?? 0);
        $actorCharacterId = (int) ($context['actor_character_id'] ?? 0);

        if ($type === 'log_progress') {
            $logType = trim((string) ($payload['log_type'] ?? 'outcome_log'));
            $this->insertProgressLog(
                $instanceId,
                null,
                $logType !== '' ? $logType : 'outcome_log',
                'outcome',
                $definitionId > 0 ? $definitionId : null,
                ['context' => $context, 'payload' => $payload],
                $actorCharacterId > 0 ? $actorCharacterId : null,
            );
            return ['logged' => 1];
        }

        if ($type === 'notify') {
            if ($assigneeType === 'character' && $assigneeId > 0) {
                $userRow = $this->firstPrepared(
                    'SELECT user_id FROM characters WHERE id = ? LIMIT 1',
                    [$assigneeId],
                );
                if (!empty($userRow)) {
                    $this->notificationService->create(
                        (int) ($userRow->user_id ?? 0),
                        $assigneeId,
                        NotificationService::KIND_SYSTEM_UPDATE,
                        'quest_update',
                        trim((string) ($payload['title'] ?? 'Aggiornamento quest')),
                        [
                            'message' => trim((string) ($payload['message'] ?? '')),
                            'source_type' => 'quest',
                            'source_id' => $definitionId > 0 ? $definitionId : $instanceId,
                            'priority' => trim((string) ($payload['priority'] ?? 'normal')),
                        ],
                    );
                }
            }
            return ['notified' => 1];
        }

        if ($type === 'create_narrative_event' || $type === 'narrative_event_create') {
            $title = trim((string) ($payload['title'] ?? 'Aggiornamento quest'));
            if ($title === '') {
                $title = 'Aggiornamento quest';
            }
            $description = trim((string) ($payload['description'] ?? ''));
            $eventType = trim((string) ($payload['event_type'] ?? 'quest_update'));
            $visibility = $this->normalizeVisibility((string) ($payload['visibility'] ?? 'public'));
            $locationId = (int) ($payload['location_id'] ?? 0);

            $entityRefs = [];
            if ($assigneeType === 'character' && $assigneeId > 0) {
                $entityRefs[] = ['entity_type' => 'character', 'entity_id' => $assigneeId, 'role' => 'target'];
            }
            if ($assigneeType === 'faction' && $assigneeId > 0) {
                $entityRefs[] = ['entity_type' => 'faction', 'entity_id' => $assigneeId, 'role' => 'target'];
            }
            if ($assigneeType === 'guild' && $assigneeId > 0) {
                $entityRefs[] = ['entity_type' => 'event', 'entity_id' => $assigneeId, 'role' => 'target'];
            }

            $event = $this->narrativeEventService->createEvent([
                'title' => $title,
                'description' => $description,
                'event_type' => $eventType !== '' ? $eventType : 'quest_update',
                'scope' => 'local',
                'location_id' => $locationId > 0 ? $locationId : null,
                'visibility' => $visibility,
                'entity_refs' => $entityRefs,
                'source_system' => 'quest',
                'source_ref_id' => $instanceId > 0 ? $instanceId : $definitionId,
                'meta_json' => [
                    'quest_definition_id' => $definitionId,
                    'quest_instance_id' => $instanceId,
                    'trigger' => (string) ($context['trigger_type'] ?? ''),
                ],
                'created_by' => $actorCharacterId > 0 ? $actorCharacterId : null,
            ]);

            $narrativeEventId = (int) ($event['id'] ?? 0);
            if ($narrativeEventId > 0) {
                $this->upsertLink([
                    'quest_definition_id' => $definitionId > 0 ? $definitionId : null,
                    'quest_instance_id' => $instanceId > 0 ? $instanceId : null,
                    'narrative_event_id' => $narrativeEventId,
                    'link_type' => 'generated_event',
                    'meta_json' => ['trigger_type' => (string) ($context['trigger_type'] ?? '')],
                ], $actorCharacterId);
            }

            return ['narrative_event_id' => $narrativeEventId];
        }

        if ($type === 'unlock_quest') {
            $targetDefinitionId = (int) ($payload['quest_definition_id'] ?? 0);
            if ($targetDefinitionId <= 0) {
                throw AppError::validation('Outcome unlock_quest senza target quest_definition_id', [], 'quest_outcome_invalid');
            }

            $targetAssigneeType = $this->normalizeAssigneeType((string) ($payload['assignee_type'] ?? $assigneeType));
            $targetAssigneeId = (int) ($payload['assignee_id'] ?? $assigneeId);
            if ($targetAssigneeType === 'world') {
                $targetAssigneeId = 0;
            }

            $existing = $this->findExistingActiveOrAvailableInstance(
                $targetDefinitionId,
                $targetAssigneeType,
                $targetAssigneeType === 'world' ? null : $targetAssigneeId,
            );

            if (empty($existing)) {
                $new = $this->createInstance(
                    $targetDefinitionId,
                    $targetAssigneeType,
                    $targetAssigneeType === 'world' ? null : $targetAssigneeId,
                    'available',
                    'quest_outcome',
                    $instanceId > 0 ? $instanceId : null,
                    $actorCharacterId > 0 ? $actorCharacterId : null,
                    null,
                    null,
                    ['unlocked_by_instance' => $instanceId],
                );
                return ['unlocked_instance_id' => (int) ($new['id'] ?? 0)];
            }

            return ['unlocked_instance_id' => (int) ($existing['id'] ?? 0), 'already_existing' => 1];
        }

        if ($type === 'complete_quest' || $type === 'fail_quest') {
            $targetStatus = ($type === 'complete_quest') ? 'completed' : 'failed';
            $targetInstanceId = (int) ($payload['quest_instance_id'] ?? 0);
            if ($targetInstanceId <= 0) {
                $targetInstanceId = $instanceId;
            }
            if ($targetInstanceId <= 0) {
                throw AppError::validation('Outcome ' . $type . ' senza quest_instance_id', [], 'quest_outcome_invalid');
            }
            $this->setInstanceStatus($targetInstanceId, $targetStatus, $actorCharacterId, 'outcome_' . $type);
            return ['target_instance_id' => $targetInstanceId, 'status' => $targetStatus];
        }

        // Delegate unknown/module-provided outcome types to the hook registry.
        // Modules register handlers via:
        //   Hooks::add('quest.outcome.apply_narrative_state', function($r, $payload, $context) { ... return [...]; });
        //   Hooks::add('quest.outcome.remove_narrative_state', function($r, $payload, $context) { ... return [...]; });
        $hookResult = \Core\Hooks::filter('quest.outcome.' . $type, null, $payload, $context);
        if ($hookResult !== null) {
            return is_array($hookResult) ? $hookResult : ['result' => $hookResult];
        }

        throw AppError::validation('Outcome non supportato: ' . $type, [], 'quest_outcome_invalid');
    }

    private function advanceInstanceIfNeeded(int $instanceId, ?int $actorCharacterId = null, string $sourceType = 'system', ?int $sourceId = null): array
    {
        $activeStep = $this->getActiveStepInstance($instanceId);
        if ($activeStep !== null) {
            return [
                'instance_id' => $instanceId,
                'status' => 'active',
                'active_step_id' => (int) ($activeStep['id'] ?? 0),
                'completed' => 0,
            ];
        }

        $pendingRow = $this->firstPrepared(
            'SELECT si.id, si.quest_step_definition_id
             FROM quest_step_instances si
             INNER JOIN quest_step_definitions sd ON sd.id = si.quest_step_definition_id
             WHERE si.quest_instance_id = ?
               AND si.progress_status = "locked"
             ORDER BY sd.order_index ASC, si.id ASC
             LIMIT 1',
            [(int) $instanceId],
        );

        if (!empty($pendingRow)) {
            $nextStepId = (int) ($pendingRow->id ?? 0);
            if ($nextStepId > 0) {
                $this->execPrepared(
                    'UPDATE quest_step_instances
                     SET progress_status = "active", started_at = NOW(), updated_at = NOW()
                     WHERE id = ?
                     LIMIT 1',
                    [$nextStepId],
                );
                $this->insertProgressLog(
                    $instanceId,
                    $nextStepId,
                    'step_activated',
                    $sourceType,
                    $sourceId,
                    ['next_step_id' => $nextStepId],
                    $actorCharacterId,
                );
            }
            return [
                'instance_id' => $instanceId,
                'status' => 'active',
                'active_step_id' => $nextStepId,
                'completed' => 0,
            ];
        }

        $instance = $this->ensureInstanceExists($instanceId);
        if ((string) ($instance['current_status'] ?? '') !== 'completed') {
            $this->setInstanceStatus($instanceId, 'completed', $actorCharacterId ?? 0, 'auto_complete');
        }

        return [
            'instance_id' => $instanceId,
            'status' => 'completed',
            'active_step_id' => 0,
            'completed' => 1,
        ];
    }

    public function setInstanceStatus(
        int $instanceId,
        string $status,
        int $actorCharacterId,
        string $sourceType = 'manual',
        ?string $instanceIntensityLevel = null,
    ): array {
        $this->ensureEnabled();

        $instance = $this->ensureInstanceExists($instanceId);
        $oldStatus = (string) ($instance['current_status'] ?? 'available');
        $status = $this->normalizeStatus($status);
        $oldIntensity = $this->normalizeIntensityLevel($instance['intensity_level'] ?? null, 'STANDARD', true);
        $intensityChanged = false;
        $normalizedNewIntensity = $oldIntensity;
        if ($instanceIntensityLevel !== null) {
            $normalizedNewIntensity = $this->normalizeIntensityLevel($instanceIntensityLevel, 'STANDARD', true, true);
            $intensityChanged = $normalizedNewIntensity !== $oldIntensity;
        }
        $statusChanged = $oldStatus !== $status;
        if (!$statusChanged && !$intensityChanged) {
            return $instance;
        }

        $transitionDates = $this->resolveTransitionDateForStatus($status);

        if ($statusChanged) {
            $this->execPrepared(
                'UPDATE quest_instances SET
                    current_status = ?,
                    started_at = COALESCE(started_at, ?),
                    completed_at = ?,
                    failed_at = ?,
                    intensity_level = CASE WHEN ? = 1 THEN ? ELSE intensity_level END,
                    last_activity_at = NOW()
                 WHERE id = ?
                 LIMIT 1',
                [
                    $status,
                    $transitionDates['started_at'],
                    $transitionDates['completed_at'],
                    $transitionDates['failed_at'],
                    $instanceIntensityLevel !== null ? 1 : 0,
                    $normalizedNewIntensity,
                    (int) $instanceId,
                ],
            );

            if ($status === 'cancelled' || $status === 'failed' || $status === 'expired') {
                $this->execPrepared(
                    'UPDATE quest_step_instances
                     SET progress_status = CASE WHEN progress_status IN ("active","pending") THEN "failed" ELSE progress_status END,
                         failed_at = CASE WHEN progress_status IN ("active","pending") THEN NOW() ELSE failed_at END,
                         updated_at = NOW()
                     WHERE quest_instance_id = ?',
                    [(int) $instanceId],
                );
            }

            $this->insertProgressLog(
                $instanceId,
                null,
                'instance_status_changed',
                $sourceType,
                $actorCharacterId > 0 ? $actorCharacterId : null,
                ['from' => $oldStatus, 'to' => $status],
                $actorCharacterId > 0 ? $actorCharacterId : null,
            );

            $definitionId = (int) ($instance['quest_definition_id'] ?? 0);
            if ($definitionId > 0) {
                if ($status === 'completed') {
                    $this->applyOutcomes($definitionId, 'quest_completed', [
                        'quest_definition_id' => $definitionId,
                        'quest_instance_id' => $instanceId,
                        'assignee_type' => (string) ($instance['assignee_type'] ?? 'character'),
                        'assignee_id' => (int) ($instance['assignee_id'] ?? 0),
                        'actor_character_id' => $actorCharacterId,
                        'trigger_type' => 'quest_completed',
                    ]);
                } elseif (in_array($status, ['failed', 'expired', 'cancelled'], true)) {
                    $this->applyOutcomes($definitionId, 'quest_failed', [
                        'quest_definition_id' => $definitionId,
                        'quest_instance_id' => $instanceId,
                        'assignee_type' => (string) ($instance['assignee_type'] ?? 'character'),
                        'assignee_id' => (int) ($instance['assignee_id'] ?? 0),
                        'actor_character_id' => $actorCharacterId,
                        'trigger_type' => 'quest_failed',
                    ]);
                }
            }

            if (in_array($status, ['completed', 'failed', 'cancelled', 'expired'], true)) {
                try {
                    (new QuestClosureService($this->db, $this))->ensureMinimalReport(
                        $instanceId,
                        $status,
                        $actorCharacterId,
                        $sourceType,
                    );
                } catch (\Throwable $closureError) {
                    // Compat difensiva: la chiusura tecnica non deve bloccare l'update stato.
                }
            }
        } elseif ($intensityChanged) {
            $this->execPrepared(
                'UPDATE quest_instances SET
                    intensity_level = ?,
                    last_activity_at = NOW()
                 WHERE id = ?
                 LIMIT 1',
                [$normalizedNewIntensity, (int) $instanceId],
            );
        }

        if ($intensityChanged) {
            $this->insertProgressLog(
                $instanceId,
                null,
                'instance_intensity_changed',
                $sourceType,
                $actorCharacterId > 0 ? $actorCharacterId : null,
                [
                    'from' => $oldIntensity,
                    'to' => $normalizedNewIntensity,
                ],
                $actorCharacterId > 0 ? $actorCharacterId : null,
            );
        }

        return $this->ensureInstanceExists($instanceId);
    }

    public function setStepStatus(int $instanceId, int $stepInstanceId, string $status, int $actorCharacterId, string $sourceType = 'staff'): array
    {
        $this->ensureEnabled();

        $instance = $this->ensureInstanceExists($instanceId);
        $status = strtolower(trim($status));
        $allowed = ['pending', 'active', 'completed', 'failed', 'skipped', 'locked'];
        if (!in_array($status, $allowed, true)) {
            throw AppError::validation('Stato step non valido', [], 'quest_instance_invalid_state');
        }

        $step = $this->firstPrepared(
            'SELECT si.*, sd.step_key, sd.order_index
             FROM quest_step_instances si
             INNER JOIN quest_step_definitions sd ON sd.id = si.quest_step_definition_id
             WHERE si.id = ?
               AND si.quest_instance_id = ?
             LIMIT 1',
            [(int) $stepInstanceId, (int) $instanceId],
        );

        if (empty($step)) {
            throw AppError::notFound('Step quest non trovato', [], 'quest_not_found');
        }

        $stepArray = $this->rowToArray($step);
        $oldStatus = (string) ($stepArray['progress_status'] ?? 'locked');

        $this->execPrepared(
            'UPDATE quest_step_instances SET
                progress_status = ?,
                started_at = CASE WHEN ? = "active" THEN COALESCE(started_at, NOW()) ELSE started_at END,
                completed_at = CASE WHEN ? = "completed" THEN NOW() ELSE completed_at END,
                failed_at = CASE WHEN ? = "failed" THEN NOW() ELSE failed_at END,
                updated_at = NOW()
             WHERE id = ?
             LIMIT 1',
            [$status, $status, $status, $status, (int) $stepInstanceId],
        );

        $this->insertProgressLog(
            $instanceId,
            $stepInstanceId,
            'step_status_changed',
            $sourceType,
            $actorCharacterId > 0 ? $actorCharacterId : null,
            ['from' => $oldStatus, 'to' => $status],
            $actorCharacterId > 0 ? $actorCharacterId : null,
        );

        if ($status === 'completed') {
            $definitionId = (int) ($instance['quest_definition_id'] ?? 0);
            if ($definitionId > 0) {
                $this->applyOutcomes($definitionId, 'step_completed', [
                    'quest_definition_id' => $definitionId,
                    'quest_instance_id' => $instanceId,
                    'step_instance_id' => $stepInstanceId,
                    'step_key' => (string) ($stepArray['step_key'] ?? ''),
                    'assignee_type' => (string) ($instance['assignee_type'] ?? 'character'),
                    'assignee_id' => (int) ($instance['assignee_id'] ?? 0),
                    'actor_character_id' => $actorCharacterId,
                    'trigger_type' => 'step_completed',
                ]);
            }

            return $this->advanceInstanceIfNeeded(
                $instanceId,
                $actorCharacterId > 0 ? $actorCharacterId : null,
                $sourceType,
                $actorCharacterId > 0 ? $actorCharacterId : null,
            );
        }

        if ($status === 'failed') {
            $this->setInstanceStatus($instanceId, 'failed', $actorCharacterId, $sourceType);
        }

        return [
            'instance_id' => $instanceId,
            'step_instance_id' => $stepInstanceId,
            'status' => $status,
        ];
    }

    public function confirmStepForStaff(array $payload, int $actorCharacterId): array
    {
        $instanceId = (int) ($payload['quest_instance_id'] ?? $payload['instance_id'] ?? 0);
        $stepInstanceId = (int) ($payload['step_instance_id'] ?? $payload['id'] ?? 0);
        $status = trim((string) ($payload['status'] ?? 'completed'));

        if ($instanceId <= 0 || $stepInstanceId <= 0) {
            throw AppError::validation('Istanza o step non validi', [], 'quest_not_found');
        }

        return $this->setStepStatus($instanceId, $stepInstanceId, $status, $actorCharacterId, 'staff_confirm');
    }

    public function forceProgress(array $payload, int $actorCharacterId): array
    {
        $instanceId = (int) ($payload['quest_instance_id'] ?? $payload['instance_id'] ?? 0);
        if ($instanceId <= 0) {
            throw AppError::validation('Istanza quest non valida', [], 'quest_not_found');
        }

        $activeStep = $this->getActiveStepInstance($instanceId);
        if ($activeStep === null) {
            $result = $this->advanceInstanceIfNeeded($instanceId, $actorCharacterId, 'staff_force', $actorCharacterId);
            return ['advanced' => 1, 'result' => $result];
        }

        $stepInstanceId = (int) ($activeStep['id'] ?? 0);
        $status = trim((string) ($payload['status'] ?? 'completed'));
        $res = $this->setStepStatus($instanceId, $stepInstanceId, $status, $actorCharacterId, 'staff_force');

        return ['advanced' => 1, 'result' => $res];
    }

    public function listLogs(array $filters, int $limit = 50, int $page = 1): array
    {
        $this->ensureEnabled();

        $limit = max(1, min(200, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];
        $instanceId = (int) ($filters['quest_instance_id'] ?? 0);
        if ($instanceId > 0) {
            $where[] = 'l.quest_instance_id = ?';
            $params[] = $instanceId;
        }
        $definitionId = (int) ($filters['quest_definition_id'] ?? 0);
        if ($definitionId > 0) {
            $where[] = 'i.quest_definition_id = ?';
            $params[] = $definitionId;
        }
        $logType = trim((string) ($filters['log_type'] ?? ''));
        if ($logType !== '') {
            $where[] = 'l.log_type = ?';
            $params[] = $logType;
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $totalRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n
             FROM quest_progress_logs l
             INNER JOIN quest_instances i ON i.id = l.quest_instance_id
             ' . $whereSql,
            $params,
        );
        $total = (int) ($totalRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT l.*, i.quest_definition_id, d.title AS quest_title
             FROM quest_progress_logs l
             INNER JOIN quest_instances i ON i.id = l.quest_instance_id
             INNER JOIN quest_definitions d ON d.id = i.quest_definition_id
             ' . $whereSql . '
             ORDER BY l.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->decodeLog($this->rowToArray($row));
        }

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $out,
        ];
    }

    private function evaluateSingleCondition(array $condition, array $context): bool
    {
        $payload = is_array($condition['condition_payload'] ?? null) ? $condition['condition_payload'] : [];
        $operator = strtolower(trim((string) ($condition['operator'] ?? 'eq')));

        $field = trim((string) ($payload['field'] ?? ''));
        if ($field !== '') {
            $expected = $payload['value'] ?? null;
            $actual = $context[$field] ?? null;
            return $this->compareValues($actual, $expected, $operator);
        }

        if (!empty($payload)) {
            foreach ($payload as $key => $expected) {
                if ($key === 'field' || $key === 'value') {
                    continue;
                }
                $actual = $context[$key] ?? null;
                if (!$this->compareValues($actual, $expected, $operator)) {
                    return false;
                }
            }
            return true;
        }

        return true;
    }

    private function compareValues($actual, $expected, string $operator): bool
    {
        switch ($operator) {
            case 'ne':
                return $actual != $expected;
            case 'in':
                if (!is_array($expected)) {
                    $expected = array_map('trim', explode(',', (string) $expected));
                }
                return in_array((string) $actual, array_map('strval', $expected), true);
            case 'not_in':
                if (!is_array($expected)) {
                    $expected = array_map('trim', explode(',', (string) $expected));
                }
                return !in_array((string) $actual, array_map('strval', $expected), true);
            case 'gt':
                return (float) $actual > (float) $expected;
            case 'gte':
                return (float) $actual >= (float) $expected;
            case 'lt':
                return (float) $actual < (float) $expected;
            case 'lte':
                return (float) $actual <= (float) $expected;
            case 'contains':
                return strpos(strtolower((string) $actual), strtolower((string) $expected)) !== false;
            case 'eq':
            default:
                return (string) $actual === (string) $expected;
        }
    }

    private function evaluateConditions(array $conditions, array $context): bool
    {
        if (empty($conditions)) {
            return false;
        }

        $mode = strtolower(trim((string) ($conditions[0]['evaluation_mode'] ?? 'all_required')));
        $results = [];
        foreach ($conditions as $condition) {
            if ((int) ($condition['is_active'] ?? 1) !== 1) {
                continue;
            }
            $results[] = $this->evaluateSingleCondition($condition, $context);
        }

        if (empty($results)) {
            return false;
        }

        if ($mode === 'any_required' || $mode === 'optional') {
            foreach ($results as $value) {
                if ($value === true) {
                    return true;
                }
            }
            return false;
        }

        foreach ($results as $value) {
            if ($value !== true) {
                return false;
            }
        }
        return true;
    }

    public function processTrigger(string $triggerType, array $context = []): array
    {
        $this->ensureEnabled();

        $triggerType = strtolower(trim($triggerType));
        if ($triggerType === '') {
            throw AppError::validation('Trigger quest non valido', [], 'quest_trigger_unsupported');
        }

        $rows = $this->fetchPrepared(
            'SELECT i.id AS instance_id, i.quest_definition_id
             FROM quest_instances i
             WHERE i.current_status = "active"',
        );

        $processed = 0;
        $matched = 0;
        $completedSteps = 0;

        foreach ($rows as $row) {
            $instanceId = (int) ($row->instance_id ?? 0);
            $definitionId = (int) ($row->quest_definition_id ?? 0);
            if ($instanceId <= 0 || $definitionId <= 0) {
                continue;
            }

            $processed++;
            $activeStep = $this->getActiveStepInstance($instanceId);
            if ($activeStep === null) {
                continue;
            }

            $stepDefId = (int) ($activeStep['quest_step_definition_id'] ?? 0);
            $conditions = $this->listConditions([
                'quest_definition_id' => $definitionId,
                'quest_step_definition_id' => $stepDefId,
                'condition_type' => $triggerType,
            ]);

            if (empty($conditions)) {
                $conditions = $this->listConditions([
                    'quest_definition_id' => $definitionId,
                    'condition_type' => $triggerType,
                ]);
            }

            if (empty($conditions)) {
                continue;
            }

            $match = $this->evaluateConditions($conditions, $context);
            if (!$match) {
                continue;
            }

            $matched++;
            $this->setStepStatus(
                $instanceId,
                (int) ($activeStep['id'] ?? 0),
                'completed',
                (int) ($context['actor_character_id'] ?? 0),
                'trigger:' . $triggerType,
            );
            $completedSteps++;
        }

        return [
            'trigger_type' => $triggerType,
            'processed_instances' => $processed,
            'matched_instances' => $matched,
            'completed_steps' => $completedSteps,
        ];
    }

    public function maintenanceRun(bool $force = false): array
    {
        $this->ensureEnabled();

        $interval = (int) $this->getConfig('quests_maintenance_interval_minutes', '5');
        if ($interval < 1) {
            $interval = 5;
        }

        $now = time();
        if (!$force && self::$lastMaintenanceRunAt > 0) {
            if (($now - self::$lastMaintenanceRunAt) < ($interval * 60)) {
                return ['skipped' => 'interval', 'expired_ids' => []];
            }
        }

        self::$lastMaintenanceRunAt = $now;

        $rows = $this->fetchPrepared(
            'SELECT id
             FROM quest_instances
             WHERE current_status IN ("available","active")
               AND expires_at IS NOT NULL
               AND expires_at <= NOW()',
        );
        $expired = [];

        foreach ($rows as $row) {
            $instanceId = (int) ($row->id ?? 0);
            if ($instanceId <= 0) {
                continue;
            }
            $this->setInstanceStatus($instanceId, 'expired', 0, 'maintenance');
            $expired[] = $instanceId;
        }

        return ['expired_ids' => array_values(array_unique($expired))];
    }
}
