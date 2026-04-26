<?php

declare(strict_types=1);

namespace Modules\Logeon\Weather\Services;

use App\Contracts\WeatherAdvancedInterface;

class CoreWeatherProvider implements WeatherAdvancedInterface
{
    private WeatherGenerationService $generation;
    private WeatherOverrideService $override;
    private WeatherResolverService $resolver;
    private WeatherClimateService $climate;

    public function __construct(
        WeatherGenerationService $generation = null,
        WeatherOverrideService $override = null,
        WeatherResolverService $resolver = null,
        WeatherClimateService $climate = null,
    ) {
        $this->generation = $generation ?: new WeatherGenerationService();
        $this->climate = $climate ?: new WeatherClimateService();
        $this->override = $override ?: new WeatherOverrideService();
        $this->resolver = $resolver ?: new WeatherResolverService($this->generation, $this->override, $this->climate);
    }

    public function resolveState(
        int $locationId,
        ?int $worldId = null,
        int $baseTemperature = 12,
        string $renderMode = 'animated',
        string $imageBaseUrl = '',
    ): array {
        if ($locationId > 0) {
            return $this->resolver->resolveForLocation(
                $locationId,
                $baseTemperature,
                $renderMode,
                $imageBaseUrl,
                $worldId,
            );
        }

        if ($worldId !== null && $worldId > 0) {
            return $this->resolver->resolveForWorld(
                $worldId,
                $baseTemperature,
                $renderMode,
                $imageBaseUrl,
            );
        }

        return $this->resolver->resolveGlobal(
            $baseTemperature,
            $renderMode,
            $imageBaseUrl,
        );
    }

    public function options(): array
    {
        $conditions = [];
        if ($this->climate->isAvailable()) {
            $weatherTypes = $this->climate->listWeatherTypes(true);
            foreach ($weatherTypes as $row) {
                $rowArr = (array) $row;
                $conditions[] = [
                    'id' => (int) ($rowArr['id'] ?? 0),
                    'key' => (string) ($rowArr['slug'] ?? ''),
                    'title' => (string) ($rowArr['name'] ?? ($rowArr['slug'] ?? '')),
                    'visual_group' => (string) ($rowArr['visual_group'] ?? ''),
                ];
            }
        }

        if (empty($conditions)) {
            $conditions = array_map(
                static fn ($row) => [
                    'key' => (string) ($row['key'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                ],
                $this->generation->getConditions(),
            );
        }

        $moonPhases = array_map(
            static fn ($row) => [
                'phase' => (string) ($row['phase'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
            ],
            $this->generation->getMoonPhases(),
        );

        $seasons = [];
        if ($this->climate->isAvailable()) {
            $seasonRows = $this->climate->listSeasons(true);
            foreach ($seasonRows as $row) {
                $rowArr = (array) $row;
                $seasons[] = [
                    'id' => (int) ($rowArr['id'] ?? 0),
                    'slug' => (string) ($rowArr['slug'] ?? ''),
                    'title' => (string) ($rowArr['name'] ?? ''),
                ];
            }
        }

        return [
            'dataset' => [
                'conditions' => $conditions,
                'moon_phases' => $moonPhases,
                'seasons' => $seasons,
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
        return $this->climate->isAvailable();
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
        return $this->climate->getWeatherTypeBySlug($slug);
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

    public function listClimateAreas($filtersOrActiveOnly = false): array
    {
        return $this->override->listClimateAreas($filtersOrActiveOnly);
    }

    public function createClimateArea(object $data): array
    {
        return $this->override->createClimateArea($data);
    }

    public function updateClimateArea(object $data): array
    {
        return $this->override->updateClimateArea($data);
    }

    public function deleteClimateArea(int $id): void
    {
        $this->override->deleteClimateArea($id);
    }

    public function assignLocationToClimateArea(int $locationId, ?int $climateAreaId): void
    {
        $this->override->assignLocationToClimateArea($locationId, $climateAreaId);
    }

    public function listWeatherTypes($filtersOrActiveOnly = false): array
    {
        return $this->climate->listWeatherTypes($filtersOrActiveOnly);
    }

    public function createWeatherType(object $data): array
    {
        return $this->climate->createWeatherType($data);
    }

    public function updateWeatherType(object $data): array
    {
        return $this->climate->updateWeatherType($data);
    }

    public function deleteWeatherType(int $id): void
    {
        $this->climate->deleteWeatherType($id);
    }

    public function listSeasons($filtersOrActiveOnly = false): array
    {
        return $this->climate->listSeasons($filtersOrActiveOnly);
    }

    public function createSeason(object $data): array
    {
        return $this->climate->createSeason($data);
    }

    public function updateSeason(object $data): array
    {
        return $this->climate->updateSeason($data);
    }

    public function deleteSeason(int $id): void
    {
        $this->climate->deleteSeason($id);
    }

    public function listClimateZones($filtersOrActiveOnly = false): array
    {
        return $this->climate->listClimateZones($filtersOrActiveOnly);
    }

    public function createClimateZone(object $data): array
    {
        return $this->climate->createClimateZone($data);
    }

    public function updateClimateZone(object $data): array
    {
        return $this->climate->updateClimateZone($data);
    }

    public function deleteClimateZone(int $id): void
    {
        $this->climate->deleteClimateZone($id);
    }

    public function listSeasonProfiles(array $filters = []): array
    {
        return $this->climate->listSeasonProfiles($filters);
    }

    public function upsertSeasonProfile(object $data): array
    {
        return $this->climate->upsertSeasonProfile($data);
    }

    public function deleteSeasonProfile(int $id): void
    {
        $this->climate->deleteSeasonProfile($id);
    }

    public function listProfileWeights(int $profileId): array
    {
        return $this->climate->listProfileWeights($profileId);
    }

    public function syncProfileWeights(int $profileId, array $weights): array
    {
        return $this->climate->syncProfileWeights($profileId, $weights);
    }

    public function listAssignments(array $filters = []): array
    {
        return $this->climate->listAssignments($filters);
    }

    public function upsertAssignment(object $data): array
    {
        return $this->climate->upsertAssignment($data);
    }

    public function deleteAssignment(int $id): void
    {
        $this->climate->deleteAssignment($id);
    }

    public function listOverrides(array $filters = []): array
    {
        return $this->climate->listOverrides($filters);
    }

    public function upsertOverride(object $data, ?int $actorUserId = null): array
    {
        return $this->climate->upsertOverride($data, $actorUserId);
    }

    public function deleteOverride(int $id): void
    {
        $this->climate->deleteOverride($id);
    }
}
