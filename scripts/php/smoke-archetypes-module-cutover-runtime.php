<?php

declare(strict_types=1);

/**
 * Archetypes module cutover smoke (CLI).
 *
 * Validates controlled ON/OFF cutover for module `logeon.archetypes`
 * while preserving runtime-equivalent behavior on key Archetypes paths.
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-archetypes-module-cutover-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/configs/app.php';
require_once $root . '/vendor/autoload.php';
require_once $root . '/modules/logeon.archetypes/bootstrap.php';

use Core\Database\DbAdapterFactory;
use Core\ModuleManager;
use Core\ModuleRuntime;
use Modules\Logeon\Archetypes\Services\ArchetypeProviderRegistry;

function archetypesCutoverAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string,mixed> $result
 */
function archetypesCutoverEnsureManagerOk(array $result, string $step): void
{
    if (!isset($result['ok']) || $result['ok'] !== true) {
        $code = isset($result['error_code']) ? (string) $result['error_code'] : 'unknown';
        $message = isset($result['message']) ? (string) $result['message'] : 'errore sconosciuto';
        throw new RuntimeException($step . ' fallito [' . $code . ']: ' . $message);
    }
}

function archetypesCutoverResetHooks(): void
{
    if (!class_exists('\\Core\\Hooks')) {
        return;
    }

    $prop = new ReflectionProperty(\Core\Hooks::class, 'actions');
    $prop->setAccessible(true);
    $prop->setValue([]);
}

/**
 * @return array{provider_class:string,public_list:array<string,mixed>}
 */
function archetypesCutoverSnapshot(): array
{
    archetypesCutoverResetHooks();
    ModuleRuntime::reset();
    ModuleRuntime::instance()->bootActiveModules();
    ArchetypeProviderRegistry::resetRuntimeState();
    ArchetypeProviderRegistry::setProvider(null);

    $provider = ArchetypeProviderRegistry::provider();
    $providerClass = get_class($provider);
    $publicList = $provider->publicList();
    archetypesCutoverAssert(is_array($publicList), 'Snapshot publicList non valido.');

    return [
        'provider_class' => $providerClass,
        'public_list' => $publicList,
    ];
}

$moduleId = 'logeon.archetypes';
$manager = new ModuleManager();
$db = DbAdapterFactory::createFromConfig();
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
    $discovered = $manager->discover();
    archetypesCutoverAssert(
        isset($discovered[$moduleId]),
        'Modulo ' . $moduleId . ' non rilevato in discover().',
    );

    fwrite(STDOUT, '[STEP] force module OFF and capture baseline snapshot' . PHP_EOL);
    if ($manager->isActive($moduleId)) {
        $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
        archetypesCutoverEnsureManagerOk($deactivateResult, 'deactivate baseline');
    }
    $offSnapshot = archetypesCutoverSnapshot();

    fwrite(STDOUT, '[STEP] activate module and capture ON snapshot' . PHP_EOL);
    $activateResult = $manager->activate($moduleId);
    archetypesCutoverEnsureManagerOk($activateResult, 'activate');
    $onSnapshot = archetypesCutoverSnapshot();

    $moduleProviderClass = 'Modules\\Logeon\\Archetypes\\ArchetypesModuleProvider';
    archetypesCutoverAssert(
        $offSnapshot['provider_class'] !== $moduleProviderClass,
        'Provider modulo inatteso nello stato OFF.',
    );
    archetypesCutoverAssert(
        $onSnapshot['provider_class'] === $moduleProviderClass,
        'Provider modulo non risolto nello stato ON.',
    );

    $offPublicListJson = json_encode($offSnapshot['public_list'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $onPublicListJson = json_encode($onSnapshot['public_list'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    archetypesCutoverAssert(
        is_string($offPublicListJson) && is_string($onPublicListJson),
        'Serializzazione snapshot publicList non riuscita.',
    );
    archetypesCutoverAssert(
        $offPublicListJson === $onPublicListJson,
        'Cutover ON/OFF non equivalente su Archetypes::publicList.',
    );
    fwrite(STDOUT, '[OK] Archetypes module cutover smoke passed.' . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Archetypes module cutover smoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    try {
        if (!$originalInstalled) {
            if ($manager->isActive($moduleId)) {
                $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                archetypesCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
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
                archetypesCutoverEnsureManagerOk($activateResult, 'restore activate');
            } else {
                if ($manager->isActive($moduleId)) {
                    $deactivateResult = $manager->deactivate($moduleId, ['cascade' => false]);
                    archetypesCutoverEnsureManagerOk($deactivateResult, 'restore deactivate');
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
