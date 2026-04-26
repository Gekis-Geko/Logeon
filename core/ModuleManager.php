<?php

declare(strict_types=1);

namespace Core;

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;

class ModuleManager
{
    private const STATUS_INSTALLED = 'installed';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_INACTIVE = 'inactive';
    private const STATUS_ERROR = 'error';

    /** @var DbAdapterInterface */
    private $db;
    /** @var string */
    private $modulesRoot;
    /** @var string */
    private $coreVersion;
    /** @var bool */
    private $storageReady = false;

    public function __construct(
        DbAdapterInterface $db = null,
        string $modulesRoot = null,
        string $coreVersion = null,
    ) {
        $this->db = $db ?: DbAdapterFactory::createFromConfig();
        $this->modulesRoot = $modulesRoot ?: dirname(__DIR__) . '/modules';
        $this->coreVersion = $coreVersion ?: '1.0.0';
    }

    public function discover(): array
    {
        if (!is_dir($this->modulesRoot)) {
            return [];
        }

        $entries = @scandir($this->modulesRoot);
        if (!is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = $this->modulesRoot . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }

            $manifestPath = $dir . '/module.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $manifest = $this->readManifest($manifestPath);
            if (empty($manifest)) {
                continue;
            }

            $id = trim((string) ($manifest['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $manifest['_path'] = $dir;
            $manifest['_dir'] = $entry;
            $manifest['_manifest_path'] = $manifestPath;
            $manifest['_class'] = $this->normalizeModuleClass($manifest['class'] ?? '');
            $manifest['_entrypoints'] = $this->normalizeEntrypoints($manifest['entrypoints'] ?? []);
            $manifest['_assets'] = $this->normalizeAssets($manifest['assets'] ?? []);
            $manifest['_menus'] = $this->normalizeMenus($manifest['menus'] ?? []);
            $manifest['_dependencies'] = $this->normalizeDependencies(
                $manifest['dependencies'] ?? ($manifest['requires'] ?? []),
            );
            $manifest['_core'] = $this->normalizeCoreConstraint(
                $manifest['core'] ?? ($manifest['compat'] ?? []),
            );
            $out[$id] = $manifest;
        }

        return $out;
    }

    public function listModules(): array
    {
        $this->ensureStorageTables();

        $manifests = $this->discover();
        $this->syncRuntimeArtifacts($manifests);
        $rows = $this->rowsByModuleId();
        $this->healStaleCompatibilityErrors($manifests, $rows);
        $ids = array_unique(array_merge(array_keys($manifests), array_keys($rows)));

        $dataset = [];
        foreach ($ids as $moduleId) {
            $manifest = $manifests[$moduleId] ?? [];
            $row = $rows[$moduleId] ?? null;

            $status = 'detected';
            if (is_object($row) && isset($row->status)) {
                $status = (string) $row->status;
            }

            $deps = isset($manifest['_dependencies']) && is_array($manifest['_dependencies'])
                ? $manifest['_dependencies']
                : ['required' => [], 'optional' => []];

            $dataset[] = [
                'id' => (string) $moduleId,
                'name' => (string) ($manifest['name'] ?? ($row->name ?? $moduleId)),
                'description' => (string) ($manifest['description'] ?? ''),
                'vendor' => (string) ($manifest['vendor'] ?? ($row->vendor ?? '')),
                'version' => (string) ($manifest['version'] ?? ($row->version ?? '')),
                'status' => $status,
                'is_installed' => is_object($row) ? 1 : 0,
                'is_active' => ($status === self::STATUS_ACTIVE) ? 1 : 0,
                'dependencies_required' => $deps['required'],
                'dependencies_optional' => $deps['optional'],
                'core_min' => (string) (($manifest['_core']['min'] ?? '') ?: ''),
                'core_max' => (string) (($manifest['_core']['max'] ?? '') ?: ''),
                'core_compatible' => $this->isCoreCompatible(
                    (string) (($manifest['_core']['min'] ?? '') ?: ''),
                    (string) (($manifest['_core']['max'] ?? '') ?: ''),
                ) ? 1 : 0,
                'last_error' => is_object($row) ? (string) ($row->last_error ?? '') : '',
                'directory' => (string) ($manifest['_dir'] ?? ''),
                'class' => (string) ($manifest['_class'] ?? 'optional'),
                'is_bundled' => ($manifest['_class'] ?? 'optional') === 'bundled' ? 1 : 0,
            ];
        }

        usort($dataset, function ($a, $b) {
            return strcasecmp((string) $a['id'], (string) $b['id']);
        });

        return $dataset;
    }

    public function uninstall(string $moduleId, array $options = []): array
    {
        $moduleId = trim($moduleId);
        if ($moduleId === '') {
            return $this->errorResult('module_not_found', 'Modulo non valido');
        }

        $this->ensureStorageTables();

        $purge = !empty($options['purge']);
        $discovered = $this->discover();
        $manifest = $discovered[$moduleId] ?? null;

        if (is_array($manifest) && ($manifest['_class'] ?? 'optional') === 'bundled') {
            return $this->errorResult(
                'module_bundled_no_purge',
                'I moduli bundled standard non supportano la disinstallazione. Disattiva il modulo per disabilitarne le funzionalita.',
            );
        }

        $row = $this->db->fetchOnePrepared(
            'SELECT id, status
             FROM sys_modules
             WHERE module_id = ?
             LIMIT 1',
            [$moduleId],
        );

        if (!is_object($row) || !isset($row->id)) {
            return $this->errorResult('module_not_installed', 'Modulo non installato');
        }

        $status = isset($row->status) ? (string) $row->status : '';
        if ($status === self::STATUS_ACTIVE) {
            return $this->errorResult(
                'module_uninstall_requires_inactive',
                'Disattiva prima il modulo per procedere con la disinstallazione',
            );
        }

        if ($purge && is_array($manifest)) {
            $uninstall = $this->runUninstallMigrations($moduleId, $manifest);
            if (empty($uninstall['ok'])) {
                return $uninstall;
            }
        }

        $this->db->executePrepared(
            'DELETE FROM sys_module_settings
             WHERE module_id = ?',
            [$moduleId],
        );

        if ($purge) {
            $this->db->executePrepared(
                'DELETE FROM sys_module_migrations
                 WHERE module_id = ?',
                [$moduleId],
            );
        }

        $this->db->executePrepared(
            'DELETE FROM module_runtime_artifacts
             WHERE module_id = ?',
            [$moduleId],
        );

        $this->db->executePrepared(
            'DELETE FROM sys_modules
             WHERE module_id = ?',
            [$moduleId],
        );

        return [
            'ok' => true,
            'dataset' => [
                'module_id' => $moduleId,
                'status' => 'detected',
                'uninstall_mode' => $purge ? 'purge' : 'safe',
            ],
        ];
    }

    public function audit(): array
    {
        $this->ensureStorageTables();

        $discovered = $this->discover();
        $discoveredIds = array_keys($discovered);

        $installedRows = $this->db->fetchAllPrepared(
            'SELECT module_id, status, install_path, date_updated
             FROM sys_modules
             ORDER BY module_id ASC',
        );

        $artifactsRows = $this->db->fetchAllPrepared(
            'SELECT module_id, artifact_type, artifact_key, artifact_scope, date_seen
             FROM module_runtime_artifacts
             ORDER BY module_id ASC, artifact_type ASC, artifact_key ASC',
        );

        $installed = [];
        foreach ($installedRows as $row) {
            if (!is_object($row) || !isset($row->module_id)) {
                continue;
            }
            $installed[(string) $row->module_id] = $row;
        }

        $artifactCountByModule = [];
        $orphanArtifacts = [];
        foreach ($artifactsRows as $row) {
            if (!is_object($row) || !isset($row->module_id)) {
                continue;
            }
            $rowModuleId = (string) $row->module_id;
            if (!isset($artifactCountByModule[$rowModuleId])) {
                $artifactCountByModule[$rowModuleId] = 0;
            }
            $artifactCountByModule[$rowModuleId] += 1;

            if (!isset($discovered[$rowModuleId]) && !isset($installed[$rowModuleId])) {
                $orphanArtifacts[] = [
                    'module_id' => $rowModuleId,
                    'artifact_type' => (string) ($row->artifact_type ?? ''),
                    'artifact_key' => (string) ($row->artifact_key ?? ''),
                    'artifact_scope' => (string) ($row->artifact_scope ?? ''),
                    'date_seen' => (string) ($row->date_seen ?? ''),
                ];
            }
        }

        $orphanInstalled = [];
        $activeWithoutArtifacts = [];
        foreach ($installed as $installedId => $row) {
            if (!isset($discovered[$installedId])) {
                $orphanInstalled[] = [
                    'module_id' => $installedId,
                    'status' => (string) ($row->status ?? ''),
                    'install_path' => (string) ($row->install_path ?? ''),
                    'date_updated' => (string) ($row->date_updated ?? ''),
                ];
                continue;
            }

            $isActive = isset($row->status) && (string) $row->status === self::STATUS_ACTIVE;
            $artifactsCount = (int) ($artifactCountByModule[$installedId] ?? 0);
            if ($isActive && $artifactsCount <= 0) {
                $activeWithoutArtifacts[] = [
                    'module_id' => $installedId,
                    'status' => (string) ($row->status ?? ''),
                ];
            }
        }

        return [
            'ok' => true,
            'dataset' => [
                'summary' => [
                    'discovered_modules' => count($discoveredIds),
                    'installed_modules' => count($installed),
                    'artifacts_tracked' => count($artifactsRows),
                    'orphan_installed_rows' => count($orphanInstalled),
                    'orphan_artifacts' => count($orphanArtifacts),
                    'active_without_artifacts' => count($activeWithoutArtifacts),
                ],
                'orphan_installed_rows' => $orphanInstalled,
                'orphan_artifacts' => $orphanArtifacts,
                'active_modules_without_artifacts' => $activeWithoutArtifacts,
            ],
        ];
    }

    public function getActiveManifests(): array
    {
        $modules = $this->listModules();
        if (empty($modules)) {
            return [];
        }

        $discovered = $this->discover();
        $active = [];
        foreach ($modules as $row) {
            if ((int) ($row['is_active'] ?? 0) !== 1) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');
            if ($id === '' || !isset($discovered[$id])) {
                continue;
            }
            $active[$id] = $discovered[$id];
        }

        return $this->sortByDependencyOrder($active);
    }

    public function isActive(string $moduleId): bool
    {
        $moduleId = trim($moduleId);
        if ($moduleId === '') {
            return false;
        }

        $this->ensureStorageTables();

        $row = $this->db->fetchOnePrepared(
            'SELECT status
             FROM sys_modules
             WHERE module_id = ?
             LIMIT 1',
            [$moduleId],
        );

        return (is_object($row) && isset($row->status) && (string) $row->status === self::STATUS_ACTIVE);
    }

    public function activate(string $moduleId): array
    {
        $moduleId = trim($moduleId);
        if ($moduleId === '') {
            return $this->errorResult('module_not_found', 'Modulo non valido');
        }

        $this->ensureStorageTables();

        $discovered = $this->discover();
        if (!isset($discovered[$moduleId])) {
            return $this->errorResult('module_not_found', 'Modulo non trovato');
        }

        $manifest = $discovered[$moduleId];
        $this->ensureInstalledRow($moduleId, $manifest);

        $compatibility = $this->validateCompatibility($manifest);
        if (!$compatibility['ok']) {
            $this->markError($moduleId, $compatibility['message']);
            return $compatibility;
        }

        $dependencies = $this->validateRequiredDependencies($moduleId, $manifest, $discovered);
        if (!$dependencies['ok']) {
            $this->markError($moduleId, $dependencies['message']);
            return $dependencies;
        }

        $migrations = $this->runMigrations($moduleId, $manifest);
        if (!$migrations['ok']) {
            $this->markError($moduleId, $migrations['message']);
            return $migrations;
        }

        $this->db->executePrepared(
            'UPDATE sys_modules
             SET status = ?,
                 version = ?,
                 date_activated = NOW(),
                 date_updated = NOW(),
                 last_error = NULL
             WHERE module_id = ?',
            [self::STATUS_ACTIVE, (string) ($manifest['version'] ?? ''), $moduleId],
        );

        return [
            'ok' => true,
            'dataset' => [
                'module_id' => $moduleId,
                'status' => self::STATUS_ACTIVE,
            ],
        ];
    }

    public function deactivate(string $moduleId, array $options = []): array
    {
        $moduleId = trim($moduleId);
        if ($moduleId === '') {
            return $this->errorResult('module_not_found', 'Modulo non valido');
        }

        $this->ensureStorageTables();

        $cascade = !empty($options['cascade']);
        $deactivatedModules = [];
        $result = $this->deactivateInternal($moduleId, $cascade, $deactivatedModules, []);
        if (empty($result['ok'])) {
            return $result;
        }

        return [
            'ok' => true,
            'dataset' => [
                'module_id' => $moduleId,
                'status' => self::STATUS_INACTIVE,
                'deactivated_modules' => array_values(array_unique($deactivatedModules)),
            ],
        ];
    }

    private function deactivateInternal(string $moduleId, bool $cascade, array &$deactivatedModules, array $path): array
    {
        if (in_array($moduleId, $path, true)) {
            return $this->errorResult('module_deactivation_failed', 'Rilevata dipendenza ciclica in disattivazione');
        }

        $discovered = $this->discover();
        $activeDependents = $this->findActiveDependents($moduleId, $discovered);
        if (!empty($activeDependents) && !$cascade) {
            return $this->errorResult(
                'module_deactivation_requires_confirmation',
                'Disattivando ' . $moduleId . ' verranno disattivati anche: ' . implode(', ', $activeDependents),
                [
                    'module_id' => $moduleId,
                    'dependents' => $activeDependents,
                ],
            );
        }

        if (!empty($activeDependents) && $cascade) {
            foreach ($activeDependents as $dependentId) {
                $dependentResult = $this->deactivateInternal(
                    (string) $dependentId,
                    true,
                    $deactivatedModules,
                    array_merge($path, [$moduleId]),
                );
                if (empty($dependentResult['ok'])) {
                    return $dependentResult;
                }
            }
        }

        $row = $this->db->fetchOnePrepared(
            'SELECT id, status
             FROM sys_modules
             WHERE module_id = ?
             LIMIT 1',
            [$moduleId],
        );

        if (!is_object($row) || !isset($row->id)) {
            return $this->errorResult('module_not_installed', 'Modulo non installato');
        }

        $status = isset($row->status) ? (string) $row->status : '';
        if ($status !== self::STATUS_ACTIVE) {
            return ['ok' => true];
        }

        $this->db->executePrepared(
            'UPDATE sys_modules
             SET status = ?,
                 date_deactivated = NOW(),
                 date_updated = NOW(),
                 last_error = NULL
             WHERE module_id = ?',
            [self::STATUS_INACTIVE, $moduleId],
        );

        $deactivatedModules[] = $moduleId;
        return ['ok' => true];
    }

    private function findActiveDependents(string $moduleId, array $discovered): array
    {
        $dependents = [];
        $rows = $this->listModules();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['is_active'] ?? 0) !== 1) {
                continue;
            }

            $currentId = trim((string) ($row['id'] ?? ''));
            if ($currentId === '' || $currentId === $moduleId) {
                continue;
            }

            $manifest = $discovered[$currentId] ?? [];
            $required = is_array($manifest)
                ? (($manifest['_dependencies']['required'] ?? []) ?: [])
                : [];

            foreach ($required as $dependency) {
                $depId = trim((string) ($dependency['id'] ?? ''));
                if ($depId !== '' && $depId === $moduleId) {
                    $dependents[] = $currentId;
                    break;
                }
            }
        }

