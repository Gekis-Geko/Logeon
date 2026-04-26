<?php

declare(strict_types=1);

/**
 * Social status module cutover smoke (CLI).
 *
 * Validates controlled ON/OFF cutover for module `logeon.social-status`:
 * - OFF: fallback provider active, no social-status admin slot fragments, no social-status routes.
 * - ON: module provider resolved, admin slot fragment and routes registered by module runtime.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-social-status-module-cutover-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/configs/app.php';
require_once $root . '/vendor/autoload.php';

use App\Services\SocialStatusProviderRegistry;
use Core\Database\DbAdapterFactory;
use Core\ModuleManager;
use Core\ModuleRuntime;
use Core\Router;

function socialStatusCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string,mixed> $result
 */
function socialStatusCutoverEnsureManagerOk(array $result, string $step): void
{
    if (!isset($result['ok']) || $result['ok'] !== true) {
        $code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown';
        $message = isset($result['message']) ? (string) $result['message'] : 'errore sconosciuto';
        throw new RuntimeException($step . ' fallito [' . $code . ']: ' . $message);
    }
}

function socialStatusCutoverResetHooks(): void
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
function socialStatusCutoverNormalizeFragments($fragments): array
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
function socialStatusCutoverHasFragmentId(array $fragments, string $id): bool
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
function socialStatusCutoverRegisteredRoutes(): array
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
        '/admin/characters/social-status' => true,
        '/admin/social-status/list' => true,
        '/admin/social-status/admin-list' => true,
        '/admin/social-status/create' => true,
        '/admin/social-status/update' => true,
        '/admin/social-status/delete' => true,
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
function socialStatusCutoverResolveScenario($db): array
{
    $discountCharacterId = 0;
    $discountExpected = 0.0;
    $discountAvailable = false;

    $mismatchCharacterId = 0;
    $mismatchRequiredStatusId = 0;
    $mismatchAvailable = false;

    try {
        $row = $db->fetchOnePrepared(
            'SELECT c.id AS character_id, ss.shop_discount
             FROM characters c
             INNER JOIN social_status ss ON ss.id = c.socialstatus_id
             WHERE ss.shop_discount > 0
             ORDER BY ss.shop_discount DESC, c.id ASC
             LIMIT 1',
            [],
        );

        if (is_object($row)) {
            $discountCharacterId = (int) ($row->character_id ?? 0);
            $discountExpected = (float) ($row->shop_discount ?? 0.0);
            $discountAvailable = $discountCharacterId > 0 && $discountExpected > 0.0;
        }
    } catch (Throwable $e) {
        $discountAvailable = false;
    }

    try {
        $row = $db->fetchOnePrepared(
            'SELECT c.id AS character_id, ss2.id AS required_status_id
             FROM characters c
             INNER JOIN social_status ss1 ON ss1.id = c.socialstatus_id
             INNER JOIN social_status ss2 ON ss2.id <> c.socialstatus_id
             ORDER BY c.id ASC, ss2.id ASC
             LIMIT 1',
            [],
        );

        if (is_object($row)) {
            $mismatchCharacterId = (int) ($row->character_id ?? 0);
            $mismatchRequiredStatusId = (int) ($row->required_status_id ?? 0);
            $mismatchAvailable = $mismatchCharacterId > 0 && $mismatchRequiredStatusId > 0;
        }
    } catch (Throwable $e) {
        $mismatchAvailable = false;
    }

    return [
        'discount_character_id' => $discountCharacterId,
        'discount_expected' => $discountExpected,
        'discount_available' => $discountAvailable,
        'mismatch_character_id' => $mismatchCharacterId,
        'mismatch_required_status_id' => $mismatchRequiredStatusId,
        'mismatch_available' => $mismatchAvailable,
    ];
}

/**
 * @param array<string,mixed> $scenario
 * @return array<string,mixed>
 */
