<?php

declare(strict_types=1);

namespace App\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

/**
 * WeatherOverrideService
 *
 * Canonical service for explicit weather override management.
 * Handles all explicit weather overrides:
 *   - Location overrides (with optional expiration and staff note)
 *   - Global overrides (via sys_configs, unchanged)
 *   - World overrides (via sys_configs keyed by world id)
 *   - Climate area weather state management
 */
class WeatherOverrideService
{
    /** @var DbAdapterInterface */
    private $db;

    public function __construct(DbAdapterInterface $db = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
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

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            $row = $this->firstPrepared(
                'SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?
                 LIMIT 1',
                [$table, $column],
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $row = $this->firstPrepared(
                'SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                 LIMIT 1',
                [$table],
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Location overrides
    // -------------------------------------------------------------------------

    public function locationExists(int $locationId): bool
    {
        if ($locationId <= 0) {
            return false;
        }

        $row = $this->firstPrepared(
            'SELECT id FROM locations WHERE id = ? LIMIT 1',
            [$locationId],
        );

        return !empty($row);
    }

    /**
     * Returns the active location override (expired overrides are treated as absent).
     */
    public function getLocationOverride(int $locationId)
    {
        if ($locationId <= 0) {
            return null;
        }

        $hasExpiry = $this->tableHasColumn('location_weather_overrides', 'expires_at');
        $extraCols = $hasExpiry ? ', expires_at, note' : '';

        $row = $this->firstPrepared(
            'SELECT location_id, weather_key, degrees, moon_phase, updated_by, date_updated'
            . $extraCols
            . ' FROM location_weather_overrides'
            . ' WHERE location_id = ? LIMIT 1',
            [$locationId],
        );

        if (empty($row)) {
            return null;
        }

        // Treat expired overrides as absent (only if column exists)
        if ($hasExpiry && !empty($row->expires_at) && strtotime((string) $row->expires_at) < time()) {
            $this->deleteLocationOverride($locationId);
            return null;
        }

        return $row;
    }

    /**
     * Returns the override row regardless of expiry (used by staff to inspect).
     */
    public function getLocationOverrideRaw(int $locationId)
    {
        if ($locationId <= 0) {
            return null;
        }

        // Compatibilita: se le nuove colonne non esistono ancora, usa la select precedente
        $hasExpiry = $this->tableHasColumn('location_weather_overrides', 'expires_at');
        $extraCols = $hasExpiry ? ', expires_at, note' : '';

        return $this->firstPrepared(
            'SELECT location_id, weather_key, degrees, moon_phase, updated_by, date_updated' . $extraCols . '
             FROM location_weather_overrides
             WHERE location_id = ?
             LIMIT 1',
            [$locationId],
        );
    }

    public function deleteLocationOverride(int $locationId): void
    {
        if ($locationId <= 0) {
            return;
        }

        $this->execPrepared('DELETE FROM location_weather_overrides WHERE location_id = ?', [$locationId]);
        AuditLogService::writeEvent('location_weather_overrides.delete', ['location_id' => $locationId], 'admin');
    }

    /**
     * Create or update a location override.
     * Accepts optional expiration and staff note (if migration applied).
     */
    public function upsertLocationOverride(
        int $locationId,
        ?string $weatherKey,
        ?int $degrees,
        ?string $moonPhase,
        int $updatedBy,
        ?string $expiresAt = null,
        ?string $note = null,
    ): void {
        if ($locationId <= 0) {
            return;
        }

        $hasExpiry = $this->tableHasColumn('location_weather_overrides', 'expires_at');

        if ($hasExpiry) {
            $this->execPrepared(
                'INSERT INTO location_weather_overrides
                    (`location_id`,`weather_key`,`degrees`,`moon_phase`,`updated_by`,`expires_at`,`note`,`date_created`,`date_updated`)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    `weather_key` = VALUES(`weather_key`),
                    `degrees`     = VALUES(`degrees`),
                    `moon_phase`  = VALUES(`moon_phase`),
                    `updated_by`  = VALUES(`updated_by`),
                    `expires_at`  = VALUES(`expires_at`),
                    `note`        = VALUES(`note`),
                    `date_updated` = NOW()',
                [$locationId, $weatherKey, $degrees, $moonPhase, $updatedBy, $expiresAt, $note],
            );
        } else {
            // Percorso di compatibilita: migrazione non ancora applicata
            $this->execPrepared(
                'INSERT INTO location_weather_overrides
                    (`location_id`,`weather_key`,`degrees`,`moon_phase`,`updated_by`,`date_created`,`date_updated`)
                 VALUES
                    (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    `weather_key`  = VALUES(`weather_key`),
                    `degrees`      = VALUES(`degrees`),
                    `moon_phase`   = VALUES(`moon_phase`),
                    `updated_by`   = VALUES(`updated_by`),
                    `date_updated` = NOW()',
                [$locationId, $weatherKey, $degrees, $moonPhase, $updatedBy],
            );
        }
        AuditLogService::writeEvent('location_weather_overrides.upsert', ['location_id' => $locationId], 'admin');
    }

    /**
     * Remove all expired location overrides.
     * Suitable for a cron/scheduler call.
     */
    public function clearExpiredOverrides(): int
    {
        if (!$this->tableHasColumn('location_weather_overrides', 'expires_at')) {
            return 0;
        }

        $this->execPrepared('DELETE FROM location_weather_overrides WHERE expires_at IS NOT NULL AND expires_at < NOW()');
        $row = $this->firstPrepared('SELECT ROW_COUNT() AS n');
        return (int) ($row->n ?? 0);
    }

    // -------------------------------------------------------------------------
    // Global overrides (via sys_configs — unchanged from WeatherStaffService)
    // -------------------------------------------------------------------------

    public function getGlobalOverrideRows(): array
    {
        $rows = $this->fetchPrepared(
            "SELECT `key`, `value`
             FROM sys_configs
             WHERE `key` IN ('weather_global_key', 'weather_global_degrees', 'weather_global_moon_phase')",
        );

        return !empty($rows) ? $rows : [];
    }

    public function getWorldOverrideRows(int $worldId): array
    {
        if ($worldId <= 0) {
            return [];
        }

        $prefix = 'weather_world_' . $worldId . '_';
        $rows = $this->fetchPrepared(
            'SELECT `key`, `value`
             FROM sys_configs
             WHERE `key` IN (?, ?, ?)',
            [$prefix . 'key', $prefix . 'degrees', $prefix . 'moon_phase'],
        );

        return !empty($rows) ? $rows : [];
    }

    public function saveGlobalOverride(?string $weatherKey, ?int $degrees, ?string $moonPhase): void
    {
        $weatherValue = ($weatherKey === null) ? '' : $weatherKey;
        $degreesValue = ($degrees === null) ? '' : (string) $degrees;
        $moonValue = ($moonPhase === null) ? '' : $moonPhase;

        $this->execPrepared(
            "INSERT INTO sys_configs (`key`, `value`, `type`) VALUES
            ('weather_global_key', ?, 'string')
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)",
            [$weatherValue],
        );
        $this->execPrepared(
            "INSERT INTO sys_configs (`key`, `value`, `type`) VALUES
            ('weather_global_degrees', ?, 'number')
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)",
            [$degreesValue],
        );
        $this->execPrepared(
            "INSERT INTO sys_configs (`key`, `value`, `type`) VALUES
            ('weather_global_moon_phase', ?, 'string')
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)",
            [$moonValue],
        );
        AuditLogService::writeEvent('weather_overrides.save_global', [], 'admin');
    }

    public function saveWorldOverride(int $worldId, ?string $weatherKey, ?int $degrees, ?string $moonPhase): void
    {
        if ($worldId <= 0) {
            return;
        }

        $prefix = 'weather_world_' . $worldId . '_';
        $weatherValue = ($weatherKey === null) ? '' : $weatherKey;
        $degreesValue = ($degrees === null) ? '' : (string) $degrees;
        $moonValue = ($moonPhase === null) ? '' : $moonPhase;

        $this->execPrepared(
            'INSERT INTO sys_configs (`key`, `value`, `type`) VALUES
            (?, ?, \'string\')
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)',
            [$prefix . 'key', $weatherValue],
        );
        $this->execPrepared(
            'INSERT INTO sys_configs (`key`, `value`, `type`) VALUES
            (?, ?, \'number\')
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)',
            [$prefix . 'degrees', $degreesValue],
        );
        $this->execPrepared(
            'INSERT INTO sys_configs (`key`, `value`, `type`) VALUES
            (?, ?, \'string\')
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)',
            [$prefix . 'moon_phase', $moonValue],
        );
        AuditLogService::writeEvent('weather_overrides.save_world', ['world_id' => $worldId], 'admin');
    }

    public function clearWorldOverride(int $worldId): void
    {
        if ($worldId <= 0) {
            return;
        }

        $prefix = 'weather_world_' . $worldId . '_';
        $this->execPrepared(
            'DELETE FROM sys_configs WHERE `key` IN (?, ?, ?)',
            [$prefix . 'key', $prefix . 'degrees', $prefix . 'moon_phase'],
        );
        AuditLogService::writeEvent('weather_overrides.clear_world', ['world_id' => $worldId], 'admin');
    }

    /**
     * Returns suggested world options for admin UI.
     * Data sources (merged):
     * 1) maps table ids/names (if present)
     * 2) existing weather_world_* keys in sys_configs
     * 3) optional registry key: weather_world_registry_json
     */
    public function listWorldOptions(): array
    {
        $options = [];
        $namesById = [];
        $overrideById = [];

        // Source A: maps table (acts as practical world catalog in current core)
        if ($this->tableExists('maps')) {
            try {
                $rows = $this->fetchPrepared('SELECT id, name FROM maps ORDER BY position ASC, id ASC');
                foreach ($rows as $row) {
                    $id = (int) ($row->id ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $name = trim((string) ($row->name ?? ''));
                    $options[$id] = [
                        'id' => $id,
                        'name' => ($name !== '') ? ('Mappa: ' . $name) : ('Mondo ' . $id),
                        'source' => 'maps',
                        'has_override' => false,
                    ];
                }
            } catch (\Throwable $e) {
                // no-op: keep best effort behavior
            }
        }

        // Source B: sys_configs weather_world_{id}_*
        try {
            $rows = $this->fetchPrepared("SELECT `key`, `value` FROM sys_configs WHERE `key` LIKE 'weather_world_%'");
            foreach ($rows as $row) {
                $key = (string) ($row->key ?? '');
                if (!preg_match('/^weather_world_(\d+)_(key|degrees|moon_phase|name)$/', $key, $m)) {
                    continue;
                }
                $id = (int) $m[1];
                $suffix = $m[2];
                if ($id <= 0) {
                    continue;
                }

                $value = trim((string) ($row->value ?? ''));
                if ($suffix === 'name' && $value !== '') {
                    $namesById[$id] = $value;
                } elseif (in_array($suffix, ['key', 'degrees', 'moon_phase'], true) && $value !== '') {
                    $overrideById[$id] = true;
                }
            }
        } catch (\Throwable $e) {
            // no-op: keep best effort behavior
        }

        // Source C: optional registry json
        try {
            $registryRow = $this->firstPrepared(
                "SELECT `value`
                 FROM sys_configs
                 WHERE `key` = 'weather_world_registry_json'
                 LIMIT 1",
            );
            $registryRaw = trim((string) ($registryRow->value ?? ''));
            if ($registryRaw !== '') {
                $registry = json_decode($registryRaw, true);
                if (is_array($registry)) {
                    foreach ($registry as $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }
                        $id = isset($entry['id']) ? (int) $entry['id'] : 0;
                        if ($id <= 0) {
                            continue;
                        }
                        $name = trim((string) ($entry['name'] ?? ''));
                        if ($name !== '') {
                            $namesById[$id] = $name;
                        }
                        if (!isset($options[$id])) {
                            $options[$id] = [
                                'id' => $id,
                                'name' => ($name !== '') ? $name : ('Mondo ' . $id),
                                'source' => 'registry',
                                'has_override' => false,
                            ];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // no-op: keep best effort behavior
        }

        // Ensure ids discovered only from overrides are included
        foreach ($overrideById as $id => $_flag) {
            if (!isset($options[$id])) {
                $options[$id] = [
                    'id' => (int) $id,
                    'name' => 'Mondo ' . (int) $id,
                    'source' => 'overrides',
                    'has_override' => true,
                ];
            }
        }

        // Apply best available names + override flag
        foreach ($options as $id => &$opt) {
            if (isset($namesById[$id]) && trim((string) $namesById[$id]) !== '') {
                $opt['name'] = trim((string) $namesById[$id]);
            }
            if (isset($overrideById[$id]) && $overrideById[$id] === true) {
                $opt['has_override'] = true;
            }
        }
        unset($opt);

        if (empty($options)) {
            $options[1] = [
                'id' => 1,
                'name' => 'Mondo 1',
                'source' => 'default',
                'has_override' => false,
            ];
        }

        ksort($options, SORT_NUMERIC);
        return array_values($options);
    }

    // -------------------------------------------------------------------------
    // Climate areas
    // -------------------------------------------------------------------------

    public function listClimateAreas($filtersOrActiveOnly = false): array
    {
        if (!$this->tableExists('climate_areas')) {
            return [];
        }

        $filters = [];
        $activeOnly = false;
        if (is_array($filtersOrActiveOnly)) {
            $filters = $filtersOrActiveOnly;
            if (isset($filters['active_only'])) {
                $activeOnly = ((int) $filters['active_only'] === 1);
            }
        } else {
            $activeOnly = (bool) $filtersOrActiveOnly;
        }

        $where = [];
        $params = [];
        if ($activeOnly) {
            $where[] = 'is_active = 1';
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $where[] = 'is_active = ?';
            $params[] = (((int) $filters['is_active'] === 1) ? 1 : 0);
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(`code` LIKE ? OR `name` LIKE ? OR COALESCE(`description`, \'\') LIKE ? OR COALESCE(`weather_key`, \'\') LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));
        $rows = $this->fetchPrepared('SELECT * FROM `climate_areas` ' . $whereSql . ' ORDER BY `name` ASC', $params);
        return !empty($rows) ? $rows : [];
    }

    public function getClimateArea(int $id): ?array
    {
        if ($id <= 0 || !$this->tableExists('climate_areas')) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT * FROM `climate_areas` WHERE `id` = ? LIMIT 1',
            [$id],
        );

        return !empty($row) ? (array) $row : null;
    }

    /**
     * Returns the climate area assigned to a location, or null if not assigned.
     */
    public function getClimateAreaForLocation(int $locationId): ?array
    {
        if ($locationId <= 0) {
            return null;
        }

        if (!$this->tableExists('climate_areas')) {
            return null;
        }

        if (!$this->tableHasColumn('locations', 'climate_area_id')) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT ca.*
             FROM `climate_areas` ca
             INNER JOIN `locations` l ON l.climate_area_id = ca.id
             WHERE l.id = ?
               AND ca.is_active = 1
             LIMIT 1',
            [$locationId],
        );

        return !empty($row) ? (array) $row : null;
    }

    public function createClimateArea(object $data): array
    {
        if (!$this->tableExists('climate_areas')) {
            throw AppError::validation('Tabella climate_areas non disponibile. Allinea il database con database/logeon_db_core.sql.', [], 'climate_areas_table_missing');
        }

        $code = trim((string) ($data->code ?? ''));
        $name = trim((string) ($data->name ?? ''));
        $description = trim((string) ($data->description ?? ''));
        $weatherKey = trim((string) ($data->weather_key ?? '')) ?: null;
        $degrees = isset($data->degrees) && $data->degrees !== '' ? (int) $data->degrees : null;
        $moonPhase = trim((string) ($data->moon_phase ?? '')) ?: null;
        $isActive = (int) ($data->is_active ?? 1) === 1 ? 1 : 0;

        if ($code === '') {
            throw AppError::validation('Codice area clima obbligatorio', [], 'climate_area_code_required');
        }
        if ($name === '') {
            throw AppError::validation('Nome area clima obbligatorio', [], 'climate_area_name_required');
        }

        $this->execPrepared(
            'INSERT INTO `climate_areas` (`code`,`name`,`description`,`weather_key`,`degrees`,`moon_phase`,`is_active`)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$code, $name, ($description !== '' ? $description : null), $weatherKey, $degrees, $moonPhase, $isActive],
        );

        $newId = (int) $this->db->lastInsertId();
        AuditLogService::writeEvent('climate_areas.create', ['id' => $newId, 'code' => $code, 'name' => $name], 'admin');
        return $this->getClimateArea($newId) ?? [];
    }

    public function updateClimateArea(object $data): array
    {
        $id = (int) ($data->id ?? 0);
        if ($id <= 0 || !$this->tableExists('climate_areas')) {
            throw AppError::validation('Area clima non valida', [], 'climate_area_invalid');
        }

        $fields = [];
        $params = [];
        if (isset($data->name)) {
            $fields[] = '`name` = ?';
            $params[] = trim((string) $data->name);
        }
        if (isset($data->description)) {
            $fields[] = '`description` = ?';
            $params[] = ($data->description !== '' ? trim((string) $data->description) : null);
        }
        if (isset($data->weather_key)) {
            $fields[] = '`weather_key` = ?';
            $params[] = (trim((string) $data->weather_key) ?: null);
        }
        if (array_key_exists('degrees', (array) $data)) {
            $deg = ($data->degrees !== null && $data->degrees !== '') ? (int) $data->degrees : null;
            $fields[] = '`degrees` = ?';
            $params[] = $deg;
        }
        if (isset($data->moon_phase)) {
            $fields[] = '`moon_phase` = ?';
            $params[] = (trim((string) $data->moon_phase) ?: null);
        }
        if (isset($data->is_active)) {
            $fields[] = '`is_active` = ?';
            $params[] = ((int) $data->is_active === 1 ? 1 : 0);
        }

        if (!empty($fields)) {
            $params[] = $id;
            $this->execPrepared('UPDATE `climate_areas` SET ' . implode(', ', $fields) . ' WHERE `id` = ?', $params);
            AuditLogService::writeEvent('climate_areas.update', ['id' => $id], 'admin');
        }

        return $this->getClimateArea($id) ?? [];
    }

    public function deleteClimateArea(int $id): void
    {
        if ($id <= 0 || !$this->tableExists('climate_areas')) {
            return;
        }

        // Detach locations before deleting
        if ($this->tableHasColumn('locations', 'climate_area_id')) {
            $this->execPrepared('UPDATE `locations` SET `climate_area_id` = NULL WHERE `climate_area_id` = ?', [$id]);
        }

        $this->execPrepared('DELETE FROM `climate_areas` WHERE `id` = ?', [$id]);
        AuditLogService::writeEvent('climate_areas.delete', ['id' => $id], 'admin');
    }

    /**
     * Assign (or unassign) a location to a climate area.
     * Pass null to remove the assignment.
     */
    public function assignLocationToClimateArea(int $locationId, ?int $climateAreaId): void
    {
        if ($locationId <= 0) {
            return;
        }

        if (!$this->tableHasColumn('locations', 'climate_area_id')) {
            return; // Migration not applied yet — silent no-op
        }

        $this->execPrepared(
            'UPDATE `locations` SET `climate_area_id` = ? WHERE `id` = ?',
            [$climateAreaId, $locationId],
        );
    }
}
