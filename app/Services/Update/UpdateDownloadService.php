<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\Http\AppError;

class UpdateDownloadService
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
    public function download(string $targetVersion = ''): array
    {
        $check = $this->checkService->check();
        $release = (array) ($check['release'] ?? []);
        $distribution = (string) ($check['distribution'] ?? 'legacy');

        if ($distribution !== 'ready') {
            throw AppError::validation(
                'Distribuzione locale non compatibile con apply web updater',
                [],
                'update_distribution_mismatch',
            );
        }

        $latestVersion = trim((string) ($check['latest_version'] ?? ''));
        $targetVersion = trim($targetVersion);
        if ($targetVersion === '') {
            $targetVersion = $latestVersion;
        }

        $releaseVersion = trim((string) ($release['version'] ?? ''));
        if ($targetVersion === '' || $releaseVersion === '' || $targetVersion !== $releaseVersion) {
            throw AppError::validation(
                'Release target non trovata o non allineata al manifest',
                [],
                'update_release_not_found',
            );
        }

        $package = is_array($release['package'] ?? null) ? $release['package'] : [];
        $url = trim((string) ($package['url'] ?? ''));
        $checksum = strtolower(trim((string) ($package['checksum_sha256'] ?? '')));
        $sizeBytes = (int) ($package['size_bytes'] ?? 0);

        if ($url === '') {
            throw AppError::validation('URL pacchetto mancante', [], 'update_package_download_failed');
        }
        if ($checksum === '' || strtoupper($checksum) === 'CHANGE_ME') {
            throw AppError::validation('Checksum pacchetto non valido', [], 'update_package_checksum_invalid');
        }

        $this->runtime->ensureStorageLayout();

        $cacheFileName = 'logeon-' . $distribution . '-' . $targetVersion . '.zip';
        $cachePath = $this->runtime->updateCacheRoot() . DIRECTORY_SEPARATOR . $cacheFileName;
        $tmpExtractDir = $this->runtime->updateTmpRoot() . DIRECTORY_SEPARATOR . gmdate('Ymd-His') . '-' . $targetVersion;
        $this->runtime->ensureDirectory($tmpExtractDir);

        $downloaded = $this->downloadFile($url, $cachePath, 60);
        if (!$downloaded || !is_file($cachePath)) {
            throw AppError::validation(
                'Download pacchetto non riuscito',
                [],
                'update_package_download_failed',
            );
        }

        $actualSize = (int) @filesize($cachePath);
        if ($sizeBytes > 0 && $actualSize > 0 && $actualSize !== $sizeBytes) {
            throw AppError::validation(
                'Dimensione pacchetto non coerente',
                [],
                'update_package_download_failed',
            );
        }

        $actualChecksum = strtolower((string) @hash_file('sha256', $cachePath));
        if ($actualChecksum === '' || $actualChecksum !== $checksum) {
            throw AppError::validation(
                'Checksum pacchetto non valido',
                [],
                'update_package_checksum_invalid',
            );
        }

        $extractInfo = $this->extractAndValidatePackage($cachePath, $tmpExtractDir, $targetVersion, $distribution);

        $metadata = [
            'target_version' => $targetVersion,
            'distribution' => $distribution,
            'package_url' => $url,
            'package_path' => $cachePath,
            'package_size' => $actualSize,
            'package_checksum' => $actualChecksum,
            'tmp_extract_dir' => $tmpExtractDir,
            'source_root' => (string) ($extractInfo['source_root'] ?? ''),
            'manifest' => (array) ($extractInfo['manifest'] ?? []),
            'downloaded_at' => gmdate('c'),
        ];

        $metaPath = $this->runtime->updateCacheRoot() . DIRECTORY_SEPARATOR . 'download-' . $targetVersion . '.json';
        $this->runtime->writeJsonFile($metaPath, $metadata);

        $this->logService->logEvent(
            null,
            'info',
            'download_completed',
            'Pacchetto aggiornamento scaricato e validato',
            [
                'target_version' => $targetVersion,
                'package_path' => $cachePath,
            ],
        );

        return array_merge($metadata, ['metadata_path' => $metaPath]);
    }

    /**
     * @return array<string,mixed>
     */
    public function getDownloadedMetadata(string $targetVersion): array
    {
        $targetVersion = trim($targetVersion);
        if ($targetVersion === '') {
            throw AppError::validation('Versione target mancante', [], 'update_release_not_found');
        }

        $metaPath = $this->runtime->updateCacheRoot() . DIRECTORY_SEPARATOR . 'download-' . $targetVersion . '.json';
        $metadata = $this->runtime->readJsonFile($metaPath);
        if (!is_array($metadata)) {
            throw AppError::validation(
                'Metadata download non trovati per la versione target',
                [],
                'update_package_download_failed',
            );
        }
        return $metadata;
    }

    private function downloadFile(string $url, string $targetPath, int $timeoutSeconds): bool
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => "User-Agent: Logeon-Updater/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if (is_string($raw) && $raw !== '') {
            return @file_put_contents($targetPath, $raw, LOCK_EX) !== false;
        }

        if (!function_exists('curl_init')) {
            return false;
        }

        $fp = @fopen($targetPath, 'wb');
        if ($fp === false) {
            return false;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($fp);
            return false;
        }

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(15, $timeoutSeconds));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['User-Agent: Logeon-Updater/1.0']);
        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        return $ok !== false;
    }

    /**
     * @return array<string,mixed>
     */
    private function extractAndValidatePackage(
        string $zipPath,
        string $tmpExtractDir,
        string $targetVersion,
        string $distribution,
    ): array {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw AppError::validation('Impossibile aprire il pacchetto ZIP', [], 'update_package_extract_failed');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = (string) $zip->getNameIndex($i);
            if ($this->hasUnsafeZipEntry($entryName)) {
                $zip->close();
                throw AppError::validation(
                    'ZIP contiene path non sicuri',
                    [],
                    'update_package_extract_failed',
                );
            }
        }

        if (!$zip->extractTo($tmpExtractDir)) {
            $zip->close();
            throw AppError::validation('Estrazione ZIP non riuscita', [], 'update_package_extract_failed');
        }
        $zip->close();

        $manifestPath = $this->findFileByName($tmpExtractDir, 'logeon.manifest.json');
        if ($manifestPath === '') {
            throw AppError::validation('Manifest package non trovato', [], 'update_package_manifest_invalid');
        }

        $rawManifest = @file_get_contents($manifestPath);
        $manifest = is_string($rawManifest) ? json_decode($rawManifest, true) : null;
        if (!is_array($manifest)) {
            throw AppError::validation('Manifest package non valido', [], 'update_package_manifest_invalid');
        }

        $project = strtolower(trim((string) ($manifest['project'] ?? '')));
        $version = trim((string) ($manifest['version'] ?? ''));
        $manifestDistribution = trim((string) ($manifest['distribution'] ?? ''));
        if ($project !== 'logeon' || $version !== $targetVersion || $manifestDistribution !== $distribution) {
            throw AppError::validation(
                'Manifest package incoerente con la release target',
                [],
                'update_package_manifest_invalid',
            );
        }

        return [
            'manifest' => $manifest,
            'manifest_path' => $manifestPath,
            'source_root' => dirname($manifestPath),
        ];
    }

    private function hasUnsafeZipEntry(string $entryName): bool
    {
        $entryName = str_replace('\\', '/', $entryName);
        if ($entryName === '') {
            return true;
        }
        if (strpos($entryName, '../') !== false || strpos($entryName, '/..') !== false) {
            return true;
        }
        if (strpos($entryName, '..\\') !== false) {
            return true;
        }
        if (preg_match('/^[a-zA-Z]:/', $entryName) === 1) {
            return true;
        }
        if (strpos($entryName, '/') === 0) {
            return true;
        }
        return false;
    }

    private function findFileByName(string $root, string $name): string
    {
        if (!is_dir($root)) {
            return '';
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            if (strcasecmp($item->getFilename(), $name) === 0) {
                return $item->getPathname();
            }
        }

        return '';
    }
}

