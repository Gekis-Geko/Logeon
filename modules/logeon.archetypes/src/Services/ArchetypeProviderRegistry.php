<?php

declare(strict_types=1);

namespace Modules\Logeon\Archetypes\Services;

use Core\Hooks;
use Modules\Logeon\Archetypes\Contracts\ArchetypeProviderInterface;

class ArchetypeProviderRegistry
{
    /** @var ArchetypeProviderInterface|null */
    private static $provider = null;

    public static function setProvider(ArchetypeProviderInterface $provider = null): void
    {
        self::$provider = $provider;
    }

    public static function provider(): ArchetypeProviderInterface
    {
        if (self::$provider instanceof ArchetypeProviderInterface) {
            return self::$provider;
        }

        $provider = new CoreArchetypeProvider();
        $provider = self::resolveWithHooks($provider);
        self::$provider = $provider;
        return self::$provider;
    }

    public static function resetRuntimeState(): void
    {
        self::$provider = null;
    }

    private static function resolveWithHooks(ArchetypeProviderInterface $fallback): ArchetypeProviderInterface
    {
        if (!class_exists('\\Core\\Hooks')) {
            return $fallback;
        }

        $filtered = Hooks::filter('character.archetype.provider', $fallback);
        if (!$filtered instanceof ArchetypeProviderInterface) {
            // Legacy alias for backward compatibility with pre-ADR hook naming.
            $filtered = Hooks::filter('archetypes.provider', $fallback);
        }
        if ($filtered instanceof ArchetypeProviderInterface) {
            return $filtered;
        }

        if (is_string($filtered) && class_exists($filtered)) {
            try {
                $candidate = new $filtered();
                if ($candidate instanceof ArchetypeProviderInterface) {
                    return $candidate;
                }
            } catch (\Throwable $e) {
                return $fallback;
            }
        }

        return $fallback;
    }
}
