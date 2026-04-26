<?php

declare(strict_types=1);

/**
 * Attributes module cutover smoke (CLI).
 *
 * Validates controlled ON/OFF cutover for module `logeon.attributes`:
 * - OFF: no attributes slot fragments, no module routes, fallback provider no-op.
 * - ON: attributes slot fragments and routes registered, module provider active.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-attributes-module-cutover-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/configs/app.php';
require_once $root . '/vendor/autoload.php';

use App\Services\AttributeProviderRegistry;
use Core\Database\DbAdapterFactory;
use Core\ModuleManager;
use Core\ModuleRuntime;
use Core\Router;

function attributesCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string,mixed> $result
 */
function attributesCutoverEnsureManagerOk(array $result, string $step): void
{
    if (!isset($result['ok']) || $result['ok'] !== true) {
        $code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown';
        $message = isset($result['message']) ? (string) $result['message'] : 'errore sconosciuto';
        throw new RuntimeException($step . ' fallito [' . $code . ']: ' . $message);
    }
}

function attributesCutoverResetHooks(): void
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
function attributesCutoverNormalizeFragments($fragments): array
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
function attributesCutoverHasFragmentId(array $fragments, string $id): bool
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
function attributesCutoverRegisteredRoutes(): array
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
        '/admin/character-attributes/settings/get' => true,
        '/admin/character-attributes/settings/update' => true,
        '/admin/character-attributes/definitions/list' => true,
        '/admin/character-attributes/definitions/create' => true,
        '/admin/character-attributes/definitions/update' => true,
        '/admin/character-attributes/definitions/deactivate' => true,
        '/admin/character-attributes/definitions/reorder' => true,
        '/admin/character-attributes/rules/get' => true,
        '/admin/character-attributes/rules/upsert' => true,
        '/admin/character-attributes/rules/delete' => true,
        '/admin/character-attributes/recompute' => true,
        '/admin/equipment-slots/list' => true,
        '/admin/equipment-slots/create' => true,
        '/admin/equipment-slots/update' => true,
        '/admin/equipment-slots/delete' => true,
        '/admin/item-equipment-rules/list' => true,
        '/admin/item-equipment-rules/create' => true,
        '/admin/item-equipment-rules/update' => true,
        '/admin/item-equipment-rules/delete' => true,
        '/profile/attributes/list' => true,
        '/profile/attributes/update-values' => true,
        '/profile/attributes/recompute' => true,
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
function attributesCutoverSnapshot(): array
{
    attributesCutoverResetHooks();
    ModuleRuntime::reset();
    ModuleRuntime::instance()->bootActiveModules();

    AttributeProviderRegistry::resetRuntimeState();
    AttributeProviderRegistry::setProvider(null);

    $adminFragments = attributesCutoverNormalizeFragments(
        \Core\Hooks::filter('twig.slot.admin.dashboard.character-attributes', []),
    );
    $profileModalFragments = attributesCutoverNormalizeFragments(
        \Core\Hooks::filter('twig.slot.game.profile.modals', []),
    );

    $provider = AttributeProviderRegistry::provider();
    $dataset = (object) ['id' => 1001];
    AttributeProviderRegistry::decorateCharacterDataset($dataset, 1001);
    $hasCharacterAttributes = property_exists($dataset, 'character_attributes');

    return [
        'admin_fragments' => $adminFragments,
        'profile_modal_fragments' => $profileModalFragments,
        'provider_class' => get_class($provider),
        'has_character_attributes' => $hasCharacterAttributes,
        'routes' => attributesCutoverRegisteredRoutes(),
    ];
}

function attributesCutoverAssertCoreRoutesAreClean(string $root): void
{
    $coreApiPath = $root . '/app/routes/api.php';
    $coreApi = file_get_contents($coreApiPath);
    attributesCutoverAssert(is_string($coreApi), 'Impossibile leggere app/routes/api.php per verifica route core Attributi.');

    attributesCutoverAssert(
        strpos($coreApi, 'CharacterAttributes@') === false,
        'Route CharacterAttributes ancora presente nel core app/routes/api.php.',
    );
    attributesCutoverAssert(
        strpos($coreApi, '/character-attributes/') === false,
        'Route /character-attributes/* ancora presente nel core app/routes/api.php.',
    );
    attributesCutoverAssert(
        strpos($coreApi, '/attributes/list') === false,
        'Route /profile/attributes/list ancora presente nel core app/routes/api.php.',
    );
}

