<?php

declare(strict_types=1);

namespace App\Services\Update;

use Core\Http\AppError;

class UpdateCheckService
{
    /** @var UpdateDistributionService */
    private $distributionService;
    /** @var UpdateManifestService */
    private $manifestService;

    public function __construct(
        UpdateDistributionService $distributionService = null,
        UpdateManifestService $manifestService = null,
    ) {
        $this->distributionService = $distributionService ?: new UpdateDistributionService();
        $this->manifestService = $manifestService ?: new UpdateManifestService();
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $state = $this->distributionService->status();

        return [
            'current_version' => (string) ($state['installed_version'] ?? '0.0.0'),
            'installed_commit' => (string) ($state['installed_commit'] ?? 'unknown'),
            'distribution' => (string) ($state['distribution'] ?? 'legacy'),
            'channel' => (string) ($state['update_channel'] ?? 'stable'),
            'installed_at' => $state['installed_at'] ?? null,
            'is_legacy' => !empty($state['is_legacy']),
            'web_updater_allowed' => !empty($state['web_updater_allowed']),
            'manifest_url' => (string) ($state['manifest_url'] ?? ''),
            'manifest_timeout_seconds' => (int) ($state['manifest_timeout_seconds'] ?? 8),
            'update_available' => false,
            'latest_version' => null,
            'release' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        $state = $this->distributionService->status();
        $distribution = (string) ($state['distribution'] ?? 'legacy');
        if (!in_array($distribution, ['ready', 'source-dev'], true)) {
            throw AppError::validation(
                'Distribuzione locale non supportata per il check aggiornamenti',
                [],
                'update_distribution_mismatch',
            );
        }

        $manifestUrl = (string) ($state['manifest_url'] ?? '');
        $timeoutSeconds = (int) ($state['manifest_timeout_seconds'] ?? 8);
        $manifest = $this->manifestService->fetchAndValidate($manifestUrl, $timeoutSeconds);

        $channel = strtolower(trim((string) ($state['update_channel'] ?? 'stable')));
        if ($channel === '') {
            $channel = 'stable';
        }

        $latestVersion = $this->resolveLatestVersion($manifest, $distribution, $channel);
        if ($latestVersion === null) {
            return [
                'current_version' => (string) ($state['installed_version'] ?? '0.0.0'),
                'installed_commit' => (string) ($state['installed_commit'] ?? 'unknown'),
                'distribution' => $distribution,
                'channel' => $channel,
                'update_available' => false,
                'latest_version' => null,
                'release' => null,
                'web_updater_allowed' => ($distribution === 'ready'),
                'source' => [
                    'manifest_url' => $manifestUrl,
                ],
            ];
        }

        $release = $this->findRelease($manifest, $latestVersion, $channel, $distribution);
        $currentVersion = (string) ($state['installed_version'] ?? '0.0.0');
        $updateAvailable = version_compare($latestVersion, $currentVersion, '>');

        return [
            'current_version' => $currentVersion,
            'installed_commit' => (string) ($state['installed_commit'] ?? 'unknown'),
            'distribution' => $distribution,
            'channel' => $channel,
            'update_available' => $updateAvailable,
            'latest_version' => $latestVersion,
            'release' => [
                'version' => (string) ($release['version'] ?? $latestVersion),
                'date' => (string) ($release['date'] ?? ''),
                'requires_db_migration' => !empty($release['requires_db_migration']),
                'security' => !empty($release['security']),
                'minimum_supported' => (string) ($release['minimum_supported'] ?? ''),
                'requires_php' => (string) ($release['requires_php'] ?? ''),
                'changelog_url' => (string) ($release['changelog_url'] ?? ''),
                'notes' => array_values(
                    array_filter(
                        array_map('strval', (array) ($release['notes'] ?? [])),
                        static function (string $note): bool {
                            return trim($note) !== '';
                        },
                    ),
                ),
                'package' => [
                    'url' => (string) ($release['packages'][$distribution]['url'] ?? ''),
                    'checksum_sha256' => (string) ($release['packages'][$distribution]['checksum_sha256'] ?? ''),
                    'size_bytes' => (int) ($release['packages'][$distribution]['size_bytes'] ?? 0),
                ],
            ],
            'web_updater_allowed' => ($distribution === 'ready'),
            'source' => [
                'manifest_url' => $manifestUrl,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     */
    private function resolveLatestVersion(array $manifest, string $distribution, string $channel): ?string
    {
        $latest = is_array($manifest['latest'] ?? null) ? $manifest['latest'] : [];
        $fromLatest = trim((string) ($latest[$distribution] ?? ''));
        if ($fromLatest !== '') {
            return $fromLatest;
        }

        $releases = is_array($manifest['releases'] ?? null) ? $manifest['releases'] : [];
        $best = null;
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $releaseChannel = strtolower(trim((string) ($release['channel'] ?? 'stable')));
            if ($releaseChannel !== $channel) {
                continue;
            }

            $version = trim((string) ($release['version'] ?? ''));
            if ($version === '') {
                continue;
            }

            $packages = is_array($release['packages'] ?? null) ? $release['packages'] : [];
            $package = is_array($packages[$distribution] ?? null) ? $packages[$distribution] : [];
            $url = trim((string) ($package['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            if ($best === null || version_compare($version, $best, '>')) {
                $best = $version;
            }
        }

        return $best;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function findRelease(array $manifest, string $version, string $channel, string $distribution): array
    {
        $releases = is_array($manifest['releases'] ?? null) ? $manifest['releases'] : [];
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $releaseVersion = trim((string) ($release['version'] ?? ''));
            if ($releaseVersion !== $version) {
                continue;
            }

            $releaseChannel = strtolower(trim((string) ($release['channel'] ?? 'stable')));
            if ($releaseChannel !== $channel) {
                continue;
            }

            $packages = is_array($release['packages'] ?? null) ? $release['packages'] : [];
            $package = is_array($packages[$distribution] ?? null) ? $packages[$distribution] : [];
            $url = trim((string) ($package['url'] ?? ''));
            if ($url === '') {
                throw AppError::validation(
                    'Pacchetto aggiornamento non disponibile per questa distribuzione',
                    [],
                    'update_distribution_mismatch',
                );
            }

            return $release;
        }

        throw AppError::validation(
            'Release target non trovata nel manifest',
            [],
            'update_release_not_found',
        );
    }
}

