<?php

declare(strict_types=1);

/**
 * Factions module cutover smoke (CLI).
 *
 * Validates controlled ON/OFF cutover for module `logeon.factions`:
 * - OFF: fallback provider remains active (no-op behavior).
 * - ON: module provider is resolved via hook `faction.provider`.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-factions-module-cutover-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/configs/app.php';
require_once $root . '/vendor/autoload.php';

use App\Services\FactionProviderRegistry;
use Core\Database\DbAdapterFactory;
use Core\ModuleManager;
use Core\ModuleRuntime;
use Core\Router;

function factionsCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string,mixed> $result
 */
function factionsCutoverEnsureManagerOk(array $result, string $step): void
{
    if (!isset($result['ok']) || $result['ok'] !== true) {
        $code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown';
        $message = isset($result['message']) ? (string) $result['message'] : 'errore sconosciuto';
        throw new RuntimeException($step . ' fallito [' . $code . ']: ' . $message);
    }
}

function factionsCutoverResetHooks(): void
{
    if (!class_exists('\\Core\\Hooks')) {
        return;
    }

    $prop = new ReflectionProperty(\Core\Hooks::class, 'actions');
    $prop->setAccessible(true);
    $prop->setValue([]);
}

/**
 * @param mixed $fragments
 * @return array<int,array<string,mixed>>
 */
function factionsCutoverNormalizeFragments($fragments): array
{
    if (!is_array($fragments)) {
        return [];
    }

    $out = [];
    foreach ($fragments as $fragment) {
        if (is_object($fragment)) {
            $fragment = (array) $fragment;
        }
        if (!is_array($fragment)) {
            continue;
        }
        $out[] = $fragment;
    }

    return $out;
}

/**
 * @param array<int,array<string,mixed>> $fragments
 */
function factionsCutoverHasFragmentId(array $fragments, string $id): bool
{
    foreach ($fragments as $fragment) {
        if ((string) ($fragment['id'] ?? '') === $id) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int>
 */
function factionsCutoverNormalizeIds(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $out[$id] = $id;
        }
    }

    return array_values($out);
}

/**
 * @return array{character_id:int,faction_id:int,available:bool}
 */
function factionsCutoverResolveScenario($db): array
{
    $characterId = 0;
    $factionId = 0;
    $available = false;

    try {
        $row = $db->fetchOnePrepared(
            'SELECT fm.character_id, fm.faction_id
             FROM faction_memberships fm
             INNER JOIN factions f ON f.id = fm.faction_id
             WHERE fm.status = ?
               AND fm.role IN ("leader","officer","advisor")
             ORDER BY fm.id ASC
             LIMIT 1',
            ['active'],
        );

        if (is_object($row)) {
            $characterId = (int) ($row->character_id ?? 0);
            $factionId = (int) ($row->faction_id ?? 0);
            $available = $characterId > 0 && $factionId > 0;
        }
    } catch (\Throwable $e) {
        $available = false;
    }

    if (!$available) {
        try {
            $row = $db->fetchOnePrepared(
                'SELECT id
                 FROM characters
                 ORDER BY id ASC
                 LIMIT 1',
            );
            if (is_object($row)) {
                $characterId = (int) ($row->id ?? 0);
            }
        } catch (\Throwable $e) {
            $characterId = 0;
        }

        try {
            $row = $db->fetchOnePrepared(
                'SELECT id
                 FROM factions
                 ORDER BY id ASC
                 LIMIT 1',
            );
            if (is_object($row)) {
                $factionId = (int) ($row->id ?? 0);
            }
        } catch (\Throwable $e) {
            $factionId = 0;
        }
    }

    return [
        'character_id' => $characterId,
        'faction_id' => $factionId,
        'available' => $available,
    ];
}

/**
 * @return array<int,array{method:string,pattern:string,fn:string}>
 */
