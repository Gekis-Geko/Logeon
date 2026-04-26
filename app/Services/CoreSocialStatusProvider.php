<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\SocialStatusProviderInterface;

class CoreSocialStatusProvider implements SocialStatusProviderInterface
{
    public function syncForCharacter(int $characterId, float $fame, ?int $currentStatusId): ?object
    {
        return null;
    }

    public function meetsRequirement(int $characterId, ?int $requiredStatusId): bool
    {
        return true;
    }

    /**
     * @return array<int,object>
     */
    public function listAll(): array
    {
        return [];
    }

    public function getShopDiscount(int $characterId): float
    {
        return 0.0;
    }

    public function getById(int $id): ?object
    {
        return null;
    }
}

