<?php

declare(strict_types=1);

/**
 * Quests module cutover smoke (CLI).
 *
 * Validates controlled ON/OFF cutover for module `logeon.quests`:
 * - OFF: no quest trigger listeners, no quest slot fragments, no quest routes.
 * - ON: listeners, slot fragments and routes are registered by module runtime.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-quests-module-cutover-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/configs/app.php';
require_once $root . '/vendor/autoload.php';

use Core\Database\DbAdapterFactory;
use Core\ModuleManager;
use Core\ModuleRuntime;
use Core\Router;

function questsCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string,mixed> $result
 */
function questsCutoverEnsureManagerOk(array $result, string $step): void
{
    if (!isset($result['ok']) || $result['ok'] !== true) {
        $code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown';
        $message = isset($result['message']) ? (string) $result['message'] : 'errore sconosciuto';
        throw new RuntimeException($step . ' fallito [' . $code . ']: ' . $message);
    }
}

function questsCutoverResetHooks(): void
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
function questsCutoverNormalizeFragments($fragments): array
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
function questsCutoverHasFragmentId(array $fragments, string $id): bool
{
    foreach ($fragments as $fragment) {
        if ((string) ($fragment['id'] ?? '') === $id) {
            return true;
        }
    }

    return false;
}

function questsCutoverHookListenerCount(string $hook): int
{
    if (!class_exists('\\Core\\Hooks')) {
        return 0;
    }

    $prop = new ReflectionProperty(\Core\Hooks::class, 'actions');
    $prop->setAccessible(true);
    $actions = $prop->getValue();
    if (!is_array($actions) || !isset($actions[$hook]) || !is_array($actions[$hook])) {
        return 0;
    }

    $count = 0;
    foreach ($actions[$hook] as $callbacks) {
        if (is_array($callbacks)) {
            $count += count($callbacks);
        }
    }

    return $count;
}

/**
 * @return array<int,array{method:string,pattern:string,fn:string}>
 */
