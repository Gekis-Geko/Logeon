<?php

declare(strict_types=1);

namespace App\Services\Weather;

class WeatherResolverService
{
    private WeatherGenerationService $generation;
    private WeatherOverrideService $override;
    private string $moonImgBase;

    public function __construct(WeatherGenerationService $generation, WeatherOverrideService $override)
    {
        $this->generation = $generation;
        $this->override = $override;
        $this->moonImgBase = defined('CONFIG') ? ((string) CONFIG['dirs']['imgs']) . '/weather/moon-phases' : '';
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveForLocation(
        int $locationId,
        int $baseTemperature = 12,
        string $renderMode = 'animated',
        string $imageBaseUrl = '',
        ?int $worldId = null,
    ): array {
        $auto = $this->generation->generate($baseTemperature);
        /** @var array<string,string> $condition */
        $condition = $auto['condition'];
        /** @var array{degrees:int,minus:int} $temperatures */
        $temperatures = $auto['temperatures'];
        /** @var array<string,string> $moonData */
        $moonData = $this->attachMoonImage($auto['moon_phase_data']);

        $scope = 'global';
        $overrideActive = false;
        $overrideExpires = null;
        $overrideReason = null;

        $globalOverride = $this->resolveGlobalOverrideObject();
        if ($globalOverride !== null) {
            $this->applyOverrideFields($globalOverride, $condition, $temperatures, $moonData);
            $scope = 'global';
            $overrideActive = true;
        }

        $worldOverride = null;
        if ($worldId !== null && $worldId > 0) {
            $worldOverride = $this->resolveWorldOverrideObject($worldId);
            if ($worldOverride !== null) {
                $this->applyOverrideFields($worldOverride, $condition, $temperatures, $moonData);
                $scope = 'world';
                $overrideActive = true;
            }
        }

        $locationOverride = null;
        if ($locationId > 0) {
            $locationOverride = $this->override->getLocationOverride($locationId);
            if ($locationOverride !== null) {
                $this->applyOverrideFields($locationOverride, $condition, $temperatures, $moonData);
                $scope = 'location';
                $overrideActive = true;
                if (!empty($locationOverride->expires_at)) {
                    $overrideExpires = (string) $locationOverride->expires_at;
                }
                if (!empty($locationOverride->note)) {
                    $overrideReason = (string) $locationOverride->note;
                }
            }
        }

        $normalizedRenderMode = $this->normalizeRenderMode($renderMode);
        if ($normalizedRenderMode === 'image') {
            $condition = $this->attachImageData($condition, $imageBaseUrl);
        }

        return $this->buildPayload(
            $scope,
            $locationId > 0 ? $locationId : null,
            $worldId,
            $normalizedRenderMode,
            $condition,
            $temperatures,
            $moonData,
            $locationOverride,
            $globalOverride,
            $worldOverride,
            $overrideActive,
            $overrideExpires,
            $overrideReason,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveGlobal(int $baseTemperature = 12, string $renderMode = 'animated', string $imageBaseUrl = ''): array
    {
        return $this->resolveForLocation(0, $baseTemperature, $renderMode, $imageBaseUrl, null);
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveForWorld(int $worldId, int $baseTemperature = 12, string $renderMode = 'animated', string $imageBaseUrl = ''): array
    {
        if ($worldId <= 0) {
            return $this->resolveGlobal($baseTemperature, $renderMode, $imageBaseUrl);
        }

        return $this->resolveForLocation(0, $baseTemperature, $renderMode, $imageBaseUrl, $worldId);
    }

    /**
     * @param array<string,string> $condition
     * @param array{degrees:int,minus:int} $temperatures
     * @param array<string,string> $moonData
     */
    private function applyOverrideFields(
        object $override,
        array &$condition,
        array &$temperatures,
        array &$moonData,
    ): void {
        if (!empty($override->weather_key)) {
            $forced = $this->generation->getConditionByKey((string) $override->weather_key);
            if ($forced !== null) {
                $condition = $forced;
            }
        }

        if ($override->degrees !== null && $override->degrees !== '') {
            $temperatures['degrees'] = (int) $override->degrees;
        }

        if (!empty($override->moon_phase)) {
            $forcedMoon = $this->generation->getMoonPhaseByPhase((string) $override->moon_phase);
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

        $prefix = 'weather_world_' . $worldId . '_';
        $result = (object) ['weather_key' => null, 'degrees' => null, 'moon_phase' => null];
        foreach ($rows as $row) {
            $key = trim((string) ($row->key ?? ''));
            if ($key === '' || strpos($key, $prefix) !== 0) {
                continue;
            }

            $suffix = substr($key, strlen($prefix));
            $value = trim((string) ($row->value ?? ''));
            if ($suffix === 'key') {
                $result->weather_key = ($value !== '') ? $value : null;
            } elseif ($suffix === 'degrees') {
                $result->degrees = is_numeric($value) ? (int) $value : null;
            } elseif ($suffix === 'moon_phase') {
                $result->moon_phase = ($value !== '') ? $value : null;
            }
        }

        return $this->overrideHasAnyField($result) ? $result : null;
    }

    /**
     * @param array<string,string> $moonPhase
     * @return array<string,string>
     */
    private function attachMoonImage(array $moonPhase): array
    {
        $moonPhase['img'] = ($this->moonImgBase !== '')
            ? ($this->moonImgBase . '/' . ($moonPhase['phase'] ?? '') . '.png')
            : '';
        return $moonPhase;
    }

    /**
     * @param array<string,string> $condition
     * @return array<string,string>
     */
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
        return strtolower(trim($mode)) === 'image' ? 'image' : 'animated';
    }

    /**
     * @param array<string,string> $condition
     * @param array{degrees:int,minus:int} $temperatures
     * @param array<string,string> $moonData
     * @return array<string,mixed>
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
        bool $overrideActive,
        ?string $overrideExpires,
        ?string $overrideReason,
    ): array {
        $sourceType = $this->mapScopeToSourceType($scope);
        if ($overrideActive === false) {
            $sourceType = 'auto';
        }

        $scopeId = null;
        if ($scope === 'location') {
            $scopeId = $locationId;
        } elseif ($scope === 'world') {
            $scopeId = $worldId;
        }

        return [
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
            'condition' => $condition['key'] ?? '',
            'temperature_value' => (int) $temperatures['degrees'],
            'moon_phase' => $moonData['phase'] ?? '',
            'source_type' => $sourceType,
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'override_active' => $overrideActive,
            'override_expires_at' => $overrideExpires,
            'override_reason' => $overrideReason,
            'is_override' => $overrideActive,
            'season' => null,
            'climate_zone' => null,
            'climate_area' => null,
        ];
    }

    private function mapScopeToSourceType(string $scope): string
    {
        $map = [
            'location' => 'location_override',
            'world' => 'world_override',
            'global' => 'global_override',
            'weather_override' => 'weather_override',
        ];

        return $map[$scope] ?? 'auto';
    }
}
