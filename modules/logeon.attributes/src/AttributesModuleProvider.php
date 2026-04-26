<?php

declare(strict_types=1);

namespace Modules\Logeon\Attributes;

use App\Contracts\AttributeProviderInterface;
use Modules\Logeon\Attributes\Services\CharacterAttributesFacadeService;

class AttributesModuleProvider implements AttributeProviderInterface
{
    private CharacterAttributesFacadeService $facade;

    public function __construct(CharacterAttributesFacadeService $facade = null)
    {
        $this->facade = $facade ?: new CharacterAttributesFacadeService();
    }

    public function decorateCharacterDataset(object &$dataset, int $characterId): void
    {
        if (!isset($dataset->id) && $characterId > 0) {
            $dataset->id = $characterId;
        }

        $dataset = $this->facade->decorateCharacterDataset($dataset);
    }

    public function getDefinitions(): array
    {
        $payload = $this->facade->listDefinitions((object) []);
        $rows = $payload['dataset'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (is_array($row)) {
                $normalized[] = $row;
            }
        }

        return $normalized;
    }

    public function isEnabled(): bool
    {
        return $this->facade->isEnabled();
    }

    public function getAttributeModifier(int $characterId, string $attributeSlug): float
    {
        $attributeSlug = trim($attributeSlug);
        if ($characterId <= 0 || $attributeSlug === '' || !$this->facade->isEnabled()) {
            return 0.0;
        }

        try {
            $payload = $this->facade->listCharacterValues($characterId);
        } catch (\Throwable $e) {
            return 0.0;
        }

        $rows = $payload['dataset'] ?? [];
        if (!is_array($rows)) {
            return 0.0;
        }

        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }

            if (trim((string) ($row->slug ?? '')) !== $attributeSlug) {
                continue;
            }

            return isset($row->effective_value) ? (float) $row->effective_value : 0.0;
        }

        return 0.0;
    }
}

