<?php

declare(strict_types=1);

namespace App\Services\Weather;

use App\Contracts\WeatherProviderInterface;

class CoreWeatherProvider implements WeatherProviderInterface
{
    private WeatherGenerationService $generation;
    private WeatherOverrideService $override;
    private WeatherResolverService $resolver;

    public function __construct(
        WeatherGenerationService $generation = null,
        WeatherOverrideService $override = null,
        WeatherResolverService $resolver = null,
    ) {
        $this->generation = $generation ?: new WeatherGenerationService();
        $this->override = $override ?: new WeatherOverrideService();
        $this->resolver = $resolver ?: new WeatherResolverService($this->generation, $this->override);
    }

    public function resolveState(
        int $locationId,
        ?int $worldId = null,
        int $baseTemperature = 12,
        string $renderMode = 'animated',
        string $imageBaseUrl = '',
    ): array {
        if ($locationId > 0) {
            return $this->resolver->resolveForLocation($locationId, $baseTemperature, $renderMode, $imageBaseUrl, $worldId);
        }

        if ($worldId !== null && $worldId > 0) {
            return $this->resolver->resolveForWorld($worldId, $baseTemperature, $renderMode, $imageBaseUrl);
        }

        return $this->resolver->resolveGlobal($baseTemperature, $renderMode, $imageBaseUrl);
    }

    public function options(): array
    {
        $conditions = array_map(
            static fn (array $row): array => [
                'key' => (string) ($row['key'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
            ],
            $this->generation->getConditions(),
        );

        $moonPhases = array_map(
            static fn (array $row): array => [
                'phase' => (string) ($row['phase'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
            ],
            $this->generation->getMoonPhases(),
        );

        return [
            'dataset' => [
                'conditions' => $conditions,
                'moon_phases' => $moonPhases,
                'seasons' => [],
            ],
        ];
    }

    public function moonPhases(int $baseTemperature = 12): array
    {
        $state = $this->resolver->resolveGlobal($baseTemperature);
        $moon = $state['moon'] ?? [];
        return is_array($moon) ? $moon : [];
    }

    public function isClimateAvailable(): bool
    {
        return false;
    }

    public function getConditionByKey(string $key): ?array
    {
        return $this->generation->getConditionByKey($key);
    }

    public function getMoonPhaseByPhase(string $phase): ?array
    {
        return $this->generation->getMoonPhaseByPhase($phase);
    }

    public function getWeatherTypeBySlug(string $slug): ?array
    {
        return null;
    }

    public function locationExists(int $locationId): bool
    {
        return $this->override->locationExists($locationId);
    }

    public function deleteLocationOverride(int $locationId): void
    {
        $this->override->deleteLocationOverride($locationId);
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
        $this->override->upsertLocationOverride(
            $locationId,
            $weatherKey,
            $degrees,
            $moonPhase,
            $updatedBy,
            $expiresAt,
            $note,
        );
    }

    public function clearExpiredOverrides(): int
    {
        return $this->override->clearExpiredOverrides();
    }

    public function saveGlobalOverride(?string $weatherKey, ?int $degrees, ?string $moonPhase): void
    {
        $this->override->saveGlobalOverride($weatherKey, $degrees, $moonPhase);
    }

    public function saveWorldOverride(int $worldId, ?string $weatherKey, ?int $degrees, ?string $moonPhase): void
    {
        $this->override->saveWorldOverride($worldId, $weatherKey, $degrees, $moonPhase);
    }

    public function clearWorldOverride(int $worldId): void
    {
        $this->override->clearWorldOverride($worldId);
    }

    public function worldOptions(): array
    {
        return $this->override->listWorldOptions();
    }
}

