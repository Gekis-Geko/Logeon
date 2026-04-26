<?php

declare(strict_types=1);

/**
 * Multi-currency module cutover smoke (CLI).
 *
 * Validates controlled ON/OFF cutover for module `logeon.multi-currency`:
 * - OFF: no module slot fragments/routes/hooks.
 * - ON: module slot fragments/routes/hooks are registered.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-multi-currency-module-cutover-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/configs/app.php';
require_once $root . '/vendor/autoload.php';

use App\Services\CharacterStateService;
use App\Services\CurrencyService;
use Core\Database\DbAdapterFactory;
use Core\ModuleManager;
use Core\ModuleRuntime;
use Core\Router;

function multiCurrencyCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string,mixed> $result
 */
function multiCurrencyCutoverEnsureManagerOk(array $result, string $step): void
{
    if (!isset($result['ok']) || $result['ok'] !== true) {
        $code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown';
        $message = isset($result['message']) ? (string) $result['message'] : 'errore sconosciuto';
        throw new RuntimeException($step . ' fallito [' . $code . ']: ' . $message);
    }
}

function multiCurrencyCutoverResetHooks(): void
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
function multiCurrencyCutoverNormalizeFragments($fragments): array
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
function multiCurrencyCutoverHasFragmentId(array $fragments, string $id): bool
{
    foreach ($fragments as $fragment) {
        if ((string) ($fragment['id'] ?? '') === $id) {
            return true;
        }
    }

    return false;
}

function multiCurrencyCutoverHookCount(string $hookName): int
{
    if (!class_exists('\\Core\\Hooks')) {
        return 0;
    }

    $prop = new ReflectionProperty(\Core\Hooks::class, 'actions');
    $prop->setAccessible(true);
    $actions = $prop->getValue();
    if (!is_array($actions)) {
        return 0;
    }

    $entries = $actions[$hookName] ?? null;
    return is_array($entries) ? count($entries) : 0;
}

/**
 * @return array<int,array{method:string,pattern:string,fn:string}>
 */
function multiCurrencyCutoverRegisteredRoutes(): array
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
        '/admin/multi-currencies/list' => true,
        '/admin/multi-currencies/create' => true,
        '/admin/multi-currencies/update' => true,
        '/admin/multi-currencies/delete' => true,
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
 * @return array{has_default_currency:bool,character_id:int}
 */
function multiCurrencyCutoverResolveScenario($db): array
{
    $hasDefaultCurrency = false;
    $characterId = 0;

    try {
        $defaultCurrency = $db->fetchOnePrepared(
            'SELECT id
             FROM currencies
             WHERE is_default = 1
               AND is_active = 1
             LIMIT 1',
        );
        $hasDefaultCurrency = is_object($defaultCurrency) && (int) ($defaultCurrency->id ?? 0) > 0;
    } catch (Throwable $e) {
        $hasDefaultCurrency = false;
    }

    try {
        $character = $db->fetchOnePrepared(
            'SELECT id
             FROM characters
             ORDER BY id ASC
             LIMIT 1',
        );
        if (is_object($character)) {
            $characterId = (int) ($character->id ?? 0);
        }
    } catch (Throwable $e) {
        $characterId = 0;
    }

    return [
        'has_default_currency' => $hasDefaultCurrency,
        'character_id' => $characterId,
    ];
}

/**
 * @param array{has_default_currency:bool,character_id:int} $scenario
 * @return array<string,mixed>
 */
function multiCurrencyCutoverSnapshot(array $scenario): array
{
    multiCurrencyCutoverResetHooks();
    ModuleRuntime::reset();
    ModuleRuntime::instance()->bootActiveModules();

    $currencyService = new CurrencyService();
    $available = $currencyService->listAvailable();

    $characterWalletCount = 0;
    $characterId = (int) ($scenario['character_id'] ?? 0);
    if ($characterId > 0) {
        $wallets = (new CharacterStateService())->getWallets($characterId);
        $characterWalletCount = is_array($wallets) ? count($wallets) : 0;
    }

    return [
        'profile_fragments' => multiCurrencyCutoverNormalizeFragments(
            \Core\Hooks::filter('twig.slot.character.profile.wallets', []),
        ),
        'shop_fragments' => multiCurrencyCutoverNormalizeFragments(
            \Core\Hooks::filter('twig.slot.shop.price.extra', []),
        ),
        'routes' => multiCurrencyCutoverRegisteredRoutes(),
        'available_count' => is_array($available) ? count($available) : 0,
        'wallets_count' => $characterWalletCount,
        'hook_counts' => [
            'currency.extra_wallets' => multiCurrencyCutoverHookCount('currency.extra_wallets'),
            'currency.available_list' => multiCurrencyCutoverHookCount('currency.available_list'),
            'twig.slot.character.profile.wallets' => multiCurrencyCutoverHookCount('twig.slot.character.profile.wallets'),
            'twig.slot.shop.price.extra' => multiCurrencyCutoverHookCount('twig.slot.shop.price.extra'),
        ],
    ];
}