        $dependents = array_values(array_unique($dependents));
        sort($dependents);
        return $dependents;
    }

    private function readManifest(string $manifestPath): array
    {
        $raw = @file_get_contents($manifestPath);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    private function normalizeCoreConstraint($core): array
    {
        if (!is_array($core)) {
            return ['min' => '', 'max' => ''];
        }

        return [
            'min' => trim((string) ($core['min'] ?? '')),
            'max' => trim((string) ($core['max'] ?? '')),
        ];
    }

    private function normalizeDependencies($dependencies): array
    {
        $required = [];
        $optional = [];

        if (!is_array($dependencies)) {
            return ['required' => $required, 'optional' => $optional];
        }

        foreach ($dependencies as $dependency) {
            if (!is_array($dependency)) {
                continue;
            }
            $id = trim((string) ($dependency['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $row = [
                'id' => $id,
                'constraint' => trim((string) ($dependency['constraint'] ?? '')),
            ];

            $isRequired = true;
            if (array_key_exists('required', $dependency)) {
                $isRequired = ((int) $dependency['required'] === 1) || ($dependency['required'] === true);
            }

            if ($isRequired) {
                $required[] = $row;
            } else {
                $optional[] = $row;
            }
        }

        return [
            'required' => $required,
            'optional' => $optional,
        ];
    }

    private function normalizeModuleClass(string $class): string
    {
        return $class === 'bundled' ? 'bundled' : 'optional';
    }

    private function normalizeEntrypoints($entrypoints): array
    {
        if (!is_array($entrypoints)) {
            return [];
        }

        $out = [];
        foreach (['bootstrap', 'routes'] as $key) {
            $value = trim((string) ($entrypoints[$key] ?? ''));
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    private function normalizeAssets($assets): array
    {
        if (!is_array($assets)) {
            return [];
        }

        $normalizeList = function ($value): array {
            if (!is_array($value)) {
                return [];
            }
            $out = [];
            foreach ($value as $item) {
                $path = trim((string) $item);
                if ($path !== '') {
                    $out[] = $path;
                }
            }
            return $out;
        };

        $channels = ['game', 'admin'];
        $out = [];
        foreach ($channels as $channel) {
            $cfg = $assets[$channel] ?? null;
            if (!is_array($cfg)) {
                continue;
            }
            $out[$channel] = [
                'css' => $normalizeList($cfg['css'] ?? []),
                'js' => $normalizeList($cfg['js'] ?? []),
            ];
        }

        return $out;
    }

    private function normalizeMenus($menus): array
    {
        if (!is_array($menus)) {
            return [];
        }

        $out = [];
        foreach (['game', 'admin', 'public'] as $channel) {
            $channelCfg = $menus[$channel] ?? null;
            if (!is_array($channelCfg)) {
                continue;
            }

            foreach ($channelCfg as $slot => $entries) {
                $slotKey = trim((string) $slot);
                if ($slotKey === '' || !is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $label = trim((string) ($entry['label'] ?? ''));
                    $href = trim((string) ($entry['href'] ?? ''));
                    if ($label === '' || $href === '') {
                        continue;
                    }

                    $out[$channel][$slotKey][] = [
                        'label' => $label,
                        'href' => $href,
                        'icon' => trim((string) ($entry['icon'] ?? '')),
                        'target' => trim((string) ($entry['target'] ?? '')),
                        'rel' => trim((string) ($entry['rel'] ?? '')),
                        'class' => trim((string) ($entry['class'] ?? '')),
                        'page' => trim((string) ($entry['page'] ?? '')),
                        'section' => trim((string) ($entry['section'] ?? '')),
                        'section_order' => (int) ($entry['section_order'] ?? 100),
                        'order' => (int) ($entry['order'] ?? 100),
                        'requires_staff' => $this->toBool($entry['requires_staff'] ?? false),
                    ];
                }
            }
        }

        return $out;
    }

    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }
        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, ['1', 'true', 'yes', 'on', 'si'], true);
    }

    private function rowsByModuleId(): array
    {
        $rows = $this->db->fetchAllPrepared(
            'SELECT module_id, name, vendor, version, status, install_path, last_error
             FROM sys_modules',
        );

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->module_id)) {
                continue;
            }
            $out[(string) $row->module_id] = $row;
        }

        return $out;
    }

    private function healStaleCompatibilityErrors(array $manifests, array &$rows): void
    {
        foreach ($rows as $moduleId => $row) {
            if (!is_object($row) || !isset($row->status)) {
                continue;
            }

            if ((string) $row->status !== self::STATUS_ERROR) {
                continue;
            }

            $lastError = trim((string) ($row->last_error ?? ''));
            if ($lastError !== 'Modulo incompatibile con la versione corrente del core') {
                continue;
            }

            $manifest = $manifests[$moduleId] ?? null;
            if (!is_array($manifest)) {
                continue;
            }

            $core = $manifest['_core'] ?? ['min' => '', 'max' => ''];
            $min = (string) ($core['min'] ?? '');
            $max = (string) ($core['max'] ?? '');
            if (!$this->isCoreCompatible($min, $max)) {
                continue;
            }

            $this->db->executePrepared(
                'UPDATE sys_modules
                 SET status = ?,
                     last_error = NULL,
                     date_updated = NOW()
                 WHERE module_id = ?
                   AND status = ?',
                [self::STATUS_INACTIVE, (string) $moduleId, self::STATUS_ERROR],
            );

            $row->status = self::STATUS_INACTIVE;
            $row->last_error = null;
            $rows[$moduleId] = $row;
        }
    }

    private function ensureInstalledRow(string $moduleId, array $manifest): void
    {
        $row = $this->db->fetchOnePrepared(
            'SELECT id
             FROM sys_modules
             WHERE module_id = ?
             LIMIT 1',
            [$moduleId],
        );

        $name = (string) ($manifest['name'] ?? $moduleId);
        $vendor = (string) ($manifest['vendor'] ?? '');
        $version = (string) ($manifest['version'] ?? '');
        $installPath = (string) ($manifest['_path'] ?? '');

        if (!is_object($row) || !isset($row->id)) {
            $this->db->executePrepared(
                'INSERT INTO sys_modules (module_id, name, vendor, version, status, install_path, date_installed, date_updated)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$moduleId, $name, $vendor, $version, self::STATUS_INSTALLED, $installPath],
            );
            return;
        }

        $this->db->executePrepared(
            'UPDATE sys_modules
             SET name = ?,
                 vendor = ?,
                 version = ?,
                 install_path = ?,
                 date_updated = NOW()
             WHERE module_id = ?',
            [$name, $vendor, $version, $installPath, $moduleId],
        );
    }

    private function validateCompatibility(array $manifest): array
    {
        $core = $manifest['_core'] ?? ['min' => '', 'max' => ''];
        $min = (string) ($core['min'] ?? '');
        $max = (string) ($core['max'] ?? '');
        if ($this->isCoreCompatible($min, $max)) {
            return ['ok' => true];
        }

        return $this->errorResult(
            'module_incompatible_core',
            'Modulo incompatibile con la versione corrente del core',
        );
    }

    private function validateRequiredDependencies(string $moduleId, array $manifest, array $discovered): array
    {
        $dependencies = $manifest['_dependencies']['required'] ?? [];
        if (empty($dependencies)) {
            return ['ok' => true];
        }

        $missing = [];
        $inactive = [];
        foreach ($dependencies as $dependency) {
            $depId = (string) ($dependency['id'] ?? '');
            if ($depId === '') {
                continue;
            }

            if (!isset($discovered[$depId])) {
                $missing[] = $depId;
                continue;
            }

            if ($depId === $moduleId) {
                continue;
            }

            if (!$this->isActive($depId)) {
                $inactive[] = $depId;
            }
        }

        if (!empty($missing) || !empty($inactive)) {
            $parts = [];
            if (!empty($missing)) {
                $parts[] = 'mancanti: ' . implode(', ', array_values(array_unique($missing)));
            }
            if (!empty($inactive)) {
                $parts[] = 'non attive: ' . implode(', ', array_values(array_unique($inactive)));
            }

            return $this->errorResult(
                'module_dependency_missing',
                'Dipendenze richieste non soddisfatte (' . implode(' | ', $parts) . ')',
                [
                    'required_module' => $moduleId,
                    'missing_dependencies' => array_values(array_unique($missing)),
                    'inactive_dependencies' => array_values(array_unique($inactive)),
                ],
            );
        }

        return ['ok' => true];
    }

    private function isCoreCompatible(string $min, string $max): bool
    {
        $current = $this->coreVersion;

        if ($min !== '' && version_compare($current, $min, '<')) {
            return false;
        }

        if ($max === '') {
            return true;
        }

        if (strpos($max, '.x') !== false) {
            $prefix = substr($max, 0, strpos($max, '.x'));
            return strpos($current, $prefix . '.') === 0 || $current === $prefix;
        }

        return version_compare($current, $max, '<=');
    }

    private function runMigrations(string $moduleId, array $manifest): array
    {
        $migrationDir = $this->resolveMigrationDir($manifest);
        if ($migrationDir === '') {
            return ['ok' => true];
        }

        $files = glob($migrationDir . '/*.sql');
        if (!is_array($files) || empty($files)) {
            return ['ok' => true];
        }
        sort($files);

        $mysqli = $this->openMysqli();
        if (!$mysqli instanceof \mysqli) {
            return $this->errorResult('module_activation_failed', 'Connessione database non disponibile');
        }

        foreach ($files as $file) {
            $migrationKey = basename((string) $file);
            $checksum = hash_file('sha256', $file);

            $already = $this->db->fetchOnePrepared(
                'SELECT id
                 FROM sys_module_migrations
                 WHERE module_id = ?
                   AND migration_key = ?
                 LIMIT 1',
                [$moduleId, $migrationKey],
            );

            if (is_object($already) && isset($already->id)) {
                continue;
            }

            $sql = @file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                continue;
            }

            $result = $this->executeSqlScript($mysqli, $sql);
            if (!$result['ok']) {
                return $this->errorResult('module_activation_failed', 'Errore migrazione [' . $migrationKey . ']: ' . $result['error']);
            }

            $this->db->executePrepared(
                'INSERT INTO sys_module_migrations (module_id, migration_key, checksum_sha256, executed_at)
                 VALUES (?, ?, ?, NOW())',
                [$moduleId, $migrationKey, $checksum ?: ''],
            );
        }

        return ['ok' => true];
    }

    private function runUninstallMigrations(string $moduleId, array $manifest): array
    {
        $migrationDir = $this->resolveUninstallMigrationDir($manifest);
        if ($migrationDir === '') {
            return ['ok' => true];
        }

        $files = glob($migrationDir . '/*.sql');
        if (!is_array($files) || empty($files)) {
            return ['ok' => true];
        }
        sort($files);

        $mysqli = $this->openMysqli();
        if (!$mysqli instanceof \mysqli) {
            return $this->errorResult('module_uninstall_failed', 'Connessione database non disponibile');
        }

        foreach ($files as $file) {
            $migrationKey = 'uninstall:' . basename((string) $file);
            $checksum = hash_file('sha256', $file);

            $already = $this->db->fetchOnePrepared(
                'SELECT id
                 FROM sys_module_migrations
                 WHERE module_id = ?
                   AND migration_key = ?
                 LIMIT 1',
                [$moduleId, $migrationKey],
            );

            if (is_object($already) && isset($already->id)) {
                continue;
            }

            $sql = @file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                continue;
            }

            $result = $this->executeSqlScript($mysqli, $sql);
            if (!$result['ok']) {
                return $this->errorResult(
                    'module_uninstall_failed',
                    'Errore migrazione uninstall [' . $migrationKey . ']: ' . $result['error'],
                );
            }

            $this->db->executePrepared(
                'INSERT INTO sys_module_migrations (module_id, migration_key, checksum_sha256, executed_at)
                 VALUES (?, ?, ?, NOW())',
                [$moduleId, $migrationKey, $checksum ?: ''],
            );
        }

        return ['ok' => true];
    }

    private function resolveMigrationDir(array $manifest): string
    {
        $base = (string) ($manifest['_path'] ?? '');
        if ($base === '' || !is_dir($base)) {
            return '';
        }

        $explicit = trim((string) ($manifest['migrations']['path'] ?? ''));
        if ($explicit !== '') {
            $dir = $base . '/' . ltrim($explicit, '/');
            if (is_dir($dir)) {
                return $dir;
            }
            return '';
        }

        $fallback = $base . '/migrations';
        return is_dir($fallback) ? $fallback : '';
    }

    private function resolveUninstallMigrationDir(array $manifest): string
    {
        $base = (string) ($manifest['_path'] ?? '');
        if ($base === '' || !is_dir($base)) {
            return '';
        }

        $explicit = trim((string) ($manifest['uninstall']['path'] ?? ''));
        if ($explicit !== '') {
            $dir = $base . '/' . ltrim($explicit, '/');
            if (is_dir($dir)) {
                return $dir;
            }
            return '';
        }

        $fallback = $base . '/migrations/uninstall';
        return is_dir($fallback) ? $fallback : '';
    }

    private function syncRuntimeArtifacts(array $manifests): void
    {
        foreach ($manifests as $moduleId => $manifest) {
            if (!is_array($manifest)) {
                continue;
            }
            $artifacts = $this->collectManifestArtifacts($moduleId, $manifest);
            foreach ($artifacts as $artifact) {
                if (!is_array($artifact)) {
                    continue;
                }
                $artifactType = trim((string) ($artifact['type'] ?? ''));
                $artifactKey = trim((string) ($artifact['key'] ?? ''));
                if ($artifactType === '' || $artifactKey === '') {
                    continue;
                }
                $artifactScope = trim((string) ($artifact['scope'] ?? ''));
                $payload = isset($artifact['payload']) ? json_encode($artifact['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';
                if (!is_string($payload)) {
                    $payload = '{}';
                }
                $checksum = sha1($artifactType . '|' . $artifactKey . '|' . $artifactScope . '|' . $payload);

                $this->db->executePrepared(
                    'INSERT INTO module_runtime_artifacts
                        (module_id, artifact_type, artifact_key, artifact_scope, artifact_payload, checksum_sha1, date_seen)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        artifact_scope = VALUES(artifact_scope),
                        artifact_payload = VALUES(artifact_payload),
                        checksum_sha1 = VALUES(checksum_sha1),
                        date_seen = NOW()',
                    [
                        (string) $moduleId,
                        $artifactType,
                        $artifactKey,
                        ($artifactScope !== '' ? $artifactScope : null),
                        $payload,
                        $checksum,
                    ],
                );
            }
        }
    }

    private function collectManifestArtifacts(string $moduleId, array $manifest): array
    {
        $artifacts = [];
        $entrypoints = is_array($manifest['_entrypoints'] ?? null) ? $manifest['_entrypoints'] : [];
        foreach ($entrypoints as $entryType => $entryFile) {
            $entryType = trim((string) $entryType);
            $entryFile = trim((string) $entryFile);
            if ($entryType === '' || $entryFile === '') {
                continue;
            }
            $artifacts[] = [
                'type' => 'entrypoint',
                'key' => $entryType . ':' . $entryFile,
                'scope' => 'runtime',
                'payload' => ['module_id' => $moduleId, 'entrypoint' => $entryType, 'path' => $entryFile],
            ];
        }

        $assets = is_array($manifest['_assets'] ?? null) ? $manifest['_assets'] : [];
        foreach ($assets as $channel => $channelAssets) {
            if (!is_array($channelAssets)) {
                continue;
            }
            foreach (['css', 'js'] as $assetType) {
                $paths = is_array($channelAssets[$assetType] ?? null) ? $channelAssets[$assetType] : [];
                foreach ($paths as $path) {
                    $path = trim((string) $path);
                    if ($path === '') {
                        continue;
                    }
                    $artifacts[] = [
                        'type' => 'asset_' . $assetType,
                        'key' => strtolower(trim((string) $channel)) . ':' . $path,
                        'scope' => strtolower(trim((string) $channel)),
                        'payload' => ['module_id' => $moduleId, 'channel' => $channel, 'asset_type' => $assetType, 'path' => $path],
                    ];
                }
            }
        }

        $menus = is_array($manifest['_menus'] ?? null) ? $manifest['_menus'] : [];
        foreach ($menus as $channel => $slots) {
            if (!is_array($slots)) {
                continue;
            }
            foreach ($slots as $slot => $entries) {
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $href = trim((string) ($entry['href'] ?? ''));
                    $label = trim((string) ($entry['label'] ?? ''));
                    if ($href === '' || $label === '') {
                        continue;
                    }
                    $artifacts[] = [
                        'type' => 'menu_entry',
                        'key' => strtolower(trim((string) $channel)) . ':' . trim((string) $slot) . ':' . $href,
                        'scope' => strtolower(trim((string) $channel)),
                        'payload' => [
                            'module_id' => $moduleId,
                            'channel' => $channel,
                            'slot' => $slot,
                            'label' => $label,
                            'href' => $href,
                            'page' => trim((string) ($entry['page'] ?? '')),
                        ],
                    ];
                }
            }
        }

        $migrationDir = $this->resolveMigrationDir($manifest);
        if ($migrationDir !== '') {
            $files = glob($migrationDir . '/*.sql');
            if (is_array($files)) {
                sort($files);
                foreach ($files as $file) {
                    $key = trim((string) basename($file));
                    if ($key === '') {
                        continue;
                    }
                    $artifacts[] = [
                        'type' => 'migration',
                        'key' => $key,
                        'scope' => 'database',
                        'payload' => ['module_id' => $moduleId, 'path' => $file],
                    ];
                }
            }
        }

        return $artifacts;
    }

    /** @return array<string,mixed> */
    private function mysqlConfig(): array
    {
        if (!defined('DB')) {
            return [];
        }
        return (array) DB['mysql'];
    }

    private function openMysqli()
    {
        $cfg = $this->mysqlConfig();
        $host = (string) ($cfg['host'] ?? 'localhost');
        $user = (string) ($cfg['user'] ?? 'root');
        $pwd = (string) ($cfg['pwd'] ?? '');
        $dbName = (string) ($cfg['db_name'] ?? '');
        $port = (int) ($cfg['port'] ?? 3306);
        if ($port <= 0) {
            $port = 3306;
        }
        $socket = (string) ($cfg['socket'] ?? '');
        if ($socket === '') {
            $socket = null;
        }

        $mysqli = @mysqli_connect($host, $user, $pwd, $dbName, $port, $socket);
        if (!$mysqli instanceof \mysqli) {
            return null;
        }

        $charset = (string) ($cfg['charset'] ?? 'utf8mb4');
        if (!$mysqli->set_charset($charset)) {
            return null;
        }

        return $mysqli;
    }

    private function executeSqlScript(\mysqli $mysqli, string $sql): array
    {
        try {
            if (!$mysqli->multi_query($sql)) {
                return ['ok' => false, 'error' => (string) $mysqli->error];
            }

            do {
                if ($result = $mysqli->store_result()) {
                    $result->free();
                }
            } while ($mysqli->more_results() && $mysqli->next_result());

            if ($mysqli->errno) {
                $error = (string) $mysqli->error;
                while ($mysqli->more_results() && $mysqli->next_result()) {
                    if ($result = $mysqli->store_result()) {
                        $result->free();
                    }
                }
                return ['ok' => false, 'error' => $error];
            }

            return ['ok' => true];
        } catch (\Throwable $error) {
            while ($mysqli->more_results()) {
                try {
                    if (!$mysqli->next_result()) {
                        break;
                    }
                    if ($result = $mysqli->store_result()) {
                        $result->free();
                    }
                } catch (\Throwable $drainError) {
                    break;
                }
            }
            return ['ok' => false, 'error' => (string) $error->getMessage()];
        }
    }

    private function markError(string $moduleId, string $message): void
    {
        $this->db->executePrepared(
            'UPDATE sys_modules
             SET status = ?,
                 last_error = ?,
                 date_updated = NOW()
             WHERE module_id = ?',
            [self::STATUS_ERROR, $message, $moduleId],
        );
    }

    private function sortByDependencyOrder(array $manifests): array
    {
        if (empty($manifests)) {
            return [];
        }

        $sorted = [];
        $visited = [];
        $visiting = [];

        $walk = function ($moduleId) use (&$walk, &$sorted, &$visited, &$visiting, $manifests) {
            if (isset($visited[$moduleId])) {
                return;
            }
            if (isset($visiting[$moduleId])) {
                return;
            }
            $visiting[$moduleId] = true;

            $manifest = $manifests[$moduleId] ?? null;
            if (is_array($manifest)) {
                $deps = $manifest['_dependencies']['required'] ?? [];
                foreach ($deps as $dependency) {
                    $depId = (string) ($dependency['id'] ?? '');
                    if ($depId !== '' && isset($manifests[$depId])) {
                        $walk($depId);
                    }
                }
            }

            unset($visiting[$moduleId]);
            $visited[$moduleId] = true;
            $sorted[$moduleId] = $manifest;
        };

        foreach (array_keys($manifests) as $moduleId) {
            $walk($moduleId);
        }

        return $sorted;
    }

    private function ensureStorageTables(): void
    {
        if ($this->storageReady) {
            return;
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS sys_modules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_id VARCHAR(120) NOT NULL UNIQUE,
                name VARCHAR(120) NOT NULL,
                vendor VARCHAR(80) NOT NULL,
                version VARCHAR(40) NOT NULL,
                status ENUM('installed','active','inactive','error') NOT NULL DEFAULT 'installed',
                install_path VARCHAR(255) NOT NULL,
                checksum_sha256 CHAR(64) NULL,
                last_error TEXT NULL,
                date_installed TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                date_activated TIMESTAMP NULL,
                date_deactivated TIMESTAMP NULL,
                date_updated TIMESTAMP NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $this->db->query(
            'CREATE TABLE IF NOT EXISTS sys_module_migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_id VARCHAR(120) NOT NULL,
                migration_key VARCHAR(180) NOT NULL,
                checksum_sha256 CHAR(64) NULL,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_module_migration (module_id, migration_key),
                KEY idx_module_exec (module_id, executed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS sys_module_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_id VARCHAR(120) NOT NULL,
                setting_key VARCHAR(120) NOT NULL,
                setting_value LONGTEXT NULL,
                value_type VARCHAR(30) NOT NULL DEFAULT 'string',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_module_setting (module_id, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        );

        $this->db->query(
            'CREATE TABLE IF NOT EXISTS module_runtime_artifacts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_id VARCHAR(120) NOT NULL,
                artifact_type VARCHAR(60) NOT NULL,
                artifact_key VARCHAR(255) NOT NULL,
                artifact_scope VARCHAR(80) NULL,
                artifact_payload LONGTEXT NULL,
                checksum_sha1 CHAR(40) NULL,
                date_seen TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_module_artifact (module_id, artifact_type, artifact_key),
                KEY idx_module_artifact_module (module_id),
                KEY idx_module_artifact_scope (artifact_scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $this->storageReady = true;
    }

    private function errorResult(string $code, string $message, array $payload = []): array
    {
        $response = [
            'ok' => false,
            'error_code' => $code,
            'message' => $message,
        ];

        if (!empty($payload)) {
            $response['payload'] = $payload;
        }

        return $response;
    }
}
