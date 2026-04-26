<?php

declare(strict_types=1);

use Core\Database\DbAdapterFactory;
use Core\Database\DbAdapterInterface;
use Core\Http\ApiResponse;
use Core\Http\AppError;
use Core\Http\InputValidator;
use Core\Http\RequestData;
use Core\Http\ResponseEmitter;
use Core\Template;

class Themes
{
    private function requireAdmin(): void
    {
        \Core\AuthGuard::api()->requireAbility('settings.manage');
    }

    private function requestDataObject(): object
    {
        $request = RequestData::fromGlobals();
        return InputValidator::postJsonObject($request, 'data', true);
    }

    private function emitJson(array $payload): void
    {
        ResponseEmitter::emit(ApiResponse::json($payload));
    }

    private function db(): DbAdapterInterface
    {
        return DbAdapterFactory::createFromConfig();
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function themesRoot(): string
    {
        return $this->projectRoot() . '/custom/themes';
    }

    private function readConfigMap(): array
    {
        $db = $this->db();
        $rows = $db->fetchAllPrepared(
            'SELECT `key`, `value`
             FROM sys_configs
             WHERE `key` IN ("theme_system_enabled", "active_theme")',
        );

        $map = [];
        foreach ($rows as $row) {
            if (!empty($row) && isset($row->key)) {
                $map[(string) $row->key] = isset($row->value) ? (string) $row->value : '';
            }
        }

        return $map;
    }

    private function readConfigEnabled(array $map): bool
    {
        if (!array_key_exists('theme_system_enabled', $map)) {
            return false;
        }
        $raw = strtolower(trim((string) $map['theme_system_enabled']));
        return ($raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === 'on');
    }

    private function readConfigActiveTheme(array $map): string
    {
        if (!array_key_exists('active_theme', $map)) {
            return '';
        }
        return trim((string) $map['active_theme']);
    }

    private function writeConfig(string $key, string $value): void
    {
        $db = $this->db();
        $db->executePrepared(
            'INSERT INTO sys_configs (`key`, `value`)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value],
        );
    }

    private function isValidThemeId(string $themeId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $themeId);
    }

    private function readThemeManifest(string $manifestPath): array
    {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return ['valid' => false, 'errors' => ['manifest_missing']];
        }

        $raw = @file_get_contents($manifestPath);
        if (!is_string($raw) || trim($raw) === '') {
            return ['valid' => false, 'errors' => ['manifest_empty']];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['valid' => false, 'errors' => ['manifest_json_invalid']];
        }

        $required = ['id', 'name', 'version', 'compat', 'shell'];
        $errors = [];
        foreach ($required as $field) {
            if (!array_key_exists($field, $decoded)) {
                $errors[] = 'manifest_field_missing:' . $field;
            }
        }

        $manifestId = isset($decoded['id']) ? trim((string) $decoded['id']) : '';
        if ($manifestId === '') {
            $errors[] = 'manifest_theme_id_invalid';
        }

        if (!empty($errors)) {
            return ['valid' => false, 'errors' => $errors, 'manifest' => $decoded];
        }

