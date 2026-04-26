<?php

declare(strict_types=1);

namespace Modules\Logeon\Archetypes\Services;

class ArchetypeConfigAccessor
{
    /**
     * @param  array<string,mixed> $config
     * @return array{archetypes_enabled:int,archetype_required:int,multiple_archetypes_allowed:int}
     */
    public static function normalize(array $config): array
    {
        return [
            'archetypes_enabled'         => 1,
            'archetype_required'         => ((int) ($config['archetype_required'] ?? 0) === 1) ? 1 : 0,
            'multiple_archetypes_allowed' => ((int) ($config['multiple_archetypes_allowed'] ?? 0) === 1) ? 1 : 0,
        ];
    }

    /**
     * @return array{archetypes_enabled:int,archetype_required:int,multiple_archetypes_allowed:int}
     */
    public static function getConfig(): array
    {
        try {
            $provider = ArchetypeProviderRegistry::provider();
            $rawConfig = $provider->getConfig();
            return self::normalize($rawConfig);
        } catch (\Throwable $e) {
            return self::disabledConfig();
        }
    }

    /**
     * @param  array<string,mixed>|null $config
     */
    public static function isEnabled(array $config = null): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>|null $config
     */
    public static function isRequired(array $config = null): bool
    {
        $resolved = is_array($config) ? self::normalize($config) : self::getConfig();
        return $resolved['archetype_required'] === 1;
    }

    /**
     * @param  array<string,mixed>|null $config
     */
    public static function isMultipleAllowed(array $config = null): bool
    {
        $resolved = is_array($config) ? self::normalize($config) : self::getConfig();
        return $resolved['multiple_archetypes_allowed'] === 1;
    }

    /**
     * @return array{archetypes_enabled:int,archetype_required:int,multiple_archetypes_allowed:int}
     */
    private static function disabledConfig(): array
    {
        return [
            'archetypes_enabled'         => 1,
            'archetype_required'         => 0,
            'multiple_archetypes_allowed' => 0,
        ];
    }
}