function factionsCutoverRegisteredRoutes(): array
{
    $route = new Router();
    ModuleRuntime::instance()->registerRoutes($route);

    $prop = new ReflectionProperty(Router::class, '_afterRoutes');
    $prop->setAccessible(true);
    $afterRoutes = $prop->getValue($route);
    if (!is_array($afterRoutes)) {
        return [];
    }

    $expectedPatterns = [
        '/factions/list' => true,
        '/factions/get' => true,
        '/factions/my' => true,
        '/factions/members' => true,
        '/factions/relations' => true,
        '/factions/leave' => true,
        '/factions/join-request/send' => true,
        '/factions/join-request/withdraw' => true,
        '/factions/join-request/my' => true,
        '/factions/leader/requests' => true,
        '/factions/leader/request/review' => true,
        '/factions/leader/invite' => true,
        '/factions/leader/expel' => true,
        '/factions/leader/relation' => true,
        '/admin/factions/list' => true,
        '/admin/factions/get' => true,
        '/admin/factions/create' => true,
        '/admin/factions/update' => true,
        '/admin/factions/delete' => true,
        '/admin/factions/members/list' => true,
        '/admin/factions/members/add' => true,
        '/admin/factions/members/update' => true,
        '/admin/factions/members/remove' => true,
        '/admin/factions/relations/list' => true,
        '/admin/factions/relations/set' => true,
        '/admin/factions/relations/remove' => true,
        '/game/factions' => true,
    ];

    $rows = [];
    foreach ($afterRoutes as $method => $definitions) {
        if (!is_array($definitions)) {
            continue;
        }

        foreach ($definitions as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $pattern = isset($definition['pattern']) ? (string) $definition['pattern'] : '';
            if ($pattern === '' || !isset($expectedPatterns[$pattern])) {
                continue;
            }

            $fn = $definition['fn'] ?? null;
            if (is_string($fn)) {
                $fnLabel = $fn;
            } elseif (is_object($fn)) {
                $fnLabel = get_class($fn);
            } else {
                $fnLabel = gettype($fn);
            }

            $rows[] = [
                'method' => strtoupper((string) $method),
                'pattern' => $pattern,
                'fn' => $fnLabel,
            ];
        }
    }

    usort($rows, function (array $a, array $b): int {
        $cmp = strcmp($a['method'], $b['method']);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcmp($a['pattern'], $b['pattern']);
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp($a['fn'], $b['fn']);
    });

    return $rows;
}

/**
 * @param array{character_id:int,faction_id:int,available:bool} $scenario
 * @return array<string,mixed>
 */
function factionsCutoverSnapshot(array $scenario): array
{
    factionsCutoverResetHooks();
    ModuleRuntime::reset();
    ModuleRuntime::instance()->bootActiveModules();

    FactionProviderRegistry::resetRuntimeState();
    FactionProviderRegistry::setProvider(null);
    $provider = FactionProviderRegistry::provider();

    $characterId = (int) ($scenario['character_id'] ?? 0);
    $factionId = (int) ($scenario['faction_id'] ?? 0);
    $eventId = 999001;
    $activeCharacterIds = [];
    if ($factionId > 0) {
        $activeCharacterIds = factionsCutoverNormalizeIds(
            FactionProviderRegistry::getActiveCharacterIdsForFactions([$factionId]),
        );
    }

    $memberships = factionsCutoverNormalizeIds(
        FactionProviderRegistry::getMembershipsForCharacter($characterId),
    );

    return [
        'provider_class' => get_class($provider),
        'admin_fragments' => factionsCutoverNormalizeFragments(
            \Core\Hooks::filter('twig.slot.admin.dashboard.factions', []),
        ),
        'character_id' => $characterId,
        'faction_id' => $factionId,
        'memberships' => $memberships,
        'members_for_faction' => $activeCharacterIds,
        'join' => FactionProviderRegistry::joinEventAsFaction($factionId, $eventId, $characterId),
        'leave' => FactionProviderRegistry::leaveEventAsFaction($factionId, $eventId, $characterId),
        'invite' => FactionProviderRegistry::inviteFactionToEvent($factionId, $eventId, $characterId),
        'routes' => factionsCutoverRegisteredRoutes(),
    ];
}

