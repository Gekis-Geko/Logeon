<?php

declare(strict_types=1);

namespace Modules\Logeon\Weather\Services;

use App\Contracts\WeatherAdvancedInterface;
use Core\Hooks;

class WeatherProviderRegistry
{
    /** @var WeatherAdvancedInterface|null */
    private static $provider = null;

    public static function setProvider(WeatherAdvancedInterface $provider = null): void
    {
        self::$provider = $provider;
    }

    public static function provider(): WeatherAdvancedInterface
    {
        if (self::$provider instanceof WeatherAdvancedInterface) {
            return self::$provider;
        }

        $provider = new CoreWeatherProvider();
        $provider = self::resolveWithHooks($provider);
        self::$provider = $provider;
        return self::$provider;
    }

    public static function resetRuntimeState(): void
    {
        self::$provider = null;
    }

    private static function resolveWithHooks(WeatherAdvancedInterface $fallback): WeatherAdvancedInterface
    {
        if (!class_exists('\\Core\\Hooks')) {
            return $fallback;
        }

        $filtered = Hooks::filter('weather.provider', $fallback);
        if ($filtered instanceof WeatherAdvancedInterface) {
            return $filtered;
        }

        if (is_string($filtered) && class_exists($filtered)) {
            try {
                $candidate = new $filtered();
                if ($candidate instanceof WeatherAdvancedInterface) {
                    return $candidate;
                }
            } catch (\Throwable $e) {
                return $fallback;
            }
        }

        return $fallback;
    }
}
