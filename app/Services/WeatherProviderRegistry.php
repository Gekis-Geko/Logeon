<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WeatherProviderInterface;
use App\Services\Weather\CoreWeatherProvider;
use Core\Hooks;

class WeatherProviderRegistry
{
    private static ?WeatherProviderInterface $provider = null;

    public static function setProvider(WeatherProviderInterface $provider = null): void
    {
        self::$provider = $provider;
    }

    public static function provider(): WeatherProviderInterface
    {
        if (self::$provider instanceof WeatherProviderInterface) {
            return self::$provider;
        }

        $provider = new CoreWeatherProvider();
        self::$provider = self::resolveWithHooks($provider);
        return self::$provider;
    }

    public static function resetRuntimeState(): void
    {
        self::$provider = null;
    }

    private static function resolveWithHooks(WeatherProviderInterface $fallback): WeatherProviderInterface
    {
        if (!class_exists('\\Core\\Hooks')) {
            return $fallback;
        }

        $filtered = Hooks::filter('weather.provider', $fallback);
        if ($filtered instanceof WeatherProviderInterface) {
            return $filtered;
        }

        if (is_string($filtered) && class_exists($filtered)) {
            try {
                $candidate = new $filtered();
                if ($candidate instanceof WeatherProviderInterface) {
                    return $candidate;
                }
            } catch (\Throwable $e) {
                return $fallback;
            }
        }

        return $fallback;
    }
}

