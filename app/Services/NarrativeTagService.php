<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\FactionProviderRegistry;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class NarrativeTagService
{
    public const ENTITY_QUEST_DEFINITION = 'quest_definition';
    public const ENTITY_NARRATIVE_EVENT = 'narrative_event';
    public const ENTITY_SYSTEM_EVENT = 'system_event';
    public const ENTITY_SCENE = 'scene';
    public const ENTITY_FACTION = 'faction';

    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

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

    private function normalizeCategory($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        return mb_substr($raw, 0, 60);
    }

    private function normalizeSlug($value): string
    {
        $raw = strtolower(trim((string) $value));
        $raw = preg_replace('/[^a-z0-9_\-]+/u', '-', $raw);
        $raw = trim((string) $raw, '-_');
        return mb_substr((string) $raw, 0, 80);
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    public function parseTagIds($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $items = [];
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_object($raw)) {
            $items = (array) $raw;
        } else {
            $string = trim((string) $raw);
            if ($string === '') {
                return [];
            }
            if (strpos($string, '[') === 0) {
                $decoded = json_decode($string, true);
                if (is_array($decoded)) {
                    $items = $decoded;
                } else {
                    $items = preg_split('/\s*,\s*/', $string);
                }
            } else {
                $items = preg_split('/\s*,\s*/', $string);
            }
        }

        $out = [];
        foreach ($items as $item) {
            $id = (int) $item;
            if ($id > 0) {
                $out[$id] = $id;
            }
        }

        return array_values($out);
    }

    public function normalizeEntityType(string $raw): string
    {
        $value = strtolower(trim($raw));
        $map = [
            'quest' => self::ENTITY_QUEST_DEFINITION,
            'quests' => self::ENTITY_QUEST_DEFINITION,
            'quest_definition' => self::ENTITY_QUEST_DEFINITION,
            'quest_definitions' => self::ENTITY_QUEST_DEFINITION,
            'narrative_event' => self::ENTITY_NARRATIVE_EVENT,
            'narrative_events' => self::ENTITY_NARRATIVE_EVENT,
            'activity' => self::ENTITY_NARRATIVE_EVENT,
            'activities' => self::ENTITY_NARRATIVE_EVENT,
            'system_event' => self::ENTITY_SYSTEM_EVENT,
            'system_events' => self::ENTITY_SYSTEM_EVENT,
            'event' => self::ENTITY_SYSTEM_EVENT,
            'events' => self::ENTITY_SYSTEM_EVENT,
            'scene' => self::ENTITY_SCENE,
            'scenes' => self::ENTITY_SCENE,
            'location' => self::ENTITY_SCENE,
            'locations' => self::ENTITY_SCENE,
            'faction' => self::ENTITY_FACTION,
            'factions' => self::ENTITY_FACTION,
        ];

        if (!isset($map[$value])) {
            throw AppError::validation('Tipo entita tag non valido', [], 'narrative_tag_entity_invalid');
        }

        return $map[$value];
    }

    public function getMaxTagsPerEntity(): int
    {
        $row = $this->firstPrepared(
            'SELECT `value` FROM sys_configs WHERE `key` = ? LIMIT 1',
            ['narrative_tags_max_per_entity'],
        );

        $value = (int) ($row->value ?? 8);
        if ($value <= 0) {
            $value = 8;
        }

        return max(1, min(30, $value));
    }

    private function ensureEntityExists(string $entityType, int $entityId): void
    {
        if ($entityId <= 0) {
            throw AppError::validation('Entita non valida', [], 'narrative_tag_entity_not_found');
        }

        $row = null;
        if ($entityType === self::ENTITY_QUEST_DEFINITION) {
            $row = $this->firstPrepared('SELECT id FROM quest_definitions WHERE id = ? LIMIT 1', [$entityId]);
        } elseif ($entityType === self::ENTITY_NARRATIVE_EVENT) {
            $row = $this->firstPrepared('SELECT id FROM narrative_events WHERE id = ? LIMIT 1', [$entityId]);
        } elseif ($entityType === self::ENTITY_SYSTEM_EVENT) {
            $row = $this->firstPrepared('SELECT id FROM system_events WHERE id = ? LIMIT 1', [$entityId]);
        } elseif ($entityType === self::ENTITY_SCENE) {
            $row = $this->firstPrepared('SELECT id FROM locations WHERE id = ? AND date_deleted IS NULL LIMIT 1', [$entityId]);
        } elseif ($entityType === self::ENTITY_FACTION) {
            $row = FactionProviderRegistry::existsById($entityId) ? (object) ['id' => $entityId] : null;
        }

        if (empty($row)) {
            throw AppError::notFound('Entita non trovata', [], 'narrative_tag_entity_not_found');
        }
    }

    /**
     * @param int[] $tagIds
     */
    private function validateTagIds(array $tagIds): array
    {
        if (empty($tagIds)) {
            return [];
        }

        $clean = [];
        foreach ($tagIds as $tagId) {
            $id = (int) $tagId;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        $clean = array_values($clean);
        if (empty($clean)) {
            return [];
        }

        // IDs are already sanitized integers — safe to interpolate in IN clause
        $rows = $this->fetchPrepared(
            'SELECT id, is_active FROM narrative_tags WHERE id IN (' . implode(',', $clean) . ')',
        );

        $found = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ((int) ($item['is_active'] ?? 0) !== 1) {
                throw AppError::validation('Sono presenti tag non attivi', [], 'narrative_tag_inactive');
            }
            $found[$id] = $id;
        }

        if (count($found) !== count($clean)) {
            throw AppError::validation('Uno o piu tag non esistono', [], 'narrative_tag_not_found');
        }

        return array_values($found);
    }

    public function listCatalog(array $filters = [], int $limit = 200, int $page = 1, string $sort = 'label|ASC', bool $includeInactive = false): array
    {
        $limit = max(1, min(300, (int) $limit));
        $page = max(1, (int) $page);
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(t.slug LIKE ? OR t.label LIKE ? OR t.description LIKE ? OR t.category LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $category = $this->normalizeCategory($filters['category'] ?? '');
        if ($category !== '') {
            $where[] = 't.category = ?';
            $params[] = $category;
        }

        $isActiveFilter = $filters['is_active'] ?? '';
        if ($isActiveFilter !== '') {
            $active = (int) $isActiveFilter === 1 ? 1 : 0;
            $where[] = 't.is_active = ' . $active;
        } elseif (!$includeInactive) {
            $where[] = 't.is_active = 1';
        }

        $chunks = explode('|', (string) $sort);
        $sortField = in_array($chunks[0], ['id', 'slug', 'label', 'category', 'is_active', 'date_created'], true)
            ? $chunks[0]
            : 'label';
        $sortDir = strtoupper((string) ($chunks[1] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $countRow = $this->firstPrepared(
            'SELECT COUNT(*) AS n FROM narrative_tags t ' . $whereSql,
            $params,
        );
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->fetchPrepared(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM narrative_tag_assignments a WHERE a.tag_id = t.id) AS assignments_count
             FROM narrative_tags t
             ' . $whereSql . '
             ORDER BY t.' . $sortField . ' ' . $sortDir . ', t.id DESC
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        $dataset = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['is_active'] = (int) ($item['is_active'] ?? 0);
            $item['assignments_count'] = (int) ($item['assignments_count'] ?? 0);
            $dataset[] = $item;
        }

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => $dataset,
        ];
    }

    public function listActiveCatalog(?string $entityType = null, array $filters = []): array
    {
        $where = ['is_active = 1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(slug LIKE ? OR label LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($entityType !== null && trim($entityType) !== '') {
            // Core-light: il catalogo e trasversale alle entita.
            // Manteniamo la validazione del tipo senza limitare i tag per categoria tecnica.
            $this->normalizeEntityType($entityType);
        }

        $rows = $this->fetchPrepared(
            'SELECT id, slug, label, description, category, is_active
             FROM narrative_tags
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY label ASC, id ASC',
            $params,
        );

        $dataset = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['is_active'] = (int) ($item['is_active'] ?? 0);
            $dataset[] = $item;
        }

        return $dataset;
    }

    public function getById(int $tagId): array
    {
        $row = $this->firstPrepared('SELECT * FROM narrative_tags WHERE id = ? LIMIT 1', [$tagId]);
        if (empty($row)) {
            throw AppError::notFound('Tag narrativo non trovato', [], 'narrative_tag_not_found');
        }

        $item = $this->rowToArray($row);
        $item['id'] = (int) ($item['id'] ?? 0);
        $item['is_active'] = (int) ($item['is_active'] ?? 0);
        return $item;
    }

    public function create(array $payload, int $actorCharacterId = 0): array
    {
        $slug = $this->normalizeSlug($payload['slug'] ?? '');
        $label = trim((string) ($payload['label'] ?? ''));
        if ($slug === '' || $label === '') {
            throw AppError::validation('Slug e label sono obbligatori', [], 'narrative_tag_invalid');
        }

        $exists = $this->firstPrepared('SELECT id FROM narrative_tags WHERE slug = ? LIMIT 1', [$slug]);
        if (!empty($exists)) {
            throw AppError::validation('Slug gia presente', [], 'narrative_tag_slug_conflict');
        }

        $description = trim((string) ($payload['description'] ?? ''));
        $category = $this->normalizeCategory($payload['category'] ?? '');
        $isActive = (int) ($payload['is_active'] ?? 1) === 1 ? 1 : 0;

        $this->execPrepared(
            'INSERT INTO narrative_tags
            (slug, label, description, category, is_active, created_by, updated_by, date_created, date_updated)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $slug,
                mb_substr($label, 0, 80),
                $description !== '' ? mb_substr($description, 0, 255) : null,
                $category !== '' ? $category : null,
                $isActive,
                $actorCharacterId > 0 ? $actorCharacterId : null,
                $actorCharacterId > 0 ? $actorCharacterId : null,
            ],
        );

        return $this->getById((int) $this->db->lastInsertId());
    }

    public function update(int $tagId, array $payload, int $actorCharacterId = 0): array
    {
        $current = $this->getById($tagId);

        $slug = $this->normalizeSlug($payload['slug'] ?? $current['slug'] ?? '');
        $label = trim((string) ($payload['label'] ?? $current['label'] ?? ''));
        if ($slug === '' || $label === '') {
            throw AppError::validation('Slug e label sono obbligatori', [], 'narrative_tag_invalid');
        }

        $dup = $this->firstPrepared(
            'SELECT id FROM narrative_tags WHERE slug = ? AND id <> ? LIMIT 1',
            [$slug, $tagId],
        );
        if (!empty($dup)) {
            throw AppError::validation('Slug gia presente', [], 'narrative_tag_slug_conflict');
        }

        $description = trim((string) ($payload['description'] ?? ($current['description'] ?? '')));
        $category = $this->normalizeCategory($payload['category'] ?? ($current['category'] ?? ''));
        $isActive = array_key_exists('is_active', $payload)
            ? ((int) $payload['is_active'] === 1 ? 1 : 0)
            : (int) ($current['is_active'] ?? 1);

        $this->execPrepared(
            'UPDATE narrative_tags SET
                slug        = ?,
                label       = ?,
                description = ?,
                category    = ?,
                is_active   = ?,
                updated_by  = ?,
                date_updated = NOW()
             WHERE id = ?
             LIMIT 1',
            [
                $slug,
                mb_substr($label, 0, 80),
                $description !== '' ? mb_substr($description, 0, 255) : null,
                $category !== '' ? $category : null,
                $isActive,
                $actorCharacterId > 0 ? $actorCharacterId : null,
                $tagId,
            ],
        );

        return $this->getById($tagId);
    }

    public function delete(int $tagId): array
    {
        $tag = $this->getById($tagId);
        // tag_id is a sanitized integer — safe to interpolate
        $this->execPrepared('DELETE FROM narrative_tag_assignments WHERE tag_id = ?', [$tagId]);
        $this->execPrepared('DELETE FROM narrative_tags WHERE id = ? LIMIT 1', [$tagId]);
        return ['deleted' => 1, 'id' => (int) $tagId, 'slug' => (string) ($tag['slug'] ?? '')];
    }

    public function deleteAssignmentsForEntity(string $entityType, int $entityId): void
    {
        $entityType = $this->normalizeEntityType($entityType);
        if ($entityId <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM narrative_tag_assignments WHERE entity_type = ? AND entity_id = ?',
            [$entityType, $entityId],
        );

        if ($entityType === self::ENTITY_NARRATIVE_EVENT) {
            $this->syncLegacyNarrativeEventTagsColumn($entityId);
        }
    }

    private function syncLegacyNarrativeEventTagsColumn(int $eventId): void
    {
        if ($eventId <= 0) {
            return;
        }

        $tags = $this->listAssignments(self::ENTITY_NARRATIVE_EVENT, $eventId, false);
        $slugs = [];
        foreach ($tags as $tag) {
            $slug = trim((string) ($tag['slug'] ?? ''));
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        $legacy = implode(', ', $slugs);
        $this->execPrepared(
            'UPDATE narrative_events SET tags = ? WHERE id = ? LIMIT 1',
            [$legacy !== '' ? $legacy : null, $eventId],
        );
    }

    /**
     * @param int[] $tagIds
     */
    public function syncAssignments(string $entityType, int $entityId, array $tagIds, int $actorCharacterId = 0): array
    {
        $entityType = $this->normalizeEntityType($entityType);
        $this->ensureEntityExists($entityType, $entityId);

        $tagIds = $this->validateTagIds($tagIds);
        if (count($tagIds) > $this->getMaxTagsPerEntity()) {
            throw AppError::validation('Superato il massimo tag per entita', [], 'narrative_tag_limit_exceeded');
        }

        $existingRows = $this->fetchPrepared(
            'SELECT tag_id FROM narrative_tag_assignments WHERE entity_type = ? AND entity_id = ?',
            [$entityType, $entityId],
        );
        $existing = [];
        foreach ($existingRows as $row) {
            $tagId = (int) ($row->tag_id ?? 0);
            if ($tagId > 0) {
                $existing[$tagId] = $tagId;
            }
        }

        $next = [];
        foreach ($tagIds as $tagId) {
            $next[(int) $tagId] = (int) $tagId;
        }

        $toInsert = array_values(array_diff($next, $existing));
        $toDelete = array_values(array_diff($existing, $next));

        foreach ($toDelete as $tagId) {
            $this->execPrepared(
                'DELETE FROM narrative_tag_assignments WHERE tag_id = ? AND entity_type = ? AND entity_id = ?',
                [$tagId, $entityType, $entityId],
            );
        }

        foreach ($toInsert as $tagId) {
            $this->execPrepared(
                'INSERT INTO narrative_tag_assignments (tag_id, entity_type, entity_id, created_by, date_created) VALUES (?, ?, ?, ?, NOW())',
                [$tagId, $entityType, $entityId, $actorCharacterId > 0 ? $actorCharacterId : null],
            );
        }

        if ($entityType === self::ENTITY_NARRATIVE_EVENT) {
            $this->syncLegacyNarrativeEventTagsColumn($entityId);
        }

        return $this->listAssignments($entityType, $entityId, true);
    }

    public function assignTag(string $entityType, int $entityId, int $tagId, int $actorCharacterId = 0): array
    {
        $existing = $this->listAssignments($entityType, $entityId, true);
        $tagIds = [];
        foreach ($existing as $tag) {
            $id = (int) ($tag['id'] ?? 0);
            if ($id > 0) {
                $tagIds[$id] = $id;
            }
        }
        if ($tagId > 0) {
            $tagIds[$tagId] = $tagId;
        }
        return $this->syncAssignments($entityType, $entityId, array_values($tagIds), $actorCharacterId);
    }

    public function unassignTag(string $entityType, int $entityId, int $tagId): array
    {
        $entityType = $this->normalizeEntityType($entityType);
        $this->ensureEntityExists($entityType, $entityId);
        if ($tagId <= 0) {
            return $this->listAssignments($entityType, $entityId, true);
        }

        $this->execPrepared(
            'DELETE FROM narrative_tag_assignments WHERE tag_id = ? AND entity_type = ? AND entity_id = ?',
            [$tagId, $entityType, $entityId],
        );

        if ($entityType === self::ENTITY_NARRATIVE_EVENT) {
            $this->syncLegacyNarrativeEventTagsColumn($entityId);
        }

        return $this->listAssignments($entityType, $entityId, true);
    }

    public function listAssignments(string $entityType, int $entityId, bool $includeInactive = false): array
    {
        $entityType = $this->normalizeEntityType($entityType);
        if ($entityId <= 0) {
            return [];
        }

        $sql = 'SELECT t.id, t.slug, t.label, t.description, t.category, t.is_active
                FROM narrative_tag_assignments a
                INNER JOIN narrative_tags t ON t.id = a.tag_id
                WHERE a.entity_type = ? AND a.entity_id = ?'
             . ($includeInactive ? '' : ' AND t.is_active = 1')
             . ' ORDER BY t.label ASC';

        $rows = $this->fetchPrepared($sql, [$entityType, $entityId]);

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $item['id'] = (int) ($item['id'] ?? 0);
            $item['is_active'] = (int) ($item['is_active'] ?? 0);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param int[] $entityIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    public function listAssignmentsMap(string $entityType, array $entityIds, bool $includeInactive = false): array
    {
        $entityType = $this->normalizeEntityType($entityType);

        $ids = [];
        foreach ($entityIds as $entityId) {
            $id = (int) $entityId;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if (empty($ids)) {
            return [];
        }

        // Entity IDs are sanitized integers — safe to interpolate in IN clause
        $sql = 'SELECT a.entity_id, t.id, t.slug, t.label, t.description, t.category, t.is_active
                FROM narrative_tag_assignments a
                INNER JOIN narrative_tags t ON t.id = a.tag_id
                WHERE a.entity_type = ?
                  AND a.entity_id IN (' . implode(',', $ids) . ')'
             . ($includeInactive ? '' : ' AND t.is_active = 1')
             . ' ORDER BY t.label ASC';

        $rows = $this->fetchPrepared($sql, [$entityType]);

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $entityId = (int) ($item['entity_id'] ?? 0);
            if ($entityId <= 0) {
                continue;
            }
            if (!isset($out[$entityId])) {
                $out[$entityId] = [];
            }
            $out[$entityId][] = [
                'id' => (int) ($item['id'] ?? 0),
                'slug' => (string) ($item['slug'] ?? ''),
                'label' => (string) ($item['label'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'category' => (string) ($item['category'] ?? ''),
                'is_active' => (int) ($item['is_active'] ?? 0),
            ];
        }

        return $out;
    }

    public function attachTagsToRows(
        string $entityType,
        array $rows,
        string $idField = 'id',
        string $targetField = 'narrative_tags',
        bool $includeInactive = false,
    ): array {
        if (empty($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $row : (array) $row;
            $id = (int) ($item[$idField] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $map = $this->listAssignmentsMap($entityType, array_values($ids), $includeInactive);

        $out = [];
        foreach ($rows as $row) {
            $item = is_array($row) ? $row : (array) $row;
            $id = (int) ($item[$idField] ?? 0);
            $tags = $id > 0 && isset($map[$id]) ? $map[$id] : [];
            $item[$targetField] = $tags;
            $item['narrative_tag_ids'] = array_map(static function ($tag) {
                return (int) ($tag['id'] ?? 0);
            }, $tags);
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param int[] $tagIds
     * @return int[]
     */
    public function filterEntityIdsByTagIds(string $entityType, array $tagIds, bool $matchAll = false): array
    {
        $entityType = $this->normalizeEntityType($entityType);
        $tagIds = $this->validateTagIds($tagIds);
        if (empty($tagIds)) {
            return [];
        }

        $having = $matchAll
            ? ('HAVING COUNT(DISTINCT a.tag_id) >= ' . count($tagIds))
            : '';

        // Tag IDs are sanitized integers — safe to interpolate in IN clause
        $rows = $this->fetchPrepared(
            'SELECT a.entity_id
             FROM narrative_tag_assignments a
             INNER JOIN narrative_tags t ON t.id = a.tag_id
             WHERE a.entity_type = ?
               AND a.tag_id IN (' . implode(',', $tagIds) . ')
               AND t.is_active = 1
             GROUP BY a.entity_id
             ' . $having,
            [$entityType],
        );

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->entity_id ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    public function searchEntities(string $entityType, string $query = '', int $limit = 20): array
    {
        $entityType = $this->normalizeEntityType($entityType);
        $limit = max(1, min(50, (int) $limit));
        $needle = trim($query);

        if ($entityType === self::ENTITY_QUEST_DEFINITION) {
            if ($needle !== '') {
                $like = '%' . $needle . '%';
                $rows = $this->fetchPrepared(
                    'SELECT q.id, q.title AS label, q.slug AS secondary
                     FROM quest_definitions q
                     WHERE q.title LIKE ? OR q.slug LIKE ?
                     ORDER BY q.title ASC LIMIT ?',
                    [$like, $like, $limit],
                );
            } else {
                $rows = $this->fetchPrepared(
                    'SELECT q.id, q.title AS label, q.slug AS secondary
                     FROM quest_definitions q
                     ORDER BY q.title ASC LIMIT ?',
                    [$limit],
                );
            }
        } elseif ($entityType === self::ENTITY_NARRATIVE_EVENT) {
            if ($needle !== '') {
                $like = '%' . $needle . '%';
                $rows = $this->fetchPrepared(
                    'SELECT n.id, n.title AS label, n.event_type AS secondary
                     FROM narrative_events n
                     WHERE n.title LIKE ? OR n.event_type LIKE ?
                     ORDER BY n.created_at DESC LIMIT ?',
                    [$like, $like, $limit],
                );
            } else {
                $rows = $this->fetchPrepared(
                    'SELECT n.id, n.title AS label, n.event_type AS secondary
                     FROM narrative_events n
                     ORDER BY n.created_at DESC LIMIT ?',
                    [$limit],
                );
            }
        } elseif ($entityType === self::ENTITY_SYSTEM_EVENT) {
            if ($needle !== '') {
                $like = '%' . $needle . '%';
                $rows = $this->fetchPrepared(
                    'SELECT s.id, s.title AS label, s.status AS secondary
                     FROM system_events s
                     WHERE s.title LIKE ? OR s.type LIKE ? OR s.status LIKE ?
                     ORDER BY s.starts_at DESC, s.id DESC LIMIT ?',
                    [$like, $like, $like, $limit],
                );
            } else {
                $rows = $this->fetchPrepared(
                    'SELECT s.id, s.title AS label, s.status AS secondary
                     FROM system_events s
                     ORDER BY s.starts_at DESC, s.id DESC LIMIT ?',
                    [$limit],
                );
            }
        } elseif ($entityType === self::ENTITY_SCENE) {
            if ($needle !== '') {
                $like = '%' . $needle . '%';
                $rows = $this->fetchPrepared(
                    'SELECT l.id, l.name AS label, m.name AS secondary
                     FROM locations l
                     LEFT JOIN maps m ON m.id = l.map_id
                     WHERE l.date_deleted IS NULL AND (l.name LIKE ? OR l.short_description LIKE ?)
                     ORDER BY l.name ASC LIMIT ?',
                    [$like, $like, $limit],
                );
            } else {
                $rows = $this->fetchPrepared(
                    'SELECT l.id, l.name AS label, m.name AS secondary
                     FROM locations l
                     LEFT JOIN maps m ON m.id = l.map_id
                     WHERE l.date_deleted IS NULL
                     ORDER BY l.name ASC LIMIT ?',
                    [$limit],
                );
            }
        } else {
            return FactionProviderRegistry::search($needle, $limit);
        }

        $out = [];
        foreach ($rows as $row) {
            $item = $this->rowToArray($row);
            $out[] = [
                'id' => (int) ($item['id'] ?? 0),
                'label' => (string) ($item['label'] ?? ''),
                'secondary' => (string) ($item['secondary'] ?? ''),
            ];
        }
        return $out;
    }
}
