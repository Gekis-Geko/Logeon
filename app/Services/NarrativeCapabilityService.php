<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

/**
 * Servizio di verifica capability narrative.
 *
 * Regola: un personaggio può agire su una capability se:
 *   1. narrative_delegation_enabled = 1 (sys_configs)
 *   2. esiste un grant che copre il suo ruolo di gruppo (gilda/fazione)
 *   3. il max_impact_level del grant <= narrative_delegation_level globale
 *   4. la capability non è staff_only
 *
 * Nota: la verifica staff viene gestita esternamente tramite AuthGuard.
 * Questo servizio copre solo la delega ai leader di gruppo.
 */
class NarrativeCapabilityService
{
    /** @var DbAdapterInterface */
    private $db;

    /** @var int|null cached delegation level */
    private $cachedLevel = null;
    /** @var bool|null cached enabled flag */
    private $cachedEnabled = null;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verifica se un personaggio ha la capability richiesta, per lo scope dato.
     *
     * @param int    $characterId  ID del personaggio che vuole agire
     * @param string $capability   nome capability, e.g. 'narrative.event.create'
     * @param string $scope        scope operativo: 'guild', 'faction', 'local', ecc.
     */
    public function canActor(int $characterId, string $capability, string $scope = 'local'): bool
    {
        if ($characterId <= 0 || $capability === '') {
            return false;
        }
        if (!$this->isDelegationEnabled()) {
            return false;
        }
        $globalLevel = $this->getDelegationLevel();

        return $this->hasMatchingGrant($characterId, $capability, $scope, $globalLevel);
    }

    /**
     * Come canActor ma lancia AppError::forbidden se la verifica fallisce.
     */
    public function requireActor(int $characterId, string $capability, string $scope = 'local'): void
    {
        if (!$this->canActor($characterId, $capability, $scope)) {
            throw AppError::unauthorized(
                'Non hai i permessi narrativi richiesti per questa azione.',
                [],
                'narrative_capability_denied',
            );
        }
    }

