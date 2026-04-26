<?php

declare(strict_types=1);

namespace Modules\Logeon\Weather\Services;

trait WeatherClimateRuntimeTrait
{
    // ---------------------------------------------------------------------
    // Resolver (new natural climate engine + weather_overrides)
    // ---------------------------------------------------------------------

    public function resolveForLocation(
        int $locationId,
        ?int $worldId = null,
        int $baseTemperature = 12,
        ?int $timestamp = null,
    ): ?array {
        if (!$this->isAvailable()) {
            return null;
        }
        if ($locationId <= 0) {
            return null;
        }

        $ts = ($timestamp !== null && $timestamp > 0) ? $timestamp : time();
        $season = $this->resolveActiveSeason($ts);
        if (empty($season)) {
            return null;
        }

        $mapId = $this->resolveMapIdByLocation($locationId);
        $assignment = $this->resolveBestAssignment($locationId, $mapId, $worldId);

        $climateZone = null;
        $profile = null;
        $temperature = (int) $baseTemperature;
        $weatherType = null;

        if (!empty($assignment)) {
            $climateZone = $this->getClimateZone((int) ($assignment['climate_zone_id'] ?? 0));
            if (!empty($climateZone)) {
                $profile = $this->resolveProfileByZoneAndSeason(
                    (int) $climateZone['id'],
                    (int) ($season['id'] ?? 0),
                );
                $temperature = $this->resolveTemperatureValue($profile, $baseTemperature, $ts, $locationId);
                $weatherType = $this->resolveWeightedWeatherType($profile, $ts, $locationId);
            }
        }

        if (empty($weatherType)) {
            $weatherType = $this->fallbackWeatherType();
        }

        $override = $this->resolveBestOverride($locationId, $mapId, $worldId, $ts);
        $sourceType = 'climate_assignment';
        $scopeType = !empty($assignment) ? ((string) ($assignment['scope_type'] ?? 'world')) : 'world';
        $scopeId = !empty($assignment) ? (int) ($assignment['scope_id'] ?? 0) : ($worldId ?: 1);
        $overrideActive = false;
        $overrideReason = null;
        $overrideExpiresAt = null;

        if (!empty($override)) {
            $overrideActive = true;
            $sourceType = 'weather_override';
            $scopeType = (string) ($override['scope_type'] ?? $scopeType);
            $scopeId = (int) ($override['scope_id'] ?? $scopeId);
            $overrideReason = isset($override['reason']) ? (string) $override['reason'] : null;
            $overrideExpiresAt = isset($override['expires_at'])
                ? (string) $override['expires_at']
                : null;

            if (!empty($override['weather_type_id'])) {
                $forced = $this->getWeatherType((int) $override['weather_type_id']);
                if (!empty($forced)) {
                    $weatherType = $forced;
                }
            }
            if (isset($override['temperature_override']) && $override['temperature_override'] !== '') {
                $temperature = (int) round((float) $override['temperature_override']);
            }
        }

        $condition = $this->buildConditionPayload($weatherType);
        return [
            'season' => $season,
            'climate_zone' => $climateZone ? [
                'id' => (int) ($climateZone['id'] ?? 0),
                'name' => (string) ($climateZone['name'] ?? ''),
                'slug' => (string) ($climateZone['slug'] ?? ''),
            ] : null,
            'profile' => $profile,
            'weather_type' => $weatherType ? [
                'id' => (int) ($weatherType['id'] ?? 0),
                'name' => (string) ($weatherType['name'] ?? ''),
                'slug' => (string) ($weatherType['slug'] ?? ''),
                'visual_group' => (string) ($weatherType['visual_group'] ?? ''),
            ] : null,
            'weather' => $condition,
            'condition' => (string) ($condition['key'] ?? ''),
            'temperature_value' => (int) $temperature,
            'temperatures' => [
                'degrees' => (int) $temperature,
                'minus' => (int) $temperature,
            ],
            'source_type' => $sourceType,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId > 0 ? $scopeId : null,
            'override_active' => $overrideActive,
            'override_reason' => $overrideReason,
            'override_expires_at' => $overrideExpiresAt,
        ];
    }

