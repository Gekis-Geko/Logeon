<?php

declare(strict_types=1);

namespace Modules\Logeon\Weather\Services;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class WeatherClimateService
{
    use WeatherClimateRuntimeTrait;

    /** @var DbAdapterInterface */
    private $db;
    /** @var WeatherGenerationService */
    private $generation;

    public function __construct(DbAdapterInterface $db = null, WeatherGenerationService $generation = null)
    {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->generation = $generation ?: new WeatherGenerationService();
    }

    // ---------------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------------

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

    private function tableExists(string $table): bool
    {
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
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function toBoolFlag($value, int $fallback = 1): int
    {
        if ($value === null || $value === '') {
            return $fallback === 1 ? 1 : 0;
        }
        return ((int) $value === 1) ? 1 : 0;
    }

    private function toNullableText($value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return ($text === '') ? null : $text;
    }

    private function toSlug($value): string
    {
        $slug = strtolower(trim((string) ($value ?? '')));
        if ($slug === '') {
            return '';
        }
        $slug = preg_replace('/[^a-z0-9\-_\s]/', '', $slug);
        $slug = preg_replace('/[\s_]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim((string) $slug, '-');
    }

    private function parseDateTimeOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        if ($ts === false || $ts <= 0) {
            throw AppError::validation('Data non valida', [], 'weather_datetime_invalid');
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function readConfigValue(string $key, ?string $default = null): ?string
    {
        try {
            $row = $this->firstPrepared(
                'SELECT `value`
                 FROM `sys_configs`
                 WHERE `key` = ?
                 LIMIT 1',
                [$key],
            );
            if (!empty($row) && isset($row->value)) {
                return (string) $row->value;
            }
        } catch (\Throwable $e) {
            return $default;
        }
        return $default;
    }

    private function validateScopeType(string $scopeType): string
    {
        $scope = strtolower(trim($scopeType));
        $allowed = ['world', 'map', 'location', 'region', 'area'];
        if (!in_array($scope, $allowed, true)) {
            throw AppError::validation('Ambito non valido', [], 'weather_scope_invalid');
        }
        return $scope;
    }

    private function ensureCoreTables(): void
    {
        $required = [
            'weather_types',
            'seasons',
            'climate_zones',
            'climate_zone_season_profiles',
            'climate_zone_weather_weights',
            'climate_assignments',
            'weather_overrides',
        ];
        foreach ($required as $table) {
            if (!$this->tableExists($table)) {
                throw AppError::validation(
                    'Schema meteo/clima non disponibile. Allinea il database con database/logeon_db_core.sql.',
                    [],
                    'weather_schema_missing',
                );
            }
        }
    }

    public function isAvailable(): bool
    {
        return $this->tableExists('weather_types')
            && $this->tableExists('seasons')
            && $this->tableExists('climate_zones')
            && $this->tableExists('climate_zone_season_profiles')
            && $this->tableExists('climate_zone_weather_weights')
            && $this->tableExists('climate_assignments')
            && $this->tableExists('weather_overrides');
    }

    // ---------------------------------------------------------------------
    // Weather Types
    // ---------------------------------------------------------------------

    public function listWeatherTypes($filtersOrActiveOnly = false): array
    {
        $this->ensureCoreTables();
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
            $where[] = 'wt.is_active = ?';
            $params[] = 1;
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'wt.is_active = ?';
            $params[] = ((int) $filters['is_active'] === 1) ? 1 : 0;
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(wt.name LIKE ? OR wt.slug LIKE ? OR COALESCE(wt.description, \'\') LIKE ? OR COALESCE(wt.visual_group, \'\') LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $rows = $this->fetchPrepared(
            'SELECT wt.*
             FROM weather_types wt
             ' . $whereSql . '
             ORDER BY wt.sort_order ASC, wt.name ASC, wt.id ASC',
            $params,
        );

        return !empty($rows) ? $rows : [];
    }

    public function getWeatherType(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT * FROM weather_types WHERE id = ? LIMIT 1',
            [$id],
        );
        return !empty($row) ? (array) $row : null;
    }

    public function getWeatherTypeBySlug(string $slug): ?array
    {
        $slug = $this->toSlug($slug);
        if ($slug === '') {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT * FROM weather_types WHERE slug = ? LIMIT 1',
            [$slug],
        );
        return !empty($row) ? (array) $row : null;
    }

    public function createWeatherType(object $data): array
    {
        $this->ensureCoreTables();

        $name = trim((string) ($data->name ?? ''));
        $slug = $this->toSlug($data->slug ?? $name);
        if ($name === '' || $slug === '') {
            throw AppError::validation('Nome e slug sono obbligatori', [], 'weather_type_required');
        }

        $exists = $this->firstPrepared(
            'SELECT id FROM weather_types WHERE slug = ? LIMIT 1',
            [$slug],
        );
        if (!empty($exists)) {
            throw AppError::validation('Slug gia in uso', [], 'weather_type_slug_conflict');
        }

        $description = $this->toNullableText($data->description ?? null);
        $sortOrder = isset($data->sort_order) ? (int) $data->sort_order : 0;
        $visualGroup = $this->toNullableText($data->visual_group ?? null);

        $this->execPrepared(
            'INSERT INTO weather_types
                (name, slug, description, sort_order, is_active, visual_group, is_precipitation, is_snow, is_storm, reduces_visibility)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name,
                $slug,
                $description,
                $sortOrder,
                $this->toBoolFlag($data->is_active ?? 1, 1),
                $visualGroup,
                $this->toBoolFlag($data->is_precipitation ?? 0, 0),
                $this->toBoolFlag($data->is_snow ?? 0, 0),
                $this->toBoolFlag($data->is_storm ?? 0, 0),
                $this->toBoolFlag($data->reduces_visibility ?? 0, 0),
            ],
        );

        $id = (int) $this->db->lastInsertId();
        AuditLogService::writeEvent('weather_types.create', ['id' => $id, 'name' => $name], 'admin');
        return $this->getWeatherType($id) ?? [];
    }

    public function updateWeatherType(object $data): array
    {
        $this->ensureCoreTables();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('Tipo meteo non valido', [], 'weather_type_invalid');
        }

        $current = $this->getWeatherType($id);
        if (empty($current)) {
            throw AppError::notFound('Tipo meteo non trovato', [], 'weather_type_not_found');
        }

        $name = isset($data->name) ? trim((string) $data->name) : (string) ($current['name'] ?? '');
        $slug = isset($data->slug)
            ? $this->toSlug($data->slug)
            : $this->toSlug($current['slug'] ?? $name);
        if ($name === '' || $slug === '') {
            throw AppError::validation('Nome e slug sono obbligatori', [], 'weather_type_required');
        }

        $exists = $this->firstPrepared(
            'SELECT id FROM weather_types WHERE slug = ? AND id <> ? LIMIT 1',
            [$slug, $id],
        );
        if (!empty($exists)) {
            throw AppError::validation('Slug gia in uso', [], 'weather_type_slug_conflict');
        }

        $description = $this->toNullableText($data->description ?? ($current['description'] ?? null));
        $sortOrder = isset($data->sort_order) ? (int) $data->sort_order : (int) ($current['sort_order'] ?? 0);
        $visualGroup = $this->toNullableText($data->visual_group ?? ($current['visual_group'] ?? null));

        $this->execPrepared(
            'UPDATE weather_types SET
                name = ?,
                slug = ?,
                description = ?,
                sort_order = ?,
                is_active = ?,
                visual_group = ?,
                is_precipitation = ?,
                is_snow = ?,
                is_storm = ?,
                reduces_visibility = ?
             WHERE id = ?',
            [
                $name,
                $slug,
                $description,
                $sortOrder,
                $this->toBoolFlag($data->is_active ?? ($current['is_active'] ?? 1), 1),
                $visualGroup,
                $this->toBoolFlag($data->is_precipitation ?? ($current['is_precipitation'] ?? 0), 0),
                $this->toBoolFlag($data->is_snow ?? ($current['is_snow'] ?? 0), 0),
                $this->toBoolFlag($data->is_storm ?? ($current['is_storm'] ?? 0), 0),
                $this->toBoolFlag($data->reduces_visibility ?? ($current['reduces_visibility'] ?? 0), 0),
                $id,
            ],
        );
        AuditLogService::writeEvent('weather_types.update', ['id' => $id], 'admin');

        return $this->getWeatherType($id) ?? [];
    }

    public function deleteWeatherType(int $id): void
    {
        $this->ensureCoreTables();
        if ($id <= 0) {
            return;
        }

        $this->execPrepared(
            'UPDATE climate_zone_season_profiles SET default_weather_type_id = NULL WHERE default_weather_type_id = ?',
            [$id],
        );
        $this->execPrepared(
            'DELETE FROM climate_zone_weather_weights WHERE weather_type_id = ?',
            [$id],
        );
        $this->execPrepared(
            'UPDATE weather_overrides SET weather_type_id = NULL WHERE weather_type_id = ?',
            [$id],
        );
        $this->execPrepared(
            'DELETE FROM weather_types WHERE id = ?',
            [$id],
        );
        AuditLogService::writeEvent('weather_types.delete', ['id' => $id], 'admin');
    }

    // ---------------------------------------------------------------------
    // Seasons
    // ---------------------------------------------------------------------

    public function listSeasons($filtersOrActiveOnly = false): array
    {
        $this->ensureCoreTables();
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
            $where[] = 's.is_active = ?';
            $params[] = 1;
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 's.is_active = ?';
            $params[] = ((int) $filters['is_active'] === 1) ? 1 : 0;
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(s.name LIKE ? OR s.slug LIKE ? OR COALESCE(s.description, \'\') LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $rows = $this->fetchPrepared(
            'SELECT s.*
             FROM seasons s
             ' . $whereSql . '
             ORDER BY s.sort_order ASC, s.name ASC, s.id ASC',
            $params,
        );

        return !empty($rows) ? $rows : [];
    }

    public function getSeason(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT * FROM seasons WHERE id = ? LIMIT 1',
            [$id],
        );
        return !empty($row) ? (array) $row : null;
    }

    public function createSeason(object $data): array
    {
        $this->ensureCoreTables();
        $name = trim((string) ($data->name ?? ''));
        $slug = $this->toSlug($data->slug ?? $name);
        if ($name === '' || $slug === '') {
            throw AppError::validation('Nome e slug sono obbligatori', [], 'season_required');
        }

        $exists = $this->firstPrepared(
            'SELECT id FROM seasons WHERE slug = ? LIMIT 1',
            [$slug],
        );
        if (!empty($exists)) {
            throw AppError::validation('Slug gia in uso', [], 'season_slug_conflict');
        }

        $description = $this->toNullableText($data->description ?? null);
        $sortOrder = isset($data->sort_order) ? (int) $data->sort_order : 0;
        $startMonth = isset($data->starts_at_month) && $data->starts_at_month !== '' ? (int) $data->starts_at_month : null;
        $startDay = isset($data->starts_at_day) && $data->starts_at_day !== '' ? (int) $data->starts_at_day : null;
        $endMonth = isset($data->ends_at_month) && $data->ends_at_month !== '' ? (int) $data->ends_at_month : null;
        $endDay = isset($data->ends_at_day) && $data->ends_at_day !== '' ? (int) $data->ends_at_day : null;

        $this->execPrepared(
            'INSERT INTO seasons
                (name, slug, description, sort_order, is_active, starts_at_month, starts_at_day, ends_at_month, ends_at_day)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $name,
                $slug,
                $description,
                $sortOrder,
                $this->toBoolFlag($data->is_active ?? 1, 1),
                $startMonth,
                $startDay,
                $endMonth,
                $endDay,
            ],
        );

        $id = (int) $this->db->lastInsertId();
        AuditLogService::writeEvent('seasons.create', ['id' => $id, 'name' => $name], 'admin');
        return $this->getSeason($id) ?? [];
    }

