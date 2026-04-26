<?php

declare(strict_types=1);

namespace App\Services\Weather;

use Core\AuditLogService;
use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class WeatherOverrideService
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

    /**
     * @return array<int,object>
     */
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

    public function locationExists(int $locationId): bool
    {
        if ($locationId <= 0) {
            return false;
        }

        $row = $this->firstPrepared('SELECT id FROM locations WHERE id = ? LIMIT 1', [$locationId]);
        return !empty($row);
    }

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
            . ' FROM location_weather_overrides
               WHERE location_id = ?
               LIMIT 1',
            [$locationId],
        );

        if (empty($row)) {
            return null;
        }

        if ($hasExpiry && !empty($row->expires_at) && strtotime((string) $row->expires_at) < time()) {
            $this->deleteLocationOverride($locationId);
            return null;
        }

        return $row;
    }

    public function getLocationOverrideRaw(int $locationId)
    {
        if ($locationId <= 0) {
            return null;
        }

        $hasExpiry = $this->tableHasColumn('location_weather_overrides', 'expires_at');
        $extraCols = $hasExpiry ? ', expires_at, note' : '';

        return $this->firstPrepared(
            'SELECT location_id, weather_key, degrees, moon_phase, updated_by, date_updated'
            . $extraCols
            . ' FROM location_weather_overrides
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
                    `degrees` = VALUES(`degrees`),
                    `moon_phase` = VALUES(`moon_phase`),
                    `updated_by` = VALUES(`updated_by`),
                    `expires_at` = VALUES(`expires_at`),
                    `note` = VALUES(`note`),
                    `date_updated` = NOW()',
                [$locationId, $weatherKey, $degrees, $moonPhase, $updatedBy, $expiresAt, $note],
            );
        } else {
            $this->execPrepared(
                'INSERT INTO location_weather_overrides
                    (`location_id`,`weather_key`,`degrees`,`moon_phase`,`updated_by`,`date_created`,`date_updated`)
                 VALUES
                    (?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    `weather_key` = VALUES(`weather_key`),
                    `degrees` = VALUES(`degrees`),
                    `moon_phase` = VALUES(`moon_phase`),
                    `updated_by` = VALUES(`updated_by`),
                    `date_updated` = NOW()',
                [$locationId, $weatherKey, $degrees, $moonPhase, $updatedBy],
            );
        }

        AuditLogService::writeEvent('location_weather_overrides.upsert', ['location_id' => $locationId], 'admin');
    }

    public function clearExpiredOverrides(): int
    {
        if (!$this->tableHasColumn('location_weather_overrides', 'expires_at')) {
            return 0;
        }

        $this->execPrepared('DELETE FROM location_weather_overrides WHERE expires_at IS NOT NULL AND expires_at < NOW()');
        $row = $this->firstPrepared('SELECT ROW_COUNT() AS n');
        return (int) ($row->n ?? 0);
    }

    /**
     * @return array<int,object>
     */
    public function getGlobalOverrideRows(): array
    {
        $rows = $this->fetchPrepared(
            "SELECT `key`, `value`
             FROM sys_configs
             WHERE `key` IN ('weather_global_key', 'weather_global_degrees', 'weather_global_moon_phase')",
        );
        return !empty($rows) ? $rows : [];
    }

    /**
     * @return array<int,object>
     */
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
     * @return array<int,array{id:int,name:string,source:string,has_override:bool}>
     */
    public function listWorldOptions(): array
    {
        /** @var array<int,array{id:int,name:string,source:string,has_override:bool}> $options */
        $options = [];
        /** @var array<int,string> $namesById */
        $namesById = [];
        /** @var array<int,bool> $overrideById */
        $overrideById = [];

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
            }
        }

        try {
            $rows = $this->fetchPrepared("SELECT `key`, `value` FROM sys_configs WHERE `key` LIKE 'weather_world_%'");
            foreach ($rows as $row) {
                $key = (string) ($row->key ?? '');
                if (!preg_match('/^weather_world_(\d+)_(key|degrees|moon_phase|name)$/', $key, $match)) {
                    continue;
                }
                $id = (int) $match[1];
                $suffix = $match[2];
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
        }

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

        foreach ($options as $id => &$option) {
            if (isset($namesById[$id]) && trim($namesById[$id]) !== '') {
                $option['name'] = trim($namesById[$id]);
            }
            if (isset($overrideById[$id])) {
                $option['has_override'] = true;
            }
        }
        unset($option);

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
}