    public function resolveBestOverride(int $locationId, ?int $mapId, ?int $worldId, int $timestamp): ?array
    {
        $this->ensureCoreTables();
        $targets = [];
        $targetParams = [];
        if ($locationId > 0) {
            $targets[] = '(o.scope_type = ? AND o.scope_id = ?)';
            $targetParams[] = 'location';
            $targetParams[] = $locationId;
        }
        if ($mapId !== null && $mapId > 0) {
            $targets[] = '(o.scope_type = ? AND o.scope_id = ?)';
            $targetParams[] = 'map';
            $targetParams[] = $mapId;
        }
        if ($worldId !== null && $worldId > 0) {
            $targets[] = '(o.scope_type = ? AND o.scope_id = ?)';
            $targetParams[] = 'world';
            $targetParams[] = $worldId;
        }
        if (empty($targets)) {
            return null;
        }

        $now = date('Y-m-d H:i:s', $timestamp);
        $params = array_merge($targetParams, [$now, $now]);
        $row = $this->firstPrepared(
            'SELECT o.*
             FROM weather_overrides o
             WHERE o.is_active = 1
               AND (' . implode(' OR ', $targets) . ')
               AND (o.starts_at IS NULL OR o.starts_at <= ?)
               AND (o.expires_at IS NULL OR o.expires_at >= ?)
             ORDER BY
               CASE o.scope_type
                    WHEN \'location\' THEN 500
                    WHEN \'region\' THEN 430
                    WHEN \'area\' THEN 420
                    WHEN \'map\' THEN 400
                    WHEN \'world\' THEN 300
                    ELSE 100
               END DESC,
               o.date_updated DESC,
               o.id DESC
             LIMIT 1',
            $params,
        );

        return !empty($row) ? (array) $row : null;
    }

    public function resolveActiveSeason(int $timestamp): ?array
    {
        $this->ensureCoreTables();
        $mode = strtolower(trim((string) $this->readConfigValue('weather_season_mode', 'auto')));
        if ($mode === '') {
            $mode = 'auto';
        }

        if ($mode === 'manual') {
            $seasonId = (int) ((string) $this->readConfigValue('weather_active_season_id', '0'));
            if ($seasonId > 0) {
                $season = $this->getSeason($seasonId);
                if (!empty($season) && (int) ($season['is_active'] ?? 0) === 1) {
                    return [
                        'id' => (int) ($season['id'] ?? 0),
                        'name' => (string) ($season['name'] ?? ''),
                        'slug' => (string) ($season['slug'] ?? ''),
                    ];
                }
            }
        }

        $activeSeasons = $this->listSeasons(true);
        if (empty($activeSeasons)) {
            return null;
        }

        $month = (int) date('n', $timestamp);
        $day = (int) date('j', $timestamp);

        foreach ($activeSeasons as $season) {
            $startMonth = isset($season->starts_at_month) ? (int) $season->starts_at_month : 0;
            $startDay = isset($season->starts_at_day) ? (int) $season->starts_at_day : 0;
            $endMonth = isset($season->ends_at_month) ? (int) $season->ends_at_month : 0;
            $endDay = isset($season->ends_at_day) ? (int) $season->ends_at_day : 0;

            if ($startMonth <= 0 || $startDay <= 0 || $endMonth <= 0 || $endDay <= 0) {
                continue;
            }

            if ($this->isDayInSeasonRange($month, $day, $startMonth, $startDay, $endMonth, $endDay)) {
                return [
                    'id' => (int) ($season->id ?? 0),
                    'name' => (string) ($season->name ?? ''),
                    'slug' => (string) ($season->slug ?? ''),
                ];
            }
        }

        $first = $activeSeasons[0];
        return [
            'id' => (int) ($first->id ?? 0),
            'name' => (string) ($first->name ?? ''),
            'slug' => (string) ($first->slug ?? ''),
        ];
    }