        return ['valid' => true, 'errors' => [], 'manifest' => $decoded];
    }

    private function normalizeShellLabel($shell): string
    {
        if (is_array($shell)) {
            $parts = [];
            foreach ($shell as $key => $value) {
                if (!is_string($key) || trim($key) === '') {
                    continue;
                }
                if (!is_string($value) || trim($value) === '') {
                    continue;
                }
                $parts[] = trim($key) . ' -> ' . trim($value);
            }
            return ($parts === []) ? '-' : implode(' | ', $parts);
        }
        if (is_string($shell) && trim($shell) !== '') {
            return trim($shell);
        }
        return '-';
    }

    private function normalizeCompatLabel($compat): string
    {
        if (is_string($compat) && trim($compat) !== '') {
            return trim($compat);
        }
        if (is_array($compat) && !empty($compat)) {
            $parts = [];
            foreach ($compat as $key => $value) {
                if (is_string($key) && $key !== '') {
                    $parts[] = trim($key) . ': ' . trim((string) $value);
                } else {
                    $parts[] = trim((string) $value);
                }
            }
            return implode(' | ', $parts);
        }
        return '-';
    }

    public function list()
    {
        $this->requireAdmin();

        $config = $this->readConfigMap();
        $enabled = $this->readConfigEnabled($config);
        $activeThemeId = $this->readConfigActiveTheme($config);

        $themesRoot = $this->themesRoot();
        $dataset = [];

        if (is_dir($themesRoot)) {
            $entries = @scandir($themesRoot);
            if (!is_array($entries)) {
                $entries = [];
                $dirs = @glob($themesRoot . '/*', GLOB_ONLYDIR);
                if (is_array($dirs)) {
                    foreach ($dirs as $dirPath) {
                        $entries[] = basename((string) $dirPath);
                    }
                }
            }
            foreach ($entries as $entry) {
                    $themeId = trim((string) $entry);
                    if ($themeId === '' || $themeId === '.' || $themeId === '..') {
                        continue;
                    }

                    $themePath = $themesRoot . '/' . $themeId;
                    if (!is_dir($themePath)) {
                        continue;
                    }

                    $errors = [];
                    if (!$this->isValidThemeId($themeId)) {
                        $errors[] = 'theme_id_invalid';
                    }

                    $manifestState = $this->readThemeManifest($themePath . '/theme.json');
                    $manifest = isset($manifestState['manifest']) && is_array($manifestState['manifest'])
                        ? $manifestState['manifest']
                        : [];

                    if ((bool) ($manifestState['valid'] ?? false) !== true) {
                        $manifestErrors = isset($manifestState['errors']) && is_array($manifestState['errors'])
                            ? $manifestState['errors']
                            : ['manifest_invalid'];
                        $errors = array_merge($errors, $manifestErrors);
                    }

                    $viewsPath = $themePath . '/views';
                    if (!is_dir($viewsPath)) {
                        $errors[] = 'views_missing';
                    }

                    $manifestThemeId = isset($manifest['id']) ? trim((string) $manifest['id']) : '';
                    if ($manifestThemeId !== '' && $manifestThemeId !== $themeId) {
                        $errors[] = 'manifest_id_mismatch';
                    }

                    $isActive = ($enabled === true && $activeThemeId !== '' && $activeThemeId === $themeId);
                    $valid = empty($errors);

                    $dataset[] = [
                        'id' => $themeId,
                        'name' => isset($manifest['name']) ? (string) $manifest['name'] : $themeId,
                        'version' => isset($manifest['version']) ? (string) $manifest['version'] : '-',
                        'description' => isset($manifest['description']) ? (string) $manifest['description'] : '',
                        'author' => isset($manifest['author']) ? (string) $manifest['author'] : '',
                        'compat' => $this->normalizeCompatLabel($manifest['compat'] ?? null),
                        'shell' => $this->normalizeShellLabel($manifest['shell'] ?? null),
                        'is_active' => $isActive ? 1 : 0,
                        'status' => $isActive ? 'active' : 'inactive',
                        'is_valid' => $valid ? 1 : 0,
                        'errors' => array_values(array_unique($errors)),
                    ];
                }
        }

        usort($dataset, static function ($a, $b) {
            $aActive = (int) $a['is_active'];
            $bActive = (int) $b['is_active'];
            if ($aActive !== $bActive) {
                return ($aActive > $bActive) ? -1 : 1;
            }
            $aName = strtolower($a['name'] !== '' ? $a['name'] : $a['id']);
            $bName = strtolower($b['name'] !== '' ? $b['name'] : $b['id']);
            return strcmp($aName, $bName);
        });

        $this->emitJson([
            'dataset' => $dataset,
            'meta' => [
                'theme_system_enabled' => $enabled ? 1 : 0,
                'active_theme' => $activeThemeId,
            ],
        ]);
    }

    public function activate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $themeId = InputValidator::string($data, 'theme_id', '');
        $config = $this->readConfigMap();
        $currentEnabled = $this->readConfigEnabled($config);
        $currentActiveThemeId = $this->readConfigActiveTheme($config);

        if ($themeId === '' || !$this->isValidThemeId($themeId)) {
            throw AppError::validation('Tema non valido.', [], 'theme_not_found');
        }

        $themePath = $this->themesRoot() . '/' . $themeId;
        if (!is_dir($themePath)) {
            throw AppError::validation('Tema non trovato.', [], 'theme_not_found');
        }

        $manifestState = $this->readThemeManifest($themePath . '/theme.json');
        if ((bool) ($manifestState['valid'] ?? false) !== true) {
            throw AppError::validation('Manifest tema non valido.', [], 'theme_manifest_invalid');
        }
        if (!is_dir($themePath . '/views')) {
            throw AppError::validation('Tema non valido: cartella views mancante.', [], 'theme_views_missing');
        }

        $this->writeConfig('theme_system_enabled', '1');
        $this->writeConfig('active_theme', $themeId);
        Template::resetRuntimeState();

        $replacedThemeId = '';
        if (
            $currentEnabled === true
            && $currentActiveThemeId !== ''
            && $currentActiveThemeId !== $themeId
        ) {
            $replacedThemeId = $currentActiveThemeId;
        }

        $this->emitJson([
            'dataset' => [
                'theme_id' => $themeId,
                'status' => 'active',
                'theme_system_enabled' => 1,
                'active_theme' => $themeId,
                'previous_active_theme' => $replacedThemeId,
            ],
        ]);
    }

    public function deactivate()
    {
        $this->requireAdmin();
        $data = $this->requestDataObject();
        $themeId = InputValidator::string($data, 'theme_id', '');

        $config = $this->readConfigMap();
        $activeThemeId = $this->readConfigActiveTheme($config);

        if ($themeId !== '' && $activeThemeId !== '' && $themeId !== $activeThemeId) {
            $this->emitJson([
                'dataset' => [
                    'theme_id' => $themeId,
                    'status' => 'inactive',
                    'theme_system_enabled' => $this->readConfigEnabled($config) ? 1 : 0,
                    'active_theme' => $activeThemeId,
                ],
            ]);
            return;
        }

        $this->writeConfig('theme_system_enabled', '0');
        $this->writeConfig('active_theme', '');
        Template::resetRuntimeState();

        $this->emitJson([
            'dataset' => [
                'theme_id' => ($themeId !== '') ? $themeId : $activeThemeId,
                'status' => 'inactive',
                'theme_system_enabled' => 0,
                'active_theme' => '',
            ],
        ]);
    }
}