function factionsCutoverAssertCoreRoutesAreClean(string $root): void
{
    $coreApiPath = $root . '/app/routes/api.php';
    $coreApi = file_get_contents($coreApiPath);
    factionsCutoverAssert(is_string($coreApi), 'Impossibile leggere app/routes/api.php per verifica route core.');

    $needles = [
        'Factions@list',
        'Factions@get',
        'Factions@myFactions',
        'Factions@getFactionMembers',
        'Factions@getFactionRelations',
        'Factions@leaveFaction',
        'Factions@sendJoinRequest',
        'Factions@withdrawJoinRequest',
        'Factions@myJoinRequests',
        'Factions@leaderListJoinRequests',
        'Factions@reviewJoinRequest',
        'Factions@leaderInviteMember',
        'Factions@leaderExpelMember',
        'Factions@leaderProposeRelation',
        'Factions@adminList',
        'Factions@adminGet',
        'Factions@adminCreate',
        'Factions@adminUpdate',
        'Factions@adminDelete',
        'Factions@adminMemberList',
        'Factions@adminMemberAdd',
        'Factions@adminMemberUpdate',
        'Factions@adminMemberRemove',
        'Factions@adminRelationList',
        'Factions@adminRelationSet',
        'Factions@adminRelationRemove',
    ];
    foreach ($needles as $needle) {
        factionsCutoverAssert(
            strpos($coreApi, $needle) === false,
            'Callsite route Fazioni ancora presente nel core app/routes/api.php: ' . $needle,
        );
    }

    $coreGamePath = $root . '/app/routes/game.php';
    $coreGame = file_get_contents($coreGamePath);
    factionsCutoverAssert(is_string($coreGame), 'Impossibile leggere app/routes/game.php per verifica route game core.');
    factionsCutoverAssert(
        strpos($coreGame, '/game/factions') === false,
        'Route pagina Fazioni ancora hardcoded nel core app/routes/game.php.',
    );
}

function factionsCutoverAssertCoreTemplatesAreClean(string $root): void
{
    $dashboardPath = $root . '/app/views/admin/dashboard.twig';
    $dashboardTemplate = file_get_contents($dashboardPath);
    factionsCutoverAssert(is_string($dashboardTemplate), 'Impossibile leggere app/views/admin/dashboard.twig.');
    factionsCutoverAssert(
        strpos($dashboardTemplate, "current_page == 'factions'") === false,
        'Pagina Fazioni ancora hardcoded nel core admin/dashboard.twig.',
    );
    factionsCutoverAssert(
        strpos($dashboardTemplate, "include 'factions/admin/pages/factions.twig'") === false,
        'Template Fazioni ancora incluso direttamente nel core admin/dashboard.twig.',
    );
}

$moduleId = 'logeon.factions';
$manager = new ModuleManager();
$db = DbAdapterFactory::createFromConfig();
$scenario = factionsCutoverResolveScenario($db);

