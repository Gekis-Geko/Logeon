<?php

declare(strict_types=1);

namespace Core;

use Core\Contracts\ConfigRepositoryInterface;
use Core\Http\AppError;
use Core\Templates\TwigEnvironmentFactory;
use Core\Themes\ThemeResolver;

class Template
{
    /** @var array<string,mixed> */
    private static $twig = [];
    private static $twigFactory = null;
    /** @var ConfigRepositoryInterface|null */
    private static $config = null;
    /** @var array<string,array<string,mixed>> */
    private static $twigThemeRuntime = [];
    private const ERR_TWIG_MISSING = 'Twig non installato. Esegui composer install.';
    private const ERR_TEMPLATE_UNSUPPORTED = 'Template non supportato. Usa un file Twig.';

    public static function resetRuntimeState(): void
    {
        static::$twig = [];
        static::$twigFactory = null;
        static::$config = null;
        static::$twigThemeRuntime = [];
        ThemeResolver::resetRuntimeState();
    }

    private static function getSessionValue($key)
    {
        return AppContext::session()->get((string) $key);
    }

    public static function setConfig(ConfigRepositoryInterface $config = null): void
    {
        static::$config = $config;
    }

    private static function config(): ConfigRepositoryInterface
    {
        if (static::$config instanceof ConfigRepositoryInterface) {
            return static::$config;
        }

        static::$config = AppContext::config();
        return static::$config;
    }

    private static function twigFactory(): TwigEnvironmentFactory
    {
        if (static::$twigFactory instanceof TwigEnvironmentFactory) {
            return static::$twigFactory;
        }

        static::$twigFactory = new TwigEnvironmentFactory();
        return static::$twigFactory;
    }

    public static function view($file = '', $params = null)
    {
        if (!static::isTwigFile($file)) {
            throw new AppError(static::ERR_TEMPLATE_UNSUPPORTED, 500);
        }

        static::assertTwigAvailable();
        static::viewTwig($file, $params);
    }

    private static function viewTwig($file = '', $params = null)
    {
        $twig = static::getTwig($file);
        if ($twig === null) {
            throw new AppError(static::ERR_TWIG_MISSING, 500);
        }

        $context = [];
        if (is_array($params)) {
            $context = $params;
        }

        echo $twig->render($file, $context);
    }

    /** @return array{context:string,views_paths:array<int,string>,theme:array<string,mixed>} */
    private static function resolveTwigRuntime(string $template): array
    {
        $resolver = new ThemeResolver();
        $runtime = $resolver->resolveForTemplate($template);
        if (!is_array($runtime)) {
            return [
                'context' => 'public',
                'views_paths' => [__DIR__ . '/../app/views'],
                'theme' => ['active' => false, 'id' => ''],
            ];
        }
        return $runtime;
    }

