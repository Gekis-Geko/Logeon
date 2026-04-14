<?php

declare(strict_types=1);

namespace App\Services;

/**
 * WeatherResolverService
 *
 * Single canonical entry-point for reading effective weather.
 * Resolves weather hierarchically:
 *
 *   auto-generated
 *     -> global override (sys_configs)
 *       -> world override (optional, sys_configs keyed by world)
 *         -> climate area weather (if location is in an area)
 *           -> location override (highest priority)
 *
 * Each field (condition, temperature, moon_phase) is resolved
 * independently so partial overrides at any level are supported.
 *
 * The returned payload is backward-compatible with previous
 * Weathers runtime payloads and includes new normalized fields.
 */
class WeatherResolverService
{
    /** @var WeatherGenerationService */
    private $gen;
    /** @var WeatherOverrideService */
    private $override;
    /** @var WeatherClimateService */
    private $climate;

    /** @var string Moon image base path (resolved from CONFIG) */
    private $moonImgBase;

    public function __construct(
        WeatherGenerationService $gen,
        WeatherOverrideService   $override,
        WeatherClimateService    $climate = null,
    ) {
        $this->gen = $gen;
        $this->override = $override;
        $this->climate = $climate ?: new WeatherClimateService(null, $gen);

        $this->moonImgBase = defined('CONFIG')
            ? (CONFIG['dirs']['imgs'] ?? '') . '/weather/moon-phases'
            : '';
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Resolve effective weather for a specific location.
     *
     * Resolution order (lowest -> highest priority):
     *   1. Auto-generated seed
     *   2. Global override fields
     *   3. World override fields (optional)
     *   4. Climate area fields (if location has one)
     *   5. Location override fields
     *
     * @param int $locationId 0 = resolve global/world only
     */
    public function resolveForLocation(
        int $locationId,
        int $baseTemperature = 12,
        string $renderMode = 'animated',
        string $imageBaseUrl = '',
        ?int $worldId = null,
    ): array {
        $auto = $this->gen->generate($baseTemperature);

        // Mutable state: start with auto-generated values
        $condition = $auto['condition'];
        $temperatures = $auto['temperatures'];
        $moonData = $this->attachMoonImage($auto['moon_phase_data']);

        $scope = 'global';
        $overrideActive = false;
        $overrideExpires = null;
        $overrideReason = null;
        $seasonData = null;
        $climateZoneData = null;

        // 2. Global override
        $globalOverride = $this->resolveGlobalOverrideObject();
        if ($globalOverride !== null) {
            $this->applyOverrideFields($globalOverride, $condition, $temperatures, $moonData);
            $scope = 'global';
            $overrideActive = true;
        }

        // 3. World override (optional)
        $worldOverride = null;
        if ($worldId !== null && $worldId > 0) {
            $worldOverride = $this->resolveWorldOverrideObject($worldId);
            if ($worldOverride !== null) {
                $this->applyOverrideFields($worldOverride, $condition, $temperatures, $moonData);
                $scope = 'world';
                $overrideActive = true;
            }
        }

        // 3.5 New climate engine (natural resolution + weather_overrides table)
        if ($locationId > 0 && $this->climate !== null && $this->climate->isAvailable()) {
            try {
                $climateState = $this->climate->resolveForLocation($locationId, $worldId, $baseTemperature);
                if (is_array($climateState) && !empty($climateState)) {
                    if (isset($climateState['weather']) && is_array($climateState['weather'])) {
                        $condition = $climateState['weather'];
                    }
                    if (isset($climateState['temperature_value'])) {
                        $degrees = (int) $climateState['temperature_value'];
                        $temperatures['degrees'] = $degrees;
                        $temperatures['minus'] = $degrees;
                    }
                    if (!empty($climateState['season']) && is_array($climateState['season'])) {
                        $seasonData = $climateState['season'];
                    }
                    if (!empty($climateState['climate_zone']) && is_array($climateState['climate_zone'])) {
                        $climateZoneData = $climateState['climate_zone'];
                    }

                    $scope = isset($climateState['scope_type']) ? (string) $climateState['scope_type'] : $scope;
                    if (isset($climateState['override_active'])) {
                        $overrideActive = ((int) $climateState['override_active'] === 1) || ($climateState['override_active'] === true);
                    }
                    if (!empty($climateState['override_expires_at'])) {
                        $overrideExpires = (string) $climateState['override_expires_at'];
                    }
                    if (!empty($climateState['override_reason'])) {
                        $overrideReason = (string) $climateState['override_reason'];
                    }
                }
            } catch (\Throwable $e) {
                // Silent fallback: keep canonical legacy resolution.
            }
        }

        // Global/non-location fallback:
        // even when the character is "Alle mappe" (no location),
        // expose the active season if climate catalogs are available.
        if ($seasonData === null && $this->climate !== null && $this->climate->isAvailable()) {
            try {
                $activeSeason = $this->climate->resolveActiveSeason(time());
                if (is_array($activeSeason) && !empty($activeSeason)) {
                    $seasonData = $activeSeason;
                }
            } catch (\Throwable $e) {
                // Keep null season fallback for backward compatibility.
            }
        }

        // 4. Climate area override
        $areaData = null;
        if ($locationId > 0) {
            $areaData = $this->override->getClimateAreaForLocation($locationId);
            if ($areaData !== null) {
                $areaOverride = (object) [
                    'weather_key' => $areaData['weather_key'] ?? null,
                    'degrees' => $areaData['degrees'] ?? null,
                    'moon_phase' => $areaData['moon_phase'] ?? null,
                ];
                if ($this->overrideHasAnyField($areaOverride)) {
                    $this->applyOverrideFields($areaOverride, $condition, $temperatures, $moonData);
                    $scope = 'climate_area';
                    $overrideActive = true;
                    $overrideReason = null;
                }
            }
        }

        // 5. Location override
        $locationOverride = null;
        if ($locationId > 0) {
            $locationOverride = $this->override->getLocationOverride($locationId);
            if ($locationOverride !== null) {
                $this->applyOverrideFields($locationOverride, $condition, $temperatures, $moonData);
                $scope = 'location';
                $overrideActive = true;
                $overrideReason = !empty($locationOverride->note) ? (string) $locationOverride->note : null;
                if (!empty($locationOverride->expires_at)) {
                    $overrideExpires = (string) $locationOverride->expires_at;
                }
            }
        }

        $renderMode = $this->normalizeRenderMode($renderMode);
        if ($renderMode === 'image') {
            $condition = $this->attachImageData($condition, $imageBaseUrl);
        }

        return $this->buildPayload(
            $scope,
            $locationId > 0 ? $locationId : null,
            $worldId,
            $renderMode,
            $condition,
            $temperatures,
            $moonData,
            $locationOverride,
            $globalOverride,
            $worldOverride,
            $areaData,
            $overrideActive,
            $overrideExpires,
            $overrideReason,
            $seasonData,
            $climateZoneData,
        );
    }

    /**
     * Resolve global weather only (no location context).
     */
    public function resolveGlobal(int $baseTemperature = 12, string $renderMode = 'animated', string $imageBaseUrl = ''): array
    {
        return $this->resolveForLocation(0, $baseTemperature, $renderMode, $imageBaseUrl, null);
    }

    /**
     * Resolve weather for a world scope.
     * World override keys in sys_configs:
     * - weather_world_{id}_key
     * - weather_world_{id}_degrees
     * - weather_world_{id}_moon_phase
     */
    public function resolveForWorld(int $worldId, int $baseTemperature = 12, string $renderMode = 'animated', string $imageBaseUrl = ''): array
    {
        if ($worldId <= 0) {
            return $this->resolveGlobal($baseTemperature, $renderMode, $imageBaseUrl);
        }

        return $this->resolveForLocation(0, $baseTemperature, $renderMode, $imageBaseUrl, $worldId);
    }

    /**
     * Resolve weather for a climate area directly.
     * Returns auto-generated weather with climate area fields applied.
     */
    public function resolveForArea(int $climateAreaId, int $baseTemperature = 12, string $renderMode = 'animated', string $imageBaseUrl = ''): array
    {
        $auto = $this->gen->generate($baseTemperature);

        $condition = $auto['condition'];
        $temperatures = $auto['temperatures'];
        $moonData = $this->attachMoonImage($auto['moon_phase_data']);
        $scope = 'global';

        $areaData = $this->override->getClimateArea($climateAreaId);
        if ($areaData !== null) {
            $areaOverride = (object) [
                'weather_key' => $areaData['weather_key'] ?? null,
                'degrees' => $areaData['degrees'] ?? null,
                'moon_phase' => $areaData['moon_phase'] ?? null,
            ];
            if ($this->overrideHasAnyField($areaOverride)) {
                $this->applyOverrideFields($areaOverride, $condition, $temperatures, $moonData);
                $scope = 'climate_area';
            }
        }

        $renderMode = $this->normalizeRenderMode($renderMode);
        if ($renderMode === 'image') {
            $condition = $this->attachImageData($condition, $imageBaseUrl);
        }

        return $this->buildPayload(
            $scope,
            null,
            null,
            $renderMode,
            $condition,
            $temperatures,
            $moonData,
            null,
            null,
            null,
            $areaData,
            $scope !== 'global',
            null,
            null,
            null,
            null,
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Apply weather_key, degrees, moon_phase from an override object.
     * Only applies fields that are non-null in the override.
     */
    private function applyOverrideFields(
        object $override,
        array &$condition,
        array &$temperatures,
        array &$moonData,
    ): void {
        if (!empty($override->weather_key)) {
            $forced = $this->gen->getConditionByKey((string) $override->weather_key);
            if ($forced !== null) {
                $condition = $forced;
            }
        }

        if ($override->degrees !== null && $override->degrees !== '') {
            $temperatures['degrees'] = (int) $override->degrees;
        }

        if (!empty($override->moon_phase)) {
            $forcedMoon = $this->gen->getMoonPhaseByPhase((string) $override->moon_phase);
            if ($forcedMoon !== null) {
                $moonData = $this->attachMoonImage($forcedMoon);
            }
        }
    }

    private function overrideHasAnyField(object $override): bool
    {
        return !empty($override->weather_key)
            || ($override->degrees !== null && $override->degrees !== '')
            || !empty($override->moon_phase);
    }

    /**
     * Parse global override rows from sys_configs into a usable object.
     */
    private function resolveGlobalOverrideObject(): ?object
    {
        $rows = $this->override->getGlobalOverrideRows();
        if (empty($rows)) {
            return null;
        }

        $result = (object) ['weather_key' => null, 'degrees' => null, 'moon_phase' => null];

        foreach ($rows as $row) {
            $value = trim((string) ($row->value ?? ''));
            if ($row->key === 'weather_global_key') {
                $result->weather_key = ($value !== '') ? $value : null;
            } elseif ($row->key === 'weather_global_degrees') {
                $result->degrees = is_numeric($value) ? (int) $value : null;
            } elseif ($row->key === 'weather_global_moon_phase') {
                $result->moon_phase = ($value !== '') ? $value : null;
            }
        }

        return $this->overrideHasAnyField($result) ? $result : null;
    }

    private function resolveWorldOverrideObject(int $worldId): ?object
    {
        $rows = $this->override->getWorldOverrideRows($worldId);
        if (empty($rows)) {
            return null;
        }

        $suffixToField = [
            'key' => 'weather_key',
            'degrees' => 'degrees',
            'moon_phase' => 'moon_phase',
        ];
        $prefix = 'weather_world_' . $worldId . '_';
        $result = (object) ['weather_key' => null, 'degrees' => null, 'moon_phase' => null];

        foreach ($rows as $row) {
            $key = trim((string) ($row->key ?? ''));
            if ($key === '' || strpos($key, $prefix) !== 0) {
                continue;
            }

            $suffix = substr($key, strlen($prefix));
            if (!isset($suffixToField[$suffix])) {
                continue;
            }

            $value = trim((string) ($row->value ?? ''));
            $field = $suffixToField[$suffix];
            if ($field === 'degrees') {
                $result->degrees = is_numeric($value) ? (int) $value : null;
            } else {
                $result->{$field} = ($value !== '') ? $value : null;
            }
        }

        return $this->overrideHasAnyField($result) ? $result : null;
    }

    private function attachMoonImage(array $moonPhase): array
    {
        if ($this->moonImgBase !== '') {
            $moonPhase['img'] = $this->moonImgBase . '/' . $moonPhase['phase'] . '.png';
        } else {
            $moonPhase['img'] = '';
        }
        return $moonPhase;
    }

    private function attachImageData(array $condition, string $imageBase): array
    {
        $base = rtrim(trim($imageBase), '/');
        if ($base !== '' && !empty($condition['key'])) {
            $condition['img'] = $base . '/' . $condition['key'] . '.png';
        }
        return $condition;
    }

    private function normalizeRenderMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        return ($mode === 'image') ? 'image' : 'animated';
    }

    /**
     * Build final payload.
     * Backward-compatible with previous runtime keys plus normalized aliases.
     */
    private function buildPayload(
        string $scope,
        ?int $locationId,
        ?int $worldId,
        string $renderMode,
        array $condition,
        array $temperatures,
        array $moonData,
        $locationOverride,
        $globalOverride,
        $worldOverride,
        ?array $areaData,
        bool $overrideActive,
        ?string $overrideExpires,
        ?string $overrideReason,
        ?array $seasonData,
        ?array $climateZoneData,
    ): array {
        $sourceType = $this->mapScopeToSourceType($scope);
        if ($overrideActive === false) {
            if ($scope === 'global') {
                $sourceType = 'auto';
            } else {
                $sourceType = 'climate_assignment';
            }
        }
        $scopeId = null;
        if ($scope === 'location') {
            $scopeId = $locationId;
        } elseif ($scope === 'climate_area') {
            $scopeId = isset($areaData['id']) ? (int) $areaData['id'] : null;
        } elseif ($scope === 'world') {
            $scopeId = $worldId;
        } elseif ($scope === 'map' || $scope === 'area' || $scope === 'region') {
            $scopeId = null;
        }

        return [
            // Backward-compatible fields
            'scope' => $scope,
            'location_id' => $locationId,
            'world_id' => $worldId,
            'render_mode' => $renderMode,
            'weather' => $condition,
            'moon' => $moonData,
            'temperatures' => $temperatures,
            'location_override_data' => $locationOverride,
            'global_override_data' => $globalOverride,
            'world_override_data' => $worldOverride,

            // Normalized aliases
            'condition' => $condition['key'] ?? '',
            'temperature_value' => (int) ($temperatures['degrees'] ?? 0),
            'moon_phase' => $moonData['phase'] ?? '',
            'source_type' => $sourceType,
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'override_active' => $overrideActive,
            'override_expires_at' => $overrideExpires,
            'override_reason' => $overrideReason,
            'is_override' => $overrideActive,
            'season' => $seasonData,
            'climate_zone' => $climateZoneData,
            'climate_area' => $areaData ? [
                'id' => $areaData['id'] ?? null,
                'name' => $areaData['name'] ?? '',
                'code' => $areaData['code'] ?? '',
            ] : null,
        ];
    }

    private function mapScopeToSourceType(string $scope): string
    {
        $map = [
            'location' => 'location_override',
            'climate_area' => 'climate_area_override',
            'area' => 'area_override',
            'region' => 'region_override',
            'map' => 'map_override',
            'world' => 'world_override',
            'global' => 'global_override',
            'weather_override' => 'weather_override',
        ];

        return $map[$scope] ?? 'auto';
    }
}
