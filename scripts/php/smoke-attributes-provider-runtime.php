<?php

declare(strict_types=1);

/**
 * Attributes provider runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-attributes-provider-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/vendor/autoload.php';

use App\Contracts\AttributeProviderInterface;
use App\Services\AttributeProviderRegistry;
use Core\Hooks;

function attributesProviderSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class AttributesProviderSmokeOverride implements AttributeProviderInterface
{
    public function decorateCharacterDataset(object &$dataset, int $characterId): void
    {
        $dataset->character_attributes = [
            'enabled' => 1,
            'profile' => [
                'primary' => [
                    [
                        'id' => 999,
                        'slug' => 'smoke_strength',
                        'name' => 'Smoke Strength',
                        'effective_value' => 42,
                    ],
                ],
                'secondary' => [],
                'narrative' => [],
            ],
            'location' => [
                'primary' => [],
                'secondary' => [],
                'narrative' => [],
            ],
        ];
    }

    public function getDefinitions(): array
    {
        return [
            [
                'id' => 999,
                'slug' => 'smoke_strength',
                'name' => 'Smoke Strength',
                'attribute_group' => 'primary',
            ],
        ];
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function getAttributeModifier(int $characterId, string $attributeSlug): float
    {
        return 0.0;
    }
}

try {
    fwrite(STDOUT, "[STEP] fallback provider resolution (module OFF)\n");
    AttributeProviderRegistry::resetRuntimeState();
    AttributeProviderRegistry::setProvider(null);
    $fallback = AttributeProviderRegistry::provider();
    attributesProviderSmokeAssert($fallback instanceof AttributeProviderInterface, 'Fallback provider Attributi non risolto.');
    attributesProviderSmokeAssert(!$fallback->isEnabled(), 'Fallback provider Attributi dovrebbe essere disabilitato.');
    attributesProviderSmokeAssert(AttributeProviderRegistry::definitions() === [], 'Fallback definitions dovrebbe essere vuoto.');

    $fallbackDataset = (object) ['id' => 1001];
    AttributeProviderRegistry::decorateCharacterDataset($fallbackDataset, 1001);
    attributesProviderSmokeAssert(
        !property_exists($fallbackDataset, 'character_attributes'),
        'Fallback provider non deve iniettare character_attributes.',
    );

    fwrite(STDOUT, "[STEP] hook override provider resolution (module ON)\n");
    Hooks::add('attribute.provider', function ($currentProvider) {
        return new AttributesProviderSmokeOverride();
    });
    AttributeProviderRegistry::resetRuntimeState();
    $override = AttributeProviderRegistry::provider();
    attributesProviderSmokeAssert($override instanceof AttributeProviderInterface, 'Provider override Attributi non risolto.');
    attributesProviderSmokeAssert(AttributeProviderRegistry::isEnabled(), 'Provider override Attributi dovrebbe essere abilitato.');

    $definitions = AttributeProviderRegistry::definitions();
    attributesProviderSmokeAssert((int) ($definitions[0]['id'] ?? 0) === 999, 'Definitions override non applicate.');

    $dataset = (object) ['id' => 1001];
    AttributeProviderRegistry::decorateCharacterDataset($dataset, 1001);
    attributesProviderSmokeAssert(
        (int) ($dataset->character_attributes['profile']['primary'][0]['effective_value'] ?? 0) === 42,
        'Decorazione dataset override non applicata.',
    );

    fwrite(STDOUT, "[OK] Attributes provider runtime smoke passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Attributes provider runtime smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
