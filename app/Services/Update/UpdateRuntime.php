<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\Http\AppError;

class UpdateRuntime
{
    public function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    public function storageRoot(): string
    {
        return $this->projectRoot() . DIRECTORY_SEPARATOR . 'storage';
    }

    public function backupsRoot(): string
    {
        return $this->storageRoot() . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'updates';
    }

    public function updateCacheRoot(): string
    {
        return $this->storageRoot() . DIRECTORY_SEPARATOR . 'update-cache';
    }

    public function updateTmpRoot(): string
    {
        return $this->storageRoot() . DIRECTORY_SEPARATOR . 'update-tmp';
    }

    public function lockPath(): string
    {
        return $this->storageRoot() . DIRECTORY_SEPARATOR . 'update.lock';
    }

    public function maintenancePath(): string
    {
        return $this->storageRoot() . DIRECTORY_SEPARATOR . 'maintenance.lock';
    }

    public function ensureStorageLayout(): void
    {
        $this->ensureDirectory($this->storageRoot());
        $this->ensureDirectory($this->backupsRoot());
        $this->ensureDirectory($this->updateCacheRoot());
        $this->ensureDirectory($this->updateTmpRoot());
    }

    public function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw AppError::validation(
                'Directory non disponibile per gli aggiornamenti: ' . $path,
                [],
                'update_storage_not_writable',
            );
        }

        if (!is_writable($path)) {
            throw AppError::validation(
                'Directory non scrivibile per gli aggiornamenti: ' . $path,
                [],
                'update_storage_not_writable',
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function writeJsonFile(string $path, array $payload): void
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json) || $json === '') {
            throw AppError::validation('Serializzazione JSON non riuscita', [], 'update_apply_failed');
        }

        if (@file_put_contents($path, $json, LOCK_EX) === false) {
            throw AppError::validation(
                'Scrittura file stato aggiornamento non riuscita',
                [],
                'update_storage_not_writable',
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function lockState(): ?array
    {
        return $this->readJsonFile($this->lockPath());
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function acquireLock(array $payload): void
    {
        if ($this->lockState() !== null) {
            throw AppError::validation(
                'Aggiornamento già in corso o lock non rimosso',
                [],
                'update_lock_active',
            );
        }

        $this->ensureStorageLayout();
        $payload['status'] = (string) ($payload['status'] ?? 'running');
        $payload['started_at'] = (string) ($payload['started_at'] ?? gmdate('c'));
        $this->writeJsonFile($this->lockPath(), $payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateLock(array $payload): void
    {
        if ($this->lockState() === null) {
            return;
        }
        $current = $this->lockState();
        if (!is_array($current)) {
            $current = [];
        }
        $next = array_merge($current, $payload);
        $this->writeJsonFile($this->lockPath(), $next);
    }

    public function releaseLock(): void
    {
        $path = $this->lockPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function maintenanceState(): ?array
    {
        return $this->readJsonFile($this->maintenancePath());
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function enableMaintenance(array $payload = []): void
    {
        $this->ensureStorageLayout();
        $payload = array_merge(
            [
                'reason' => 'core_update',
                'started_at' => gmdate('c'),
            ],
            $payload,
        );
        $this->writeJsonFile($this->maintenancePath(), $payload);
    }

    public function disableMaintenance(): void
    {
        $path = $this->maintenancePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function isProtectedPath(string $relativePath): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        $normalized = strtolower($normalized);

        if ($normalized === '' || $normalized === '.env') {
            return true;
        }

        $protected = [
            'configs/',
            'custom/',
            'uploads/',
            'storage/',
            'modules/',
            'public/uploads/',
            'public/storage/',
            '.htaccess',
        ];

        foreach ($protected as $prefix) {
            $prefix = strtolower($prefix);
            if ($normalized === rtrim($prefix, '/')) {
                return true;
            }
            if (strpos($normalized, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    public function relativeToRoot(string $absolutePath): string
    {
        $root = rtrim(str_replace('\\', '/', $this->projectRoot()), '/');
        $path = str_replace('\\', '/', $absolutePath);
        if (strpos($path, $root . '/') === 0) {
            return substr($path, strlen($root) + 1);
        }
        return ltrim($path, '/');
    }
}