function questsCutoverRegisteredRoutes(): array
{
    $route = new Router();
    ModuleRuntime::instance()->registerRoutes($route);

    $prop = new ReflectionProperty(Router::class, '_afterRoutes');
    $prop->setAccessible(true);
    $afterRoutes = $prop->getValue($route);
    if (!is_array($afterRoutes)) {
        return [];
    }

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
            if ($pattern === '') {
                continue;
            }

            $isQuestPattern =
                strpos($pattern, '/quests/') === 0
                || strpos($pattern, '/admin/quests/') === 0
                || strpos($pattern, '/game/quests/') === 0;

            if (!$isQuestPattern) {
                continue;
            }

            $fn = $definition['fn'] ?? null;
            $fnLabel = '';
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
 * @return array<string,mixed>
 */
function questsCutoverSnapshot(): array
{
    questsCutoverResetHooks();
    ModuleRuntime::reset();
    ModuleRuntime::instance()->bootActiveModules();

    $triggerHooks = [
        'narrative.event.created',
        'system_event.status_changed',
        'faction.membership.changed',
        'lifecycle.phase.entered',
        'presence.position_changed',
    ];

    $hookCounts = [];
    foreach ($triggerHooks as $hook) {
        $hookCounts[$hook] = questsCutoverHookListenerCount($hook);
    }

    return [
        'admin_fragments' => questsCutoverNormalizeFragments(
            \Core\Hooks::filter('twig.slot.admin.dashboard.quests', []),
        ),
        'game_modal_fragments' => questsCutoverNormalizeFragments(
            \Core\Hooks::filter('twig.slot.game.modals', []),
        ),
        'game_navbar_fragments' => questsCutoverNormalizeFragments(
            \Core\Hooks::filter('twig.slot.game.navbar.organizations.after_bank', []),
        ),
        'game_offcanvas_fragments' => questsCutoverNormalizeFragments(
            \Core\Hooks::filter('twig.slot.game.offcanvas.mobile.organizations.after', []),
        ),
        'hook_counts' => $hookCounts,
        'routes' => questsCutoverRegisteredRoutes(),
    ];
}

function questsCutoverAssertCoreRoutesAreClean(string $root): void
{
    $coreApiPath = $root . '/app/routes/api.php';
    $coreApi = file_get_contents($coreApiPath);
    questsCutoverAssert(is_string($coreApi), 'Impossibile leggere app/routes/api.php per verifica route core Quest.');
    questsCutoverAssert(
        strpos($coreApi, '$route->group(\'/quests\'') === false,
        'Route group /quests ancora presente nel core app/routes/api.php.',
    );
    questsCutoverAssert(
        strpos($coreApi, '$route->group(\'/admin/quests\'') === false,
        'Route group /admin/quests ancora presente nel core app/routes/api.php.',
    );
    questsCutoverAssert(
        strpos($coreApi, 'QuestTriggerService::bootstrap(') === false,
        'Bootstrap trigger Quest ancora presente nel core app/routes/api.php.',
    );

    $coreGamePath = $root . '/app/routes/game.php';
    $coreGame = file_get_contents($coreGamePath);
    questsCutoverAssert(is_string($coreGame), 'Impossibile leggere app/routes/game.php per verifica route game Quest.');
    questsCutoverAssert(
        strpos($coreGame, '$route->group(\'/game/quests\'') === false,
        'Route group /game/quests ancora presente nel core app/routes/game.php.',
    );
    questsCutoverAssert(
        strpos($coreGame, 'app/quest_history.twig') === false,
        'Template quest_history ancora referenziato dal core app/routes/game.php.',
    );
}

function questsCutoverAssertCoreTemplatesAreClean(string $root): void
{
    $navbarPath = $root . '/app/views/app/layouts/navbar.twig';
    $navbarTemplate = file_get_contents($navbarPath);
    questsCutoverAssert(is_string($navbarTemplate), 'Impossibile leggere app/views/app/layouts/navbar.twig.');
    questsCutoverAssert(
        strpos($navbarTemplate, "slot('game.navbar.organizations.after_bank')") !== false,
        'Slot quest navbar assente nel core navbar.twig.',
    );
    questsCutoverAssert(
        strpos($navbarTemplate, "include 'app/modals/quests/quests.twig'") === false,
        'Modale Quest ancora hardcoded nel core navbar.twig.',
    );
    questsCutoverAssert(
        strpos($navbarTemplate, 'offcanvasQuests') === false,
        'Trigger offcanvasQuests ancora hardcoded nel core navbar.twig.',
    );

    $offcanvasPath = $root . '/app/views/app/offcanvas/navbar.twig';
    $offcanvasTemplate = file_get_contents($offcanvasPath);
    questsCutoverAssert(is_string($offcanvasTemplate), 'Impossibile leggere app/views/app/offcanvas/navbar.twig.');
    questsCutoverAssert(
        strpos($offcanvasTemplate, "slot('game.offcanvas.mobile.organizations.after')") !== false,
        'Slot quest offcanvas assente nel core offcanvas/navbar.twig.',
    );
    questsCutoverAssert(
        strpos($offcanvasTemplate, 'offcanvasQuests') === false,
        'Markup quest offcanvas ancora hardcoded nel core offcanvas/navbar.twig.',
    );

    $dashboardPath = $root . '/app/views/admin/dashboard.twig';
    $dashboardTemplate = file_get_contents($dashboardPath);
    questsCutoverAssert(is_string($dashboardTemplate), 'Impossibile leggere app/views/admin/dashboard.twig.');
    questsCutoverAssert(
        strpos($dashboardTemplate, "include 'admin/pages/quests.twig'") === false,
        'Pagina Quest ancora hardcoded nel core admin/dashboard.twig.',
    );

    $asidePath = $root . '/app/views/admin/layouts/aside.twig';
    $asideTemplate = file_get_contents($asidePath);
    questsCutoverAssert(is_string($asideTemplate), 'Impossibile leggere app/views/admin/layouts/aside.twig.');
    questsCutoverAssert(
        strpos($asideTemplate, 'href="/admin/quests"') === false,
        'Link Quest ancora hardcoded nel core admin/layouts/aside.twig.',
    );
}

function questsCutoverAssertCoreJsIsClean(string $root): void
{
    $checks = [
        [
            'path' => '/assets/js/app/core/game.registry.js',
            'needle' => "'game.quests': 'QuestsModuleFactory'",
            'message' => 'Modulo game.quests ancora hardcoded nel core game.registry.js.',
        ],
        [
            'path' => '/assets/js/app/core/game.feature-loader.js',
            'needle' => '/assets/js/app/features/game/QuestsPage.js',
            'message' => 'Feature script QuestsPage ancora hardcoded nel core game.feature-loader.js.',
        ],
        [
            'path' => '/assets/js/app/core/game.feature-loader.js',
            'needle' => '/assets/js/app/features/game/QuestHistoryPage.js',
            'message' => 'Feature script QuestHistoryPage ancora hardcoded nel core game.feature-loader.js.',
        ],
        [
            'path' => '/assets/js/app/core/admin.registry.js',
            'needle' => "'admin.quests': 'AdminQuestsModuleFactory'",
            'message' => 'Modulo admin.quests ancora hardcoded nel core admin.registry.js.',
        ],
        [
            'path' => '/assets/js/app/core/admin.feature-loader.js',
            'needle' => "/assets/js/app/features/admin/Quests.js",
            'message' => 'Feature script Admin Quests ancora hardcoded nel core admin.feature-loader.js.',
        ],
        [
            'path' => '/assets/js/app/core/admin.runtime.js',
            'needle' => "quests: ['admin.quests']",
            'message' => 'Mappatura admin.quests ancora hardcoded nel core admin.runtime.js.',
        ],
        [
            'path' => '/assets/js/app/core/game.page.js',
            'needle' => "module: 'game.quests'",
            'message' => 'Controller game.quests ancora hardcoded nel core game.page.js.',
        ],
        [
            'path' => '/assets/js/app/core/game.page.js',
            'needle' => "factory: 'GameQuestHistoryPage'",
            'message' => 'Factory GameQuestHistoryPage ancora hardcoded nel core game.page.js.',
        ],
        [
            'path' => '/assets/js/app/entries/game-core.entry.js',
            'needle' => "import '../modules/game/QuestsModule.js';",
            'message' => 'Import QuestsModule ancora hardcoded nel core game-core.entry.js.',
        ],
        [
            'path' => '/assets/js/app/entries/game-home.entry.js',
            'needle' => "import '../features/game/QuestsPage.js';",
            'message' => 'Import QuestsPage ancora hardcoded nel core game-home.entry.js.',
        ],
        [
            'path' => '/assets/js/app/entries/game-community.entry.js',
            'needle' => "import '../features/game/QuestsPage.js';",
            'message' => 'Import QuestsPage ancora hardcoded nel core game-community.entry.js.',
        ],
        [
            'path' => '/assets/js/app/entries/game-location.entry.js',
            'needle' => "import '../features/game/QuestsPage.js';",
            'message' => 'Import QuestsPage ancora hardcoded nel core game-location.entry.js.',
        ],
        [
            'path' => '/assets/js/app/entries/game-world.entry.js',
            'needle' => "import '../features/game/QuestsPage.js';",
            'message' => 'Import QuestsPage ancora hardcoded nel core game-world.entry.js.',
        ],
        [
            'path' => '/assets/js/app/entries/game-character.entry.js',
            'needle' => "import '../features/game/QuestsPage.js';",
            'message' => 'Import QuestsPage ancora hardcoded nel core game-character.entry.js.',
        ],
        [
            'path' => '/assets/js/app/entries/game-character.entry.js',
            'needle' => "import '../features/game/QuestHistoryPage.js';",
            'message' => 'Import QuestHistoryPage ancora hardcoded nel core game-character.entry.js.',
        ],
        [
            'path' => '/assets/js/app/entries/admin-core.entry.js',
            'needle' => "import '../modules/admin/QuestsModule.js';",
            'message' => 'Import Admin QuestsModule ancora hardcoded nel core admin-core.entry.js.',
        ],
        [
            'path' => '/assets/js/app/entries/admin-narrative.entry.js',
            'needle' => "import '../features/admin/Quests.js';",
            'message' => 'Import feature Admin Quests ancora hardcoded nel core admin-narrative.entry.js.',
        ],
    ];

    foreach ($checks as $check) {
        $path = $root . (string) ($check['path'] ?? '');
        $contents = file_get_contents($path);
        questsCutoverAssert(
            is_string($contents),
            'Impossibile leggere ' . (string) ($check['path'] ?? 'file') . ' per verifica JS core Quest.',
        );
        questsCutoverAssert(
            strpos((string) $contents, (string) ($check['needle'] ?? '')) === false,
            (string) ($check['message'] ?? 'Residuo JS Quest hardcoded rilevato nel core.'),
        );
    }

    $legacyCoreFiles = [
        '/assets/js/app/modules/game/QuestsModule.js',
        '/assets/js/app/features/game/QuestsPage.js',
        '/assets/js/app/features/game/QuestHistoryPage.js',
        '/assets/js/app/modules/admin/QuestsModule.js',
        '/assets/js/app/features/admin/Quests.js',
    ];
    foreach ($legacyCoreFiles as $legacyPath) {
        questsCutoverAssert(
            !is_file($root . $legacyPath),
            'File JS Quest legacy ancora presente nel core: ' . $legacyPath,
        );
    }
}

function questsCutoverAssertModuleJsAssetsPresent(string $root): void
{
    $manifestPath = $root . '/modules/logeon.quests/module.json';
    $manifestRaw = file_get_contents($manifestPath);
    questsCutoverAssert(
        is_string($manifestRaw),
        'Impossibile leggere modules/logeon.quests/module.json.',
    );

    $manifest = json_decode((string) $manifestRaw, true);
    questsCutoverAssert(
        is_array($manifest),
        'Manifest modules/logeon.quests/module.json non valido.',
    );

    $adminJs = $manifest['assets']['admin']['js'][0] ?? null;
    $gameJs = $manifest['assets']['game']['js'][0] ?? null;
    questsCutoverAssert(
        is_string($adminJs) && $adminJs === 'dist/admin.js',
        'Manifest Quest senza asset admin.js atteso (dist/admin.js).',
    );
    questsCutoverAssert(
        is_string($gameJs) && $gameJs === 'dist/game.js',
        'Manifest Quest senza asset game.js atteso (dist/game.js).',
    );

    $moduleFiles = [
        '/modules/logeon.quests/assets/js/index.admin.js',
        '/modules/logeon.quests/assets/js/index.game.js',
        '/modules/logeon.quests/assets/js/admin/QuestsModule.js',
        '/modules/logeon.quests/assets/js/admin/Quests.js',
        '/modules/logeon.quests/assets/js/game/QuestsModule.js',
        '/modules/logeon.quests/assets/js/game/QuestsPage.js',
        '/modules/logeon.quests/assets/js/game/QuestHistoryPage.js',
        '/modules/logeon.quests/dist/admin.js',
        '/modules/logeon.quests/dist/game.js',
    ];

    foreach ($moduleFiles as $moduleFile) {
        questsCutoverAssert(
            is_file($root . $moduleFile),
            'Asset JS Quest modulo mancante: ' . $moduleFile,
        );
    }
}

$moduleId = 'logeon.quests';
$manager = new ModuleManager();
$db = DbAdapterFactory::createFromConfig();

$expectedOnRouteSignatures = [
    'POST|/quests/list|Modules\\Logeon\\Quests\\Controllers\\Quests@list',
    'POST|/admin/quests/definitions/list|Modules\\Logeon\\Quests\\Controllers\\AdminQuests@definitionsList',
    'POST|/admin/quests/maintenance/run|Modules\\Logeon\\Quests\\Controllers\\AdminQuests@maintenanceRun',
    'GET|/game/quests/history|Closure',
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
    questsCutoverAssertCoreRoutesAreClean($root);
    questsCutoverAssertCoreTemplatesAreClean($root);
    questsCutoverAssertCoreJsIsClean($root);
    questsCutoverAssertModuleJsAssetsPresent($root);

    $discovered = $manager->discover();
    questsCutoverAssert(
        isset($discovered[$moduleId]),
        'Modulo ' . $moduleId . ' non rilevato in discover().',
    );

    fwrite(STDOUT, '[STEP] force module OFF and capture baseline snapshot' . PHP_EOL);
    if ($manager->isActive($moduleId)) {
        $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
        questsCutoverEnsureManagerOk($deactivateResult, 'deactivate baseline');
    }
    $offSnapshot = questsCutoverSnapshot();

    fwrite(STDOUT, '[STEP] activate module and capture ON snapshot' . PHP_EOL);
    $activateResult = $manager->activate($moduleId);
    questsCutoverEnsureManagerOk($activateResult, 'activate');
    $onSnapshot = questsCutoverSnapshot();

    questsCutoverAssert(
        !questsCutoverHasFragmentId($offSnapshot['admin_fragments'], 'quests-admin-dashboard-page'),
        'Fragment quest admin dashboard presente nello stato OFF.',
    );
    questsCutoverAssert(
        !questsCutoverHasFragmentId($offSnapshot['game_modal_fragments'], 'quests-game-modals'),
        'Fragment quest game modals presente nello stato OFF.',
    );
    questsCutoverAssert(
        !questsCutoverHasFragmentId($offSnapshot['game_navbar_fragments'], 'quests-game-navbar-link'),
        'Fragment quest game navbar presente nello stato OFF.',
    );
    questsCutoverAssert(
        !questsCutoverHasFragmentId($offSnapshot['game_offcanvas_fragments'], 'quests-game-offcanvas-link'),
        'Fragment quest game offcanvas presente nello stato OFF.',
    );
    questsCutoverAssert(
        empty($offSnapshot['routes']),
        'Route quest registrate nello stato OFF.',
    );

    $hookNames = array_keys(is_array($offSnapshot['hook_counts']) ? $offSnapshot['hook_counts'] : []);
    foreach ($hookNames as $hookName) {
        $offCount = (int) ($offSnapshot['hook_counts'][$hookName] ?? 0);
        $onCount = (int) ($onSnapshot['hook_counts'][$hookName] ?? 0);
        questsCutoverAssert(
            $offCount === 0,
            'Listener quest inatteso nello stato OFF per hook `' . $hookName . '`.',
        );
        questsCutoverAssert(
            $onCount > $offCount,
            'Listener quest non registrato nello stato ON per hook `' . $hookName . '`.',
        );
    }

    questsCutoverAssert(
        questsCutoverHasFragmentId($onSnapshot['admin_fragments'], 'quests-admin-dashboard-page'),
        'Fragment quest admin dashboard assente nello stato ON.',
    );
    questsCutoverAssert(
        questsCutoverHasFragmentId($onSnapshot['game_modal_fragments'], 'quests-game-modals'),
        'Fragment quest game modals assente nello stato ON.',
    );
    questsCutoverAssert(
        questsCutoverHasFragmentId($onSnapshot['game_navbar_fragments'], 'quests-game-navbar-link'),
        'Fragment quest game navbar assente nello stato ON.',
    );
    questsCutoverAssert(
        questsCutoverHasFragmentId($onSnapshot['game_offcanvas_fragments'], 'quests-game-offcanvas-link'),
        'Fragment quest game offcanvas assente nello stato ON.',
    );

    $onRouteSignatures = [];
    foreach ($onSnapshot['routes'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $onRouteSignatures[] = (string) ($row['method'] ?? '') . '|'
            . (string) ($row['pattern'] ?? '') . '|'
            . (string) ($row['fn'] ?? '');
    }
    $onRouteSignatures = array_values(array_unique($onRouteSignatures));

    foreach ($expectedOnRouteSignatures as $signature) {
        questsCutoverAssert(
            in_array($signature, $onRouteSignatures, true),
            'Route quest attesa assente nello stato ON: ' . $signature,
        );
    }

    fwrite(STDOUT, '[OK] Quests module cutover smoke passed.' . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Quests module cutover smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        if (!$originalInstalled) {
            if ($manager->isActive($moduleId)) {
                $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                questsCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
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
                questsCutoverEnsureManagerOk($activateResult, 'restore activate');
            } else {
                if ($manager->isActive($moduleId)) {
                    $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                    questsCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
                }

                $db->executePrepared(
                    'UPDATE sys_modules
                     SET status = ?, last_error = ?, date_updated = NOW()
                     WHERE module_id = ?',
                    [$originalStatus, $originalLastError, $moduleId],
                );
            }
        }
    } catch (Throwable $restoreError) {
        fwrite(STDERR, '[WARN] Restore module state failed: ' . $restoreError->getMessage() . PHP_EOL);
    }
}

exit(0);
