<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\AppContext;
use Core\Http\AppError;

class UpdateBackupService
{
    /** @var UpdatePreflightService */
    private $preflightService;
    /** @var UpdateRuntime */
    private $runtime;
    /** @var UpdateLogService */
    private $logService;

    public function __construct(
        UpdatePreflightService $preflightService = null,
        UpdateRuntime $runtime = null,
        UpdateLogService $logService = null,
    ) {
        $this->preflightService = $preflightService ?: new UpdatePreflightService();
        $this->runtime = $runtime ?: new UpdateRuntime();
        $this->logService = $logService ?: new UpdateLogService();
    }

    /**
     * @return array<string,mixed>
     */
    public function create(string $targetVersion = ''): array
    {
        $preflight = $this->preflightService->run($targetVersion);
        if (empty($preflight['ok'])) {
            throw AppError::validation(
                'Preflight non superato. Impossibile creare backup.',
                ['dataset' => $preflight],
                'update_preflight_failed',
            );
        }

        $this->runtime->ensureStorageLayout();

        $backupId = gmdate('Ymd-His');
        $backupDir = $this->runtime->backupsRoot() . DIRECTORY_SEPARATOR . $backupId;
        $this->runtime->ensureDirectory($backupDir);

        $dbDumpPath = $backupDir . DIRECTORY_SEPARATOR . 'db.sql';
        $filesZipPath = $backupDir . DIRECTORY_SEPARATOR . 'files.zip';
        $metaPath = $backupDir . DIRECTORY_SEPARATOR . 'update-before.json';

        $dbDumpOk = $this->createDbBackup($dbDumpPath);
        if (!$dbDumpOk) {
            throw AppError::validation(
                'Backup database non riuscito',
                [],
                'update_backup_failed',
            );
        }

        $filesBackupOk = $this->createFilesBackup($filesZipPath);
        if (!$filesBackupOk) {
            throw AppError::validation(
                'Backup file non riuscito',
                [],
                'update_backup_failed',
            );
        }

        $releaseStatus = (new UpdateDistributionService())->status();
        $meta = [
            'created_at' => gmdate('c'),
            'from_version' => (string) ($releaseStatus['installed_version'] ?? '0.0.0'),
            'distribution' => (string) ($releaseStatus['distribution'] ?? 'legacy'),
            'php_version' => PHP_VERSION,
            'backup_kind' => 'pre-update',
            'created_by_user_id' => AppContext::authContext()->userId(),
            'target_version' => (string) ($preflight['target_version'] ?? ''),
        ];

        $this->runtime->writeJsonFile($metaPath, $meta);
        $this->logService->logEvent(
            null,
            'info',
            'backup_completed',
            'Backup aggiornamento creato',
            [
                'backup_id' => $backupId,
                'backup_path' => $backupDir,
            ],
        );

        return [
            'backup_id' => $backupId,
            'backup_path' => $backupDir,
            'db_dump_path' => $dbDumpPath,
            'files_zip_path' => $filesZipPath,
            'target_version' => (string) ($preflight['target_version'] ?? ''),
        ];
    }

    private function createDbBackup(string $outputPath): bool
    {
        if ($this->tryMysqlDump($outputPath)) {
            return true;
        }

        return $this->fallbackDbDump($outputPath);
    }

    private function tryMysqlDump(string $outputPath): bool
    {
        if (!defined('DB') || !is_array(DB) || !isset(DB['mysql']) || !is_array(DB['mysql'])) {
            return false;
        }

        $mysql = DB['mysql'];
        $host = (string) ($mysql['host'] ?? 'localhost');
        $user = (string) ($mysql['user'] ?? '');
        $pwd = (string) ($mysql['pwd'] ?? '');
        $dbName = (string) ($mysql['db_name'] ?? '');

        if ($user === '' || $dbName === '') {
            return false;
        }

        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'mysqldump',
        ];

        $executable = '';
        foreach ($candidates as $candidate) {
            if ($candidate === 'mysqldump' || is_file($candidate)) {
                $executable = $candidate;
                break;
            }
        }

        if ($executable === '') {
            return false;
        }

        $parts = [];
        $parts[] = escapeshellarg($executable);
        $parts[] = '--single-transaction';
        $parts[] = '--skip-lock-tables';
        $parts[] = '--default-character-set=utf8mb4';
        $parts[] = '-h' . escapeshellarg($host);
        $parts[] = '-u' . escapeshellarg($user);
        if ($pwd !== '') {
            $parts[] = '--password=' . escapeshellarg($pwd);
        }
        $parts[] = escapeshellarg($dbName);