$expectedOnRoutes = [
    'POST|/factions/list|Modules\Logeon\Factions\Controllers\Factions@list',
    'POST|/factions/get|Modules\Logeon\Factions\Controllers\Factions@get',
    'POST|/factions/my|Modules\Logeon\Factions\Controllers\Factions@myFactions',
    'POST|/factions/members|Modules\Logeon\Factions\Controllers\Factions@getFactionMembers',
    'POST|/factions/relations|Modules\Logeon\Factions\Controllers\Factions@getFactionRelations',
    'POST|/factions/leave|Modules\Logeon\Factions\Controllers\Factions@leaveFaction',
    'POST|/factions/join-request/send|Modules\Logeon\Factions\Controllers\Factions@sendJoinRequest',
    'POST|/factions/join-request/withdraw|Modules\Logeon\Factions\Controllers\Factions@withdrawJoinRequest',
    'POST|/factions/join-request/my|Modules\Logeon\Factions\Controllers\Factions@myJoinRequests',
    'POST|/factions/leader/requests|Modules\Logeon\Factions\Controllers\Factions@leaderListJoinRequests',
    'POST|/factions/leader/request/review|Modules\Logeon\Factions\Controllers\Factions@reviewJoinRequest',
    'POST|/factions/leader/invite|Modules\Logeon\Factions\Controllers\Factions@leaderInviteMember',
    'POST|/factions/leader/expel|Modules\Logeon\Factions\Controllers\Factions@leaderExpelMember',
    'POST|/factions/leader/relation|Modules\Logeon\Factions\Controllers\Factions@leaderProposeRelation',
    'POST|/admin/factions/list|Modules\Logeon\Factions\Controllers\Factions@adminList',
    'POST|/admin/factions/get|Modules\Logeon\Factions\Controllers\Factions@adminGet',
    'POST|/admin/factions/create|Modules\Logeon\Factions\Controllers\Factions@adminCreate',
    'POST|/admin/factions/update|Modules\Logeon\Factions\Controllers\Factions@adminUpdate',
    'POST|/admin/factions/delete|Modules\Logeon\Factions\Controllers\Factions@adminDelete',
    'POST|/admin/factions/members/list|Modules\Logeon\Factions\Controllers\Factions@adminMemberList',
    'POST|/admin/factions/members/add|Modules\Logeon\Factions\Controllers\Factions@adminMemberAdd',
    'POST|/admin/factions/members/update|Modules\Logeon\Factions\Controllers\Factions@adminMemberUpdate',
    'POST|/admin/factions/members/remove|Modules\Logeon\Factions\Controllers\Factions@adminMemberRemove',
    'POST|/admin/factions/relations/list|Modules\Logeon\Factions\Controllers\Factions@adminRelationList',
    'POST|/admin/factions/relations/set|Modules\Logeon\Factions\Controllers\Factions@adminRelationSet',
    'POST|/admin/factions/relations/remove|Modules\Logeon\Factions\Controllers\Factions@adminRelationRemove',
    'GET|/game/factions|Closure',
];

$originalRow = $db->fetchOnePrepared(
    'SELECT status, last_error
     FROM sys_modules
     WHERE module_id = ?
     LIMIT 1',
    [$moduleId],
);

$originalInstalled = is_object($originalRow) && isset($originalRow->status);
$originalStatus = $originalInstalled ? (string) $originalRow->status : 'detected';
$originalLastError = null;
if ($originalInstalled && property_exists($originalRow, 'last_error') && $originalRow->last_error !== null) {
    $originalLastError = (string) $originalRow->last_error;
}

$offSnapshot = null;
$onSnapshot = null;