    private static function getTwig(string $template = '')
    {
        $config = static::config();
        $appConfig = $config->getAll('APP');
        $runtimeConfig = $config->getAll('CONFIG');

        $runtime = static::resolveTwigRuntime($template);
        $context = (string) ($runtime['context'] ?? 'public');
        $viewsPaths = (array) ($runtime['views_paths'] ?? [__DIR__ . '/../app/views']);
        $themeRuntime = (array) ($runtime['theme'] ?? ['active' => false, 'id' => '']);
        $cacheKey = $context . '|' . md5(implode('|', $viewsPaths));

        if (array_key_exists($cacheKey, static::$twig)) {
            return static::$twig[$cacheKey];
        }

        $tmpDir = (string) $config->get('CONFIG.dirs.tmp', __DIR__ . '/../tmp');
        $debugEnabled = (bool) $config->get('CONFIG.debug', false);
        $cache_dir = rtrim($tmpDir, '/\\') . '/twig-cache';
        $factory = static::twigFactory();
        $twig = $factory->create($viewsPaths, $cache_dir, $debugEnabled, function ($twig) use ($themeRuntime, $context, $viewsPaths, $appConfig, $runtimeConfig) {
            $twig->addGlobal('APP', $appConfig);
            $twig->addGlobal('CONFIG', $runtimeConfig);
            $twig->addGlobal('csrf_token', \Core\Csrf::token());
            $twig->addGlobal('THEME', $themeRuntime);
            $twig->addGlobal('THEME_CONTEXT', $context);

            $twig->addFunction(new \Twig\TwigFunction('session', function ($key) {
                return static::getSessionValue($key);
            }));

            $twig->addFunction(new \Twig\TwigFunction('theme_active', function () use ($themeRuntime) {
                return ((bool) ($themeRuntime['active'] ?? false) === true);
            }));

            $twig->addFunction(new \Twig\TwigFunction('theme_id', function () use ($themeRuntime) {
                return (string) ($themeRuntime['id'] ?? '');
            }));

            $twig->addFunction(new \Twig\TwigFunction('theme_meta', function ($key = null) use ($themeRuntime) {
                $manifest = (array) ($themeRuntime['manifest'] ?? []);
                if ($key === null) {
                    return $manifest;
                }
                $k = trim((string) $key);
                if ($k === '') {
                    return $manifest;
                }
                return $manifest[$k] ?? null;
            }));

            $twig->addFunction(new \Twig\TwigFunction('theme_shell', function ($area, $fallback) use ($themeRuntime, $viewsPaths) {
                $fallbackTpl = trim((string) $fallback);
                if ($fallbackTpl === '') {
                    return '';
                }
                if ((bool) ($themeRuntime['active'] ?? false) !== true) {
                    return $fallbackTpl;
                }

                $normalizedArea = strtolower(trim((string) $area));
                $shellKey = ($normalizedArea === 'game') ? 'game_layout' : 'public_layout';
                $manifest = (array) ($themeRuntime['manifest'] ?? []);
                $shell = is_array($manifest['shell'] ?? null) ? (array) $manifest['shell'] : [];
                $candidate = trim((string) ($shell[$shellKey] ?? ''));
                if ($candidate === '') {
                    return $fallbackTpl;
                }
                $candidate = ltrim(str_replace('\\', '/', $candidate), '/');
                if (
                    $candidate === ''
                    || strpos($candidate, '..') !== false
                    || substr($candidate, -5) !== '.twig'
                ) {
                    return $fallbackTpl;
                }

                foreach ($viewsPaths as $viewPath) {
                    if (!is_string($viewPath) || trim($viewPath) === '') {
                        continue;
                    }
                    $absoluteCandidate = rtrim($viewPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate);
                    if (is_file($absoluteCandidate)) {
                        $templateContents = @file_get_contents($absoluteCandidate);
                        if (!is_string($templateContents)) {
                            continue;
                        }
                        $hasShellBlock =
                            (preg_match('/\{%\s*block\s+content\s*%\}/i', $templateContents) === 1)
                            || (preg_match('/\{%\s*block\s+public_page_content\s*%\}/i', $templateContents) === 1)
                            || (preg_match('/\{%\s*block\s+game_page_content\s*%\}/i', $templateContents) === 1)
                            || (preg_match('/\{%\s*block\s+public_shell\s*%\}/i', $templateContents) === 1)
                            || (preg_match('/\{%\s*block\s+game_shell\s*%\}/i', $templateContents) === 1);
                        if (!$hasShellBlock) {
                            continue;
                        }
                        // Contract v1 safety: theme shell must extend the corresponding core layout.
                        // This guarantees runtime mounts/modals/offcanvas/module hooks remain available.
                        $fallbackPattern = '/\{%\s*extends\s+[\'"]' . preg_quote($fallbackTpl, '/') . '[\'"]\s*%\}/i';
                        if (preg_match($fallbackPattern, $templateContents) !== 1) {
                            continue;
                        }
                        return $candidate;
                    }
                }

                return $fallbackTpl;
            }));

            $twig->addFunction(new \Twig\TwigFunction('theme_asset', function ($relativePath = '') use ($themeRuntime) {
                if ((bool) ($themeRuntime['active'] ?? false) !== true) {
                    return '';
                }
                $base = trim((string) ($themeRuntime['assets_base_url'] ?? ''));
                if ($base === '') {
                    return '';
                }
                $path = trim((string) $relativePath);
                if ($path === '') {
                    return $base;
                }
                $path = ltrim(str_replace('\\', '/', $path), '/');
                if ($path === '' || strpos($path, '..') !== false) {
                    return '';
                }
                return rtrim($base, '/') . '/' . $path;
            }));

            $twig->addFunction(new \Twig\TwigFunction('theme_assets', function ($channel = '') use ($themeRuntime) {
                if ((bool) ($themeRuntime['active'] ?? false) !== true) {
                    return '';
                }
                $manifest = (array) ($themeRuntime['manifest'] ?? []);
                $assets = is_array($manifest['assets'] ?? null) ? (array) $manifest['assets'] : [];
                $channelKey = trim((string) $channel);
                if ($channelKey === '') {
                    return '';
                }
                $entries = $assets[$channelKey] ?? null;
                if (!is_array($entries) || empty($entries)) {
                    return '';
                }

                $base = trim((string) ($themeRuntime['assets_base_url'] ?? ''));
                if ($base === '') {
                    return '';
                }

                $html = [];
                foreach ($entries as $entry) {
                    $path = ltrim(str_replace('\\', '/', trim((string) $entry)), '/');
                    if ($path === '' || strpos($path, '..') !== false) {
                        continue;
                    }
                    $url = rtrim($base, '/') . '/' . $path;
                    if (substr($path, -4) === '.css') {
                        $html[] = '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
                        continue;
                    }
                    if (substr($path, -3) === '.js') {
                        $html[] = '<script type="text/javascript" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
                    }
                }

                return implode("\n", $html);
            }, ['is_safe' => ['html']]));

            $twig->addFunction(new \Twig\TwigFunction('module_assets', function ($channel) {
                if (!class_exists('\\Core\\ModuleRuntime')) {
                    return '';
                }
                return \Core\ModuleRuntime::instance()->renderAssetTags((string) $channel);
            }));

            $twig->addFunction(new \Twig\TwigFunction('module_active', function ($moduleId) {
                if (!class_exists('\\Core\\ModuleRuntime')) {
                    return false;
                }
                return \Core\ModuleRuntime::instance()->isModuleActive((string) $moduleId);
            }));

            $twig->addFunction(new \Twig\TwigFunction('module_menu_entries', function ($channel, $slot, $context = []) {
                if (!class_exists('\\Core\\ModuleRuntime')) {
                    return [];
                }
                if (!is_array($context)) {
                    $context = [];
                }
                return \Core\ModuleRuntime::instance()->getMenuEntries((string) $channel, (string) $slot, $context);
            }));

            $twig->addFunction(new \Twig\TwigFunction('module_menu_sections', function ($channel, $slot, $context = []) {
                if (!class_exists('\\Core\\ModuleRuntime')) {
                    return [];
                }
                if (!is_array($context)) {
                    $context = [];
                }
                return \Core\ModuleRuntime::instance()->getMenuSections((string) $channel, (string) $slot, $context);
            }));

            $twig->addFunction(new \Twig\TwigFunction('active_modules', function () {
                if (!class_exists('\\Core\\ModuleRuntime')) {
                    return [];
                }
                return \Core\ModuleRuntime::instance()->activeModuleIds();
            }));

            $twig->addFunction(new \Twig\TwigFunction('module_slot', function ($slot) {
                if (!class_exists('\\Core\\ModuleRuntime')) {
                    return '';
                }
                return \Core\ModuleRuntime::instance()->renderViewSlot((string) $slot);
            }, ['is_safe' => ['html']]));

            $twig->addFunction(new \Twig\TwigFunction('archetypes_enabled', function () {
                try {
                    if (!class_exists('\\App\\Services\\ArchetypeService')) {
                        return false;
                    }
                    $service = new \App\Services\ArchetypeService();
                    $config = $service->getConfig();
                    return (int) ($config['archetypes_enabled'] ?? 0) === 1;
                } catch (\Throwable $e) {
                    return false;
                }
            }));

            if (class_exists('\\Core\\Hooks')) {
                \Core\Hooks::run('twig.register', $twig);
            }
        });
        if ($twig === null) {
            return null;
        }

        static::$twig[$cacheKey] = $twig;
        static::$twigThemeRuntime[$cacheKey] = $themeRuntime;
        return static::$twig[$cacheKey];
    }

    private static function isTwigAvailable()
    {
        return static::twigFactory()->isAvailable();
    }

    private static function assertTwigAvailable(): void
    {
        if (static::isTwigAvailable()) {
            return;
        }

        throw new AppError(static::ERR_TWIG_MISSING, 500);
    }

    private static function isTwigFile($file)
    {
        return is_string($file) && str_ends_with($file, '.twig');
    }

}
