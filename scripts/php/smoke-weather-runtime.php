<?php

declare(strict_types=1);

/**
 * Weather runtime smoke (CLI).
 *
 * Coverage:
 * - Weather resolver global/location/climate_area/world paths
 * - Expired override auto-ignore behavior
 * - World override compatibility keys in sys_configs
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-weather-runtime.php
 */

$root = dirname(__DIR__, 2);

$bootstrap = [
    $root . '/configs/config.php',
    $root . '/configs/db.php',
    $root . '/configs/app.php',
    $root . '/vendor/autoload.php',
];

foreach ($bootstrap as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "[FAIL] Missing bootstrap file: {$file}\n");
        exit(1);
    }
    require_once $file;
}

$customBootstrap = $root . '/custom/bootstrap.php';
if (is_file($customBootstrap)) {
    require_once $customBootstrap;
}

use App\Services\WeatherGenerationService;
use App\Services\WeatherOverrideService;
use App\Services\WeatherResolverService;
use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;

function weatherSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function weatherSmokeStep(string $label): void
{
    fwrite(STDOUT, "[STEP] {$label}\n");
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $db = DbAdapterFactory::createFromConfig();
    weatherSmokeAssert($db instanceof MysqliDbAdapter, 'DbAdapterFactory must return MysqliDbAdapter.');

    $gen = new WeatherGenerationService();
    $override = new WeatherOverrideService($db);
    $resolver = new WeatherResolverService($gen, $override);

    $conditions = $gen->getConditions();
    $moonPhases = $gen->getMoonPhases();
    weatherSmokeAssert(!empty($conditions), 'Weather conditions catalog is empty.');
    weatherSmokeAssert(!empty($moonPhases), 'Moon phases catalog is empty.');

    $weatherKey = (string) ($conditions[0]['key'] ?? '');
    $moonPhase = (string) ($moonPhases[0]['phase'] ?? '');
    weatherSmokeAssert($weatherKey !== '', 'Invalid smoke weather key.');
    weatherSmokeAssert($moonPhase !== '', 'Invalid smoke moon phase.');

    $locationRow = $db->query('SELECT id FROM locations ORDER BY id ASC LIMIT 1')->first();
    weatherSmokeAssert(!empty($locationRow), 'No location found for weather smoke.');
    $locationId = (int) ($locationRow->id ?? 0);
    weatherSmokeAssert($locationId > 0, 'Invalid location id for weather smoke.');

    $supportsClimateAreas = false;
    $climateAreaId = 0;
    $worldId = 9901;

    $cleanup = [
        'location_override' => false,
        'global_override' => false,
        'world_override' => false,
        'climate_area' => false,
    ];

    try {
        weatherSmokeStep('global resolver');
        $global = $resolver->resolveGlobal(12, 'animated', '');
        weatherSmokeAssert(isset($global['weather'], $global['temperatures'], $global['moon']), 'Global resolver payload is incomplete.');
        weatherSmokeAssert(isset($global['source_type'], $global['scope_type']), 'Global resolver normalized fields missing.');

        weatherSmokeStep('set global override');
        $override->saveGlobalOverride($weatherKey, 11, $moonPhase);
        $cleanup['global_override'] = true;
        $globalOverridden = $resolver->resolveGlobal(12, 'animated', '');
        weatherSmokeAssert(($globalOverridden['scope'] ?? '') === 'global', 'Global override scope mismatch.');
        weatherSmokeAssert((int) ($globalOverridden['temperature_value'] ?? -999) === 11, 'Global override degrees mismatch.');
        weatherSmokeAssert(($globalOverridden['moon_phase'] ?? '') === $moonPhase, 'Global override moon phase mismatch.');

        weatherSmokeStep('expired location override fallback');
        $expiredAt = date('Y-m-d H:i:s', time() - 3600);
        $override->upsertLocationOverride($locationId, $weatherKey, 5, $moonPhase, 1, $expiredAt, 'smoke-expired');
        $cleanup['location_override'] = true;

        $locationResolved = $resolver->resolveForLocation($locationId, 12, 'animated', '');
        weatherSmokeAssert(($locationResolved['scope'] ?? '') !== 'location', 'Expired location override should not be active.');

        weatherSmokeStep('active location override');
        $futureAt = date('Y-m-d H:i:s', time() + 3600);
        $override->upsertLocationOverride($locationId, $weatherKey, 3, $moonPhase, 1, $futureAt, 'smoke-active');
        $locationResolved = $resolver->resolveForLocation($locationId, 12, 'animated', '');
        weatherSmokeAssert(($locationResolved['scope'] ?? '') === 'location', 'Location override scope mismatch.');
        weatherSmokeAssert((int) ($locationResolved['temperature_value'] ?? -999) === 3, 'Location override temperature mismatch.');
        weatherSmokeAssert(!empty($locationResolved['override_expires_at']), 'Location override expiration should be present.');

        weatherSmokeStep('world resolver override');
        $override->saveWorldOverride($worldId, $weatherKey, 9, $moonPhase);
        $cleanup['world_override'] = true;

        $worldResolved = $resolver->resolveForWorld($worldId, 12, 'animated', '');
        weatherSmokeAssert(($worldResolved['scope'] ?? '') === 'world', 'World override scope mismatch.');
        weatherSmokeAssert((int) ($worldResolved['temperature_value'] ?? -999) === 9, 'World override temperature mismatch.');
        weatherSmokeAssert(($worldResolved['source_type'] ?? '') === 'world_override', 'World override source_type mismatch.');

        weatherSmokeStep('climate area resolver (if migration applied)');
        $tableRow = $db->query("SHOW TABLES LIKE 'climate_areas'")->first();
        $columnRow = $db->query("SHOW COLUMNS FROM `locations` LIKE 'climate_area_id'")->first();
        $supportsClimateAreas = !empty($tableRow) && !empty($columnRow);
        if ($supportsClimateAreas) {
            $code = 'SMK_' . date('His') . '_' . mt_rand(100, 999);
            $area = $override->createClimateArea((object) [
                'code' => $code,
                'name' => 'Smoke Area ' . $code,
                'description' => 'Smoke test climate area',
                'weather_key' => $weatherKey,
                'degrees' => 4,
                'moon_phase' => $moonPhase,
                'is_active' => 1,
            ]);
            $climateAreaId = (int) ($area['id'] ?? 0);
            weatherSmokeAssert($climateAreaId > 0, 'Climate area creation failed in smoke.');
            $cleanup['climate_area'] = true;

            $override->deleteLocationOverride($locationId);
            $cleanup['location_override'] = false;
            $override->assignLocationToClimateArea($locationId, $climateAreaId);

            $climateResolved = $resolver->resolveForLocation($locationId, 12, 'animated', '');
            weatherSmokeAssert(($climateResolved['scope'] ?? '') === 'climate_area', 'Climate area scope mismatch.');
            weatherSmokeAssert((int) ($climateResolved['temperature_value'] ?? -999) === 4, 'Climate area temperature mismatch.');
        } else {
            fwrite(STDOUT, "[INFO] climate_areas migration not detected; climate scope checks skipped.\n");
        }

        weatherSmokeStep('cleanup');
    } finally {
        $override->deleteLocationOverride($locationId);
        $override->saveGlobalOverride(null, null, null);

        if ($cleanup['world_override']) {
            $override->clearWorldOverride($worldId);
        }

        if ($cleanup['climate_area'] && $climateAreaId > 0) {
            $override->assignLocationToClimateArea($locationId, null);
            $override->deleteClimateArea($climateAreaId);
        }
    }

    fwrite(STDOUT, "[OK] Weather runtime smoke passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