function attributesCutoverAssertCoreTemplatesAreClean(string $root): void
{
    $dashboardPath = $root . '/app/views/admin/dashboard.twig';
    $dashboardTemplate = file_get_contents($dashboardPath);
    attributesCutoverAssert(is_string($dashboardTemplate), 'Impossibile leggere app/views/admin/dashboard.twig.');
    attributesCutoverAssert(
        strpos($dashboardTemplate, "include 'admin/pages/character-attributes.twig'") === false,
        'Pagina Attributi ancora hardcoded nel core admin/dashboard.twig.',
    );

    $asidePath = $root . '/app/views/admin/layouts/aside.twig';
    $asideTemplate = file_get_contents($asidePath);
    attributesCutoverAssert(is_string($asideTemplate), 'Impossibile leggere app/views/admin/layouts/aside.twig.');
    attributesCutoverAssert(
        strpos($asideTemplate, 'href="/admin/character-attributes"') === false,
        'Link Attributi ancora hardcoded nel core admin/layouts/aside.twig.',
    );

    $profilePath = $root . '/app/views/app/profile.twig';
    $profileTemplate = file_get_contents($profilePath);
    attributesCutoverAssert(is_string($profileTemplate), 'Impossibile leggere app/views/app/profile.twig.');
    attributesCutoverAssert(
        strpos($profileTemplate, "slot('game.profile.modals'") !== false,
        'Slot game.profile.modals assente in app/views/app/profile.twig.',
    );
    attributesCutoverAssert(
        strpos($profileTemplate, "include 'app/modals/profile/edit-attributes.twig'") === false,
        'Modal Attributi ancora hardcoded in app/views/app/profile.twig.',
    );
}

$moduleId = 'logeon.attributes';
$manager = new ModuleManager();
$db = DbAdapterFactory::createFromConfig();