    public function updateSeason(object $data): array
    {
        $this->ensureCoreTables();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('Stagione non valida', [], 'season_invalid');
        }
        $current = $this->getSeason($id);
        if (empty($current)) {
            throw AppError::notFound('Stagione non trovata', [], 'season_not_found');
        }

        $name = isset($data->name) ? trim((string) $data->name) : (string) ($current['name'] ?? '');
        $slug = isset($data->slug) ? $this->toSlug($data->slug) : $this->toSlug($current['slug'] ?? $name);
        if ($name === '' || $slug === '') {
            throw AppError::validation('Nome e slug sono obbligatori', [], 'season_required');
        }

        $exists = $this->firstPrepared(
            'SELECT id FROM seasons WHERE slug = ? AND id <> ? LIMIT 1',
            [$slug, $id],
        );
        if (!empty($exists)) {
            throw AppError::validation('Slug gia in uso', [], 'season_slug_conflict');
        }

        $description = $this->toNullableText($data->description ?? ($current['description'] ?? null));
        $sortOrder = isset($data->sort_order) ? (int) $data->sort_order : (int) ($current['sort_order'] ?? 0);
        $startMonth = array_key_exists('starts_at_month', (array) $data) ? (($data->starts_at_month !== '' && $data->starts_at_month !== null) ? (int) $data->starts_at_month : null) : (($current['starts_at_month'] ?? null) !== null ? (int) $current['starts_at_month'] : null);
        $startDay = array_key_exists('starts_at_day', (array) $data) ? (($data->starts_at_day !== '' && $data->starts_at_day !== null) ? (int) $data->starts_at_day : null) : (($current['starts_at_day'] ?? null) !== null ? (int) $current['starts_at_day'] : null);
        $endMonth = array_key_exists('ends_at_month', (array) $data) ? (($data->ends_at_month !== '' && $data->ends_at_month !== null) ? (int) $data->ends_at_month : null) : (($current['ends_at_month'] ?? null) !== null ? (int) $current['ends_at_month'] : null);
        $endDay = array_key_exists('ends_at_day', (array) $data) ? (($data->ends_at_day !== '' && $data->ends_at_day !== null) ? (int) $data->ends_at_day : null) : (($current['ends_at_day'] ?? null) !== null ? (int) $current['ends_at_day'] : null);

