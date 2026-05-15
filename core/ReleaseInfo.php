<?php

declare(strict_types=1);

namespace Core;

final class ReleaseInfo
{
    /** @var array<string,mixed>|null */
    private static $cachedStatus = null;

    public static function resetRuntimeState(): void
    {
        self::$cachedStatus = null;
    }

    /**
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        if (is_array(self::$cachedStatus)) {
            return self::$cachedStatus;
        }

        $distributionConfig = self::loadDistributionConfig();
        $manifestConfig = self::loadLocalManifest();

        $distribution = self::normalizeDistribution(
            (string) ($distributionConfig['distribution'] ?? ($manifestConfig['distribution'] ?? '')),
        );

        $installedVersion = trim((string) ($manifestConfig['version'] ?? ''));
        if ($installedVersion === '') {
            $installedVersion = trim((string) ($distributionConfig['installed_version'] ?? ''));
        }
        if ($installedVersion === '') {
            $installedVersion = '0.0.0';
        }

        $installedCommit = trim((string) ($manifestConfig['commit'] ?? ''));
        if ($installedCommit === '') {
            $installedCommit = trim((string) ($distributionConfig['installed_commit'] ?? ''));
        }
        if ($installedCommit === '') {
            $installedCommit = 'unknown';
        }

        $channel = trim((string) ($distributionConfig['update_channel'] ?? 'stable'));
        if ($channel === '') {
            $channel = 'stable';
        }

        $installedAt = trim((string) ($distributionConfig['installed_at'] ?? ''));
        if ($installedAt === '') {
            $installedAt = null;
        }

        self::$cachedStatus = [
            'distribution' => $distribution,
            'installed_version' => $installedVersion,
            'installed_commit' => $installedCommit,
            'update_channel' => $channel,
            'installed_at' => $installedAt,
            'is_legacy' => empty($distributionConfig),
            'web_updater_allowed' => ($distribution === 'ready'),
            'local_manifest' => $manifestConfig,
        ];

        return self::$cachedStatus;
    }

    public static function version(): string
    {
        return (string) (self::status()['installed_version'] ?? '0.0.0');
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadDistributionConfig(): array
    {
        $path = self::distributionConfigPath();
        if (!is_file($path)) {
            return [];
        }

        $payload = require $path;
        if (!is_array($payload)) {
            return [];
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private static function loadLocalManifest(): array
    {
        $path = self::localManifestPath();
        if (!is_file($path)) {
            return [];
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private static function normalizeDistribution(string $value): string
    {
        $distribution = strtolower(trim($value));
        if ($distribution === 'ready') {
            return 'ready';
        }
        if ($distribution === 'source-dev') {
            return 'source-dev';
        }
        return 'legacy';
    }

    private static function distributionConfigPath(): string
    {
        return dirname(__DIR__) . '/configs/distribution.php';
    }

    private static function localManifestPath(): string
    {
        return dirname(__DIR__) . '/logeon.manifest.json';
    }
}