$expectedOnRoutes = [
    'POST|/admin/character-attributes/definitions/create|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminDefinitionsCreate',
    'POST|/admin/character-attributes/definitions/deactivate|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminDefinitionsDeactivate',
    'POST|/admin/character-attributes/definitions/list|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminDefinitionsList',
    'POST|/admin/character-attributes/definitions/reorder|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminDefinitionsReorder',
    'POST|/admin/character-attributes/definitions/update|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminDefinitionsUpdate',
    'POST|/admin/character-attributes/recompute|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminRecompute',
    'POST|/admin/character-attributes/rules/delete|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminRulesDelete',
    'POST|/admin/character-attributes/rules/get|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminRulesGet',
    'POST|/admin/character-attributes/rules/upsert|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminRulesUpsert',
    'POST|/admin/character-attributes/settings/get|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminSettingsGet',
    'POST|/admin/character-attributes/settings/update|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@adminSettingsUpdate',
    'POST|/admin/equipment-slots/list|Modules\\Logeon\\Attributes\\Controllers\\EquipmentSlots@list',
    'POST|/admin/equipment-slots/create|Modules\\Logeon\\Attributes\\Controllers\\EquipmentSlots@create',
    'POST|/admin/equipment-slots/update|Modules\\Logeon\\Attributes\\Controllers\\EquipmentSlots@update',
    'POST|/admin/equipment-slots/delete|Modules\\Logeon\\Attributes\\Controllers\\EquipmentSlots@delete',
    'POST|/admin/item-equipment-rules/list|Modules\\Logeon\\Attributes\\Controllers\\ItemEquipmentRules@list',
    'POST|/admin/item-equipment-rules/create|Modules\\Logeon\\Attributes\\Controllers\\ItemEquipmentRules@create',
    'POST|/admin/item-equipment-rules/update|Modules\\Logeon\\Attributes\\Controllers\\ItemEquipmentRules@update',
    'POST|/admin/item-equipment-rules/delete|Modules\\Logeon\\Attributes\\Controllers\\ItemEquipmentRules@delete',
    'POST|/profile/attributes/list|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@profileList',
    'POST|/profile/attributes/recompute|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@profileRecompute',
    'POST|/profile/attributes/update-values|Modules\\Logeon\\Attributes\\Controllers\\CharacterAttributes@profileUpdateValues',
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
    attributesCutoverAssertCoreRoutesAreClean($root);
    attributesCutoverAssertCoreTemplatesAreClean($root);

    $discovered = $manager->discover();
    attributesCutoverAssert(
        isset($discovered[$moduleId]),
        'Modulo ' . $moduleId . ' non rilevato in discover().',
    );

    fwrite(STDOUT, '[STEP] force module OFF and capture baseline snapshot' . PHP_EOL);
    if ($manager->isActive($moduleId)) {
        $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
        attributesCutoverEnsureManagerOk($deactivateResult, 'deactivate baseline');
    }
    $offSnapshot = attributesCutoverSnapshot();

    fwrite(STDOUT, '[STEP] activate module and capture ON snapshot' . PHP_EOL);
    $activateResult = $manager->activate($moduleId);
    attributesCutoverEnsureManagerOk($activateResult, 'activate');
    $onSnapshot = attributesCutoverSnapshot();

    attributesCutoverAssert(
        !attributesCutoverHasFragmentId($offSnapshot['admin_fragments'], 'attributes-admin-dashboard-page'),
        'Fragment Attributi admin dashboard presente nello stato OFF.',
    );
    attributesCutoverAssert(
        !attributesCutoverHasFragmentId($offSnapshot['profile_modal_fragments'], 'attributes-game-profile-edit-modal'),
        'Fragment Attributi profile modal presente nello stato OFF.',
    );
    attributesCutoverAssert(
        !$offSnapshot['has_character_attributes'],
        'Provider fallback Attributi non no-op nello stato OFF.',
    );
    attributesCutoverAssert(
        empty($offSnapshot['routes']),
        'Route Attributi registrate nello stato OFF.',
    );

    $moduleProviderClass = 'Modules\\Logeon\\Attributes\\AttributesModuleProvider';
    attributesCutoverAssert(
        $offSnapshot['provider_class'] !== $moduleProviderClass,
        'Provider modulo Attributi inatteso nello stato OFF.',
    );
    attributesCutoverAssert(
        $onSnapshot['provider_class'] === $moduleProviderClass,
        'Provider modulo Attributi non risolto nello stato ON.',
    );

    attributesCutoverAssert(
        attributesCutoverHasFragmentId($onSnapshot['admin_fragments'], 'attributes-admin-dashboard-page'),
        'Fragment Attributi admin dashboard assente nello stato ON.',
    );
    attributesCutoverAssert(
        attributesCutoverHasFragmentId($onSnapshot['profile_modal_fragments'], 'attributes-game-profile-edit-modal'),
        'Fragment Attributi profile modal assente nello stato ON.',
    );
    attributesCutoverAssert(
        $onSnapshot['has_character_attributes'],
        'Provider Attributi modulo non decora il dataset nello stato ON.',
    );

    $onRouteSet = [];
    foreach ($onSnapshot['routes'] as $row) {
        $onRouteSet[] = $row['method'] . '|' . $row['pattern'] . '|' . $row['fn'];
    }

    foreach ($expectedOnRoutes as $expectedRoute) {
        attributesCutoverAssert(
            in_array($expectedRoute, $onRouteSet, true),
            'Route Attributi mancante nello stato ON: ' . $expectedRoute,
        );
    }

    fwrite(STDOUT, '[OK] Attributes module cutover smoke passed.' . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Attributes module cutover smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        if (!$originalInstalled) {
            if ($manager->isActive($moduleId)) {
                $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                attributesCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
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
                attributesCutoverEnsureManagerOk($activateResult, 'restore activate');
            } else {
                if ($manager->isActive($moduleId)) {
                    $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                    attributesCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
                }

                $db->executePrepared(
                    'UPDATE sys_modules
                     SET status = ?, last_error = ?, date_updated = NOW()
                     WHERE module_id = ?',
                    [$originalStatus, $originalLastError, $moduleId],
                );
            }
        }

        attributesCutoverResetHooks();
        ModuleRuntime::reset();
    } catch (Throwable $restoreError) {
        fwrite(STDERR, '[WARN] Restore module state failed: ' . $restoreError->getMessage() . PHP_EOL);
    }
}

exit(0);