        $this->execPrepared(
            'UPDATE seasons SET
                name = ?,
                slug = ?,
                description = ?,
                sort_order = ?,
                is_active = ?,
                starts_at_month = ?,
                starts_at_day = ?,
                ends_at_month = ?,
                ends_at_day = ?
             WHERE id = ?',
            [
                $name,
                $slug,
                $description,
                $sortOrder,
                $this->toBoolFlag($data->is_active ?? ($current['is_active'] ?? 1), 1),
                $startMonth,
                $startDay,
                $endMonth,
                $endDay,
                $id,
            ],
        );

        AuditLogService::writeEvent('seasons.update', ['id' => $id], 'admin');
        return $this->getSeason($id) ?? [];
    }

    public function deleteSeason(int $id): void
    {
        $this->ensureCoreTables();
        if ($id <= 0) {
            return;
        }
        $this->execPrepared(
            'DELETE FROM climate_zone_weather_weights
             WHERE profile_id IN (SELECT id FROM climate_zone_season_profiles WHERE season_id = ?)',
            [$id],
        );
        $this->execPrepared(
            'DELETE FROM climate_zone_season_profiles WHERE season_id = ?',
            [$id],
        );
        $this->execPrepared(
            'DELETE FROM seasons WHERE id = ?',
            [$id],
        );
        AuditLogService::writeEvent('seasons.delete', ['id' => $id], 'admin');
    }

    // ---------------------------------------------------------------------
    // Climate Zones
    // ---------------------------------------------------------------------

    public function listClimateZones($filtersOrActiveOnly = false): array
    {
        $this->ensureCoreTables();
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
            $where[] = 'cz.is_active = ?';
            $params[] = 1;
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'cz.is_active = ?';
            $params[] = ((int) $filters['is_active'] === 1) ? 1 : 0;
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(cz.name LIKE ? OR cz.slug LIKE ? OR COALESCE(cz.description, \'\') LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $rows = $this->fetchPrepared(
            'SELECT cz.*
             FROM climate_zones cz
             ' . $whereSql . '
             ORDER BY cz.sort_order ASC, cz.name ASC, cz.id ASC',
            $params,
        );
        return !empty($rows) ? $rows : [];
    }

    public function getClimateZone(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT * FROM climate_zones WHERE id = ? LIMIT 1',
            [$id],
        );
        return !empty($row) ? (array) $row : null;
    }

    public function createClimateZone(object $data): array
    {
        $this->ensureCoreTables();
        $name = trim((string) ($data->name ?? ''));
        $slug = $this->toSlug($data->slug ?? $name);
        if ($name === '' || $slug === '') {
            throw AppError::validation('Nome e slug sono obbligatori', [], 'climate_zone_required');
        }

        $exists = $this->firstPrepared(
            'SELECT id FROM climate_zones WHERE slug = ? LIMIT 1',
            [$slug],
        );
        if (!empty($exists)) {
            throw AppError::validation('Slug gia in uso', [], 'climate_zone_slug_conflict');
        }

        $description = $this->toNullableText($data->description ?? null);
        $sortOrder = isset($data->sort_order) ? (int) $data->sort_order : 0;

        $this->execPrepared(
            'INSERT INTO climate_zones
                (name, slug, description, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?)',
            [$name, $slug, $description, $sortOrder, $this->toBoolFlag($data->is_active ?? 1, 1)],
        );

        $id = (int) $this->db->lastInsertId();
        AuditLogService::writeEvent('climate_zones.create', ['id' => $id, 'name' => $name], 'admin');
        return $this->getClimateZone($id) ?? [];
    }

    public function updateClimateZone(object $data): array
    {
        $this->ensureCoreTables();
        $id = (int) ($data->id ?? 0);
        if ($id <= 0) {
            throw AppError::validation('Zona climatica non valida', [], 'climate_zone_invalid');
        }
        $current = $this->getClimateZone($id);
        if (empty($current)) {
            throw AppError::notFound('Zona climatica non trovata', [], 'climate_zone_not_found');
        }

        $name = isset($data->name) ? trim((string) $data->name) : (string) ($current['name'] ?? '');
        $slug = isset($data->slug) ? $this->toSlug($data->slug) : $this->toSlug($current['slug'] ?? $name);
        if ($name === '' || $slug === '') {
            throw AppError::validation('Nome e slug sono obbligatori', [], 'climate_zone_required');
        }

        $exists = $this->firstPrepared(
            'SELECT id FROM climate_zones WHERE slug = ? AND id <> ? LIMIT 1',
            [$slug, $id],
        );
        if (!empty($exists)) {
            throw AppError::validation('Slug gia in uso', [], 'climate_zone_slug_conflict');
        }

        $description = $this->toNullableText($data->description ?? ($current['description'] ?? null));
        $sortOrder = isset($data->sort_order) ? (int) $data->sort_order : (int) ($current['sort_order'] ?? 0);

        $this->execPrepared(
            'UPDATE climate_zones SET
                name = ?,
                slug = ?,
                description = ?,
                sort_order = ?,
                is_active = ?
             WHERE id = ?',
            [
                $name,
                $slug,
                $description,
                $sortOrder,
                $this->toBoolFlag($data->is_active ?? ($current['is_active'] ?? 1), 1),
                $id,
            ],
        );

        AuditLogService::writeEvent('climate_zones.update', ['id' => $id], 'admin');
        return $this->getClimateZone($id) ?? [];
    }

    public function deleteClimateZone(int $id): void
    {
        $this->ensureCoreTables();
        if ($id <= 0) {
            return;
        }

        $this->execPrepared(
            'DELETE FROM climate_assignments WHERE climate_zone_id = ?',
            [$id],
        );
        $this->execPrepared(
            'DELETE FROM climate_zone_weather_weights
             WHERE profile_id IN (SELECT id FROM climate_zone_season_profiles WHERE climate_zone_id = ?)',
            [$id],
        );
        $this->execPrepared(
            'DELETE FROM climate_zone_season_profiles WHERE climate_zone_id = ?',
            [$id],
        );
        $this->execPrepared(
            'DELETE FROM climate_zones WHERE id = ?',
            [$id],
        );
        AuditLogService::writeEvent('climate_zones.delete', ['id' => $id], 'admin');
    }

    // ---------------------------------------------------------------------
    // Climate profiles + weights
    // ---------------------------------------------------------------------

    public function listSeasonProfiles(array $filters = []): array
    {
        $this->ensureCoreTables();
        $where = [];
        $params = [];

        if (!empty($filters['climate_zone_id'])) {
            $where[] = 'p.climate_zone_id = ?';
            $params[] = (int) $filters['climate_zone_id'];
        }
        if (!empty($filters['season_id'])) {
            $where[] = 'p.season_id = ?';
            $params[] = (int) $filters['season_id'];
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'p.is_active = ?';
            $params[] = ((int) $filters['is_active'] === 1) ? 1 : 0;
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(cz.name LIKE ? OR COALESCE(cz.slug, \'\') LIKE ? OR s.name LIKE ? OR COALESCE(s.slug, \'\') LIKE ? OR COALESCE(wt.name, \'\') LIKE ? OR COALESCE(wt.slug, \'\') LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $rows = $this->fetchPrepared(
            'SELECT p.*,
                    cz.name AS climate_zone_name,
                    cz.slug AS climate_zone_slug,
                    s.name AS season_name,
                    s.slug AS season_slug,
                    wt.name AS default_weather_name,
                    wt.slug AS default_weather_slug
             FROM climate_zone_season_profiles p
             LEFT JOIN climate_zones cz ON cz.id = p.climate_zone_id
             LEFT JOIN seasons s ON s.id = p.season_id
             LEFT JOIN weather_types wt ON wt.id = p.default_weather_type_id
             ' . $whereSql . '
             ORDER BY cz.sort_order ASC, cz.name ASC, s.sort_order ASC, s.name ASC, p.id ASC',
            $params,
        );

        return !empty($rows) ? $rows : [];
    }

    public function getSeasonProfile(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT p.*,
                    cz.name AS climate_zone_name,
                    s.name AS season_name,
                    wt.name AS default_weather_name
             FROM climate_zone_season_profiles p
             LEFT JOIN climate_zones cz ON cz.id = p.climate_zone_id
             LEFT JOIN seasons s ON s.id = p.season_id
             LEFT JOIN weather_types wt ON wt.id = p.default_weather_type_id
             WHERE p.id = ?
             LIMIT 1',
            [$id],
        );
        return !empty($row) ? (array) $row : null;
    }

    public function upsertSeasonProfile(object $data): array
    {
        $this->ensureCoreTables();

        $id = (int) ($data->id ?? 0);
        $zoneId = (int) ($data->climate_zone_id ?? 0);
        $seasonId = (int) ($data->season_id ?? 0);
        if ($zoneId <= 0 || $seasonId <= 0) {
            throw AppError::validation('Zona climatica e stagione sono obbligatorie', [], 'climate_profile_required');
        }

        if (empty($this->getClimateZone($zoneId))) {
            throw AppError::validation('Zona climatica non valida', [], 'climate_zone_invalid');
        }
        if (empty($this->getSeason($seasonId))) {
            throw AppError::validation('Stagione non valida', [], 'season_invalid');
        }

        $temperatureMin = array_key_exists('temperature_min', (array) $data) && $data->temperature_min !== ''
            ? (float) $data->temperature_min
            : null;
        $temperatureMax = array_key_exists('temperature_max', (array) $data) && $data->temperature_max !== ''
            ? (float) $data->temperature_max
            : null;
        if ($temperatureMin !== null && $temperatureMax !== null && $temperatureMin > $temperatureMax) {
            throw AppError::validation('Intervallo temperatura non valido', [], 'weather_temperature_range_invalid');
        }

        $roundMode = strtolower(trim((string) ($data->temperature_round_mode ?? 'round')));
        $allowedRound = ['none', 'floor', 'ceil', 'round'];
        if (!in_array($roundMode, $allowedRound, true)) {
            throw AppError::validation('Modalità arrotondamento non valida', [], 'weather_round_mode_invalid');
        }

        $defaultWeatherTypeId = isset($data->default_weather_type_id) && $data->default_weather_type_id !== ''
            ? (int) $data->default_weather_type_id
            : null;
        if ($defaultWeatherTypeId !== null && empty($this->getWeatherType($defaultWeatherTypeId))) {
            throw AppError::validation('Tipo meteo predefinito non valido', [], 'weather_type_invalid');
        }

        $isActive = $this->toBoolFlag($data->is_active ?? 1, 1);

        if ($id > 0) {
            $existing = $this->getSeasonProfile($id);
            if (empty($existing)) {
                throw AppError::notFound('Profilo stagionale non trovato', [], 'climate_profile_not_found');
            }

            $dup = $this->firstPrepared(
                'SELECT id
                 FROM climate_zone_season_profiles
                 WHERE climate_zone_id = ?
                   AND season_id = ?
                   AND id <> ?
                 LIMIT 1',
                [$zoneId, $seasonId, $id],
            );
            if (!empty($dup)) {
                throw AppError::validation('Profilo per zona+stagione gia esistente', [], 'climate_profile_duplicate');
            }

            $this->execPrepared(
                'UPDATE climate_zone_season_profiles SET
                    climate_zone_id = ?,
                    season_id = ?,
                    temperature_min = ?,
                    temperature_max = ?,
                    temperature_round_mode = ?,
                    default_weather_type_id = ?,
                    is_active = ?
                 WHERE id = ?',
                [$zoneId, $seasonId, $temperatureMin, $temperatureMax, $roundMode, $defaultWeatherTypeId, $isActive, $id],
            );
        } else {
            $dup = $this->firstPrepared(
                'SELECT id
                 FROM climate_zone_season_profiles
                 WHERE climate_zone_id = ?
                   AND season_id = ?
                 LIMIT 1',
                [$zoneId, $seasonId],
            );
            if (!empty($dup)) {
                throw AppError::validation('Profilo per zona+stagione gia esistente', [], 'climate_profile_duplicate');
            }

            $this->execPrepared(
                'INSERT INTO climate_zone_season_profiles
                    (climate_zone_id, season_id, temperature_min, temperature_max, temperature_round_mode, default_weather_type_id, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$zoneId, $seasonId, $temperatureMin, $temperatureMax, $roundMode, $defaultWeatherTypeId, $isActive],
            );
            $id = (int) $this->db->lastInsertId();
        }

        AuditLogService::writeEvent('climate_season_profiles.upsert', ['id' => $id], 'admin');
        return $this->getSeasonProfile($id) ?? [];
    }

    public function deleteSeasonProfile(int $id): void
    {
        $this->ensureCoreTables();
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM climate_zone_weather_weights WHERE profile_id = ?', [$id]);
        $this->execPrepared('DELETE FROM climate_zone_season_profiles WHERE id = ?', [$id]);
        AuditLogService::writeEvent('climate_season_profiles.delete', ['id' => $id], 'admin');
    }

    public function listProfileWeights(int $profileId): array
    {
        $this->ensureCoreTables();
        if ($profileId <= 0) {
            return [];
        }
        $rows = $this->fetchPrepared(
            'SELECT w.*,
                    wt.name AS weather_type_name,
                    wt.slug AS weather_type_slug
             FROM climate_zone_weather_weights w
             LEFT JOIN weather_types wt ON wt.id = w.weather_type_id
             WHERE w.profile_id = ?
             ORDER BY wt.sort_order ASC, wt.name ASC, w.id ASC',
            [$profileId],
        );
        return !empty($rows) ? $rows : [];
    }

    /**
     * @param array<int,array<string,mixed>> $weights
     */
    public function syncProfileWeights(int $profileId, array $weights): array
    {
        $this->ensureCoreTables();
        if ($profileId <= 0) {
            throw AppError::validation('Profilo non valido', [], 'climate_profile_invalid');
        }
        if (empty($this->getSeasonProfile($profileId))) {
            throw AppError::notFound('Profilo stagionale non trovato', [], 'climate_profile_not_found');
        }

        $seen = [];
        $normalized = [];
        foreach ($weights as $row) {
            $weatherTypeId = isset($row['weather_type_id']) ? (int) $row['weather_type_id'] : 0;
            if ($weatherTypeId <= 0) {
                continue;
            }
            if (isset($seen[$weatherTypeId])) {
                throw AppError::validation('Tipo meteo duplicato nei pesi', [], 'weather_weight_duplicate');
            }
            if (empty($this->getWeatherType($weatherTypeId))) {
                throw AppError::validation('Tipo meteo non valido', [], 'weather_type_invalid');
            }
            $weight = isset($row['weight']) ? (float) $row['weight'] : 0.0;
            if ($weight < 0) {
                throw AppError::validation('Peso non valido', [], 'weather_weight_invalid');
            }
            $isActive = $this->toBoolFlag($row['is_active'] ?? 1, 1);
            $normalized[] = [
                'weather_type_id' => $weatherTypeId,
                'weight' => $weight,
                'is_active' => $isActive,
            ];
            $seen[$weatherTypeId] = true;
        }

        $this->db->query('START TRANSACTION');
        try {
            $this->execPrepared('DELETE FROM climate_zone_weather_weights WHERE profile_id = ?', [$profileId]);
            foreach ($normalized as $entry) {
                $this->execPrepared(
                    'INSERT INTO climate_zone_weather_weights
                        (profile_id, weather_type_id, weight, is_active)
                     VALUES (?, ?, ?, ?)',
                    [$profileId, (int) $entry['weather_type_id'], (float) $entry['weight'], (int) $entry['is_active']],
                );
            }
            $this->db->query('COMMIT');
        } catch (\Throwable $e) {
            $this->db->query('ROLLBACK');
            throw $e;
        }

        return $this->listProfileWeights($profileId);
    }

    // ---------------------------------------------------------------------
    // Climate assignments
    // ---------------------------------------------------------------------

    public function listAssignments(array $filters = []): array
    {
        $this->ensureCoreTables();
        $where = [];
        $params = [];

        if (!empty($filters['scope_type'])) {
            $scopeType = $this->validateScopeType((string) $filters['scope_type']);
            $where[] = 'ca.scope_type = ?';
            $params[] = $scopeType;
        }
        if (!empty($filters['scope_id'])) {
            $where[] = 'ca.scope_id = ?';
            $params[] = (int) $filters['scope_id'];
        }
        if (!empty($filters['climate_zone_id'])) {
            $where[] = 'ca.climate_zone_id = ?';
            $params[] = (int) $filters['climate_zone_id'];
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'ca.is_active = ?';
            $params[] = ((int) $filters['is_active'] === 1) ? 1 : 0;
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(ca.scope_type LIKE ? OR CAST(ca.scope_id AS CHAR) LIKE ? OR COALESCE(cz.name, \'\') LIKE ? OR COALESCE(cz.slug, \'\') LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $rows = $this->fetchPrepared(
            'SELECT ca.*,
                    cz.name AS climate_zone_name,
                    cz.slug AS climate_zone_slug
             FROM climate_assignments ca
             LEFT JOIN climate_zones cz ON cz.id = ca.climate_zone_id
             ' . $whereSql . '
             ORDER BY ca.scope_type ASC, ca.scope_id ASC, ca.priority DESC, ca.id DESC',
            $params,
        );

        return !empty($rows) ? $rows : [];
    }

    public function getAssignment(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT * FROM climate_assignments WHERE id = ? LIMIT 1',
            [$id],
        );
        return !empty($row) ? (array) $row : null;
    }

    public function upsertAssignment(object $data): array
    {
        $this->ensureCoreTables();
        $id = (int) ($data->id ?? 0);

        $scopeType = $this->validateScopeType((string) ($data->scope_type ?? ''));
        $scopeId = (int) ($data->scope_id ?? 0);
        if ($scopeId <= 0) {
            throw AppError::validation('Riferimento non valido', [], 'weather_scope_id_invalid');
        }

        $climateZoneId = (int) ($data->climate_zone_id ?? 0);
        if ($climateZoneId <= 0 || empty($this->getClimateZone($climateZoneId))) {
            throw AppError::validation('Zona climatica non valida', [], 'climate_zone_invalid');
        }

        $priority = isset($data->priority) ? (int) $data->priority : 0;
        $isActive = $this->toBoolFlag($data->is_active ?? 1, 1);

        if ($id > 0) {
            if (empty($this->getAssignment($id))) {
                throw AppError::notFound('Assegnazione non trovata', [], 'climate_assignment_not_found');
            }
            $this->execPrepared(
                'UPDATE climate_assignments SET
                    scope_type = ?,
                    scope_id = ?,
                    climate_zone_id = ?,
                    priority = ?,
                    is_active = ?
                 WHERE id = ?',
                [$scopeType, $scopeId, $climateZoneId, $priority, $isActive, $id],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO climate_assignments
                    (scope_type, scope_id, climate_zone_id, priority, is_active)
                 VALUES (?, ?, ?, ?, ?)',
                [$scopeType, $scopeId, $climateZoneId, $priority, $isActive],
            );
            $id = (int) $this->db->lastInsertId();
        }

        AuditLogService::writeEvent('climate_assignments.upsert', ['id' => $id], 'admin');
        return $this->getAssignment($id) ?? [];
    }

    public function deleteAssignment(int $id): void
    {
        $this->ensureCoreTables();
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM climate_assignments WHERE id = ?', [$id]);
        AuditLogService::writeEvent('climate_assignments.delete', ['id' => $id], 'admin');
    }

    // ---------------------------------------------------------------------
    // Weather overrides (new domain table)
    // ---------------------------------------------------------------------

    public function listOverrides(array $filters = []): array
    {
        $this->ensureCoreTables();
        $where = [];
        $params = [];
        if (!empty($filters['scope_type'])) {
            $scopeType = $this->validateScopeType((string) $filters['scope_type']);
            $where[] = 'o.scope_type = ?';
            $params[] = $scopeType;
        }
        if (!empty($filters['scope_id'])) {
            $where[] = 'o.scope_id = ?';
            $params[] = (int) $filters['scope_id'];
        }
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $where[] = 'o.is_active = ?';
            $params[] = ((int) $filters['is_active'] === 1) ? 1 : 0;
        }
        $query = trim((string) ($filters['query'] ?? ''));
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(o.scope_type LIKE ? OR CAST(o.scope_id AS CHAR) LIKE ? OR COALESCE(wt.name, \'\') LIKE ? OR COALESCE(wt.slug, \'\') LIKE ? OR COALESCE(o.reason, \'\') LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

        $rows = $this->fetchPrepared(
            'SELECT o.*,
                    wt.name AS weather_type_name,
                    wt.slug AS weather_type_slug
             FROM weather_overrides o
             LEFT JOIN weather_types wt ON wt.id = o.weather_type_id
             ' . $whereSql . '
             ORDER BY o.is_active DESC, o.scope_type ASC, o.scope_id ASC, o.id DESC',
            $params,
        );

        return !empty($rows) ? $rows : [];
    }

    public function getOverride(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT o.*,
                    wt.name AS weather_type_name,
                    wt.slug AS weather_type_slug
             FROM weather_overrides o
             LEFT JOIN weather_types wt ON wt.id = o.weather_type_id
             WHERE o.id = ?
             LIMIT 1',
            [$id],
        );
        return !empty($row) ? (array) $row : null;
    }

    public function upsertOverride(object $data, ?int $actorUserId = null): array
    {
        $this->ensureCoreTables();
        $id = (int) ($data->id ?? 0);

        $scopeType = $this->validateScopeType((string) ($data->scope_type ?? ''));
        $scopeId = (int) ($data->scope_id ?? 0);
        if ($scopeId <= 0) {
            throw AppError::validation('Riferimento non valido', [], 'weather_scope_id_invalid');
        }

        $weatherTypeId = isset($data->weather_type_id) && $data->weather_type_id !== ''
            ? (int) $data->weather_type_id
            : null;
        if ($weatherTypeId !== null && empty($this->getWeatherType($weatherTypeId))) {
            throw AppError::validation('Tipo meteo non valido', [], 'weather_type_invalid');
        }

        $temperatureOverride = array_key_exists('temperature_override', (array) $data)
            && $data->temperature_override !== ''
            && $data->temperature_override !== null
            ? (float) $data->temperature_override
            : null;

        if ($temperatureOverride !== null && ($temperatureOverride < -80 || $temperatureOverride > 80)) {
            throw AppError::validation('Temperatura forzata fuori limite', [], 'weather_temperature_out_of_range');
        }

        if ($weatherTypeId === null && $temperatureOverride === null) {
            throw AppError::validation('Forzatura vuota non consentita', [], 'weather_override_empty');
        }

        $startsAt = $this->parseDateTimeOrNull($data->starts_at ?? null);
        $expiresAt = $this->parseDateTimeOrNull($data->expires_at ?? null);
        if ($startsAt !== null && $expiresAt !== null && strtotime($expiresAt) < strtotime($startsAt)) {
            throw AppError::validation('Intervallo forzatura non valido', [], 'weather_override_interval_invalid');
        }

        $reason = $this->toNullableText($data->reason ?? null);
        $isActive = $this->toBoolFlag($data->is_active ?? 1, 1);
        $createdBy = $actorUserId !== null ? (int) $actorUserId : null;

        if ($id > 0) {
            if (empty($this->getOverride($id))) {
                throw AppError::notFound('Forzatura non trovata', [], 'weather_override_not_found');
            }

            $this->execPrepared(
                'UPDATE weather_overrides SET
                    scope_type = ?,
                    scope_id = ?,
                    weather_type_id = ?,
                    temperature_override = ?,
                    reason = ?,
                    starts_at = ?,
                    expires_at = ?,
                    is_active = ?
                 WHERE id = ?',
                [$scopeType, $scopeId, $weatherTypeId, $temperatureOverride, $reason, $startsAt, $expiresAt, $isActive, $id],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO weather_overrides
                    (scope_type, scope_id, weather_type_id, temperature_override, reason, created_by, starts_at, expires_at, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$scopeType, $scopeId, $weatherTypeId, $temperatureOverride, $reason, $createdBy, $startsAt, $expiresAt, $isActive],
            );
            $id = (int) $this->db->lastInsertId();
        }

        AuditLogService::writeEvent('weather_overrides.upsert', ['id' => $id], 'admin');
        return $this->getOverride($id) ?? [];
    }

    public function deleteOverride(int $id): void
    {
        $this->ensureCoreTables();
        if ($id <= 0) {
            return;
        }
        $this->execPrepared('DELETE FROM weather_overrides WHERE id = ?', [$id]);
        AuditLogService::writeEvent('weather_overrides.delete', ['id' => $id], 'admin');
    }
}
