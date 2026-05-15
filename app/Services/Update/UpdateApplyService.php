<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\AppContext;
use Core\Http\AppError;

class UpdateApplyService
{
    /** @var UpdatePreflightService */
    private $preflightService;
    /** @var UpdateDownloadService */
    private $downloadService;
    /** @var UpdateRuntime */
    private $runtime;
    /** @var UpdateLogService */
    private $logService;

    public function __construct(
        UpdatePreflightService $preflightService = null,
        UpdateDownloadService $downloadService = null,
        UpdateRuntime $runtime = null,
        UpdateLogService $logService = null,
    ) {
        $this->preflightService = $preflightService ?: new UpdatePreflightService();
        $this->downloadService = $downloadService ?: new UpdateDownloadService();
        $this->runtime = $runtime ?: new UpdateRuntime();
        $this->logService = $logService ?: new UpdateLogService();
    }

    /**
     * @return array<string,mixed>
     */
    public function apply(string $targetVersion, string $backupId): array
    {
        $targetVersion = trim($targetVersion);
        $backupId = trim($backupId);

        if ($targetVersion === '') {
            throw AppError::validation('Versione target mancante', [], 'update_release_not_found');
        }
        if ($backupId === '') {
            throw AppError::validation('Backup richiesto prima dell\'apply', [], 'update_backup_failed');
        }

        $preflight = $this->preflightService->run($targetVersion);
        if (empty($preflight['ok'])) {
            throw AppError::validation(
                'Preflight non superato. Apply bloccato.',
                ['dataset' => $preflight],
                'update_preflight_failed',
            );
        }

        $backupPath = $this->runtime->backupsRoot() . DIRECTORY_SEPARATOR . $backupId;
        $dbDumpPath = $backupPath . DIRECTORY_SEPARATOR . 'db.sql';
        $filesZipPath = $backupPath . DIRECTORY_SEPARATOR . 'files.zip';
        if (!is_dir($backupPath) || !is_file($dbDumpPath) || !is_file($filesZipPath)) {
            throw AppError::validation(
                'Backup indicato non valido o incompleto',
                [],
                'update_backup_failed',
            );
        }

        $download = $this->downloadService->getDownloadedMetadata($targetVersion);
        $packagePath = (string) ($download['package_path'] ?? '');
        $sourceRoot = (string) ($download['source_root'] ?? '');
        if ($packagePath === '' || !is_file($packagePath) || $sourceRoot === '' || !is_dir($sourceRoot)) {
            throw AppError::validation(
                'Pacchetto aggiornamento non disponibile: esegui prima il download',
                [],
                'update_package_download_failed',
            );
        }

        $distributionStatus = (new UpdateDistributionService())->status();
        $fromVersion = (string) ($distributionStatus['installed_version'] ?? '0.0.0');
        $distribution = (string) ($distributionStatus['distribution'] ?? 'legacy');

        $updateId = $this->logService->createUpdateRecord(
            $fromVersion,
            $targetVersion,
            $distribution,
            'pending',
            AppContext::authContext()->userId(),
        );

        $this->runtime->acquireLock([
            'update_id' => $updateId,
            'from_version' => $fromVersion,
            'to_version' => $targetVersion,
            'created_by_user_id' => AppContext::authContext()->userId(),
            'status' => 'running',
        ]);
        $this->runtime->enableMaintenance([
            'reason' => 'core_update',
            'update_id' => $updateId,
        ]);

        $this->logService->setUpdateStatus($updateId, 'applying', '', '', $backupPath, $packagePath, false);
        $this->logService->logEvent($updateId, 'info', 'apply_started', 'Avvio applicazione aggiornamento');

        try {
            $copied = $this->copyPackageFiles($sourceRoot);
            $migrationSummary = $this->applyDatabaseMigrations($sourceRoot, $updateId);
            $manifest = (array) ($download['manifest'] ?? []);

            $this->writeDistributionVersion($targetVersion, (string) ($manifest['commit'] ?? 'unknown'));
            $this->copyManifestIfPresent($sourceRoot);

            $this->runtime->disableMaintenance();
            $this->runtime->releaseLock();

            $this->logService->setUpdateStatus(
                $updateId,
                'completed',
                '',
                '',
                $backupPath,
                $packagePath,
                true,
            );
            $this->logService->logEvent(
                $updateId,
                'info',
                'apply_completed',
                'Aggiornamento applicato con successo',
                [
                    'copied_files' => $copied,
                    'migrations_applied' => (int) ($migrationSummary['applied'] ?? 0),
                    'migrations_skipped' => (int) ($migrationSummary['skipped'] ?? 0),
                ],
            );

            return [
                'ok' => true,
                'update_id' => $updateId,
                'from_version' => $fromVersion,
                'to_version' => $targetVersion,
                'backup_path' => $backupPath,
                'package_path' => $packagePath,
                'copied_files' => $copied,
                'migrations' => $migrationSummary,
            ];
        } catch (AppError $e) {
            $this->runtime->updateLock([
                'status' => 'failed',
                'error_code' => $e->errorCode(),
                'error_message' => $e->getMessage(),
                'failed_at' => gmdate('c'),
            ]);
            $this->logService->setUpdateStatus(
                $updateId,
                'failed',
                $e->errorCode(),
                $e->getMessage(),
                $backupPath,
                $packagePath,
                false,
            );
            $this->logService->logEvent(
                $updateId,
                'error',
                'apply_failed',
                $e->getMessage(),
                ['error_code' => $e->errorCode()],
            );
            throw $e;
        } catch (\Throwable $e) {
            $message = 'Errore durante apply update: ' . $e->getMessage();
            $error = AppError::validation($message, [], 'update_apply_failed');
            $this->runtime->updateLock([
                'status' => 'failed',
                'error_code' => $error->errorCode(),
                'error_message' => $error->getMessage(),
                'failed_at' => gmdate('c'),
            ]);
            $this->logService->setUpdateStatus(
                $updateId,
                'failed',
                $error->errorCode(),
                $error->getMessage(),
                $backupPath,
                $packagePath,
                false,
            );
            $this->logService->logEvent(
                $updateId,
                'error',
                'apply_failed',
                $error->getMessage(),
                [],
            );
            throw $error;
        }
    }