    private function isDayInSeasonRange(
        int $month,
        int $day,
        int $startMonth,
        int $startDay,
        int $endMonth,
        int $endDay,
    ): bool {
        $current = $month * 100 + $day;
        $start = $startMonth * 100 + $startDay;
        $end = $endMonth * 100 + $endDay;

        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }
        return $current >= $start || $current <= $end;
    }

    private function resolveMapIdByLocation(int $locationId): ?int
    {
        if ($locationId <= 0 || !$this->tableExists('locations')) {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT map_id FROM locations WHERE id = ? LIMIT 1',
            [$locationId],
        );
        if (empty($row) || !isset($row->map_id)) {
            return null;
        }
        $mapId = (int) $row->map_id;
        return $mapId > 0 ? $mapId : null;
    }

    private function resolveBestAssignment(int $locationId, ?int $mapId, ?int $worldId): ?array
    {
        $targets = [];
        $targetParams = [];
        if ($locationId > 0) {
            $targets[] = '(ca.scope_type = ? AND ca.scope_id = ?)';
            $targetParams[] = 'location';
            $targetParams[] = $locationId;
        }
        if ($mapId !== null && $mapId > 0) {
            $targets[] = '(ca.scope_type = ? AND ca.scope_id = ?)';
            $targetParams[] = 'map';
            $targetParams[] = $mapId;
        }
        if ($worldId !== null && $worldId > 0) {
            $targets[] = '(ca.scope_type = ? AND ca.scope_id = ?)';
            $targetParams[] = 'world';
            $targetParams[] = $worldId;
        }

        $fallbackScope = strtolower(trim((string) $this->readConfigValue('weather_fallback_scope_type', 'world')));
        $fallbackScopeId = (int) ((string) $this->readConfigValue('weather_fallback_scope_id', '1'));
        if ($fallbackScope !== '' && $fallbackScopeId > 0) {
            $targets[] = '(ca.scope_type = ? AND ca.scope_id = ?)';
            $targetParams[] = $fallbackScope;
            $targetParams[] = $fallbackScopeId;
        }

        if (empty($targets)) {
            return null;
        }

        $row = $this->firstPrepared(
            'SELECT ca.*
             FROM climate_assignments ca
             WHERE ca.is_active = 1
               AND (' . implode(' OR ', $targets) . ')
             ORDER BY
               CASE ca.scope_type
                    WHEN \'location\' THEN 500
                    WHEN \'region\' THEN 430
                    WHEN \'area\' THEN 420
                    WHEN \'map\' THEN 400
                    WHEN \'world\' THEN 300
                    ELSE 100
               END DESC,
               ca.priority DESC,
               ca.id DESC
             LIMIT 1',
            $targetParams,
        );

        return !empty($row) ? (array) $row : null;
    }

    private function resolveProfileByZoneAndSeason(int $zoneId, int $seasonId): ?array
    {
        if ($zoneId <= 0 || $seasonId <= 0) {
            return null;
        }
        $row = $this->firstPrepared(
            'SELECT *
             FROM climate_zone_season_profiles
             WHERE climate_zone_id = ?
               AND season_id = ?
               AND is_active = 1
             LIMIT 1',
            [$zoneId, $seasonId],
        );

        return !empty($row) ? (array) $row : null;
    }

    private function resolveTemperatureValue(?array $profile, int $baseTemperature, int $timestamp, int $salt): int
    {
        $min = $profile !== null && isset($profile['temperature_min'])
            ? (float) $profile['temperature_min']
            : (float) ($baseTemperature - 3);
        $max = $profile !== null && isset($profile['temperature_max'])
            ? (float) $profile['temperature_max']
            : (float) ($baseTemperature + 3);

        if ($min > $max) {
            $tmp = $min;
            $min = $max;
            $max = $tmp;
        }

        $seed = $this->seedValue('temp|' . (string) ($profile['id'] ?? '0') . '|' . date('YmdH', $timestamp) . '|' . $salt);
        $value = $min + (($max - $min) * $seed);

        $roundMode = strtolower((string) ($profile['temperature_round_mode'] ?? 'round'));
        if ($roundMode === 'none') {
            return (int) round($value);
        }
        if ($roundMode === 'floor') {
            return (int) floor($value);
        }
        if ($roundMode === 'ceil') {
            return (int) ceil($value);
        }
        return (int) round($value);
    }

    private function resolveWeightedWeatherType(?array $profile, int $timestamp, int $salt): ?array
    {
        if ($profile === null || empty($profile['id'])) {
            return $this->fallbackWeatherType();
        }
        $profileId = (int) $profile['id'];
        $rows = $this->fetchPrepared(
            'SELECT w.weather_type_id, w.weight
             FROM climate_zone_weather_weights w
             INNER JOIN weather_types wt ON wt.id = w.weather_type_id
             WHERE w.profile_id = ?
               AND w.is_active = 1
               AND wt.is_active = 1
             ORDER BY wt.sort_order ASC, wt.id ASC',
            [$profileId],
        );

        $entries = [];
        $sum = 0.0;
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $typeId = isset($row->weather_type_id) ? (int) $row->weather_type_id : 0;
                $weight = isset($row->weight) ? (float) $row->weight : 0.0;
                if ($typeId <= 0 || $weight <= 0) {
                    continue;
                }
                $entries[] = ['weather_type_id' => $typeId, 'weight' => $weight];
                $sum += $weight;
            }
        }

        if ($sum > 0 && !empty($entries)) {
            $seed = $this->seedValue('weather|' . $profileId . '|' . date('YmdH', $timestamp) . '|' . $salt);
            $target = $seed * $sum;
            $cursor = 0.0;
            foreach ($entries as $entry) {
                $cursor += (float) $entry['weight'];
                if ($target <= $cursor) {
                    $picked = $this->getWeatherType((int) $entry['weather_type_id']);
                    if (!empty($picked)) {
                        return $picked;
                    }
                }
            }
        }

        if (!empty($profile['default_weather_type_id'])) {
            $default = $this->getWeatherType((int) $profile['default_weather_type_id']);
            if (!empty($default)) {
                return $default;
            }
        }

        return $this->fallbackWeatherType();
    }

    private function fallbackWeatherType(): ?array
    {
        $row = $this->firstPrepared(
            'SELECT * FROM weather_types
             WHERE is_active = 1
             ORDER BY sort_order ASC, id ASC
             LIMIT 1',
            [],
        );

        return !empty($row) ? (array) $row : null;
    }

    private function seedValue(string $seedSource): float
    {
        $hash = sha1($seedSource);
        $hex = substr($hash, 0, 8);
        $dec = hexdec($hex);
        return ((float) ($dec % 1000000)) / 1000000.0;
    }

    public function buildConditionPayload(?array $weatherType): array
    {
        if ($weatherType === null || empty($weatherType['slug'])) {
            $fallback = $this->generation->getConditionByKey('clear');
            return $fallback ?: ['key' => 'clear', 'title' => 'Sereno', 'img' => '', 'body' => ''];
        }

        $slug = (string) $weatherType['slug'];
        $title = (string) ($weatherType['name'] ?? $slug);
        $visualGroup = strtolower(trim((string) ($weatherType['visual_group'] ?? $slug)));

        $templateKey = 'clear';
        if (in_array($visualGroup, ['clear'], true)) {
            $templateKey = 'clear';
        } elseif (in_array($visualGroup, ['variable'], true)) {
            $templateKey = 'variable';
        } elseif (in_array($visualGroup, ['cloudy', 'fog'], true)) {
            $templateKey = 'cloudy';
        } elseif (in_array($visualGroup, ['rain', 'storm'], true)) {
            $templateKey = 'rain';
        } elseif (in_array($visualGroup, ['snow'], true)) {
            $templateKey = 'snow';
        } elseif (in_array($slug, ['variable', 'cloudy', 'rain', 'snow', 'clear'], true)) {
            $templateKey = $slug;
        }

        $template = $this->generation->getConditionByKey($templateKey);
        if ($template === null) {
            $template = $this->generation->getConditionByKey('clear');
        }

        return [
            'id' => (int) ($weatherType['id'] ?? 0),
            'key' => $slug,
            'title' => $title,
            'img' => '',
            'body' => (string) ($template['body'] ?? ''),
            'visual_group' => $visualGroup,
            'is_precipitation' => (int) ($weatherType['is_precipitation'] ?? 0),
            'is_snow' => (int) ($weatherType['is_snow'] ?? 0),
            'is_storm' => (int) ($weatherType['is_storm'] ?? 0),
            'reduces_visibility' => (int) ($weatherType['reduces_visibility'] ?? 0),
        ];
    }
}
