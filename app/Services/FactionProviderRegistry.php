<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\FactionProviderInterface;
use Core\Hooks;

class FactionProviderRegistry
{
    /** @var FactionProviderInterface|null */
    private static $provider = null;

    public static function setProvider(FactionProviderInterface $provider = null): void
    {
        self::$provider = $provider;
    }

    public static function provider(): FactionProviderInterface
    {
        if (self::$provider instanceof FactionProviderInterface) {
            return self::$provider;
        }

        $provider = new CoreFactionProvider();
        $provider = self::resolveWithHooks($provider);
        self::$provider = $provider;

        return self::$provider;
    }

    /**
     * @return array<int>
     */
    public static function getMembershipsForCharacter(int $characterId): array
    {
        return self::provider()->getMembershipsForCharacter($characterId);
    }

    /**
     * @param array<int> $factionIds
     * @return array<int>
     */
    public static function getActiveCharacterIdsForFactions(array $factionIds): array
    {
        return self::provider()->getActiveCharacterIdsForFactions($factionIds);
    }

    public static function joinEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return self::provider()->joinEventAsFaction($factionId, $eventId, $characterId);
    }

    public static function leaveEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return self::provider()->leaveEventAsFaction($factionId, $eventId, $characterId);
    }

    public static function inviteFactionToEvent(int $factionId, int $eventId, int $inviterCharacterId): bool
    {
        return self::provider()->inviteFactionToEvent($factionId, $eventId, $inviterCharacterId);
    }

    public static function existsById(int $id): bool
    {
        return self::provider()->existsById($id);
    }

    public static function getNameById(int $id): ?string
    {
        return self::provider()->getNameById($id);
    }

    /**
     * @return array<int,array{id:int,label:string,secondary:string}>
     */
    public static function search(string $needle, int $limit): array
    {
        return self::provider()->search($needle, $limit);
    }

    public static function resetRuntimeState(): void
    {
        self::$provider = null;
    }

    private static function resolveWithHooks(FactionProviderInterface $fallback): FactionProviderInterface
    {
        if (!class_exists('\\Core\\Hooks')) {
            return $fallback;
        }

        $filtered = Hooks::filter('faction.provider', $fallback);
        if ($filtered instanceof FactionProviderInterface) {
            return $filtered;
        }

        if (is_string($filtered) && class_exists($filtered)) {
            try {
                $candidate = new $filtered();
                if ($candidate instanceof FactionProviderInterface) {
                    return $candidate;
                }
            } catch (\Throwable $e) {
                return $fallback;
            }
        }

        return $fallback;
    }
}
