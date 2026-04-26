<?php

declare(strict_types=1);

namespace App\Contracts;

interface WeatherAdvancedInterface extends WeatherProviderInterface
{
    /**
     * @param  array<string,mixed>|bool $filtersOrActiveOnly
     * @return array<int,array<string,mixed>>
     */
    public function listClimateAreas($filtersOrActiveOnly = false): array;

    /**
     * @return array<string,mixed>
     */
    public function createClimateArea(object $data): array;

    /**
     * @return array<string,mixed>
     */
    public function updateClimateArea(object $data): array;

    public function deleteClimateArea(int $id): void;

    public function assignLocationToClimateArea(int $locationId, ?int $climateAreaId): void;

    /**
     * @param  array<string,mixed>|bool $filtersOrActiveOnly
     * @return array<int,array<string,mixed>>
     */
    public function listWeatherTypes($filtersOrActiveOnly = false): array;

    /**
     * @return array<string,mixed>
     */
    public function createWeatherType(object $data): array;

    /**
     * @return array<string,mixed>
     */
    public function updateWeatherType(object $data): array;

    public function deleteWeatherType(int $id): void;

    /**
     * @param  array<string,mixed>|bool $filtersOrActiveOnly
     * @return array<int,array<string,mixed>>
     */
    public function listSeasons($filtersOrActiveOnly = false): array;

    /**
     * @return array<string,mixed>
     */
    public function createSeason(object $data): array;

    /**
     * @return array<string,mixed>
     */
    public function updateSeason(object $data): array;

    public function deleteSeason(int $id): void;

    /**
     * @param  array<string,mixed>|bool $filtersOrActiveOnly
     * @return array<int,array<string,mixed>>
     */
    public function listClimateZones($filtersOrActiveOnly = false): array;

    /**
     * @return array<string,mixed>
     */
    public function createClimateZone(object $data): array;

    /**
     * @return array<string,mixed>
     */
    public function updateClimateZone(object $data): array;

    public function deleteClimateZone(int $id): void;

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listSeasonProfiles(array $filters = []): array;

    /**
     * @return array<string,mixed>
     */
    public function upsertSeasonProfile(object $data): array;

    public function deleteSeasonProfile(int $id): void;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listProfileWeights(int $profileId): array;

    /**
     * @param  array<int,array<string,mixed>> $weights
     * @return array<int,array<string,mixed>>
     */
    public function syncProfileWeights(int $profileId, array $weights): array;

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listAssignments(array $filters = []): array;

    /**
     * @return array<string,mixed>
     */
    public function upsertAssignment(object $data): array;

    public function deleteAssignment(int $id): void;

    /**
     * @param  array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listOverrides(array $filters = []): array;

    /**
     * @return array<string,mixed>
     */
    public function upsertOverride(object $data, ?int $actorUserId = null): array;

    public function deleteOverride(int $id): void;
}
