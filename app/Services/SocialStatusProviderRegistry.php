<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SocialStatusProviderInterface;
use Core\Hooks;

class SocialStatusProviderRegistry
{
    /** @var SocialStatusProviderInterface|null */
    private static $provider = null;

    public static function setProvider(SocialStatusProviderInterface $provider = null): void
    {
        self::$provider = $provider;
    }

    public static function provider(): SocialStatusProviderInterface
    {
        if (self::$provider instanceof SocialStatusProviderInterface) {
            return self::$provider;
        }

        $provider = new CoreSocialStatusProvider();
        $provider = self::resolveWithHooks($provider);
        self::$provider = $provider;

        return self::$provider;
    }

    public static function syncForCharacter(int $characterId, float $fame, ?int $currentStatusId): ?object
    {
        return self::provider()->syncForCharacter($characterId, $fame, $currentStatusId);
    }

    public static function meetsRequirement(int $characterId, ?int $requiredStatusId): bool
    {
        return self::provider()->meetsRequirement($characterId, $requiredStatusId);
    }

    /**
     * @return array<int,object>
     */
    public static function listAll(): array
    {
        return self::provider()->listAll();
    }

    public static function getShopDiscount(int $characterId): float
    {
        return self::provider()->getShopDiscount($characterId);
    }

    public static function getById(int $id): ?object
    {
        return self::provider()->getById($id);
    }

    public static function resetRuntimeState(): void
    {
        self::$provider = null;
    }

    private static function resolveWithHooks(SocialStatusProviderInterface $fallback): SocialStatusProviderInterface
    {
        if (!class_exists('\\Core\\Hooks')) {
            return $fallback;
        }

        $filtered = Hooks::filter('social_status.provider', $fallback);
        if ($filtered instanceof SocialStatusProviderInterface) {
            return $filtered;
        }

        if (is_string($filtered) && class_exists($filtered)) {
            try {
                $candidate = new $filtered();
                if ($candidate instanceof SocialStatusProviderInterface) {
                    return $candidate;
                }
            } catch (\Throwable $e) {
                return $fallback;
            }
        }

        return $fallback;
    }
}

