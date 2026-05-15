<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\AppContext;

class UpdateLogService
{
    /** @var bool */
    private static $tablesReady = false;

    /**
     * @return array<string,mixed>
     */
    public function statusSnapshot(): array
    {
        $runtime = new UpdateRuntime();
        $lock = $runtime->lockState();
        $maintenance = $runtime->maintenanceState();
        $latest = $this->latestUpdate();

        return [
            'lock' => $lock,
            'maintenance' => $maintenance,
            'latest_update' => $latest,
        ];
    }

    public function createUpdateRecord(
        string $fromVersion,
        string $toVersion,
        string $distribution,
        string $status,
        ?int $userId = null,
    ): int {
        $this->ensureTables();

        $db = AppContext::dbProvider()->connection();
        $db->executePrepared(
            'INSERT INTO core_updates
                (from_version, to_version, distribution, status, started_at, created_by_user_id)
             VALUES (?, ?, ?, ?, NOW(), ?)',
            [$fromVersion, $toVersion, $distribution, $status, $userId],
        );

        return (int) $db->lastInsertId();
    }

    public function setUpdateStatus(
        int $updateId,
        string $status,
        string $errorCode = '',
        string $errorMessage = '',
        string $backupPath = '',
        string $packagePath = '',
        bool $markCompleted = false,
    ): void {
        $this->ensureTables();

        $completedAtSql = $markCompleted ? 'NOW()' : 'NULL';
        $db = AppContext::dbProvider()->connection();
        $db->executePrepared(
            "UPDATE core_updates
                SET status = ?,
                    error_code = ?,
                    error_message = ?,
                    backup_path = ?,
                    package_path = ?,
                    completed_at = {$completedAtSql}
              WHERE id = ?",
            [$status, $errorCode, $errorMessage, $backupPath, $packagePath, $updateId],
        );
    }

    /**
     * @param array<string,mixed> $context
     */
    public function logEvent(
        ?int $updateId,
        string $level,
        string $eventKey,
        string $message = '',
        array $context = [],
    ): void {
        $this->ensureTables();

        $allowedLevels = ['info', 'warning', 'error', 'debug'];
        if (!in_array($level, $allowedLevels, true)) {
            $level = 'info';
        }

        $contextJson = '';
        if (!empty($context)) {
            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $contextJson = is_string($json) ? $json : '';
        }

        $db = AppContext::dbProvider()->connection();
        $db->executePrepared(
            'INSERT INTO core_update_events
                (update_id, level, event_key, message, context_json, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$updateId, $level, $eventKey, $message, $contextJson],
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latestUpdate(): ?array
    {
        $this->ensureTables();
        $db = AppContext::dbProvider()->connection();
        $row = $db->fetchOnePrepared(
            'SELECT id, from_version, to_version, distribution, status, started_at, completed_at, error_code, error_message, backup_path, package_path, created_by_user_id
             FROM core_updates
             ORDER BY id DESC
             LIMIT 1',
            [],
        );

        if (empty($row)) {
            return null;
        }

        return [
            'id' => (int) ($row->id ?? 0),
            'from_version' => (string) ($row->from_version ?? ''),
            'to_version' => (string) ($row->to_version ?? ''),
            'distribution' => (string) ($row->distribution ?? ''),
            'status' => (string) ($row->status ?? ''),
            'started_at' => (string) ($row->started_at ?? ''),
            'completed_at' => (string) ($row->completed_at ?? ''),
            'error_code' => (string) ($row->error_code ?? ''),
            'error_message' => (string) ($row->error_message ?? ''),
            'backup_path' => (string) ($row->backup_path ?? ''),
            'package_path' => (string) ($row->package_path ?? ''),
            'created_by_user_id' => (int) ($row->created_by_user_id ?? 0),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listUpdates(int $limit = 20): array
    {
        $this->ensureTables();
        $limit = max(1, min(100, $limit));

        $db = AppContext::dbProvider()->connection();
        $rows = $db->fetchAllPrepared(
            "SELECT id, from_version, to_version, distribution, status, started_at, completed_at, error_code, error_message
             FROM core_updates
             ORDER BY id DESC
             LIMIT {$limit}",
            [],
        );

        $dataset = [];
        foreach ($rows as $row) {
            $dataset[] = [
                'id' => (int) ($row->id ?? 0),
                'from_version' => (string) ($row->from_version ?? ''),
                'to_version' => (string) ($row->to_version ?? ''),
                'distribution' => (string) ($row->distribution ?? ''),
                'status' => (string) ($row->status ?? ''),
                'started_at' => (string) ($row->started_at ?? ''),
                'completed_at' => (string) ($row->completed_at ?? ''),
                'error_code' => (string) ($row->error_code ?? ''),
                'error_message' => (string) ($row->error_message ?? ''),
            ];
        }

        return $dataset;
    }

    public function migrationApplied(string $migrationKey): bool
    {
        $this->ensureTables();
        $db = AppContext::dbProvider()->connection();
        $row = $db->fetchOnePrepared(
            'SELECT id FROM core_migrations WHERE migration = ? LIMIT 1',
            [$migrationKey],
        );
        return !empty($row);
    }

    public function registerMigration(string $migrationKey): void
    {
        $this->ensureTables();
        $db = AppContext::dbProvider()->connection();
        $db->executePrepared(
            'INSERT INTO core_migrations (migration, applied_at) VALUES (?, NOW())',
            [$migrationKey],
        );
    }

    private function ensureTables(): void
    {
        if (self::$tablesReady) {
            return;
        }

        $db = AppContext::dbProvider()->connection();

        $db->query(
            'CREATE TABLE IF NOT EXISTS core_updates (
              id INT AUTO_INCREMENT PRIMARY KEY,
              from_version VARCHAR(40) NOT NULL,
              to_version VARCHAR(40) NOT NULL,
              distribution VARCHAR(40) NOT NULL,
              status VARCHAR(40) NOT NULL,
              started_at DATETIME NOT NULL,
              completed_at DATETIME NULL,
              error_code VARCHAR(120) NULL,
              error_message TEXT NULL,
              backup_path VARCHAR(255) NULL,
              package_path VARCHAR(255) NULL,
              created_by_user_id INT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->query(
            'CREATE TABLE IF NOT EXISTS core_migrations (
              id INT AUTO_INCREMENT PRIMARY KEY,
              migration VARCHAR(190) NOT NULL UNIQUE,
              applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $db->query(
            'CREATE TABLE IF NOT EXISTS core_update_events (
              id INT AUTO_INCREMENT PRIMARY KEY,
              update_id INT NULL,
              level VARCHAR(20) NOT NULL,
              event_key VARCHAR(120) NOT NULL,
              message TEXT NULL,
              context_json LONGTEXT NULL,
              created_at DATETIME NOT NULL,
              INDEX idx_core_update_events_update_id (update_id),
              INDEX idx_core_update_events_level (level)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        self::$tablesReady = true;
    }
}