        $command = implode(' ', $parts) . ' > ' . escapeshellarg($outputPath);
        @exec($command, $unusedOutput, $exitCode);

        return $exitCode === 0 && is_file($outputPath) && (int) @filesize($outputPath) > 0;
    }

    private function fallbackDbDump(string $outputPath): bool
    {
        try {
            $db = AppContext::dbProvider()->connection();
            $tables = $db->fetchAllPrepared('SHOW TABLES', []);
            if (empty($tables)) {
                return false;
            }

            $sqlParts = [];
            $sqlParts[] = '-- Logeon fallback DB dump';
            $sqlParts[] = '-- Generated at ' . gmdate('c');
            $sqlParts[] = 'SET FOREIGN_KEY_CHECKS=0;';
            $sqlParts[] = '';

            foreach ($tables as $tableRow) {
                $tableName = $this->firstObjectValue($tableRow);
                if ($tableName === '') {
                    continue;
                }

                $escapedTable = str_replace('`', '``', $tableName);
                $createRow = $db->fetchOnePrepared('SHOW CREATE TABLE `' . $escapedTable . '`', []);
                $createSql = $this->secondObjectValue($createRow);
                if ($createSql === '') {
                    continue;
                }

                $sqlParts[] = 'DROP TABLE IF EXISTS `' . $escapedTable . '`;';
                $sqlParts[] = $createSql . ';';
                $sqlParts[] = '';

                $rows = $db->fetchAllPrepared('SELECT * FROM `' . $escapedTable . '`', []);
                foreach ($rows as $row) {
                    $values = [];
                    foreach ((array) $row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                            continue;
                        }
                        if (is_int($value) || is_float($value)) {
                            $values[] = (string) $value;
                            continue;
                        }
                        if (is_bool($value)) {
                            $values[] = $value ? '1' : '0';
                            continue;
                        }
                        $values[] = "'" . addslashes((string) $value) . "'";
                    }
                    $sqlParts[] = 'INSERT INTO `' . $escapedTable . '` VALUES (' . implode(', ', $values) . ');';
                }
                $sqlParts[] = '';
            }

            $sqlParts[] = 'SET FOREIGN_KEY_CHECKS=1;';
            $dump = implode(PHP_EOL, $sqlParts) . PHP_EOL;
            return @file_put_contents($outputPath, $dump, LOCK_EX) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function createFilesBackup(string $zipPath): bool
    {
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $root = $this->runtime->projectRoot();
        $includePaths = [
            'app',
            'core',
            'assets',
            'public',
            'database',
            'scripts',
            'vendor',
            'autoload.php',
            'composer.lock',
            'logeon.manifest.json',
            'configs/distribution.php',
        ];

        foreach ($includePaths as $relative) {
            $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (is_dir($absolute)) {
                $this->zipDirectory($zip, $absolute, $relative);
                continue;
            }
            if (is_file($absolute)) {
                $zip->addFile($absolute, str_replace('\\', '/', $relative));
            }
        }

        $zip->close();
        return is_file($zipPath) && (int) @filesize($zipPath) > 0;
    }

    private function zipDirectory(\ZipArchive $zip, string $absoluteDir, string $relativePrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();
            $base = str_replace('\\', '/', $absoluteDir);
            $normalizedPath = str_replace('\\', '/', $path);
            $suffix = ltrim(substr($normalizedPath, strlen($base)), '/');
            $entry = trim(str_replace('\\', '/', $relativePrefix), '/');
            if ($suffix !== '') {
                $entry .= '/' . $suffix;
            }

            if ($item->isDir()) {
                $zip->addEmptyDir($entry);
                continue;
            }
            if ($item->isFile()) {
                $zip->addFile($path, $entry);
            }
        }
    }

    private function firstObjectValue($row): string
    {
        if (!is_object($row)) {
            return '';
        }
        $values = array_values((array) $row);
        return isset($values[0]) ? (string) $values[0] : '';
    }

    private function secondObjectValue($row): string
    {
        if (!is_object($row)) {
            return '';
        }
        $values = array_values((array) $row);
        return isset($values[1]) ? (string) $values[1] : '';
    }
}