    private function copyManifestIfPresent(string $sourceRoot): void
    {
        $sourceManifest = $sourceRoot . DIRECTORY_SEPARATOR . 'logeon.manifest.json';
        if (!is_file($sourceManifest)) {
            return;
        }

        $targetManifest = $this->runtime->projectRoot() . DIRECTORY_SEPARATOR . 'logeon.manifest.json';
        if (!@copy($sourceManifest, $targetManifest)) {
            throw AppError::validation(
                'Impossibile aggiornare il manifest locale',
                [],
                'update_apply_failed',
            );
        }
    }

    /**
     * @return array<string,int>
     */
    private function applyDatabaseMigrations(string $sourceRoot, int $updateId): array
    {
        $migrationsDir = $sourceRoot . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        if (!is_dir($migrationsDir)) {
            return ['applied' => 0, 'skipped' => 0];
        }

        $files = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false || empty($files)) {
            return ['applied' => 0, 'skipped' => 0];
        }
        sort($files, SORT_STRING);

        $applied = 0;
        $skipped = 0;
        foreach ($files as $file) {
            $key = basename((string) $file);
            if ($this->logService->migrationApplied($key)) {
                $skipped++;
                continue;
            }

            $sql = @file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                continue;
            }

            $this->executeSqlScript($sql);
            $this->logService->registerMigration($key);
            $this->logService->logEvent($updateId, 'info', 'migration_completed', 'Migrazione applicata: ' . $key);
            $applied++;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function executeSqlScript(string $sql): void
    {
        if (!defined('DB') || !is_array(DB) || !isset(DB['mysql']) || !is_array(DB['mysql'])) {
            throw AppError::validation('Configurazione database non disponibile', [], 'update_migration_failed');
        }

        $mysql = DB['mysql'];
        $host = (string) ($mysql['host'] ?? 'localhost');
        $user = (string) ($mysql['user'] ?? '');
        $pwd = (string) ($mysql['pwd'] ?? '');
        $dbName = (string) ($mysql['db_name'] ?? '');
        $port = (int) ($mysql['port'] ?? 3306);

        $mysqli = @new \mysqli($host, $user, $pwd, $dbName, $port > 0 ? $port : 3306);
        if ($mysqli->connect_errno) {
            throw AppError::validation('Connessione DB fallita durante migrazione', [], 'update_migration_failed');
        }

        $charset = (string) ($mysql['charset'] ?? 'utf8mb4');
        $mysqli->set_charset($charset !== '' ? $charset : 'utf8mb4');

        if (!$mysqli->multi_query($sql)) {
            $error = trim((string) $mysqli->error);
            $mysqli->close();
            throw AppError::validation(
                'Errore migrazione SQL: ' . ($error !== '' ? $error : 'errore sconosciuto'),
                [],
                'update_migration_failed',
            );
        }

        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        if ($mysqli->errno) {
            $error = trim((string) $mysqli->error);
            $mysqli->close();
            throw AppError::validation(
                'Errore migrazione SQL: ' . ($error !== '' ? $error : 'errore sconosciuto'),
                [],
                'update_migration_failed',
            );
        }

        $mysqli->close();
    }

    private function writeDistributionVersion(string $version, string $commit): void
    {
        $path = $this->runtime->projectRoot() . DIRECTORY_SEPARATOR . 'configs' . DIRECTORY_SEPARATOR . 'distribution.php';
        $current = [];
        if (is_file($path)) {
            $data = require $path;
            if (is_array($data)) {
                $current = $data;
            }
        }

        $distribution = trim((string) ($current['distribution'] ?? 'ready'));
        if (!in_array($distribution, ['ready', 'source-dev'], true)) {
            $distribution = 'ready';
        }

        $channel = trim((string) ($current['update_channel'] ?? 'stable'));
        if ($channel === '') {
            $channel = 'stable';
        }

        $payload = [
            'distribution' => $distribution,
            'installed_version' => $version,
            'installed_commit' => $commit !== '' ? $commit : 'unknown',
            'update_channel' => $channel,
            'installed_at' => gmdate('c'),
        ];

        $content = "<?php\n\nreturn " . var_export($payload, true) . ";\n";
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            throw AppError::validation('Impossibile aggiornare configs/distribution.php', [], 'update_apply_failed');
        }
    }

    private function copyPackageFiles(string $sourceRoot): int
    {
        $sourceRoot = rtrim($sourceRoot, DIRECTORY_SEPARATOR);
        $targetRoot = $this->runtime->projectRoot();
        $copied = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }

            $sourcePath = $item->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($sourcePath, strlen($sourceRoot))), '/');
            if ($relative === '') {
                continue;
            }
            if ($this->runtime->isProtectedPath($relative)) {
                continue;
            }

            $targetPath = $targetRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $targetDir = dirname($targetPath);
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw AppError::validation(
                    'Impossibile creare directory target: ' . $targetDir,
                    [],
                    'update_apply_failed',
                );
            }

            if (!@copy($sourcePath, $targetPath)) {
                throw AppError::validation(
                    'Copia file non riuscita: ' . $relative,
                    [],
                    'update_apply_failed',
                );
            }

            $copied++;
        }

        return $copied;
    }
}