function multiCurrencyCutoverAssertCoreRoutesAreClean(string $root): void
{
    $coreApiPath = $root . '/app/routes/api.php';
    $coreApi = file_get_contents($coreApiPath);
    multiCurrencyCutoverAssert(
        is_string($coreApi),
        'Impossibile leggere app/routes/api.php per verifica route core Multi Currency.',
    );

    multiCurrencyCutoverAssert(
        strpos($coreApi, '/admin/multi-currencies/') === false,
        'Route modulo Multi Currency ancora hardcoded nel core app/routes/api.php.',
    );
    multiCurrencyCutoverAssert(
        strpos($coreApi, 'AdminCurrencies@') === false,
        'Controller modulo Multi Currency ancora hardcoded nel core app/routes/api.php.',
    );
}

function multiCurrencyCutoverAssertCoreTemplatesAreReady(string $root): void
{
    $profilePath = $root . '/app/views/app/profile.twig';
    $profileTemplate = file_get_contents($profilePath);
    multiCurrencyCutoverAssert(is_string($profileTemplate), 'Impossibile leggere app/views/app/profile.twig.');
    multiCurrencyCutoverAssert(
        strpos($profileTemplate, "slot('character.profile.wallets'") !== false,
        'Slot character.profile.wallets assente in app/views/app/profile.twig.',
    );
    multiCurrencyCutoverAssert(
        strpos($profileTemplate, 'multi-currency') === false,
        'Riferimento hardcoded al modulo Multi Currency presente in profile.twig.',
    );

    $shopPath = $root . '/app/views/app/shop.twig';
    $shopTemplate = file_get_contents($shopPath);
    multiCurrencyCutoverAssert(is_string($shopTemplate), 'Impossibile leggere app/views/app/shop.twig.');
    multiCurrencyCutoverAssert(
        strpos($shopTemplate, "slot('shop.price.extra'") !== false,
        'Slot shop.price.extra assente in app/views/app/shop.twig.',
    );
    multiCurrencyCutoverAssert(
        strpos($shopTemplate, 'multi-currency') === false,
        'Riferimento hardcoded al modulo Multi Currency presente in shop.twig.',
    );
}

$moduleId = 'logeon.multi-currency';
$manager = new ModuleManager();
$db = DbAdapterFactory::createFromConfig();
$scenario = multiCurrencyCutoverResolveScenario($db);

