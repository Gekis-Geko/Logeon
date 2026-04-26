<?php

declare(strict_types=1);

/**
 * Archetypes provider runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-archetypes-provider-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/vendor/autoload.php';

$moduleBootstrapPath = $root . '/modules/logeon.archetypes/bootstrap.php';
if (!is_file($moduleBootstrapPath)) {
    fwrite(STDERR, '[FAIL] Archetypes provider runtime smoke failed: Bootstrap modulo Archetypes non trovato.' . PHP_EOL);
    exit(1);
}
$moduleBootstrap = require $moduleBootstrapPath;

use Core\Hooks;
use Core\Logging\LoggerInterface;
use Modules\Logeon\Archetypes\Contracts\ArchetypeProviderInterface;
use Modules\Logeon\Archetypes\Controllers\Archetypes;
use Modules\Logeon\Archetypes\Services\ArchetypeConfigAccessor;
use Modules\Logeon\Archetypes\Services\ArchetypeProviderRegistry;

function archetypesProviderSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ArchetypesProviderSmokeLogger implements LoggerInterface
{
    /**
     * @param mixed $message
     * @param mixed $context
     */
    public function trace($message, $context = false): void
    {
        // No-op for smoke.
    }

    public function error(string $message): void
    {
        // No-op for smoke.
    }
}

final class ArchetypesProviderSmokeOverride implements ArchetypeProviderInterface
{
    public function getConfig(): array
    {
        return [
            'archetypes_enabled' => 1,
            'archetype_required' => 0,
            'multiple_archetypes_allowed' => 1,
        ];
    }

    public function updateConfig(object $data): array
    {
        return $this->getConfig();
    }

    public function publicList(): array
    {
        return [
            'config' => $this->getConfig(),
            'dataset' => [
                [
                    'id' => 999,
                    'name' => 'Smoke Archetype',
                    'slug' => 'smoke-archetype',
                    'icon' => null,
                    'description' => 'Provider override smoke row',
                    'lore_text' => null,
                    'sort_order' => 1,
                ],
            ],
        ];
    }

    public function getCharacterArchetypes(int $characterId): array
    {
        return [
            [
                'id' => 999,
                'name' => 'Smoke Archetype',
                'slug' => 'smoke-archetype',
                'assigned_at' => '1970-01-01 00:00:00',
            ],
        ];
    }

    public function assignArchetype(int $characterId, int $archetypeId, bool $multipleAllowed = false): void
    {
    }

    public function removeArchetype(int $characterId, int $archetypeId): void
    {
    }

    public function clearCharacterArchetypes(int $characterId): void
    {
    }

    /**
     * @param  array<int> $archetypeIds
     * @return array<int>
     */
    public function validateSelectableArchetypes(array $archetypeIds): array
    {
        return $archetypeIds;
    }

    public function adminList(array $filters = [], int $limit = 20, int $page = 1, string $sort = 'sort_order|ASC'): array
    {
        return [
            'total' => 1,
            'page' => max(1, $page),
            'limit' => max(1, $limit),
            'rows' => [
                [
                    'id' => 999,
                    'name' => 'Smoke Archetype',
                    'slug' => 'smoke-archetype',
                    'is_active' => 1,
                    'is_selectable' => 1,
                    'sort_order' => 1,
                ],
            ],
        ];
    }

    public function adminGet(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Smoke Archetype',
            'slug' => 'smoke-archetype',
            'is_active' => 1,
            'is_selectable' => 1,
            'sort_order' => 1,
        ];
    }

    public function adminCreate(object $data): array
    {
        return $this->adminGet(999);
    }

    public function adminUpdate(object $data): array
    {
        return $this->adminGet((int) ($data->id ?? 999));
    }

    public function adminDelete(int $id): void
    {
    }
}

try {
    fwrite(STDOUT, "[STEP] fallback provider resolution (module OFF)\n");
    ArchetypeProviderRegistry::resetRuntimeState();
    ArchetypeProviderRegistry::setProvider(null);
    $fallback = ArchetypeProviderRegistry::provider();
    archetypesProviderSmokeAssert($fallback instanceof ArchetypeProviderInterface, 'Fallback provider non risolto.');

    fwrite(STDOUT, "[STEP] module bootstrap registers archetypes.provider hook\n");
    if (is_callable($moduleBootstrap)) {
        call_user_func($moduleBootstrap, null, ['id' => 'logeon.archetypes']);
    }
    ArchetypeProviderRegistry::resetRuntimeState();
    $moduleProvider = ArchetypeProviderRegistry::provider();
    archetypesProviderSmokeAssert(
        $moduleProvider instanceof \Modules\Logeon\Archetypes\ArchetypesModuleProvider,
        'Provider modulo Archetypes non risolto via bootstrap hook.',
    );

    fwrite(STDOUT, "[STEP] hook override provider resolution (module ON)\n");
    Hooks::add('character.archetype.provider', function ($currentProvider) {
        return new ArchetypesProviderSmokeOverride();
    });
    ArchetypeProviderRegistry::resetRuntimeState();
    $overrideProvider = ArchetypeProviderRegistry::provider();
    $overridePayload = $overrideProvider->publicList();
    archetypesProviderSmokeAssert(
        (int) ($overridePayload['dataset'][0]['id'] ?? 0) === 999,
        'Hook provider non applicato correttamente.',
    );

    fwrite(STDOUT, "[STEP] config accessor uses hooked provider\n");
    $config = ArchetypeConfigAccessor::getConfig();
    archetypesProviderSmokeAssert(
        ArchetypeConfigAccessor::isEnabled($config),
        'Accessor config non allineato al provider hook.',
    );

    fwrite(STDOUT, "[STEP] archetypes controller publicList uses provider contract\n");
    $controller = new Archetypes();
    $controller->setLogger(new ArchetypesProviderSmokeLogger());
    $response = $controller->publicList(false);
    archetypesProviderSmokeAssert(
        (string) ($response['dataset'][0]['name'] ?? '') === 'Smoke Archetype',
        'Controller Archetypes::publicList non allineato al provider hook.',
    );

    fwrite(STDOUT, "[OK] Archetypes provider runtime smoke passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Archetypes provider runtime smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