function socialStatusCutoverSnapshot(array $scenario): array
{
    socialStatusCutoverResetHooks();
    ModuleRuntime::reset();
    ModuleRuntime::instance()->bootActiveModules();
    SocialStatusProviderRegistry::resetRuntimeState();
    SocialStatusProviderRegistry::setProvider(null);

    $provider = SocialStatusProviderRegistry::provider();
    $list = SocialStatusProviderRegistry::listAll();

    $discountCharacterId = (int) ($scenario['discount_character_id'] ?? 0);
    $discountValue = $discountCharacterId > 0
        ? (float) SocialStatusProviderRegistry::getShopDiscount($discountCharacterId)
        : 0.0;

    $mismatchCharacterId = (int) ($scenario['mismatch_character_id'] ?? 0);
    $mismatchRequiredStatusId = (int) ($scenario['mismatch_required_status_id'] ?? 0);
    $mismatchResult = true;
    if ($mismatchCharacterId > 0 && $mismatchRequiredStatusId > 0) {
        $mismatchResult = SocialStatusProviderRegistry::meetsRequirement(
            $mismatchCharacterId,
            $mismatchRequiredStatusId,
        );
    }

    return [
        'provider_class' => get_class($provider),
        'admin_fragments' => socialStatusCutoverNormalizeFragments(
            \Core\Hooks::filter('twig.slot.admin.dashboard.social-status', []),
        ),
        'routes' => socialStatusCutoverRegisteredRoutes(),
        'list_count' => count($list),
        'discount_value' => $discountValue,
        'mismatch_meets_requirement' => $mismatchResult,
    ];
}

function socialStatusCutoverAssertCoreRoutesAreClean(string $root): void
{
    $coreApiPath = $root . '/app/routes/api.php';
    $coreApi = file_get_contents($coreApiPath);
    socialStatusCutoverAssert(is_string($coreApi), 'Impossibile leggere app/routes/api.php per verifica route core.');

    $needles = [
        'Characters@setSocialStatus',
        'Characters@listSocialStatus',
        'SocialStatuses@adminList',
        'SocialStatuses@adminCreate',
        'SocialStatuses@adminUpdate',
        'SocialStatuses@adminDelete',
    ];
    foreach ($needles as $needle) {
        socialStatusCutoverAssert(
            strpos($coreApi, $needle) === false,
            'Callsite route Social Status ancora presente nel core app/routes/api.php: ' . $needle,
        );
    }
}

function socialStatusCutoverAssertCoreTemplatesAreClean(string $root): void
{
    $dashboardPath = $root . '/app/views/admin/dashboard.twig';
    $dashboardTemplate = file_get_contents($dashboardPath);
    socialStatusCutoverAssert(is_string($dashboardTemplate), 'Impossibile leggere app/views/admin/dashboard.twig.');
    socialStatusCutoverAssert(
        strpos($dashboardTemplate, "current_page == 'social-status'") === false,
        'Pagina Social Status ancora hardcoded nel core admin/dashboard.twig.',
    );
    socialStatusCutoverAssert(
        strpos($dashboardTemplate, "include 'admin/pages/social-status.twig'") === false,
        'Template Social Status ancora incluso direttamente nel core admin/dashboard.twig.',
    );

    $asidePath = $root . '/app/views/admin/layouts/aside.twig';
    $asideTemplate = file_get_contents($asidePath);
    socialStatusCutoverAssert(is_string($asideTemplate), 'Impossibile leggere app/views/admin/layouts/aside.twig.');
    socialStatusCutoverAssert(
        strpos($asideTemplate, 'href="/admin/social-status"') === false,
        'Link Social Status ancora hardcoded nel core admin/layouts/aside.twig.',
    );
}

$moduleId = 'logeon.social-status';
$manager = new ModuleManager();
$db = DbAdapterFactory::createFromConfig();
$scenario = socialStatusCutoverResolveScenario($db);

