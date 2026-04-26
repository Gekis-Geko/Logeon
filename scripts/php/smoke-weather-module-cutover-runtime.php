<?php

declare(strict_types=1);

/**
 * Weather module cutover smoke (CLI).
 *
 * Validates controlled ON/OFF cutover for module `logeon.weather`
 * while preserving runtime-equivalent behavior on key Weather paths.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-weather-module-cutover-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/modules/logeon.weather/bootstrap.php';

use Core\Database\DbAdapterFactory;
use Core\ModuleManager;
use Core\ModuleRuntime;
use Modules\Logeon\Weather\Services\WeatherProviderRegistry;

function weatherCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string,mixed> $result
 */
function weatherCutoverEnsureManagerOk(array $result, string $step): void
{
    if (!isset($result['ok']) || $result['ok'] !== true) {
        $code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown';
        $message = isset($result['message']) ? (string) $result['message'] : 'errore sconosciuto';
        throw new RuntimeException($step . ' fallito [' . $code . ']: ' . $message);
    }
}

function weatherCutoverResetHooks(): void
{
    if (!class_exists('\\Core\\Hooks')) {
        return;
    }

    $prop = new ReflectionProperty(\Core\Hooks::class, 'actions');
    $prop->setAccessible(true);
    $prop->setValue([]);
}

/**
 * @param mixed $value
 * @return mixed
 */
function weatherCutoverCanonicalize($value)
{
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $out = [];
            foreach ($value as $item) {
                $out[] = weatherCutoverCanonicalize($item);
            }
            return $out;
        }

        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = weatherCutoverCanonicalize($v);
        }
        ksort($out);
        return $out;
    }

    if (is_object($value)) {
        return weatherCutoverCanonicalize((array) $value);
    }

    return $value;
}

/**
 * @param mixed $value
 */
function weatherCutoverJson($value): string
{
    $encoded = json_encode(
        weatherCutoverCanonicalize($value),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    weatherCutoverAssert(is_string($encoded), 'Serializzazione snapshot meteo non riuscita.');
    return $encoded;
}

function weatherCutoverResolveSampleLocationId(): int
{
    try {
        $db = DbAdapterFactory::createFromConfig();
        $row = $db->fetchOnePrepared(
            'SELECT id
             FROM locations
             ORDER BY id ASC
             LIMIT 1',
            [],
        );
        if (is_object($row) && isset($row->id)) {
            return (int) $row->id;
        }
    } catch (\Throwable $e) {
        return 0;
    }

    return 0;
}

/**
 * @return array<string,string>
 */
function weatherCutoverSnapshot(int $locationId): array
{
    weatherCutoverResetHooks();
    ModuleRuntime::reset();
    ModuleRuntime::instance()->bootActiveModules();
    WeatherProviderRegistry::resetRuntimeState();
    WeatherProviderRegistry::setProvider(null);

    $provider = WeatherProviderRegistry::provider();
    $out = [
        'provider_class' => get_class($provider),
        'options' => weatherCutoverJson($provider->options()),
        'world_options' => weatherCutoverJson($provider->worldOptions()),
        'moon_phases' => weatherCutoverJson($provider->moonPhases(12)),
        'resolve_global' => weatherCutoverJson($provider->resolveState(0, null, 12, 'animated', '')),
    ];

    if ($locationId > 0) {
        $out['resolve_location'] = weatherCutoverJson(
            $provider->resolveState($locationId, null, 12, 'animated', ''),
        );
    }

    return $out;
}

$moduleId = 'logeon.weather';
$manager = new ModuleManager();
$db = DbAdapterFactory::createFromConfig();
$locationId = weatherCutoverResolveSampleLocationId();

$originalRow = $db->fetchOnePrepared(
    'SELECT status, last_error
     FROM sys_modules
     WHERE module_id = ?
     LIMIT 1',
    [$moduleId],
);

$originalInstalled = is_object($originalRow) && isset($originalRow->status);
$originalStatus = $originalInstalled ? (string) $originalRow->status : 'detected';
$originalLastError = null;
if ($originalInstalled && property_exists($originalRow, 'last_error') && $originalRow->last_error !== null) {
    $originalLastError = (string) $originalRow->last_error;
}

$offSnapshot = null;
$onSnapshot = null;

try {
    $discovered = $manager->discover();
    weatherCutoverAssert(
        isset($discovered[$moduleId]),
        'Modulo ' . $moduleId . ' non rilevato in discover().',
    );

    fwrite(STDOUT, '[STEP] force module OFF and capture baseline snapshot' . PHP_EOL);
    if ($manager->isActive($moduleId)) {
        $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
        weatherCutoverEnsureManagerOk($deactivateResult, 'deactivate baseline');
    }
    $offSnapshot = weatherCutoverSnapshot($locationId);

    fwrite(STDOUT, '[STEP] activate module and capture ON snapshot' . PHP_EOL);
    $activateResult = $manager->activate($moduleId);
    weatherCutoverEnsureManagerOk($activateResult, 'activate');
    $onSnapshot = weatherCutoverSnapshot($locationId);

    $moduleProviderClass = 'Modules\\Logeon\\Weather\\WeatherModuleProvider';
    weatherCutoverAssert(
        $offSnapshot['provider_class'] !== $moduleProviderClass,
        'Provider modulo inatteso nello stato OFF.',
    );
    weatherCutoverAssert(
        $onSnapshot['provider_class'] === $moduleProviderClass,
        'Provider modulo non risolto nello stato ON.',
    );

    foreach (['options', 'world_options', 'moon_phases', 'resolve_global', 'resolve_location'] as $key) {
        if (!array_key_exists($key, $offSnapshot) || !array_key_exists($key, $onSnapshot)) {
            continue;
        }
        weatherCutoverAssert(
            $offSnapshot[$key] === $onSnapshot[$key],
            'Cutover ON/OFF non equivalente sul payload `' . $key . '`.',
        );
    }

    fwrite(STDOUT, '[OK] Weather module cutover smoke passed.' . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Weather module cutover smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        if (!$originalInstalled) {
            if ($manager->isActive($moduleId)) {
                $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                weatherCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
            }
            $uninstallResult = $manager->uninstall($moduleId, ['purge' => false]);
            if (is_array($uninstallResult) && isset($uninstallResult['ok']) && $uninstallResult['ok'] !== true) {
                $errorCode = (string) ($uninstallResult['error_code'] ?? '');
                if ($errorCode !== 'module_not_installed' && $errorCode !== 'module_bundled_no_purge') {
                    throw new RuntimeException('restore uninstall fallito: ' . (string) ($uninstallResult['message'] ?? ''));
                }
            }
        } else {
            if ($originalStatus === 'active') {
                $activateResult = $manager->activate($moduleId);
                weatherCutoverEnsureManagerOk($activateResult, 'restore activate');
            } else {
                if ($manager->isActive($moduleId)) {
                    $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                    weatherCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
                }

                $db->executePrepared(
                    'UPDATE sys_modules
                     SET status = ?, last_error = ?, date_updated = NOW()
                     WHERE module_id = ?',
                    [$originalStatus, $originalLastError, $moduleId],
                );
            }
        }
    } catch (Throwable $restoreError) {
        fwrite(STDERR, '[WARN] Restore module state failed: ' . $restoreError->getMessage() . PHP_EOL);
    }
}

exit(0);
