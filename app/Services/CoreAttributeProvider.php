<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AttributeProviderInterface;

class CoreAttributeProvider implements AttributeProviderInterface
{
    public function decorateCharacterDataset(object &$dataset, int $characterId): void
    {
        // Fallback core no-op: nessun attributo aggiunto al dataset.
    }

    public function getDefinitions(): array
    {
        return [];
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function getAttributeModifier(int $characterId, string $attributeSlug): float
    {
        return 0.0;
    }
}