$expectedOnRoutes = [
    'POST|/admin/characters/social-status|Characters@setSocialStatus',
    'POST|/admin/social-status/list|Characters@listSocialStatus',
    'POST|/admin/social-status/admin-list|Modules\Logeon\SocialStatus\Controllers\SocialStatuses@adminList',
    'POST|/admin/social-status/create|Modules\Logeon\SocialStatus\Controllers\SocialStatuses@adminCreate',
    'POST|/admin/social-status/update|Modules\Logeon\SocialStatus\Controllers\SocialStatuses@adminUpdate',
    'POST|/admin/social-status/delete|Modules\Logeon\SocialStatus\Controllers\SocialStatuses@adminDelete',
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
    socialStatusCutoverAssertCoreRoutesAreClean($root);
    socialStatusCutoverAssertCoreTemplatesAreClean($root);

    $discovered = $manager->discover();
    socialStatusCutoverAssert(
        isset($discovered[$moduleId]),
        'Modulo ' . $moduleId . ' non rilevato in discover().',
    );

    fwrite(STDOUT, '[STEP] force module OFF and capture baseline snapshot' . PHP_EOL);
    if ($manager->isActive($moduleId)) {
        $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
        socialStatusCutoverEnsureManagerOk($deactivateResult, 'deactivate baseline');
    }
    $offSnapshot = socialStatusCutoverSnapshot($scenario);

    fwrite(STDOUT, '[STEP] activate module and capture ON snapshot' . PHP_EOL);
    $activateResult = $manager->activate($moduleId);
    socialStatusCutoverEnsureManagerOk($activateResult, 'activate');
    $onSnapshot = socialStatusCutoverSnapshot($scenario);

    $moduleProviderClass = 'Modules\\Logeon\\SocialStatus\\SocialStatusModuleProvider';
    socialStatusCutoverAssert(
        $offSnapshot['provider_class'] !== $moduleProviderClass,
        'Provider modulo Social Status inatteso nello stato OFF.',
    );
    socialStatusCutoverAssert(
        $onSnapshot['provider_class'] === $moduleProviderClass,
        'Provider modulo Social Status non risolto nello stato ON.',
    );

    socialStatusCutoverAssert(
        !socialStatusCutoverHasFragmentId($offSnapshot['admin_fragments'], 'social-status-admin-dashboard-page'),
        'Fragment social-status admin dashboard presente nello stato OFF.',
    );
    socialStatusCutoverAssert(
        socialStatusCutoverHasFragmentId($onSnapshot['admin_fragments'], 'social-status-admin-dashboard-page'),
        'Fragment social-status admin dashboard assente nello stato ON.',
    );

    socialStatusCutoverAssert(
        empty($offSnapshot['routes']),
        'Route Social Status registrate nello stato OFF.',
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
        socialStatusCutoverAssert(
            in_array($expectedOnRoute, $onRouteSet, true),
            'Route Social Status mancante nello stato ON: ' . $expectedOnRoute,
        );
    }

    socialStatusCutoverAssert(
        (int) ($offSnapshot['list_count'] ?? -1) === 0,
        'Provider fallback Social Status non no-op su listAll() nello stato OFF.',
    );

    if ((bool) ($scenario['discount_available'] ?? false) === true) {
        $expectedDiscount = (float) ($scenario['discount_expected'] ?? 0.0);
        $offDiscount = (float) ($offSnapshot['discount_value'] ?? 0.0);
        $onDiscount = (float) ($onSnapshot['discount_value'] ?? 0.0);

        socialStatusCutoverAssert(
            abs($offDiscount - 0.0) < 0.0001,
            'Provider fallback Social Status dovrebbe restituire discount 0 nello stato OFF.',
        );
        socialStatusCutoverAssert(
            abs($onDiscount - $expectedDiscount) < 0.0001,
            'Provider modulo Social Status non restituisce il discount atteso nello stato ON.',
        );
    } else {
        fwrite(STDOUT, '[INFO] Scenario discount > 0 non disponibile: check discount differenziale saltato.' . PHP_EOL);
    }

    if ((bool) ($scenario['mismatch_available'] ?? false) === true) {
        socialStatusCutoverAssert(
            (bool) ($offSnapshot['mismatch_meets_requirement'] ?? false) === true,
            'Fallback Social Status dovrebbe consentire requisito mismatch nello stato OFF.',
        );
        socialStatusCutoverAssert(
            (bool) ($onSnapshot['mismatch_meets_requirement'] ?? true) === false,
            'Provider modulo Social Status dovrebbe negare requisito mismatch nello stato ON.',
        );
    } else {
        fwrite(STDOUT, '[INFO] Scenario mismatch status non disponibile: check differenziale requirement saltato.' . PHP_EOL);
    }

    fwrite(STDOUT, '[OK] Social status module cutover smoke passed.' . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Social status module cutover smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        if (!$originalInstalled) {
            if ($manager->isActive($moduleId)) {
                $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                socialStatusCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
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
                socialStatusCutoverEnsureManagerOk($activateResult, 'restore activate');
            } else {
                if ($manager->isActive($moduleId)) {
                    $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                    socialStatusCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
                }

                $db->executePrepared(
                    'UPDATE sys_modules
                     SET status = ?, last_error = ?, date_updated = NOW()
                     WHERE module_id = ?',
                    [$originalStatus, $originalLastError, $moduleId],
                );
            }
        }

        socialStatusCutoverResetHooks();
        ModuleRuntime::reset();
        SocialStatusProviderRegistry::resetRuntimeState();
        SocialStatusProviderRegistry::setProvider(null);
    } catch (Throwable $restoreError) {
        fwrite(STDERR, '[WARN] Restore module state failed: ' . $restoreError->getMessage() . PHP_EOL);
    }
}

exit(0);
