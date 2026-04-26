<?php

declare(strict_types=1);

namespace Core\Themes;

use Core\Database\DbAdapterFactory;

class ThemeResolver
{
    private const CONFIG_KEY_ENABLED = 'theme_system_enabled';
    private const CONFIG_KEY_ACTIVE_THEME = 'active_theme';

    /** @var array<string,mixed>|null */
    private static $cachedThemeRuntime = null;

    /** @return array<string,mixed> */
    public function resolveForTemplate(string $template): array
    {
        $context = $this->resolveContext($template);
        $projectRoot = dirname(__DIR__, 2);
        $coreViews = $projectRoot . '/app/views';
        $paths = [];

        $themeRuntime = $this->resolveActiveThemeRuntime();
        if ($context !== 'admin' && (bool) ($themeRuntime['active'] ?? false) === true) {
            $themeViews = (string) ($themeRuntime['views_path'] ?? '');
            if ($themeViews !== '' && is_dir($themeViews)) {
                $paths[] = $themeViews;
            }
        }

        // Legacy customization fallback kept for compatibility on public/game.
        if ($context !== 'admin') {
            $legacyCustomViews = $projectRoot . '/custom/views';
            if (is_dir($legacyCustomViews)) {
                $paths[] = $legacyCustomViews;
            }
        }

        $paths[] = $coreViews;

        return [
            'context' => $context,
            'views_paths' => $paths,
            'theme' => ($context === 'admin') ? $this->emptyThemeRuntime() : $themeRuntime,
        ];
    }

    /** @return array<string,mixed> */
    public function resolveActiveThemeRuntime(): array
    {
        if (is_array(self::$cachedThemeRuntime)) {
            return self::$cachedThemeRuntime;
        }

        $enabledDefault = $this->appBool('enabled', true);
        $enabled = $this->configBool(self::CONFIG_KEY_ENABLED, $enabledDefault);
        if ($enabled !== true) {
            self::$cachedThemeRuntime = $this->emptyThemeRuntime();
            return self::$cachedThemeRuntime;
        }

        $activeThemeDefault = $this->appString('active_theme', '');
        $themeId = trim($this->configString(self::CONFIG_KEY_ACTIVE_THEME, $activeThemeDefault));
        if ($themeId === '') {
            self::$cachedThemeRuntime = $this->emptyThemeRuntime();
            return self::$cachedThemeRuntime;
        }

        if (!$this->isValidThemeId($themeId)) {
            $invalid = $this->emptyThemeRuntime();
            $invalid['errors'][] = 'theme_id_invalid';
            self::$cachedThemeRuntime = $invalid;
            return self::$cachedThemeRuntime;
        }

        $projectRoot = dirname(__DIR__, 2);
        $themePath = $projectRoot . '/custom/themes/' . $themeId;
        if (!is_dir($themePath)) {
            $invalid = $this->emptyThemeRuntime();
            $invalid['errors'][] = 'theme_missing_directory';
            $invalid['id'] = $themeId;
            self::$cachedThemeRuntime = $invalid;
            return self::$cachedThemeRuntime;
        }

        $manifestPath = $themePath . '/theme.json';
        $manifest = $this->readThemeManifest($manifestPath);
        if (!$manifest['valid']) {
            $invalid = $this->emptyThemeRuntime();
            $invalid['errors'][] = 'theme_manifest_invalid';
            $invalid['id'] = $themeId;
            self::$cachedThemeRuntime = $invalid;
            return self::$cachedThemeRuntime;
        }

        $viewsPath = $themePath . '/views';
        if (!is_dir($viewsPath)) {
            $invalid = $this->emptyThemeRuntime();
            $invalid['errors'][] = 'theme_views_missing';
            $invalid['id'] = $themeId;
            self::$cachedThemeRuntime = $invalid;
            return self::$cachedThemeRuntime;
        }

        $baseAssetsUrl = '/custom/themes/' . $themeId . '/assets';
        self::$cachedThemeRuntime = [
            'active' => true,
            'id' => $themeId,
            'path' => $themePath,
            'views_path' => $viewsPath,
            'assets_base_url' => $baseAssetsUrl,
            'manifest' => (array) ($manifest['manifest'] ?? []),
            'errors' => [],
        ];

        return self::$cachedThemeRuntime;
    }

    public static function resetRuntimeState(): void
    {
        self::$cachedThemeRuntime = null;
    }

    private function resolveContext(string $template): string
    {
        $normalized = trim(str_replace('\\', '/', $template));
        if ($normalized === '') {
            return 'public';
        }
        if (strpos($normalized, 'admin/') === 0) {
            return 'admin';
        }
        if ($normalized === 'admin.twig' || $normalized === 'admin/dashboard.twig') {
            return 'admin';
        }
        if ($normalized === 'app.twig' || strpos($normalized, 'app/') === 0) {
            return 'game';
        }
        return 'public';
    }

    private function isValidThemeId(string $themeId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $themeId);
    }

    /** @return array{valid:bool,manifest?:array<string,mixed>} */
    private function readThemeManifest(string $manifestPath): array
    {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return ['valid' => false];
        }

        $json = @file_get_contents($manifestPath);
        if (!is_string($json) || trim($json) === '') {
            return ['valid' => false];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['valid' => false];
        }

        $required = ['id', 'name', 'version', 'compat', 'shell'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $decoded)) {
                return ['valid' => false];
            }
        }

        if (!is_string($decoded['id']) || trim((string) $decoded['id']) === '') {
            return ['valid' => false];
        }

        return [
            'valid' => true,
            'manifest' => $decoded,
        ];
    }

    private function appThemeConfig(): array
    {
        if (!defined('APP')) {
            return [];
        }
        $theme = APP['theme'];
        return (array) $theme;
    }

    private function appBool(string $key, bool $fallback): bool
    {
        $theme = $this->appThemeConfig();
        if (!array_key_exists($key, $theme)) {
            return $fallback;
        }
        $value = $theme[$key];
        return ($value === true || (string) $value === '1' || (int) $value === 1);
    }

    private function appString(string $key, string $fallback): string
    {
        $theme = $this->appThemeConfig();
        if (!array_key_exists($key, $theme)) {
            return $fallback;
        }
        return trim((string) $theme[$key]);
    }

    private function configString(string $key, string $fallback): string
    {
        try {
            $db = DbAdapterFactory::createFromConfig();
            $row = $db->fetchOnePrepared(
                'SELECT `value`
                 FROM sys_configs
                 WHERE `key` = ?
                 LIMIT 1',
                [$key],
            );
            if (!empty($row) && isset($row->value)) {
                return trim((string) $row->value);
            }
        } catch (\Throwable $e) {
            return $fallback;
        }
        return $fallback;
    }

    private function configBool(string $key, bool $fallback): bool
    {
        $value = $this->configString($key, $fallback ? '1' : '0');
        return ($value === '1' || strtolower($value) === 'true');
    }

    /** @return array<string,mixed> */
    private function emptyThemeRuntime(): array
    {
        return [
            'active' => false,
            'id' => '',
            'path' => '',
            'views_path' => '',
            'assets_base_url' => '',
            'manifest' => [],
            'errors' => [],
        ];
    }
}