    /**
     * Ritorna tutte le capability attive per un personaggio dato lo scope.
     * Utile per popolare UI lato game.
     *
     * @return string[]
     */
    public function listActorCapabilities(int $characterId, string $scope = 'local'): array
    {
        if ($characterId <= 0 || !$this->isDelegationEnabled()) {
            return [];
        }
        $globalLevel = $this->getDelegationLevel();
        $rows = $this->fetchGrantedCapabilities($characterId, $scope, $globalLevel);
        return array_unique(array_column($rows, 'capability'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Grant resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * UNION query: cerca un grant che corrisponda al personaggio
     * tramite ruolo gilda (guild_role) o ruolo fazione (faction_role),
     * limitato al global delegation level e senza capability staff_only.
     */
    private function hasMatchingGrant(
        int $characterId,
        string $capability,
        string $scope,
        int $globalLevel,
    ): bool {
        $sql = $this->buildGrantUnionSql();
        $params = [
            // guild branch
            $characterId, $capability, $scope, $globalLevel,
            // faction branch
            $characterId, $capability, $scope, $globalLevel,
        ];
        $row = $this->db->fetchOnePrepared($sql, $params);
        return !empty($row);
    }

    /**
     * Come hasMatchingGrant ma ritorna tutte le righe (per listActorCapabilities).
     *
     * @return array<int,array<string,mixed>>
     */
    private function fetchGrantedCapabilities(
        int $characterId,
        string $scope,
        int $globalLevel,
    ): array {
        // Query all capabilities at once (no filter on capability name)
        $sql = $this->buildGrantUnionAllCapabilitiesSql();
        $params = [
            $characterId, $scope, $globalLevel,
            $characterId, $scope, $globalLevel,
        ];
        return $this->db->fetchAllPrepared($sql, $params);
    }

    /**
     * Costruisce la UNION SQL per verifica singola capability.
     */
    private function buildGrantUnionSql(): string
    {
        return '
            SELECT 1 AS matched
            FROM `narrative_capability_grants` g
            JOIN `narrative_capabilities` nc
                ON nc.`name` = g.`capability` AND nc.`staff_only` = 0
            JOIN `guild_members` gm
                ON gm.`character_id` = ?
            JOIN `guild_roles` gr
                ON gr.`id` = gm.`role_id`
            WHERE g.`grantee_type` = \'guild_role\'
              AND g.`capability`   = ?
              AND (
                  (g.`grantee_ref` = \'leader\'  AND gr.`is_leader`  = 1) OR
                  (g.`grantee_ref` = \'officer\' AND gr.`is_officer` = 1)
              )
              AND (g.`scope_restriction` IS NULL OR g.`scope_restriction` = ?)
              AND g.`max_impact_level` <= ?

            UNION ALL

            SELECT 1 AS matched
            FROM `narrative_capability_grants` g
            JOIN `narrative_capabilities` nc
                ON nc.`name` = g.`capability` AND nc.`staff_only` = 0
            JOIN `faction_memberships` fm
                ON fm.`character_id` = ? AND fm.`status` = \'active\'
            WHERE g.`grantee_type` = \'faction_role\'
              AND g.`capability`   = ?
              AND g.`grantee_ref`  = fm.`role`
              AND (g.`scope_restriction` IS NULL OR g.`scope_restriction` = ?)
              AND g.`max_impact_level` <= ?

            LIMIT 1
        ';
    }

    /**
     * Costruisce la UNION SQL per listare tutte le capability (senza filtro su name).
     */
    private function buildGrantUnionAllCapabilitiesSql(): string
    {
        return '
            SELECT g.`capability`
            FROM `narrative_capability_grants` g
            JOIN `narrative_capabilities` nc
                ON nc.`name` = g.`capability` AND nc.`staff_only` = 0
            JOIN `guild_members` gm
                ON gm.`character_id` = ?
            JOIN `guild_roles` gr
                ON gr.`id` = gm.`role_id`
            WHERE g.`grantee_type` = \'guild_role\'
              AND (
                  (g.`grantee_ref` = \'leader\'  AND gr.`is_leader`  = 1) OR
                  (g.`grantee_ref` = \'officer\' AND gr.`is_officer` = 1)
              )
              AND (g.`scope_restriction` IS NULL OR g.`scope_restriction` = ?)
              AND g.`max_impact_level` <= ?

            UNION

            SELECT g.`capability`
            FROM `narrative_capability_grants` g
            JOIN `narrative_capabilities` nc
                ON nc.`name` = g.`capability` AND nc.`staff_only` = 0
            JOIN `faction_memberships` fm
                ON fm.`character_id` = ? AND fm.`status` = \'active\'
            WHERE g.`grantee_type` = \'faction_role\'
              AND g.`grantee_ref`  = fm.`role`
              AND (g.`scope_restriction` IS NULL OR g.`scope_restriction` = ?)
              AND g.`max_impact_level` <= ?
        ';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin CRUD — narrative_capabilities
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<int,array<string,mixed>>
     */
    public function adminListCapabilities(): array
    {
        $rows = $this->db->fetchAllPrepared(
            'SELECT `id`, `name`, `label`, `max_impact_allowed`, `staff_only`
             FROM `narrative_capabilities`
             ORDER BY `name` ASC',
            [],
        );
        return array_map([$this, 'rowToArray'], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Admin CRUD — narrative_capability_grants
    // ─────────────────────────────────────────────────────────────────────────

    private static $validGranteeTypes = ['guild_role', 'faction_role', 'user_role'];

    /**
     * @param array<string,mixed> $filters
     * @return array{rows:array<int,mixed>,total:int,page:int,limit:int}
     */
    public function adminListGrants(array $filters = [], int $limit = 25, int $page = 1, string $orderBy = 'id|ASC'): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['grantee_type'])) {
            $where[] = 'g.`grantee_type` = ?';
            $params[] = (string) $filters['grantee_type'];
        }
        if (!empty($filters['capability'])) {
            $where[] = 'g.`capability` = ?';
            $params[] = (string) $filters['capability'];
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $allowedSort = ['id', 'grantee_type', 'grantee_ref', 'capability', 'max_impact_level'];
        $parts = explode('|', $orderBy);
        $sortField = in_array($parts[0], $allowedSort, true) ? $parts[0] : 'id';
        $sortDir = isset($parts[1]) && strtoupper($parts[1]) === 'DESC' ? 'DESC' : 'ASC';

        $limit = max(1, min(100, $limit));
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $countRow = $this->db->fetchOnePrepared(
            'SELECT COUNT(*) AS n
             FROM `narrative_capability_grants` g ' . $whereClause,
            $params,
        );
        $total = (int) ($countRow->n ?? 0);

        $rows = $this->db->fetchAllPrepared(
            'SELECT g.`id`, g.`grantee_type`, g.`grantee_ref`, g.`capability`,
                    COALESCE(nc.`label`, g.`capability`) AS capability_label,
                    g.`max_impact_level`, g.`scope_restriction`, g.`date_created`
             FROM `narrative_capability_grants` g
             LEFT JOIN `narrative_capabilities` nc ON nc.`name` = g.`capability`
             ' . $whereClause . '
             ORDER BY g.`' . $sortField . '` ' . $sortDir . '
             LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset]),
        );

        return [
            'rows' => array_map([$this, 'rowToArray'], $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function adminCreateGrant(array $params): array
    {
        $granteeType = $this->validateGranteeType((string) ($params['grantee_type'] ?? ''));
        $granteeRef = trim((string) ($params['grantee_ref'] ?? ''));
        $capability = trim((string) ($params['capability'] ?? ''));
        $maxImpact = $this->validateImpactLevel((int) ($params['max_impact_level'] ?? 0));
        $scopeRestriction = trim((string) ($params['scope_restriction'] ?? ''));

        if ($granteeRef === '') {
            throw AppError::validation('Il ruolo grantee è obbligatorio', [], 'grantee_ref_required');
        }
        $this->validateCapabilityExists($capability);

        $this->db->executePrepared(
            'INSERT INTO `narrative_capability_grants`
                (`grantee_type`, `grantee_ref`, `capability`, `max_impact_level`, `scope_restriction`)
             VALUES (?, ?, ?, ?, ?)',
            [
                $granteeType,
                $granteeRef,
                $capability,
                $maxImpact,
                $scopeRestriction !== '' ? $scopeRestriction : null,
            ],
        );
        $newId = (int) $this->db->lastInsertId();
        return $this->adminGetGrant($newId);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function adminUpdateGrant(int $id, array $params): array
    {
        $this->adminGetGrant($id); // throws if not found

        $maxImpact = $this->validateImpactLevel((int) ($params['max_impact_level'] ?? 0));
        $scopeRestriction = trim((string) ($params['scope_restriction'] ?? ''));

        $this->db->executePrepared(
            'UPDATE `narrative_capability_grants`
             SET `max_impact_level` = ?, `scope_restriction` = ?
             WHERE `id` = ?',
            [
                $maxImpact,
                $scopeRestriction !== '' ? $scopeRestriction : null,
                $id,
            ],
        );
        return $this->adminGetGrant($id);
    }

    public function adminDeleteGrant(int $id): void
    {
        $this->adminGetGrant($id); // throws if not found
        $this->db->executePrepared(
            'DELETE FROM `narrative_capability_grants` WHERE `id` = ?',
            [$id],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function adminGetGrant(int $id): array
    {
        $row = $this->db->fetchOnePrepared(
            'SELECT g.*, COALESCE(nc.`label`, g.`capability`) AS capability_label
             FROM `narrative_capability_grants` g
             LEFT JOIN `narrative_capabilities` nc ON nc.`name` = g.`capability`
             WHERE g.`id` = ? LIMIT 1',
            [$id],
        );
        if (empty($row)) {
            throw AppError::notFound('Grant non trovato', [], 'grant_not_found');
        }
        return $this->rowToArray($row);
    }

    private function validateImpactLevel(int $level): int
    {
        return in_array($level, [0, 1, 2], true) ? $level : 0;
    }

    private function validateGranteeType(string $type): string
    {
        if (!in_array($type, self::$validGranteeTypes, true)) {
            throw AppError::validation('grantee_type non valido', [], 'invalid_grantee_type');
        }
        return $type;
    }

    private function validateCapabilityExists(string $name): void
    {
        if ($name === '') {
            throw AppError::validation('La capability è obbligatoria', [], 'capability_required');
        }
        $row = $this->db->fetchOnePrepared(
            'SELECT `id` FROM `narrative_capabilities` WHERE `name` = ? LIMIT 1',
            [$name],
        );
        if (empty($row)) {
            throw AppError::validation('Capability non trovata: ' . $name, [], 'capability_not_found');
        }
    }

    /**
     * @param mixed $row
     * @return array<string,mixed>
     */
    private function rowToArray($row): array
    {
        if (is_object($row)) {
            return (array) $row;
        }
        return is_array($row) ? $row : [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Config helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function isDelegationEnabled(): bool
    {
        if ($this->cachedEnabled !== null) {
            return $this->cachedEnabled;
        }
        $row = $this->db->fetchOnePrepared(
            "SELECT `value` FROM `sys_configs` WHERE `key` = 'narrative_delegation_enabled' LIMIT 1",
            [],
        );
        $this->cachedEnabled = !empty($row) && (string) ($row->value ?? '0') === '1';
        return $this->cachedEnabled;
    }

    private function getDelegationLevel(): int
    {
        if ($this->cachedLevel !== null) {
            return $this->cachedLevel;
        }
        $row = $this->db->fetchOnePrepared(
            "SELECT `value` FROM `sys_configs` WHERE `key` = 'narrative_delegation_level' LIMIT 1",
            [],
        );
        $level = (int) ($row->value ?? 0);
        $this->cachedLevel = max(0, min(2, $level));
        return $this->cachedLevel;
    }
}
