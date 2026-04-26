<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class NarrativeStateService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
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

    private function failValidation(string $message, string $errorCode = 'validation_error'): void
    {
        throw AppError::validation($message, [], $errorCode);
    }

    private function normalizeText($value): string
    {
        return trim((string) $value);
    }

    private function normalizeNullableText($value): ?string
    {
        $value = trim((string) $value);
        return ($value === '') ? null : $value;
    }

    private function normalizeCode($value): string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9_]+/i', '_', $value);
        $value = trim((string) $value, '_');
        return $value;
    }

    private function normalizeInt($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    private function normalizeBool($value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default ? 1 : 0;
        }

        $raw = strtolower(trim((string) $value));
        if (in_array($raw, ['1', 'true', 'yes', 'si', 'on'], true)) {
            return 1;
        }
        if (in_array($raw, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }

        return ((int) $value > 0) ? 1 : 0;
    }

    private function normalizeEnum($value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '' || !in_array($value, $allowed, true)) {
            return $fallback;
        }
        return $value;
    }

    private function hasTable(string $table): bool
    {
        $table = trim((string) $table);
        if ($table === '') {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT COUNT(*) AS c
             FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name = ?
             LIMIT 1',
            [$table],
        );

        return !empty($row) && (int) ($row->c ?? 0) > 0;
    }

    public function findByIdOrCode(int $stateId = 0, string $stateCode = '', bool $activeOnly = true)
    {
        $stateCode = trim((string) $stateCode);
        if ($stateId <= 0 && $stateCode === '') {
            return null;
        }

        $where = [];
        $params = [];
        if ($stateId > 0) {
            $where[] = 'ns.id = ?';
            $params[] = $stateId;
        }
        if ($stateCode !== '') {
            $where[] = 'ns.code = ?';
            $params[] = $stateCode;
        }

        if (empty($where)) {
            return null;
        }

        $sql = 'SELECT
                    ns.id,
                    ns.code,
                    ns.name,
                    ns.description,
                    ns.category,
                    ns.scope,
                    ns.stack_mode,
                    ns.max_stacks,
                    ns.conflict_group,
                    ns.priority,
                    ns.is_active,
                    ns.visible_to_players,
                    ns.metadata_json,
                    ns.date_created,
                    ns.date_updated
                FROM narrative_states ns
                WHERE (' . implode(' OR ', $where) . ')';

        if ($activeOnly) {
            $sql .= ' AND ns.is_active = 1';
        }

        $sql .= ' ORDER BY ns.id ASC LIMIT 1';

        return $this->firstPrepared($sql, $params);
    }

    public function getByIdOrCode(int $stateId = 0, string $stateCode = '', bool $activeOnly = true)
    {
        $row = $this->findByIdOrCode($stateId, $stateCode, $activeOnly);
        if (empty($row)) {
            $this->failValidation('Stato narrativo non trovato', 'state_not_found');
        }
        return $row;
    }

    public function catalog(bool $includeHidden = false): array
    {
        $sql = 'SELECT
                    id,
                    code,
                    name,
                    description,
                    category,
                    scope,
                    stack_mode,
                    max_stacks,
                    conflict_group,
                    priority,
                    is_active,
                    visible_to_players,
                    metadata_json
                FROM narrative_states
                WHERE is_active = 1';

        if (!$includeHidden) {
            $sql .= ' AND visible_to_players = 1';
        }

        $sql .= ' ORDER BY priority DESC, name ASC, id ASC';

        $rows = $this->fetchPrepared($sql);
        return $rows ?: [];
    }

    public function adminList(bool $includeInactive = true, array $filters = []): array
    {
        $sql = 'SELECT
                    id,
                    code,
                    name,
                    description,
                    category,
                    scope,
                    stack_mode,
                    max_stacks,
                    conflict_group,
                    priority,
                    is_active,
                    visible_to_players,
                    metadata_json,
                    date_created,
                    date_updated
                FROM narrative_states';

        $where = [];
        $params = [];
        if (!$includeInactive) {
            $where[] = 'is_active = 1';
        }

        $search = $this->normalizeText($filters['search'] ?? '');
        if ($search !== '') {
            $where[] = '(code LIKE ? OR name LIKE ? OR description LIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        $category = $this->normalizeText($filters['category'] ?? '');
        if ($category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }

        $scope = $this->normalizeEnum($filters['scope'] ?? '', ['character', 'scene', 'both'], '');
        if ($scope !== '') {
            $where[] = 'scope = ?';
            $params[] = $scope;
        }

        $stackMode = $this->normalizeEnum($filters['stack_mode'] ?? '', ['replace', 'stack', 'refresh'], '');
        if ($stackMode !== '') {
            $where[] = 'stack_mode = ?';
            $params[] = $stackMode;
        }

        $visibleFilter = $filters['visible_to_players'] ?? '';
        if ($visibleFilter !== '') {
            $where[] = 'visible_to_players = ?';
            $params[] = $this->normalizeBool($visibleFilter, 0);
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY priority DESC, name ASC, id ASC';

        $rows = $this->fetchPrepared($sql, $params);
        return $rows ?: [];
    }

    public function adminCreate(object $data): void
    {
        $code = $this->normalizeCode($data->code ?? '');
        $name = $this->normalizeText($data->name ?? '');
        if ($code === '' || $name === '') {
            $this->failValidation('Codice e nome sono obbligatori', 'state_required_fields');
        }

        $scope = $this->normalizeEnum($data->scope ?? 'character', ['character', 'scene', 'both'], 'character');
        $stackMode = $this->normalizeEnum($data->stack_mode ?? 'replace', ['replace', 'stack', 'refresh'], 'replace');
        $maxStacks = $this->normalizeInt($data->max_stacks ?? 1, 1);
        if ($maxStacks < 1) {
            $maxStacks = 1;
        }

        $priority = $this->normalizeInt($data->priority ?? 0, 0);

        $this->execPrepared(
            'INSERT INTO narrative_states
            (code, name, description, category, scope, stack_mode, max_stacks, conflict_group, priority, is_active, visible_to_players, date_created)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $code,
                $name,
                $this->normalizeNullableText($data->description ?? null),
                $this->normalizeNullableText($data->category ?? null),
                $scope,
                $stackMode,
                $maxStacks,
                $this->normalizeNullableText($data->conflict_group ?? null),
                $priority,
                $this->normalizeBool($data->is_active ?? 1, 1),
                $this->normalizeBool($data->visible_to_players ?? 1, 1),
            ],
        );
        AuditLogService::writeEvent('narrative_states.create', ['code' => $code, 'name' => $name], 'admin');
    }

    public function adminUpdate(object $data): void
    {
        $id = $this->normalizeInt($data->id ?? 0, 0);
        if ($id <= 0) {
            $this->failValidation('Stato narrativo non valido', 'state_not_found');
        }

        $code = $this->normalizeCode($data->code ?? '');
        $name = $this->normalizeText($data->name ?? '');
        if ($code === '' || $name === '') {
            $this->failValidation('Codice e nome sono obbligatori', 'state_required_fields');
        }

        $scope = $this->normalizeEnum($data->scope ?? 'character', ['character', 'scene', 'both'], 'character');
        $stackMode = $this->normalizeEnum($data->stack_mode ?? 'replace', ['replace', 'stack', 'refresh'], 'replace');
        $maxStacks = $this->normalizeInt($data->max_stacks ?? 1, 1);
        if ($maxStacks < 1) {
            $maxStacks = 1;
        }

        $priority = $this->normalizeInt($data->priority ?? 0, 0);

        $this->execPrepared(
            'UPDATE narrative_states SET
                code = ?,
                name = ?,
                description = ?,
                category = ?,
                scope = ?,
                stack_mode = ?,
                max_stacks = ?,
                conflict_group = ?,
                priority = ?,
                is_active = ?,
                visible_to_players = ?,
                date_updated = NOW()
             WHERE id = ?
             LIMIT 1',
            [
                $code,
                $name,
                $this->normalizeNullableText($data->description ?? null),
                $this->normalizeNullableText($data->category ?? null),
                $scope,
                $stackMode,
                $maxStacks,
                $this->normalizeNullableText($data->conflict_group ?? null),
                $priority,
                $this->normalizeBool($data->is_active ?? 1, 1),
                $this->normalizeBool($data->visible_to_players ?? 1, 1),
                $id,
            ],
        );
        AuditLogService::writeEvent('narrative_states.update', ['id' => $id], 'admin');
    }

    public function adminDelete(int $id): void
    {
        if ($id <= 0) {
            $this->failValidation('Stato narrativo non valido', 'state_not_found');
        }

        $exists = $this->firstPrepared(
            'SELECT id
             FROM narrative_states
             WHERE id = ?
             LIMIT 1',
            [$id],
        );
        if (empty($exists)) {
            $this->failValidation('Stato narrativo non trovato', 'state_not_found');
        }

        $this->execPrepared(
            'UPDATE applied_narrative_states SET
                status = "removed",
                removed_at = NOW(),
                removal_reason = "state_deleted",
                date_updated = NOW()
             WHERE state_id = ?
               AND status = "active"',
            [$id],
        );

        if ($this->hasTable('abilities')) {
            $this->execPrepared(
                'UPDATE abilities SET
                    applies_state_id = NULL,
                    date_updated = NOW()
                 WHERE applies_state_id = ?',
                [$id],
            );
        }

        $this->execPrepared(
            'DELETE FROM narrative_states
             WHERE id = ?
             LIMIT 1',
            [$id],
        );
        AuditLogService::writeEvent('narrative_states.delete', ['id' => $id], 'admin');
    }
}
