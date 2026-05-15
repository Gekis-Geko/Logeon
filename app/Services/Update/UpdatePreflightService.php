<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\AppContext;
use Core\Http\AppError;

class UpdatePreflightService
{
    /** @var UpdateCheckService */
    private $checkService;
    /** @var UpdateRuntime */
    private $runtime;
    /** @var UpdateLogService */
    private $logService;

    public function __construct(
        UpdateCheckService $checkService = null,
        UpdateRuntime $runtime = null,
        UpdateLogService $logService = null,
    ) {
        $this->checkService = $checkService ?: new UpdateCheckService();
        $this->runtime = $runtime ?: new UpdateRuntime();
        $this->logService = $logService ?: new UpdateLogService();
    }

    /**
     * @return array<string,mixed>
     */
    public function run(string $targetVersion = ''): array
    {
        $status = $this->checkService->status();
        $distribution = (string) ($status['distribution'] ?? 'legacy');

        $dataset = [
            'ok' => false,
            'target_version' => '',
            'checks' => [],
            'blocking_errors' => [],
            'release' => null,
        ];

        if (!in_array($distribution, ['ready', 'source-dev'], true)) {
            $this->pushCheck(
                $dataset,
                'distribution',
                false,
                'blocking',
                'Distribuzione locale non riconosciuta',
                'update_distribution_mismatch',
            );
            return $this->finalizeDataset($dataset);
        }

        if ($distribution !== 'ready') {
            $this->pushCheck(
                $dataset,
                'distribution',
                false,
                'blocking',
                'Distribuzione source-dev: apply web non consentito',
                'update_distribution_mismatch',
            );
            return $this->finalizeDataset($dataset);
        }

        $check = [];
        try {
            $check = $this->checkService->check();
            $this->pushCheck($dataset, 'manifest', true, 'blocking', 'Manifest remoto valido');
        } catch (AppError $e) {
            $this->pushCheck(
                $dataset,
                'manifest',
                false,
                'blocking',
                $e->getMessage(),
                $e->errorCode() !== '' ? $e->errorCode() : 'update_manifest_invalid',
            );
            return $this->finalizeDataset($dataset);
        }

        $release = (array) ($check['release'] ?? []);
        $latestVersion = trim((string) ($check['latest_version'] ?? ''));
        $targetVersion = trim($targetVersion);
        if ($targetVersion === '') {
            $targetVersion = $latestVersion;
        }

        $dataset['target_version'] = $targetVersion;
        $dataset['release'] = $release;

        if ($targetVersion === '' || empty($release)) {
            $this->pushCheck(
                $dataset,
                'release',
                false,
                'blocking',
                'Nessuna release target disponibile',
                'update_no_release_available',
            );
            return $this->finalizeDataset($dataset);
        }

        if (((bool) ($check['update_available'] ?? false)) !== true) {
            $this->pushCheck(
                $dataset,
                'version',
                false,
                'blocking',
                'Nessun aggiornamento disponibile rispetto alla versione installata',
                'update_no_release_available',
            );
        } else {
            $this->pushCheck($dataset, 'version', true, 'blocking', 'Nuova release disponibile: ' . $targetVersion);
        }

        $requiresPhp = trim((string) ($release['requires_php'] ?? ''));
        if ($requiresPhp !== '') {
            $ok = $this->isPhpConstraintSatisfied(PHP_VERSION, $requiresPhp);
            $this->pushCheck(
                $dataset,
                'php_version',
                $ok,
                'blocking',
                $ok ? 'Versione PHP compatibile (' . PHP_VERSION . ')' : 'Versione PHP non compatibile (' . PHP_VERSION . ')',
                $ok ? '' : 'update_php_incompatible',
            );
        } else {
            $this->pushCheck($dataset, 'php_version', true, 'blocking', 'Nessun vincolo PHP dichiarato');
        }

        $lockState = $this->runtime->lockState();
        $lockOk = ($lockState === null);
        $this->pushCheck(
            $dataset,
            'lock',
            $lockOk,
            'blocking',
            $lockOk ? 'Nessun lock update attivo' : 'Lock update attivo',
            $lockOk ? '' : 'update_lock_active',
        );

        $maintenanceState = $this->runtime->maintenanceState();
        $maintenanceOk = ($maintenanceState === null);
        $this->pushCheck(
            $dataset,
            'maintenance',
            $maintenanceOk,
            'blocking',
            $maintenanceOk ? 'Maintenance mode non attivo' : 'Maintenance mode già attivo',
            $maintenanceOk ? '' : 'update_maintenance_active',
        );

        $this->runStorageChecks($dataset);
        $this->runDbCheck($dataset);
        $this->runPackageChecks($dataset, $release);

        return $this->finalizeDataset($dataset);
    }

