<?php

declare(strict_types=1);

/**
 * Novelty module cutover smoke (CLI).
 *
 * Validates controlled ON/OFF cutover for module `logeon.novelty`:
 * - OFF: no novelty feed hook contribution, no novelty slot fragments, no novelty routes.
 * - ON: novelty feed hook active, novelty slot fragments registered, novelty routes registered.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-novelty-module-cutover-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/configs/app.php';
require_once $root . '/vendor/autoload.php';

use Core\Database\DbAdapterFactory;
use Core\ModuleManager;
use Core\ModuleRuntime;
use Core\Router;

function noveltyCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string,mixed> $result
 */
function noveltyCutoverEnsureManagerOk(array $result, string $step): void
{
    if (!isset($result['ok']) || $result['ok'] !== true) {
        $code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown';
        $message = isset($result['message']) ? (string) $result['message'] : 'errore sconosciuto';
        throw new RuntimeException($step . ' fallito [' . $code . ']: ' . $message);
    }
}

function noveltyCutoverResetHooks(): void
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
function noveltyCutoverNormalizeFragments($fragments): array
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
function noveltyCutoverHasFragmentId(array $fragments, string $id): bool
{
    foreach ($fragments as $fragment) {
        if ((string) ($fragment['id'] ?? '') === $id) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<int,array{method:string,pattern:string,fn:string}>
 */
function noveltyCutoverRegisteredNoveltyRoutes(): array
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
        '/admin/news/list' => true,
        '/admin/news/create' => true,
        '/admin/news/update' => true,
        '/admin/news/delete' => true,
        '/list/news' => true,
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
 * @return array<string,mixed>
 */
function noveltyCutoverSnapshot(string $feedSentinel): array
{
    noveltyCutoverResetHooks();
    ModuleRuntime::reset();
    ModuleRuntime::instance()->bootActiveModules();

    $adminFragments = noveltyCutoverNormalizeFragments(
        \Core\Hooks::filter('twig.slot.admin.dashboard.news', []),
    );
    $gameFragments = noveltyCutoverNormalizeFragments(
        \Core\Hooks::filter('twig.slot.game.modals', []),
    );
    $navbarFragments = noveltyCutoverNormalizeFragments(
        \Core\Hooks::filter('twig.slot.game.navbar.news_link', []),
    );
    $offcanvasFragments = noveltyCutoverNormalizeFragments(
        \Core\Hooks::filter('twig.slot.game.offcanvas.mobile.news_link', []),
    );

    $feedDefault = \Core\Hooks::filter('novelty.homepage_feed', [], 6);
    $feedWithSentinel = \Core\Hooks::filter('novelty.homepage_feed', $feedSentinel, 6);

    return [
        'admin_fragments' => $adminFragments,
        'game_fragments' => $gameFragments,
        'navbar_fragments' => $navbarFragments,
        'offcanvas_fragments' => $offcanvasFragments,
        'feed_default' => $feedDefault,
        'feed_sentinel' => $feedWithSentinel,
        'novelty_routes' => noveltyCutoverRegisteredNoveltyRoutes(),
    ];
}

function noveltyCutoverAssertCoreRoutesAreClean(string $root): void
{
    $coreApiPath = $root . '/app/routes/api.php';
    $coreApi = file_get_contents($coreApiPath);
    noveltyCutoverAssert(is_string($coreApi), 'Impossibile leggere app/routes/api.php per verifica route core.');

    noveltyCutoverAssert(
        strpos($coreApi, '/list/news') === false,
        'Route /list/news ancora presente nel core app/routes/api.php.',
    );
    noveltyCutoverAssert(
        strpos($coreApi, '/admin/news/') === false,
        'Route /admin/news/* ancora presente nel core app/routes/api.php.',
    );
}

function noveltyCutoverAssertCoreTemplatesAreClean(string $root): void
{
    $navbarPath = $root . '/app/views/app/layouts/navbar.twig';
    $navbarTemplate = file_get_contents($navbarPath);
    noveltyCutoverAssert(is_string($navbarTemplate), 'Impossibile leggere app/views/app/layouts/navbar.twig.');
    noveltyCutoverAssert(
        strpos($navbarTemplate, "slot('game.navbar.news_link')") !== false,
        'Slot novelty navbar assente nel core navbar.twig.',
    );
    noveltyCutoverAssert(
        strpos($navbarTemplate, 'data-action="open-news"') === false,
        'Trigger open-news ancora hardcoded nel core navbar.twig.',
    );

    $offcanvasPath = $root . '/app/views/app/offcanvas/navbar.twig';
    $offcanvasTemplate = file_get_contents($offcanvasPath);
    noveltyCutoverAssert(is_string($offcanvasTemplate), 'Impossibile leggere app/views/app/offcanvas/navbar.twig.');
    noveltyCutoverAssert(
        strpos($offcanvasTemplate, "slot('game.offcanvas.mobile.news_link')") !== false,
        'Slot novelty offcanvas assente nel core offcanvas/navbar.twig.',
    );
    noveltyCutoverAssert(
        strpos($offcanvasTemplate, 'data-ref="news"') === false,
        'Markup News ancora hardcoded nel core offcanvas/navbar.twig.',
    );
}

$moduleId = 'logeon.novelty';
$manager = new ModuleManager();
$db = DbAdapterFactory::createFromConfig();
$feedSentinel = '__NOVELTY_FEED_SENTINEL__';

$expectedOnRoutes = [
    'POST|/admin/news/create|Modules\\Logeon\\Novelty\\Controllers\\Novelties@create',
    'POST|/admin/news/delete|Modules\\Logeon\\Novelty\\Controllers\\Novelties@adminDelete',
    'POST|/admin/news/list|Modules\\Logeon\\Novelty\\Controllers\\Novelties@adminList',
    'POST|/admin/news/update|Modules\\Logeon\\Novelty\\Controllers\\Novelties@update',
    'POST|/list/news|Modules\\Logeon\\Novelty\\Controllers\\Novelties@list',
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
    noveltyCutoverAssertCoreRoutesAreClean($root);
    noveltyCutoverAssertCoreTemplatesAreClean($root);

    $discovered = $manager->discover();
    noveltyCutoverAssert(
        isset($discovered[$moduleId]),
        'Modulo ' . $moduleId . ' non rilevato in discover().',
    );

    fwrite(STDOUT, '[STEP] force module OFF and capture baseline snapshot' . PHP_EOL);
    if ($manager->isActive($moduleId)) {
        $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
        noveltyCutoverEnsureManagerOk($deactivateResult, 'deactivate baseline');
    }
    $offSnapshot = noveltyCutoverSnapshot($feedSentinel);

    fwrite(STDOUT, '[STEP] activate module and capture ON snapshot' . PHP_EOL);
    $activateResult = $manager->activate($moduleId);
    noveltyCutoverEnsureManagerOk($activateResult, 'activate');
    $onSnapshot = noveltyCutoverSnapshot($feedSentinel);

    noveltyCutoverAssert(
        !noveltyCutoverHasFragmentId($offSnapshot['admin_fragments'], 'novelty-admin-dashboard-page'),
        'Fragment novelty admin dashboard presente nello stato OFF.',
    );
    noveltyCutoverAssert(
        !noveltyCutoverHasFragmentId($offSnapshot['game_fragments'], 'novelty-game-modal-news'),
        'Fragment novelty game modal presente nello stato OFF.',
    );
    noveltyCutoverAssert(
        !noveltyCutoverHasFragmentId($offSnapshot['navbar_fragments'], 'novelty-game-navbar-news-link'),
        'Fragment novelty navbar presente nello stato OFF.',
    );
    noveltyCutoverAssert(
        !noveltyCutoverHasFragmentId($offSnapshot['offcanvas_fragments'], 'novelty-game-offcanvas-news-link'),
        'Fragment novelty offcanvas presente nello stato OFF.',
    );
    noveltyCutoverAssert(
        $offSnapshot['feed_default'] === [],
        'Feed novelty non vuoto nello stato OFF.',
    );
    noveltyCutoverAssert(
        $offSnapshot['feed_sentinel'] === $feedSentinel,
        'Hook novelty.homepage_feed attivo nello stato OFF.',
    );
    noveltyCutoverAssert(
        empty($offSnapshot['novelty_routes']),
        'Route novelty registrate nello stato OFF.',
    );

    noveltyCutoverAssert(
        noveltyCutoverHasFragmentId($onSnapshot['admin_fragments'], 'novelty-admin-dashboard-page'),
        'Fragment novelty admin dashboard assente nello stato ON.',
    );
    noveltyCutoverAssert(
        noveltyCutoverHasFragmentId($onSnapshot['game_fragments'], 'novelty-game-modal-news'),
        'Fragment novelty game modal assente nello stato ON.',
    );
    noveltyCutoverAssert(
        noveltyCutoverHasFragmentId($onSnapshot['navbar_fragments'], 'novelty-game-navbar-news-link'),
        'Fragment novelty navbar assente nello stato ON.',
    );
    noveltyCutoverAssert(
        noveltyCutoverHasFragmentId($onSnapshot['offcanvas_fragments'], 'novelty-game-offcanvas-news-link'),
        'Fragment novelty offcanvas assente nello stato ON.',
    );
    noveltyCutoverAssert(
        is_array($onSnapshot['feed_default']),
        'Feed novelty non-array nello stato ON.',
    );
    noveltyCutoverAssert(
        is_array($onSnapshot['feed_sentinel']),
        'Hook novelty.homepage_feed non attivo nello stato ON.',
    );

    $onRouteSet = [];
    foreach ($onSnapshot['novelty_routes'] as $row) {
        $onRouteSet[] = $row['method'] . '|' . $row['pattern'] . '|' . $row['fn'];
    }

    foreach ($expectedOnRoutes as $expectedRoute) {
        noveltyCutoverAssert(
            in_array($expectedRoute, $onRouteSet, true),
            'Route novelty mancante nello stato ON: ' . $expectedRoute,
        );
    }

    fwrite(STDOUT, '[OK] Novelty module cutover smoke passed.' . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Novelty module cutover smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        if (!$originalInstalled) {
            if ($manager->isActive($moduleId)) {
                $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                noveltyCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
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
                noveltyCutoverEnsureManagerOk($activateResult, 'restore activate');
            } else {
                if ($manager->isActive($moduleId)) {
                    $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                    noveltyCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
                }

                $db->executePrepared(
                    'UPDATE sys_modules
                     SET status = ?, last_error = ?, date_updated = NOW()
                     WHERE module_id = ?',
                    [$originalStatus, $originalLastError, $moduleId],
                );
            }
        }

        noveltyCutoverResetHooks();
        ModuleRuntime::reset();
    } catch (Throwable $restoreError) {
        fwrite(STDERR, '[WARN] Restore module state failed: ' . $restoreError->getMessage() . PHP_EOL);
    }
}

exit(0);