$expectedOnRoutes = [
    'POST|/admin/multi-currencies/list|Modules\\Logeon\\MultiCurrency\\Controllers\\AdminCurrencies@list',
    'POST|/admin/multi-currencies/create|Modules\\Logeon\\MultiCurrency\\Controllers\\AdminCurrencies@create',
    'POST|/admin/multi-currencies/update|Modules\\Logeon\\MultiCurrency\\Controllers\\AdminCurrencies@update',
    'POST|/admin/multi-currencies/delete|Modules\\Logeon\\MultiCurrency\\Controllers\\AdminCurrencies@delete',
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
    multiCurrencyCutoverAssertCoreRoutesAreClean($root);
    multiCurrencyCutoverAssertCoreTemplatesAreReady($root);

    $discovered = $manager->discover();
    multiCurrencyCutoverAssert(
        isset($discovered[$moduleId]),
        'Modulo ' . $moduleId . ' non rilevato in discover().',
    );

    fwrite(STDOUT, '[STEP] force module OFF and capture baseline snapshot' . PHP_EOL);
    if ($manager->isActive($moduleId)) {
        $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
        multiCurrencyCutoverEnsureManagerOk($deactivateResult, 'deactivate baseline');
    }
    $offSnapshot = multiCurrencyCutoverSnapshot($scenario);

    fwrite(STDOUT, '[STEP] activate module and capture ON snapshot' . PHP_EOL);
    $activateResult = $manager->activate($moduleId);
    multiCurrencyCutoverEnsureManagerOk($activateResult, 'activate');
    $onSnapshot = multiCurrencyCutoverSnapshot($scenario);

    multiCurrencyCutoverAssert(
        !multiCurrencyCutoverHasFragmentId($offSnapshot['profile_fragments'], 'multi-currency-profile-wallets'),
        'Fragment profile wallets presente nello stato OFF.',
    );
    multiCurrencyCutoverAssert(
        !multiCurrencyCutoverHasFragmentId($offSnapshot['shop_fragments'], 'multi-currency-shop-price-extra'),
        'Fragment shop price presente nello stato OFF.',
    );
    multiCurrencyCutoverAssert(
        empty($offSnapshot['routes']),
        'Route Multi Currency registrate nello stato OFF.',
    );

    multiCurrencyCutoverAssert(
        multiCurrencyCutoverHasFragmentId($onSnapshot['profile_fragments'], 'multi-currency-profile-wallets'),
        'Fragment profile wallets assente nello stato ON.',
    );
    multiCurrencyCutoverAssert(
        multiCurrencyCutoverHasFragmentId($onSnapshot['shop_fragments'], 'multi-currency-shop-price-extra'),
        'Fragment shop price assente nello stato ON.',
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
    foreach ($expectedOnRoutes as $expectedRoute) {
        multiCurrencyCutoverAssert(
            in_array($expectedRoute, $onRouteSet, true),
            'Route Multi Currency mancante nello stato ON: ' . $expectedRoute,
        );
    }

    foreach ((array) ($offSnapshot['hook_counts'] ?? []) as $hookName => $count) {
        multiCurrencyCutoverAssert(
            (int) $count === 0,
            'Hook modulo inatteso nello stato OFF: ' . (string) $hookName,
        );
    }
    foreach ((array) ($onSnapshot['hook_counts'] ?? []) as $hookName => $count) {
        multiCurrencyCutoverAssert(
            (int) $count > 0,
            'Hook modulo assente nello stato ON: ' . (string) $hookName,
        );
    }

    if ((bool) ($scenario['has_default_currency'] ?? false) === true) {
        multiCurrencyCutoverAssert(
            (int) ($offSnapshot['available_count'] ?? 0) >= 1,
            'OFF: listAvailable() dovrebbe includere almeno la valuta default.',
        );
        multiCurrencyCutoverAssert(
            (int) ($onSnapshot['available_count'] ?? 0) >= (int) ($offSnapshot['available_count'] ?? 0),
            'ON: listAvailable() non dovrebbe ridurre la lista valute disponibili.',
        );
    } else {
        fwrite(STDOUT, '[INFO] Default currency non disponibile: check listAvailable differenziale saltato.' . PHP_EOL);
    }

    if ((int) ($scenario['character_id'] ?? 0) > 0) {
        multiCurrencyCutoverAssert(
            (int) ($onSnapshot['wallets_count'] ?? 0) >= (int) ($offSnapshot['wallets_count'] ?? 0),
            'ON: wallets_count non dovrebbe ridursi rispetto a OFF.',
        );
    } else {
        fwrite(STDOUT, '[INFO] Nessun personaggio disponibile: check wallets_count differenziale saltato.' . PHP_EOL);
    }

    fwrite(STDOUT, '[OK] Multi-currency module cutover smoke passed.' . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Multi-currency module cutover smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        if (!$originalInstalled) {
            if ($manager->isActive($moduleId)) {
                $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                multiCurrencyCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
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
                multiCurrencyCutoverEnsureManagerOk($activateResult, 'restore activate');
            } else {
                if ($manager->isActive($moduleId)) {
                    $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                    multiCurrencyCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
                }

                $db->executePrepared(
                    'UPDATE sys_modules
                     SET status = ?, last_error = ?, date_updated = NOW()
                     WHERE module_id = ?',
                    [$originalStatus, $originalLastError, $moduleId],
                );
            }
        }

        multiCurrencyCutoverResetHooks();
        ModuleRuntime::reset();
    } catch (Throwable $restoreError) {
        fwrite(STDERR, '[WARN] Restore module state failed: ' . $restoreError->getMessage() . PHP_EOL);
    }
}

exit(0);