try {
    factionsCutoverAssertCoreRoutesAreClean($root);
    factionsCutoverAssertCoreTemplatesAreClean($root);

    $discovered = $manager->discover();
    factionsCutoverAssert(
        isset($discovered[$moduleId]),
        'Modulo ' . $moduleId . ' non rilevato in discover().',
    );

    fwrite(STDOUT, '[STEP] force module OFF and capture baseline snapshot' . PHP_EOL);
    if ($manager->isActive($moduleId)) {
        $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
        factionsCutoverEnsureManagerOk($deactivateResult, 'deactivate baseline');
    }
    $offSnapshot = factionsCutoverSnapshot($scenario);

    fwrite(STDOUT, '[STEP] activate module and capture ON snapshot' . PHP_EOL);
    $activateResult = $manager->activate($moduleId);
    factionsCutoverEnsureManagerOk($activateResult, 'activate');
    $onSnapshot = factionsCutoverSnapshot($scenario);

    $moduleProviderClass = 'Modules\\Logeon\\Factions\\FactionsModuleProvider';
    factionsCutoverAssert(
        $offSnapshot['provider_class'] !== $moduleProviderClass,
        'Provider modulo Fazioni inatteso nello stato OFF.',
    );
    factionsCutoverAssert(
        $onSnapshot['provider_class'] === $moduleProviderClass,
        'Provider modulo Fazioni non risolto nello stato ON.',
    );

    factionsCutoverAssert(
        !factionsCutoverHasFragmentId($offSnapshot['admin_fragments'], 'factions-admin-dashboard-page'),
        'Fragment Fazioni admin dashboard presente nello stato OFF.',
    );
    factionsCutoverAssert(
        factionsCutoverHasFragmentId($onSnapshot['admin_fragments'], 'factions-admin-dashboard-page'),
        'Fragment Fazioni admin dashboard assente nello stato ON.',
    );

    factionsCutoverAssert(
        $offSnapshot['memberships'] === [] && $offSnapshot['members_for_faction'] === [],
        'Provider fallback Fazioni non no-op nello stato OFF (membership).',
    );
    factionsCutoverAssert(
        $offSnapshot['join'] === false && $offSnapshot['leave'] === false && $offSnapshot['invite'] === false,
        'Provider fallback Fazioni non no-op nello stato OFF (operazioni evento).',
    );

    factionsCutoverAssert(
        empty($offSnapshot['routes']),
        'Route modulo Fazioni registrate nello stato OFF.',
    );

    $onRouteSet = [];
    foreach ($onSnapshot['routes'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $onRouteSet[] = (string) ($row['method'] ?? '') . '|'
            . (string) ($row['pattern'] ?? '') . '|'
            . (string) ($row['fn'] ?? '');
    }
    $onRouteSet = array_values(array_unique($onRouteSet));
    foreach ($expectedOnRoutes as $expectedOnRoute) {
        factionsCutoverAssert(
            in_array($expectedOnRoute, $onRouteSet, true),
            'Route Fazioni mancante nello stato ON: ' . $expectedOnRoute,
        );
    }

    if ((bool) ($scenario['available'] ?? false) === true) {
        $scenarioFactionId = (int) ($scenario['faction_id'] ?? 0);
        factionsCutoverAssert(
            in_array($scenarioFactionId, $onSnapshot['memberships'], true),
            'Provider modulo Fazioni non restituisce la membership attesa nello stato ON.',
        );
        factionsCutoverAssert(
            in_array((int) ($scenario['character_id'] ?? 0), $onSnapshot['members_for_faction'], true),
            'Provider modulo Fazioni non restituisce membri attivi per la fazione nello stato ON.',
        );
        factionsCutoverAssert(
            $onSnapshot['join'] === true && $onSnapshot['leave'] === true && $onSnapshot['invite'] === true,
            'Provider modulo Fazioni non abilita le operazioni evento nello stato ON.',
        );
    } else {
        fwrite(STDOUT, '[INFO] Scenario officer non disponibile: check ON limitato a risoluzione provider.' . PHP_EOL);
    }

    fwrite(STDOUT, '[OK] Factions module cutover smoke passed.' . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Factions module cutover smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        if (!$originalInstalled) {
            if ($manager->isActive($moduleId)) {
                $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                factionsCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
            }
            $uninstallResult = $manager->uninstall($moduleId, ['purge' => false]);
            if (is_array($uninstallResult) && isset($uninstallResult['ok']) && $uninstallResult['ok'] !== true) {
                $errorCode = (string) ($uninstallResult['error_code'] ?? '');
                if ($errorCode !== 'module_not_installed' && $errorCode !== 'module_bundled_no_purge') {
                    throw new RuntimeException('restore uninstall fallito: ' . (string) ($uninstallResult['message'] ?? ''));
                }
            }
        } else {
            if ($originalStatus === 'active') {
                $activateResult = $manager->activate($moduleId);
                factionsCutoverEnsureManagerOk($activateResult, 'restore activate');
            } else {
                if ($manager->isActive($moduleId)) {
                    $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                    factionsCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
                }

                $db->executePrepared(
                    'UPDATE sys_modules
                     SET status = ?, last_error = ?, date_updated = NOW()
                     WHERE module_id = ?',
                    [$originalStatus, $originalLastError, $moduleId],
                );
            }
        }

        factionsCutoverResetHooks();
        ModuleRuntime::reset();
        FactionProviderRegistry::resetRuntimeState();
        FactionProviderRegistry::setProvider(null);
    } catch (Throwable $restoreError) {
        fwrite(STDERR, '[WARN] Restore module state failed: ' . $restoreError->getMessage() . PHP_EOL);
    }
}

exit(0);
