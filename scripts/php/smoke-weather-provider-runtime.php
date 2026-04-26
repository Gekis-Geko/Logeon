<?php

declare(strict_types=1);

/**
 * Weather provider runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-weather-provider-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/vendor/autoload.php';

use App\Contracts\WeatherProviderInterface;
use Modules\Logeon\Weather\Services\WeatherProviderRegistry;

function weatherProviderSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $moduleBootstrapPath = $root . '/modules/logeon.weather/bootstrap.php';
    weatherProviderSmokeAssert(is_file($moduleBootstrapPath), 'Bootstrap modulo Weather non trovato.');
    $moduleBootstrap = require $moduleBootstrapPath;

    fwrite(STDOUT, "[STEP] fallback provider resolution (module OFF)\n");
    WeatherProviderRegistry::resetRuntimeState();
    WeatherProviderRegistry::setProvider(null);
    $fallback = WeatherProviderRegistry::provider();
    weatherProviderSmokeAssert($fallback instanceof WeatherProviderInterface, 'Fallback provider Meteo non risolto.');

    fwrite(STDOUT, "[STEP] module bootstrap registers weather.provider hook\n");
    if (is_callable($moduleBootstrap)) {
        call_user_func($moduleBootstrap, null, ['id' => 'logeon.weather']);
    }
    WeatherProviderRegistry::resetRuntimeState();
    $moduleProvider = WeatherProviderRegistry::provider();
    weatherProviderSmokeAssert(
        $moduleProvider instanceof \Modules\Logeon\Weather\WeatherModuleProvider,
        'Provider modulo Weather non risolto via bootstrap hook.',
    );

    fwrite(STDOUT, "[STEP] module provider contract payload shape\n");
    $options = $moduleProvider->options();
    weatherProviderSmokeAssert(is_array($options), 'Payload options provider non valido.');
    weatherProviderSmokeAssert(
        isset($options['dataset']) && is_array($options['dataset']),
        'Payload options provider privo di dataset.',
    );

    fwrite(STDOUT, "[OK] Weather provider runtime smoke passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Weather provider runtime smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
