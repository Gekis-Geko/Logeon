<?php

declare(strict_types=1);

/**
 * Factions provider runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-factions-provider-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/vendor/autoload.php';

use App\Contracts\FactionProviderInterface;
use App\Services\FactionProviderRegistry;
use Core\Hooks;

function factionsProviderSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class FactionsProviderSmokeOverride implements FactionProviderInterface
{
    public function existsById(int $id): bool
    {
        return $id === 101;
    }

    public function getNameById(int $id): ?string
    {
        return $id > 0 ? 'Smoke Faction' : null;
    }

    /**
     * @return array<int,array{id:int,label:string,secondary:string}>
     */
    public function search(string $needle, int $limit): array
    {
        return [];
    }

    public function getMembershipsForCharacter(int $characterId): array
    {
        if ($characterId <= 0) {
            return [];
        }

        return [101, 202];
    }

    public function getActiveCharacterIdsForFactions(array $factionIds): array
    {
        if (empty($factionIds)) {
            return [];
        }

        return [1001, 1002];
    }

    public function joinEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return $factionId > 0 && $eventId > 0 && $characterId > 0;
    }

    public function leaveEventAsFaction(int $factionId, int $eventId, int $characterId): bool
    {
        return $factionId > 0 && $eventId > 0 && $characterId > 0;
    }

    public function inviteFactionToEvent(int $factionId, int $eventId, int $inviterCharacterId): bool
    {
        return $factionId > 0 && $eventId > 0 && $inviterCharacterId > 0;
    }
}

try {
    fwrite(STDOUT, "[STEP] fallback provider resolution (module OFF)\n");
    FactionProviderRegistry::resetRuntimeState();
    FactionProviderRegistry::setProvider(null);
    $fallback = FactionProviderRegistry::provider();
    factionsProviderSmokeAssert($fallback instanceof FactionProviderInterface, 'Fallback provider Fazioni non risolto.');
    factionsProviderSmokeAssert(
        FactionProviderRegistry::getMembershipsForCharacter(1001) === [],
        'Fallback provider Fazioni dovrebbe restituire membership vuote.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::joinEventAsFaction(1, 1, 1001) === false,
        'Fallback provider Fazioni dovrebbe restituire false su join evento.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::getActiveCharacterIdsForFactions([1, 2]) === [],
        'Fallback provider Fazioni dovrebbe restituire membri vuoti per fazioni.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::leaveEventAsFaction(1, 1, 1001) === false,
        'Fallback provider Fazioni dovrebbe restituire false su leave evento.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::inviteFactionToEvent(1, 1, 1001) === false,
        'Fallback provider Fazioni dovrebbe restituire false su invite evento.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::existsById(1) === false,
        'Fallback provider Fazioni dovrebbe restituire false su existsById.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::getNameById(1) === null,
        'Fallback provider Fazioni dovrebbe restituire null su getNameById.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::search('smoke', 5) === [],
        'Fallback provider Fazioni dovrebbe restituire ricerca vuota.',
    );

    fwrite(STDOUT, "[STEP] module bootstrap registers faction.provider hook\n");
    $moduleBootstrapPath = $root . '/modules/logeon.factions/bootstrap.php';
    factionsProviderSmokeAssert(
        is_file($moduleBootstrapPath),
        'Bootstrap modulo Fazioni non trovato.',
    );
    $moduleBootstrap = require $moduleBootstrapPath;
    if (is_callable($moduleBootstrap)) {
        $moduleBootstrap();
    }
    FactionProviderRegistry::resetRuntimeState();
    $moduleProvider = FactionProviderRegistry::provider();
    factionsProviderSmokeAssert(
        $moduleProvider instanceof \Modules\Logeon\Factions\FactionsModuleProvider,
        'Provider modulo Fazioni non risolto via bootstrap.',
    );

    fwrite(STDOUT, "[STEP] hook override provider resolution (module ON)\n");
    Hooks::add('faction.provider', function ($currentProvider) {
        return new FactionsProviderSmokeOverride();
    });
    FactionProviderRegistry::resetRuntimeState();
    $override = FactionProviderRegistry::provider();
    factionsProviderSmokeAssert($override instanceof FactionProviderInterface, 'Provider override Fazioni non risolto.');
    factionsProviderSmokeAssert(
        FactionProviderRegistry::getMembershipsForCharacter(1001) === [101, 202],
        'Override membership Fazioni non applicato.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::joinEventAsFaction(10, 20, 30) === true,
        'Override join evento Fazioni non applicato.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::getActiveCharacterIdsForFactions([10, 20]) === [1001, 1002],
        'Override membri attivi per fazioni non applicato.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::leaveEventAsFaction(10, 20, 30) === true,
        'Override leave evento Fazioni non applicato.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::inviteFactionToEvent(10, 20, 30) === true,
        'Override invite evento Fazioni non applicato.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::existsById(101) === true,
        'Override existsById Fazioni non applicato.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::getNameById(101) === 'Smoke Faction',
        'Override getNameById Fazioni non applicato.',
    );
    factionsProviderSmokeAssert(
        FactionProviderRegistry::search('smoke', 5) === [],
        'Override search Fazioni non applicato.',
    );

    fwrite(STDOUT, "[OK] Factions provider runtime smoke passed.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Factions provider runtime smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
