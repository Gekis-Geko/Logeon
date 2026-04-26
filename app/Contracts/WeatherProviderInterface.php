<?php

declare(strict_types=1);

namespace App\Contracts;

interface WeatherProviderInterface
{
    /**
     * Resolve effective weather state for location/world/global scope.
     *
     * @return array<string,mixed>
     */
    public function resolveState(
        int $locationId,
        ?int $worldId = null,
        int $baseTemperature = 12,
        string $renderMode = 'animated',
        string $imageBaseUrl = '',
    ): array;

    /**
     * @return array{dataset:array{conditions:array<int,array<string,mixed>>,moon_phases:array<int,array<string,mixed>>,seasons:array<int,array<string,mixed>>}}
     */
    public function options(): array;

    /**
     * @return array<string,mixed>
     */
    public function moonPhases(int $baseTemperature = 12): array;

    public function isClimateAvailable(): bool;

    /**
     * @return array<string,mixed>|null
     */
    public function getConditionByKey(string $key): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function getMoonPhaseByPhase(string $phase): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function getWeatherTypeBySlug(string $slug): ?array;

    public function locationExists(int $locationId): bool;

    public function deleteLocationOverride(int $locationId): void;

    public function upsertLocationOverride(
        int $locationId,
        ?string $weatherKey,
        ?int $degrees,
        ?string $moonPhase,
        int $updatedBy,
        ?string $expiresAt = null,
        ?string $note = null,
    ): void;

    public function clearExpiredOverrides(): int;

    public function saveGlobalOverride(?string $weatherKey, ?int $degrees, ?string $moonPhase): void;

    public function saveWorldOverride(int $worldId, ?string $weatherKey, ?int $degrees, ?string $moonPhase): void;

    public function clearWorldOverride(int $worldId): void;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function worldOptions(): array;
}