    /**
     * @param array<string,mixed> $dataset
     * @param array<string,mixed> $release
     */
    private function runPackageChecks(array &$dataset, array $release): void
    {
        $package = is_array($release['package'] ?? null) ? $release['package'] : [];
        $url = trim((string) ($package['url'] ?? ''));
        $checksum = trim((string) ($package['checksum_sha256'] ?? ''));
        $sizeBytes = (int) ($package['size_bytes'] ?? 0);

        $this->pushCheck(
            $dataset,
            'package_url',
            $url !== '',
            'blocking',
            $url !== '' ? 'URL pacchetto disponibile' : 'URL pacchetto mancante',
            $url !== '' ? '' : 'update_package_download_failed',
        );

        $checksumOk = ($checksum !== '' && strtoupper($checksum) !== 'CHANGE_ME');
        $this->pushCheck(
            $dataset,
            'checksum',
            $checksumOk,
            'blocking',
            $checksumOk ? 'Checksum SHA-256 disponibile' : 'Checksum SHA-256 assente o placeholder',
            $checksumOk ? '' : 'update_package_checksum_invalid',
        );

        $storagePath = $this->runtime->storageRoot();
        $freeBytes = @disk_free_space($storagePath);
        $required = 64 * 1024 * 1024;
        if ($sizeBytes > 0) {
            $required = max($required, $sizeBytes * 2);
        }
        $diskOk = is_numeric($freeBytes) && ((float) $freeBytes >= (float) $required);
        $this->pushCheck(
            $dataset,
            'disk_space',
            $diskOk,
            'blocking',
            $diskOk ? 'Spazio disco sufficiente' : 'Spazio disco insufficiente',
            $diskOk ? '' : 'update_insufficient_disk_space',
        );
    }

    /**
     * @param array<string,mixed> $dataset
     */
    private function runDbCheck(array &$dataset): void
    {
        try {
            $row = AppContext::dbProvider()->connection()->fetchOnePrepared('SELECT 1 AS ok', []);
            $ok = !empty($row) && ((int) ($row->ok ?? 0) === 1);
            $this->pushCheck(
                $dataset,
                'database',
                $ok,
                'blocking',
                $ok ? 'Connessione database disponibile' : 'Connessione database non disponibile',
                $ok ? '' : 'update_preflight_failed',
            );
        } catch (\Throwable $e) {
            $this->pushCheck(
                $dataset,
                'database',
                false,
                'blocking',
                'Connessione database non disponibile',
                'update_preflight_failed',
            );
        }
    }

    /**
     * @param array<string,mixed> $dataset
     */
    private function runStorageChecks(array &$dataset): void
    {
        try {
            $this->runtime->ensureStorageLayout();
            $this->pushCheck($dataset, 'storage', true, 'blocking', 'Storage aggiornamenti scrivibile');
        } catch (AppError $e) {
            $this->pushCheck(
                $dataset,
                'storage',
                false,
                'blocking',
                $e->getMessage(),
                $e->errorCode() !== '' ? $e->errorCode() : 'update_storage_not_writable',
            );
        }
    }

    private function isPhpConstraintSatisfied(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '') {
            return true;
        }

        if (preg_match('/^(>=|<=|>|<|=)?\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)$/', $constraint, $matches) !== 1) {
            return true;
        }

        $operator = trim((string) ($matches[1] ?? ''));
        $target = trim((string) ($matches[2] ?? ''));
        if ($operator === '') {
            $operator = '>=';
        }

        return version_compare($version, $target, $operator);
    }

    /**
     * @param array<string,mixed> $dataset
     */
    private function finalizeDataset(array $dataset): array
    {
        $blockingErrors = [];
        foreach ((array) ($dataset['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }
            $isBlocking = ((string) ($check['severity'] ?? '')) === 'blocking';
            $isOk = !empty($check['ok']);
            if ($isBlocking && !$isOk) {
                $errorCode = trim((string) ($check['error_code'] ?? 'update_preflight_failed'));
                $blockingErrors[] = $errorCode !== '' ? $errorCode : 'update_preflight_failed';
            }
        }

        $dataset['blocking_errors'] = array_values(array_unique($blockingErrors));
        $dataset['ok'] = empty($dataset['blocking_errors']);

        $this->logService->logEvent(
            null,
            !empty($dataset['ok']) ? 'info' : 'warning',
            !empty($dataset['ok']) ? 'preflight_completed' : 'preflight_failed',
            !empty($dataset['ok']) ? 'Preflight completato' : 'Preflight con errori bloccanti',
            [
                'target_version' => (string) ($dataset['target_version'] ?? ''),
                'blocking_errors' => (array) ($dataset['blocking_errors'] ?? []),
            ],
        );

        return $dataset;
    }

    /**
     * @param array<string,mixed> $dataset
     */
    private function pushCheck(
        array &$dataset,
        string $key,
        bool $ok,
        string $severity,
        string $message,
        string $errorCode = '',
    ): void {
        if (!isset($dataset['checks']) || !is_array($dataset['checks'])) {
            $dataset['checks'] = [];
        }

        $dataset['checks'][] = [
            'key' => $key,
            'ok' => $ok,
            'severity' => $severity,
            'message' => $message,
            'error_code' => $errorCode,
        ];
    }
}

