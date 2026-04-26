<?php

declare(strict_types=1);

namespace Modules\Logeon\Factions\Services;

use App\Services\NarrativeDomainService;
use App\Services\NarrativeTagService;
use App\Services\NotificationService;
use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class FactionService
{
    /** @var DbAdapterInterface */
    private $db;
    /** @var NotificationService */
    private $notifService;
    /** @var NarrativeDomainService|null */
    private $narrativeDomainService = null;
    /** @var NarrativeTagService|null */
    private $tagService = null;

    private function tagService(): NarrativeTagService
    {
        if ($this->tagService instanceof NarrativeTagService) {
            return $this->tagService;
        }
        $this->tagService = new NarrativeTagService($this->db);
        return $this->tagService;
    }

    private static $validTypes = ['political', 'military', 'religious', 'criminal', 'mercantile', 'other'];
    private static $validScopes = ['local', 'regional', 'global'];
    private static $validRoles = ['member', 'leader', 'advisor', 'agent', 'initiate'];
    private static $validStatuses = ['active', 'inactive', 'expelled'];
    private static $validRelations = ['ally', 'neutral', 'rival', 'enemy', 'vassal', 'overlord'];

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->notifService = new NotificationService($this->db);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    private function decodeRow(array $row): array
    {
        if (isset($row['meta_json']) && is_string($row['meta_json'])) {
            $decoded = json_decode($row['meta_json'], true);
            $row['meta_json'] = is_array($decoded) ? $decoded : (object) [];
        }
        return $row;
    }

    private function normalizeEnum($value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function narrativeDomainService(): NarrativeDomainService
    {
        if ($this->narrativeDomainService instanceof NarrativeDomainService) {
            return $this->narrativeDomainService;
        }

        $this->narrativeDomainService = new NarrativeDomainService($this->db);
        return $this->narrativeDomainService;
    }

    private function factionVisibility(array $faction): string
    {
        return ((int) ($faction['is_public'] ?? 0) === 1 && (int) ($faction['is_active'] ?? 0) === 1)
            ? 'public'
            : 'staff_only';
    }

    private function resolveFactionsNotificationActionUrl(int $factionId): string
    {
        $default = '/game';
        if (!class_exists('\\Core\\Hooks')) {
            return $default;
        }

        $context = [
            'faction_id' => $factionId,
            'source' => 'faction_join_request',
        ];
        $url = \Core\Hooks::filter('faction.notification.action_url', null, $context);

        if (!is_string($url)) {
            return $default;
        }

        $url = trim($url);
        return $url !== '' ? $url : $default;
    }

    private function publishFactionNarrative(array $payload): void
    {
        try {
            $this->narrativeDomainService()->processAction($payload);
        } catch (\Throwable $error) {
            // Non bloccare i flussi fazione se il dominio narrativo non è disponibile.
        }
    }

    private function getCharacterDisplayName(int $characterId): string
    {
        if ($characterId <= 0) {
            return 'Personaggio #' . $characterId;
        }

        $row = $this->firstPrepared(
            'SELECT name, surname
             FROM `characters`
             WHERE `id` = ?
             LIMIT 1',
            [$characterId],
        );
        $name = trim((string) ($row->name ?? ''));
        $surname = trim((string) ($row->surname ?? ''));
        $fullName = trim($name . ' ' . $surname);
        if ($fullName !== '') {
            return $fullName;
        }
        if ($name !== '') {
            return $name;
        }

        return 'Personaggio #' . $characterId;
    }

    // -------------------------------------------------------------------------
    // Public (game-facing)
    // -------------------------------------------------------------------------

    public function list(bool $includeInactive = false, int $limit = 50, int $page = 1): array
    {
        $whereClause = $includeInactive ? '' : ' WHERE `is_active` = 1 AND `is_public` = 1';
        $offset = max(0, ($page - 1) * $limit);

        $total = (int) (($this->firstPrepared('SELECT COUNT(*) AS n FROM `factions`' . $whereClause)->n) ?? 0);
        $rows = $this->fetchPrepared(
            'SELECT * FROM `factions`'
            . $whereClause
            . ' ORDER BY `scope` DESC, `power_level` DESC, `name` ASC LIMIT ? OFFSET ?',
            [$limit, $offset],
        );

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => array_map(function ($r) {
                return $this->decodeRow($this->rowToArray($r));
            }, $rows),
        ];
    }

    public function get(int $factionId, bool $adminView = false): array
    {
        $sql = 'SELECT * FROM `factions` WHERE `id` = ?';
        if (!$adminView) {
            $sql .= ' AND `is_public` = 1 AND `is_active` = 1';
        }
        $sql .= ' LIMIT 1';

        $row = $this->firstPrepared($sql, [$factionId]);
        if (empty($row)) {
            throw AppError::notFound('Fazione non trovata', [], 'faction_not_found');
        }
        $faction = $this->decodeRow($this->rowToArray($row));
        if ($adminView) {
            $faction['narrative_tags'] = $this->tagService()->listAssignments(
                NarrativeTagService::ENTITY_FACTION,
                $factionId,
                false,
            );
            $faction['narrative_tag_ids'] = array_map(static function ($tag): int {
                return (int) (is_array($tag) ? ($tag['id'] ?? 0) : ($tag->id ?? 0));
            }, $faction['narrative_tags']);
        }
        return $faction;
    }

    public function getByCode(string $code, bool $adminView = false): array
    {
        $sql = 'SELECT * FROM `factions` WHERE `code` = ?';
        if (!$adminView) {
            $sql .= ' AND `is_public` = 1 AND `is_active` = 1';
        }
        $sql .= ' LIMIT 1';

        $row = $this->firstPrepared($sql, [$code]);
        if (empty($row)) {
            throw AppError::notFound('Fazione non trovata', [], 'faction_not_found');
        }
        return $this->decodeRow($this->rowToArray($row));
    }

    public function myFactions(int $characterId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT fm.*, f.code AS faction_code, f.name AS faction_name, f.type AS faction_type,
                    f.scope AS faction_scope, f.power_level, f.color_hex, f.icon
             FROM `faction_memberships` fm
             INNER JOIN `factions` f ON f.id = fm.faction_id
             WHERE fm.character_id = ? AND fm.status = \'active\' AND f.is_active = 1
             ORDER BY f.power_level DESC, f.name ASC',
            [$characterId],
        );
        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    // -------------------------------------------------------------------------
    // Admin — faction CRUD
    // -------------------------------------------------------------------------

    public function adminList(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'name|ASC'): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = '`type` = ?';
            $params[] = (string) $filters['type'];
        }
        if (!empty($filters['scope'])) {
            $where[] = '`scope` = ?';
            $params[] = (string) $filters['scope'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(`name` LIKE ? OR `code` LIKE ?)';
            $search = '%' . (string) $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        if (isset($filters['is_active'])) {
            $where[] = '`is_active` = ?';
            $params[] = ((int) $filters['is_active'] === 1 ? 1 : 0);
        }
        if (isset($filters['is_public'])) {
            $where[] = '`is_public` = ?';
            $params[] = ((int) $filters['is_public'] === 1 ? 1 : 0);
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sortParts = explode('|', $sort);
        $sortField = in_array($sortParts[0], ['id', 'code', 'name', 'type', 'scope', 'power_level', 'is_active'], true)
            ? $sortParts[0] : 'name';
        $sortDir = strtoupper($sortParts[1] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $offset = max(0, ($page - 1) * $limit);

        $total = (int) (($this->firstPrepared('SELECT COUNT(*) AS n FROM `factions` ' . $whereClause, $params)->n) ?? 0);
        $rows = $this->fetchPrepared(
            'SELECT * FROM `factions` ' . $whereClause
            . ' ORDER BY `' . $sortField . '` ' . $sortDir
            . ' LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'rows' => array_map(function ($r) {
                return $this->decodeRow($this->rowToArray($r));
            }, $rows),
        ];
    }

    public function adminCreate(object $data): array
    {
        $code = trim((string) ($data->code ?? ''));
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $type = $this->normalizeEnum($data->type ?? 'political', self::$validTypes, 'political');
        $scope = $this->normalizeEnum($data->scope ?? 'regional', self::$validScopes, 'regional');
        $alignment = trim((string) ($data->alignment ?? ''));
        $powerLevel = max(1, min(10, (int) ($data->power_level ?? 5)));
        $isPublic = (int) ($data->is_public ?? 1) === 1 ? 1 : 0;
        $isActive = (int) ($data->is_active ?? 1) === 1 ? 1 : 0;
        $allowJoinRequests = (int) ($data->allow_join_requests ?? 0) === 1 ? 1 : 0;
        $colorHex = trim((string) ($data->color_hex ?? ''));
        $icon = trim((string) ($data->icon ?? ''));
        $actorCharacterId = (int) ($data->actor_character_id ?? 0);

        if ($code === '') {
            throw AppError::validation('Il codice è obbligatorio', [], 'faction_code_required');
        }
        if ($name === '') {
            throw AppError::validation('Il nome è obbligatorio', [], 'faction_name_required');
        }

        $this->execPrepared(
            'INSERT INTO `factions`
            (`code`,`name`,`description`,`type`,`scope`,`alignment`,`power_level`,`is_public`,`is_active`,`allow_join_requests`,`color_hex`,`icon`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $code,
                $name,
                $description !== '' ? $description : null,
                $type,
                $scope,
                $alignment !== '' ? $alignment : null,
                $powerLevel,
                $isPublic,
                $isActive,
                $allowJoinRequests,
                $colorHex !== '' ? $colorHex : null,
                $icon !== '' ? $icon : null,
            ],
        );

        $newId = (int) $this->db->lastInsertId();
        \Core\Hooks::fire('faction.created', $newId, $code);

        if ($newId > 0 && isset($data->tag_ids) && is_array($data->tag_ids)) {
            $this->tagService()->syncAssignments(NarrativeTagService::ENTITY_FACTION, $newId, array_map('intval', $data->tag_ids), $actorCharacterId);
        }

        $faction = $this->get($newId, true);

        AuditLogService::writeEvent('factions.create', ['id' => $newId, 'code' => $code, 'name' => $name], 'admin');

        $this->publishFactionNarrative([
            'source_system' => 'faction',
            'source_ref_id' => $newId,
            'event_type' => 'faction_created',
            'title' => 'Nuova fazione: ' . (string) ($faction['name'] ?? $name),
            'description' => $description !== '' ? $description : 'Creazione di una nuova fazione.',
            'scope' => (string) ($faction['scope'] ?? $scope),
            'visibility' => $this->factionVisibility($faction),
            'entity_refs' => [
                ['entity_type' => 'faction', 'entity_id' => $newId, 'role' => 'subject'],
            ],
            'meta_json' => [
                'type' => (string) ($faction['type'] ?? $type),
                'power_level' => (int) ($faction['power_level'] ?? $powerLevel),
                'is_public' => (int) ($faction['is_public'] ?? $isPublic),
                'is_active' => (int) ($faction['is_active'] ?? $isActive),
            ],
            'actor_character_id' => $actorCharacterId,
        ]);

        return $faction;
    }

    public function adminUpdate(object $data): array
    {
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('ID fazione obbligatorio', [], 'faction_id_required');
        }
        $before = $this->get($id, true); // ensure exists
        $actorCharacterId = (int) ($data->actor_character_id ?? 0);

        $fields = [];
        $params = [];
        if (isset($data->name)) {
            $fields[] = '`name` = ?';
            $params[] = trim((string) $data->name);
        }
        if (isset($data->description)) {
            $fields[] = '`description` = ?';
            $params[] = $data->description !== '' ? trim((string) $data->description) : null;
        }
        if (isset($data->type)) {
            $fields[] = '`type` = ?';
            $params[] = $this->normalizeEnum($data->type, self::$validTypes, 'political');
        }
        if (isset($data->scope)) {
            $fields[] = '`scope` = ?';
            $params[] = $this->normalizeEnum($data->scope, self::$validScopes, 'regional');
        }
        if (isset($data->alignment)) {
            $fields[] = '`alignment` = ?';
            $params[] = $data->alignment !== '' ? trim((string) $data->alignment) : null;
        }
        if (isset($data->power_level)) {
            $fields[] = '`power_level` = ?';
            $params[] = max(1, min(10, (int) $data->power_level));
        }
        if (isset($data->is_public)) {
            $fields[] = '`is_public` = ?';
            $params[] = ((int) $data->is_public === 1 ? 1 : 0);
        }
        if (isset($data->is_active)) {
            $fields[] = '`is_active` = ?';
            $params[] = ((int) $data->is_active === 1 ? 1 : 0);
        }
        if (isset($data->allow_join_requests)) {
            $fields[] = '`allow_join_requests` = ?';
            $params[] = ((int) $data->allow_join_requests === 1 ? 1 : 0);
        }
        if (isset($data->color_hex)) {
            $fields[] = '`color_hex` = ?';
            $params[] = $data->color_hex !== '' ? trim((string) $data->color_hex) : null;
        }
        if (isset($data->icon)) {
            $fields[] = '`icon` = ?';
            $params[] = $data->icon !== '' ? trim((string) $data->icon) : null;
        }

        if (!empty($fields)) {
            $params[] = $id;
            $this->execPrepared('UPDATE `factions` SET ' . implode(', ', $fields) . ' WHERE `id` = ?', $params);
        }

        $after = $this->get($id, true);

        $impactFields = ['scope', 'power_level', 'is_active', 'is_public', 'type', 'alignment', 'name'];
        $changes = [];
        foreach ($impactFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ((string) $beforeValue !== (string) $afterValue) {
                $changes[$field] = [
                    'from' => $beforeValue,
                    'to' => $afterValue,
                ];
            }
        }

        if (!empty($changes)) {
            $this->publishFactionNarrative([
                'source_system' => 'faction',
                'source_ref_id' => $id,
                'event_type' => 'faction_updated',
                'title' => 'Aggiornamento fazione: ' . (string) ($after['name'] ?? ''),
                'description' => 'Aggiornamento dati fazione con impatto narrativo.',
                'scope' => (string) ($after['scope'] ?? 'regional'),
                'visibility' => $this->factionVisibility($after),
                'entity_refs' => [
                    ['entity_type' => 'faction', 'entity_id' => $id, 'role' => 'subject'],
                ],
                'meta_json' => [
                    'changes' => $changes,
                ],
                'actor_character_id' => $actorCharacterId,
            ]);
        }

        if (isset($data->tag_ids) && is_array($data->tag_ids)) {
            $this->tagService()->syncAssignments(NarrativeTagService::ENTITY_FACTION, $id, array_map('intval', $data->tag_ids), $actorCharacterId);
            $after = $this->get($id, true);
        }
        AuditLogService::writeEvent('factions.update', ['id' => $id], 'admin');

        return $after;
    }

    public function adminDelete(int $id, int $actorCharacterId = 0): void
    {
        $faction = $this->get($id, true); // ensure exists

        $inUse = (int) (($this->firstPrepared(
            'SELECT COUNT(*) AS n FROM `faction_memberships` WHERE `faction_id` = ? AND `status` = \'active\'',
            [$id],
        )->n) ?? 0);
        if ($inUse > 0) {
            throw AppError::validation(
                'La fazione ha ' . $inUse . ' membro/i attivo/i. Rimuovili prima di eliminare.',
                [],
                'faction_has_members',
            );
        }

        $this->execPrepared('DELETE FROM `faction_relationships` WHERE `faction_id` = ? OR `target_faction_id` = ?', [$id, $id]);
        $this->execPrepared('DELETE FROM `factions` WHERE `id` = ?', [$id]);
        AuditLogService::writeEvent('factions.delete', ['id' => $id], 'admin');

        $this->publishFactionNarrative([
            'source_system' => 'faction',
            'source_ref_id' => $id,
            'event_type' => 'faction_deleted',
            'title' => 'Fazione rimossa: ' . (string) ($faction['name'] ?? ''),
            'description' => 'Rimozione fazione dal sistema.',
            'scope' => (string) ($faction['scope'] ?? 'regional'),
            'visibility' => 'staff_only',
            'entity_refs' => [
                ['entity_type' => 'faction', 'entity_id' => $id, 'role' => 'subject'],
            ],
            'meta_json' => [
                'code' => (string) ($faction['code'] ?? ''),
                'type' => (string) ($faction['type'] ?? ''),
            ],
            'actor_character_id' => $actorCharacterId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin — membership management
    // -------------------------------------------------------------------------

    public function adminMemberList(int $factionId): array
    {
        $this->get($factionId, true);

        $rows = $this->fetchPrepared(
            'SELECT fm.*, TRIM(CONCAT(COALESCE(c.name, ""), IF(COALESCE(c.surname, "") <> "", CONCAT(" ", c.surname), ""))) AS character_name
             FROM `faction_memberships` fm
             LEFT JOIN `characters` c ON c.id = fm.character_id
             WHERE fm.faction_id = ?
             ORDER BY FIELD(fm.role, "leader","advisor","agent","member","initiate"), fm.character_id ASC',
            [$factionId],
        );
        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    public function adminMemberAdd(int $factionId, int $characterId, string $role = 'member', string $rank = '', int $actorCharacterId = 0): array
    {
        $faction = $this->get($factionId, true);
        $role = $this->normalizeEnum($role, self::$validRoles, 'member');

        $existing = $this->firstPrepared(
            'SELECT `id`, `status`, `role`, `rank` FROM `faction_memberships`
             WHERE `faction_id` = ? AND `character_id` = ? LIMIT 1',
            [$factionId, $characterId],
        );
        $existingStatus = !empty($existing) ? (string) ($existing->status ?? '') : '';
        $existingRole = !empty($existing) ? (string) ($existing->role ?? '') : '';
        $existingRank = !empty($existing) ? (string) ($existing->rank ?? '') : '';

        if (!empty($existing)) {
            $existingId = (int) ($existing->id ?? 0);
            $this->execPrepared(
                'UPDATE `faction_memberships`
                 SET `role` = ?,
                     `rank` = ?,
                     `status` = \'active\',
                     `left_at` = NULL
                 WHERE `id` = ?',
                [$role, $rank !== '' ? $rank : null, $existingId],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO `faction_memberships` (`faction_id`,`character_id`,`role`,`rank`)
                 VALUES (?, ?, ?, ?)',
                [$factionId, $characterId, $role, $rank !== '' ? $rank : null],
            );
        }

        \Core\Hooks::fire('faction.membership.changed', $factionId, $characterId, !empty($existing) ? 'role_changed' : 'joined', $role);

        // Notify the character
        $ownerRow = $this->firstPrepared(
            'SELECT user_id FROM `characters` WHERE `id` = ? LIMIT 1',
            [$characterId],
        );
        $ownerUserId = !empty($ownerRow) ? (int) ($ownerRow->user_id ?? 0) : 0;
        if ($ownerUserId > 0) {
            $factionName = $faction['name'] ?? '';
            $action = !empty($existing) ? 'ruolo aggiornato' : 'aggiunto';
            $this->notifService->mergeOrCreateSystemUpdate(
                $ownerUserId,
                $characterId,
                'faction_membership_' . $factionId . '_' . $characterId,
                'Fazione ' . $factionName . ': ' . $action,
                [
                    'source_type' => 'faction_membership',
                    'source_id' => $factionId,
                    'action_url' => '/game/profile',
                ],
            );
        }

        $characterName = $this->getCharacterDisplayName($characterId);
        $isNewMembership = empty($existing);
        $title = $isNewMembership
            ? ('Nuovo membro in fazione: ' . $characterName)
            : ('Aggiornamento membro di fazione: ' . $characterName);

        $this->publishFactionNarrative([
            'source_system' => 'faction',
            'source_ref_id' => $factionId,
            'event_type' => $isNewMembership ? 'faction_member_added' : 'faction_member_updated',
            'title' => $title,
            'description' => 'Aggiornamento membership in ' . (string) ($faction['name'] ?? 'fazione'),
            'scope' => (string) ($faction['scope'] ?? 'regional'),
            'visibility' => $this->factionVisibility($faction),
            'entity_refs' => [
                ['entity_type' => 'faction', 'entity_id' => $factionId, 'role' => 'subject'],
                ['entity_type' => 'character', 'entity_id' => $characterId, 'role' => 'member'],
            ],
            'meta_json' => [
                'role' => $role,
                'rank' => $rank !== '' ? $rank : null,
                'previous_status' => $existingStatus !== '' ? $existingStatus : null,
                'previous_role' => $existingRole !== '' ? $existingRole : null,
                'previous_rank' => $existingRank !== '' ? $existingRank : null,
            ],
            'actor_character_id' => $actorCharacterId,
        ]);

        return $this->adminMemberList($factionId);
    }

    public function adminMemberUpdate(int $membershipId, object $data, int $actorCharacterId = 0): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM `faction_memberships` WHERE `id` = ? LIMIT 1',
            [$membershipId],
        );
        if (empty($row)) {
            throw AppError::notFound('Membership non trovata', [], 'membership_not_found');
        }
        $membership = $this->rowToArray($row);
        $factionId = (int) ($membership['faction_id'] ?? 0);
        $characterId = (int) ($membership['character_id'] ?? 0);
        $faction = $this->get($factionId, true);

        $fields = [];
        $params = [];
        if (isset($data->role)) {
            $fields[] = '`role` = ?';
            $params[] = $this->normalizeEnum($data->role, self::$validRoles, 'member');
        }
        if (isset($data->rank)) {
            $fields[] = '`rank` = ?';
            $params[] = $data->rank !== '' ? trim((string) $data->rank) : null;
        }
        if (isset($data->status)) {
            $fields[] = '`status` = ?';
            $params[] = $this->normalizeEnum($data->status, self::$validStatuses, 'active');
        }
        if (isset($data->notes)) {
            $fields[] = '`notes` = ?';
            $params[] = $data->notes !== '' ? trim((string) $data->notes) : null;
        }

        if (!empty($fields)) {
            $params[] = $membershipId;
            $this->execPrepared(
                'UPDATE `faction_memberships` SET ' . implode(', ', $fields) . ' WHERE `id` = ?',
                $params,
            );
        }

        \Core\Hooks::fire('faction.membership.changed', (int) $membership['faction_id'], (int) $membership['character_id'], 'role_changed', (string) ($data->role ?? $membership['role']));

        $updatedRow = $this->firstPrepared(
            'SELECT * FROM `faction_memberships` WHERE `id` = ? LIMIT 1',
            [$membershipId],
        );
        $updated = !empty($updatedRow) ? $this->rowToArray($updatedRow) : $membership;

        $changed = [];
        foreach (['role', 'rank', 'status', 'notes'] as $field) {
            $beforeValue = $membership[$field] ?? null;
            $afterValue = $updated[$field] ?? null;
            if ((string) $beforeValue !== (string) $afterValue) {
                $changed[$field] = [
                    'from' => $beforeValue,
                    'to' => $afterValue,
                ];
            }
        }

        if (!empty($changed)) {
            $characterName = $this->getCharacterDisplayName($characterId);
            $this->publishFactionNarrative([
                'source_system' => 'faction',
                'source_ref_id' => $factionId,
                'event_type' => 'faction_member_updated',
                'title' => 'Aggiornamento membro di fazione: ' . $characterName,
                'description' => 'Aggiornata membership in ' . (string) ($faction['name'] ?? 'fazione'),
                'scope' => (string) ($faction['scope'] ?? 'regional'),
                'visibility' => $this->factionVisibility($faction),
                'entity_refs' => [
                    ['entity_type' => 'faction', 'entity_id' => $factionId, 'role' => 'subject'],
                    ['entity_type' => 'character', 'entity_id' => $characterId, 'role' => 'member'],
                ],
                'meta_json' => [
                    'membership_id' => $membershipId,
                    'changes' => $changed,
                ],
                'actor_character_id' => $actorCharacterId,
            ]);
        }

        return $this->adminMemberList($factionId);
    }

    public function adminMemberRemove(int $factionId, int $characterId, int $actorCharacterId = 0): void
    {
        $faction = $this->get($factionId, true);
        $membershipBefore = $this->firstPrepared(
            'SELECT id, role, rank, status
             FROM `faction_memberships`
             WHERE `faction_id` = ? AND `character_id` = ?
             LIMIT 1',
            [$factionId, $characterId],
        );

        $this->execPrepared(
            'UPDATE `faction_memberships` SET `status` = "expelled", `left_at` = NOW()'
            . ' WHERE `faction_id` = ? AND `character_id` = ?',
            [$factionId, $characterId],
        );
        \Core\Hooks::fire('faction.membership.changed', $factionId, $characterId, 'left', '');

        // Notify the character
        $ownerRow = $this->firstPrepared(
            'SELECT user_id FROM `characters` WHERE `id` = ? LIMIT 1',
            [$characterId],
        );
        $ownerUserId = !empty($ownerRow) ? (int) ($ownerRow->user_id ?? 0) : 0;
        if ($ownerUserId > 0) {
            $factionName = $faction['name'] ?? '';
            $this->notifService->create(
                $ownerUserId,
                $characterId,
                NotificationService::KIND_SYSTEM_UPDATE,
                'faction_expelled',
                'Sei stato rimosso dalla fazione: ' . $factionName,
                [
                    'source_type' => 'faction_membership',
                    'source_id' => $factionId,
                    'action_url' => '/game/profile',
                ],
            );
        }

        $characterName = $this->getCharacterDisplayName($characterId);
        $this->publishFactionNarrative([
            'source_system' => 'faction',
            'source_ref_id' => $factionId,
            'event_type' => 'faction_member_removed',
            'title' => 'Membro rimosso da fazione: ' . $characterName,
            'description' => 'Rimozione membership da ' . (string) ($faction['name'] ?? 'fazione'),
            'scope' => (string) ($faction['scope'] ?? 'regional'),
            'visibility' => $this->factionVisibility($faction),
            'entity_refs' => [
                ['entity_type' => 'faction', 'entity_id' => $factionId, 'role' => 'subject'],
                ['entity_type' => 'character', 'entity_id' => $characterId, 'role' => 'member'],
            ],
            'meta_json' => [
                'previous_role' => !empty($membershipBefore) ? (string) ($membershipBefore->role ?? '') : null,
                'previous_rank' => !empty($membershipBefore) ? (string) ($membershipBefore->rank ?? '') : null,
                'previous_status' => !empty($membershipBefore) ? (string) ($membershipBefore->status ?? '') : null,
                'new_status' => 'expelled',
            ],
            'actor_character_id' => $actorCharacterId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Admin — relationship management
    // -------------------------------------------------------------------------

    public function adminRelationList(int $factionId): array
    {
        $this->get($factionId, true);

        $rows = $this->fetchPrepared(
            'SELECT fr.*, f.name AS target_faction_name, f.code AS target_faction_code
             FROM `faction_relationships` fr
             INNER JOIN `factions` f ON f.id = fr.target_faction_id
             WHERE fr.faction_id = ?
             ORDER BY fr.relation_type ASC',
            [$factionId],
        );
        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    public function adminRelationSet(int $factionId, int $targetFactionId, string $relationType, int $intensity = 5, string $notes = '', int $actorCharacterId = 0): array
    {
        $sourceFaction = $this->get($factionId, true);
        $targetFaction = $this->get($targetFactionId, true);

        if ($factionId === $targetFactionId) {
            throw AppError::validation('Una fazione non può avere una relazione con se stessa', [], 'self_relation');
        }

        $relationType = $this->normalizeEnum($relationType, self::$validRelations, 'neutral');
        $intensity = max(1, min(10, $intensity));
        $previous = $this->firstPrepared(
            'SELECT relation_type, intensity
             FROM `faction_relationships`
             WHERE `faction_id` = ? AND `target_faction_id` = ?
             LIMIT 1',
            [$factionId, $targetFactionId],
        );

        $this->execPrepared(
            'INSERT INTO `faction_relationships` (`faction_id`,`target_faction_id`,`relation_type`,`intensity`,`notes`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `relation_type` = VALUES(`relation_type`),
                `intensity` = VALUES(`intensity`),
                `notes` = VALUES(`notes`),
                `date_updated` = NOW()',
            [$factionId, $targetFactionId, $relationType, $intensity, $notes !== '' ? $notes : null],
        );

        \Core\Hooks::fire('faction.relationship.updated', $factionId, $targetFactionId, $relationType);

        $this->publishFactionNarrative([
            'source_system' => 'faction',
            'source_ref_id' => $factionId,
            'event_type' => 'faction_relation_updated',
            'title' => 'Relazione fazione aggiornata',
            'description' => (string) ($sourceFaction['name'] ?? 'Fazione') . ' -> ' . (string) ($targetFaction['name'] ?? 'Fazione target'),
            'scope' => (string) ($sourceFaction['scope'] ?? 'regional'),
            'visibility' => 'staff_only',
            'entity_refs' => [
                ['entity_type' => 'faction', 'entity_id' => $factionId, 'role' => 'source'],
                ['entity_type' => 'faction', 'entity_id' => $targetFactionId, 'role' => 'target'],
            ],
            'meta_json' => [
                'relation_type' => $relationType,
                'intensity' => $intensity,
                'previous_relation_type' => !empty($previous) ? (string) ($previous->relation_type ?? '') : null,
                'previous_intensity' => !empty($previous) ? (int) ($previous->intensity ?? 0) : null,
            ],
            'actor_character_id' => $actorCharacterId,
        ]);

        return $this->adminRelationList($factionId);
    }

    public function adminRelationRemove(int $factionId, int $targetFactionId): void
    {
        $this->execPrepared(
            'DELETE FROM `faction_relationships` WHERE `faction_id` = ? AND `target_faction_id` = ?',
            [$factionId, $targetFactionId],
        );
    }

    // -------------------------------------------------------------------------
    // Game — public member / relation lists
    // -------------------------------------------------------------------------

    public function getFactionMembers(int $factionId): array
    {
        $this->get($factionId); // checks is_public + is_active

        $rows = $this->fetchPrepared(
            'SELECT fm.id, fm.role, fm.rank, fm.joined_at,
                    TRIM(CONCAT(COALESCE(c.name, ""), IF(COALESCE(c.surname, "") <> "", CONCAT(" ", c.surname), ""))) AS character_name
             FROM `faction_memberships` fm
             LEFT JOIN `characters` c ON c.id = fm.character_id
             WHERE fm.faction_id = ? AND fm.status = \'active\'
             ORDER BY FIELD(fm.role, \'leader\',\'advisor\',\'agent\',\'member\',\'initiate\'), fm.joined_at ASC',
            [$factionId],
        );
        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    public function getFactionRelations(int $factionId): array
    {
        $this->get($factionId); // checks is_public + is_active

        $rows = $this->fetchPrepared(
            'SELECT fr.relation_type, fr.intensity, f.id AS target_id, f.name AS target_name,
                    f.code AS target_code, f.color_hex AS target_color_hex
             FROM `faction_relationships` fr
             INNER JOIN `factions` f ON f.id = fr.target_faction_id
             WHERE fr.faction_id = ? AND f.is_public = 1 AND f.is_active = 1
             ORDER BY fr.relation_type ASC',
            [$factionId],
        );
        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    // -------------------------------------------------------------------------
    // Game — player leave
    // -------------------------------------------------------------------------

    public function leaveFaction(int $characterId, int $factionId): void
    {
        $faction = $this->get($factionId);

        $membership = $this->firstPrepared(
            'SELECT id, role FROM `faction_memberships`
             WHERE faction_id = ? AND character_id = ?
               AND status = \'active\'
             LIMIT 1',
            [$factionId, $characterId],
        );

        if (empty($membership)) {
            throw AppError::validation('Non sei membro di questa fazione', [], 'not_a_member');
        }

        $role = (string) ($membership->role ?? '');
        if ($role === 'leader') {
            $otherLeaders = (int) ($this->firstPrepared(
                'SELECT COUNT(*) AS n FROM `faction_memberships`
                 WHERE faction_id = ? AND role = \'leader\' AND status = \'active\'
                 AND character_id != ?',
                [$factionId, $characterId],
            )->n ?? 0);
            if ($otherLeaders === 0) {
                throw AppError::validation('Sei l\'unico leader: nomina un altro leader prima di abbandonare la fazione.', [], 'last_leader');
            }
        }

        $this->execPrepared(
            'UPDATE `faction_memberships` SET status = "inactive", left_at = NOW()'
            . ' WHERE faction_id = ? AND character_id = ?',
            [$factionId, $characterId],
        );

        \Core\Hooks::fire('faction.membership.changed', $factionId, $characterId, 'left', '');

        $this->publishFactionNarrative([
            'source_system' => 'faction',
            'source_ref_id' => $factionId,
            'event_type' => 'faction_member_left',
            'title' => 'Personaggio ha abbandonato la fazione',
            'description' => 'Un membro ha lasciato volontariamente la fazione ' . (string) ($faction['name'] ?? ''),
            'scope' => (string) ($faction['scope'] ?? 'regional'),
            'visibility' => $this->factionVisibility($faction),
            'entity_refs' => [
                ['entity_type' => 'faction', 'entity_id' => $factionId, 'role' => 'subject'],
                ['entity_type' => 'character', 'entity_id' => $characterId, 'role' => 'member'],
            ],
            'meta_json' => ['previous_role' => $role],
            'actor_character_id' => $characterId,
        ]);
    }

    // -------------------------------------------------------------------------
    // Game — join requests (player side)
    // -------------------------------------------------------------------------

    public function sendJoinRequest(int $characterId, int $factionId, string $message = ''): array
    {
        $faction = $this->get($factionId); // checks public+active

        if ((int) ($faction['allow_join_requests'] ?? 0) !== 1) {
            throw AppError::validation('Questa fazione non accetta richieste di adesione', [], 'join_requests_disabled');
        }

        $existing = $this->firstPrepared(
            'SELECT id, status FROM `faction_memberships`
             WHERE faction_id = ? AND character_id = ? LIMIT 1',
            [$factionId, $characterId],
        );
        if (!empty($existing) && (string) ($existing->status ?? '') === 'active') {
            throw AppError::validation('Sei già membro di questa fazione', [], 'already_member');
        }

        $pending = $this->firstPrepared(
            'SELECT id FROM `faction_join_requests`
             WHERE faction_id = ? AND character_id = ?
               AND status = \'pending\'
             LIMIT 1',
            [$factionId, $characterId],
        );
        if (!empty($pending)) {
            throw AppError::validation('Hai già una richiesta in attesa per questa fazione', [], 'request_pending');
        }

        $this->execPrepared(
            'INSERT INTO `faction_join_requests` (faction_id, character_id, message) VALUES (?, ?, ?)',
            [$factionId, $characterId, $message !== '' ? $message : null],
        );
        $requestId = (int) $this->db->lastInsertId();

        return ['id' => $requestId, 'faction_id' => $factionId, 'status' => 'pending'];
    }

    public function withdrawJoinRequest(int $characterId, int $requestId): void
    {
        $row = $this->firstPrepared(
            'SELECT id, character_id, status FROM `faction_join_requests` WHERE id = ? LIMIT 1',
            [$requestId],
        );

        if (empty($row)) {
            throw AppError::notFound('Richiesta non trovata', [], 'request_not_found');
        }
        if ((int) ($row->character_id ?? 0) !== $characterId) {
            throw AppError::unauthorized('Non puoi ritirare questa richiesta', [], 'not_owner');
        }
        if ((string) ($row->status ?? '') !== 'pending') {
            throw AppError::validation('La richiesta non è più in attesa', [], 'request_not_pending');
        }

        $this->execPrepared(
            'UPDATE `faction_join_requests` SET status = "withdrawn" WHERE id = ?',
            [$requestId],
        );
    }

    public function myJoinRequests(int $characterId): array
    {
        $rows = $this->fetchPrepared(
            'SELECT fjr.*, f.name AS faction_name, f.code AS faction_code, f.color_hex
             FROM `faction_join_requests` fjr
             INNER JOIN `factions` f ON f.id = fjr.faction_id
             WHERE fjr.character_id = ?
             ORDER BY fjr.date_created DESC',
            [$characterId],
        );
        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    // -------------------------------------------------------------------------
    // Game — join requests (leader side)
    // -------------------------------------------------------------------------

    private function requireLeaderOrAdvisor(int $characterId, int $factionId): array
    {
        $faction = $this->get($factionId);

        $membership = $this->firstPrepared(
            'SELECT role FROM `faction_memberships`
             WHERE faction_id = ? AND character_id = ?
               AND status = \'active\'
             LIMIT 1',
            [$factionId, $characterId],
        );

        $role = !empty($membership) ? (string) ($membership->role ?? '') : '';
        if (!in_array($role, ['leader', 'advisor'], true)) {
            throw AppError::unauthorized('Solo il leader o un consigliere possono gestire questa fazione', [], 'not_leader');
        }

        return $faction;
    }

    public function leaderListJoinRequests(int $characterId, int $factionId): array
    {
        $this->requireLeaderOrAdvisor($characterId, $factionId);

        $rows = $this->fetchPrepared(
            "SELECT fjr.*, TRIM(CONCAT(COALESCE(c.name,''), IF(COALESCE(c.surname,'') <> '', CONCAT(' ', c.surname), ''))) AS character_name
             FROM `faction_join_requests` fjr
             LEFT JOIN `characters` c ON c.id = fjr.character_id
             WHERE fjr.faction_id = ? AND fjr.status = 'pending'
             ORDER BY fjr.date_created ASC",
            [$factionId],
        );
        return array_map(function ($r) {
            return $this->rowToArray($r);
        }, $rows);
    }

    public function reviewJoinRequest(int $leaderCharacterId, int $requestId, string $decision): array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM `faction_join_requests` WHERE id = ? LIMIT 1',
            [$requestId],
        );

        if (empty($row)) {
            throw AppError::notFound('Richiesta non trovata', [], 'request_not_found');
        }
        if ((string) ($row->status ?? '') !== 'pending') {
            throw AppError::validation('La richiesta non è più in attesa', [], 'request_not_pending');
        }

        $factionId = (int) ($row->faction_id ?? 0);
        $characterId = (int) ($row->character_id ?? 0);

        $this->requireLeaderOrAdvisor($leaderCharacterId, $factionId);

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw AppError::validation('Decisione non valida', [], 'invalid_decision');
        }

        $this->execPrepared(
            'UPDATE `faction_join_requests`
             SET status = ?, reviewed_by_character_id = ?, reviewed_at = NOW()
             WHERE id = ?',
            [$decision, $leaderCharacterId, $requestId],
        );

        if ($decision === 'approved') {
            $this->adminMemberAdd($factionId, $characterId, 'initiate', '', $leaderCharacterId);
        }

        // Notify the requester
        $ownerRow = $this->firstPrepared(
            'SELECT user_id FROM `characters` WHERE id = ? LIMIT 1',
            [$characterId],
        );
        $ownerUserId = !empty($ownerRow) ? (int) ($ownerRow->user_id ?? 0) : 0;
        if ($ownerUserId > 0) {
            $faction = $this->get($factionId);
            $msg = $decision === 'approved'
                ? 'La tua richiesta di adesione a ' . ($faction['name'] ?? 'fazione') . ' è stata approvata!'
                : 'La tua richiesta di adesione a ' . ($faction['name'] ?? 'fazione') . ' è stata rifiutata.';
            $this->notifService->create(
                $ownerUserId,
                $characterId,
                NotificationService::KIND_SYSTEM_UPDATE,
                'faction_join_request_' . $decision,
                $msg,
                [
                    'source_type' => 'faction_membership',
                    'source_id' => $factionId,
                    'action_url' => $this->resolveFactionsNotificationActionUrl($factionId),
                ],
            );
        }

        return ['request_id' => $requestId, 'decision' => $decision, 'faction_id' => $factionId];
    }

    // -------------------------------------------------------------------------
    // Game — leader member management
    // -------------------------------------------------------------------------

    public function leaderInviteMember(int $leaderCharacterId, int $factionId, int $targetCharacterId): array
    {
        $faction = $this->requireLeaderOrAdvisor($leaderCharacterId, $factionId);

        return $this->adminMemberAdd($factionId, $targetCharacterId, 'initiate', '', $leaderCharacterId);
    }

    public function leaderExpelMember(int $leaderCharacterId, int $factionId, int $targetCharacterId): void
    {
        $faction = $this->requireLeaderOrAdvisor($leaderCharacterId, $factionId);

        if ($targetCharacterId === $leaderCharacterId) {
            throw AppError::validation('Non puoi espellere te stesso', [], 'cannot_expel_self');
        }

        $targetMembership = $this->firstPrepared(
            'SELECT role FROM `faction_memberships`
             WHERE faction_id = ? AND character_id = ?
               AND status = \'active\'
             LIMIT 1',
            [$factionId, $targetCharacterId],
        );

        if (empty($targetMembership)) {
            throw AppError::validation('Il personaggio non è membro di questa fazione', [], 'not_a_member');
        }

        $targetRole = (string) ($targetMembership->role ?? '');
        if ($targetRole === 'leader') {
            throw AppError::validation('Non puoi espellere un altro leader', [], 'cannot_expel_leader');
        }

        $this->adminMemberRemove($factionId, $targetCharacterId, $leaderCharacterId);
    }

    // -------------------------------------------------------------------------
    // Game — leader propose relation
    // -------------------------------------------------------------------------

    public function leaderProposeRelation(int $leaderCharacterId, int $factionId, int $targetFactionId, string $relationType, string $notes = ''): array
    {
        $this->requireLeaderOrAdvisor($leaderCharacterId, $factionId);

        // Leader can only propose — creates a pending narrative event; the actual save goes to adminRelationSet
        return $this->adminRelationSet($factionId, $targetFactionId, $relationType, 5, $notes, $leaderCharacterId);
    }
}

