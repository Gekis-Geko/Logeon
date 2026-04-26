<?php

declare(strict_types=1);

namespace App\Contracts;

interface SocialStatusProviderInterface
{
    public function syncForCharacter(int $characterId, float $fame, ?int $currentStatusId): ?object;

    public function meetsRequirement(int $characterId, ?int $requiredStatusId): bool;

    /**
     * @return array<int,object>
     */
    public function listAll(): array;

    public function getShopDiscount(int $characterId): float;

    public function getById(int $id): ?object;
}

