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
    private const ERR_TWIG_MISSING = 'Twig non installato. Esegui composer install.';
    private const ERR_TEMPLATE_UNSUPPORTED = 'Template non supportato. Usa un file Twig.';

    public static function resetRuntimeState(): void
    {
        self::$twig = [];
        self::$twigFactory = null;
        self::$config = null;
        ThemeResolver::resetRuntimeState();
    }

    private static function getSessionValue($key)
    {
        return AppContext::session()->get((string) $key);
    }

    public static function setConfig(ConfigRepositoryInterface $config = null): void
    {
        self::$config = $config;
    }

    private static function config(): ConfigRepositoryInterface
    {
        if (self::$config instanceof ConfigRepositoryInterface) {
            return self::$config;
        }

        self::$config = AppContext::config();
        return self::$config;
    }

    private static function twigFactory(): TwigEnvironmentFactory
    {
        if (self::$twigFactory instanceof TwigEnvironmentFactory) {
            return self::$twigFactory;
        }

        self::$twigFactory = new TwigEnvironmentFactory();
        return self::$twigFactory;
    }

    public static function view($file = '', $params = null)
    {
        if (!self::isTwigFile($file)) {
            throw new AppError(self::ERR_TEMPLATE_UNSUPPORTED, 500);
        }

        self::assertTwigAvailable();
        self::viewTwig($file, $params);
    }

    private static function viewTwig($file = '', $params = null)
    {
        $twig = self::getTwig($file);
        if ($twig === null) {
            throw new AppError(self::ERR_TWIG_MISSING, 500);
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
        return (new ThemeResolver())->resolveForTemplate($template);
    }

    private static function getTwig(string $template = '')
    {
        $config = self::config();
        $appConfig = $config->getAll('APP');
        $runtimeConfig = $config->getAll('CONFIG');

        $runtime = self::resolveTwigRuntime($template);
        $context = $runtime['context'];
        $viewsPaths = $runtime['views_paths'];
        $themeRuntime = $runtime['theme'];

        if (class_exists('\\Core\\Hooks')) {
            $extra = \Core\Hooks::filter('twig.view_paths', []);
            if (is_array($extra)) {
                $viewsPaths = array_merge($viewsPaths, $extra);
            }
        }

        $cacheKey = $context . '|' . md5(implode('|', $viewsPaths));

        if (array_key_exists($cacheKey, self::$twig)) {
            return self::$twig[$cacheKey];
        }

        $tmpDir = (string) $config->get('CONFIG.dirs.tmp', __DIR__ . '/../tmp');
        $debugEnabled = (bool) $config->get('CONFIG.debug', false);
        $cache_dir = rtrim($tmpDir, '/\\') . '/twig-cache';
        $factory = self::twigFactory();
        $twig = $factory->create($viewsPaths, $cache_dir, $debugEnabled, function ($twig) use ($themeRuntime, $context, $viewsPaths, $appConfig, $runtimeConfig) {
            $twig->addGlobal('APP', $appConfig);
            $twig->addGlobal('CONFIG', $runtimeConfig);
            $twig->addGlobal('PWA', \Core\PwaRuntime::build());
            $twig->addGlobal('csrf_token', \Core\Csrf::token());
            $twig->addGlobal('THEME', $themeRuntime);
            $twig->addGlobal('THEME_CONTEXT', $context);

            $twig->addFunction(new \Twig\TwigFunction('session', function ($key) {
                return self::getSessionValue($key);
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
                    if (trim($viewPath) === '') {
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
                        $html[] = '<script type="module" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
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

            $twig->addFunction(new \Twig\TwigFunction('slot', function ($slotName, $context = []) use ($twig) {
                return self::renderHookSlot($twig, $slotName, $context);
            }, ['is_safe' => ['html']]));

            $twig->addFunction(new \Twig\TwigFunction('hook_scripts', function ($channel) {
                return self::renderHookScripts((string) $channel);
            }, ['is_safe' => ['html']]));

            $twig->addFunction(new \Twig\TwigFunction('hook_bool', function ($hookName, $default = false) {
                return self::resolveHookBool($hookName, $default);
            }));

            $twig->addFunction(new \Twig\TwigFunction('hook_map', function ($hookName, $default = []) {
                return self::resolveHookArray($hookName, is_array($default) ? $default : []);
            }));

            if (class_exists('\\Core\\Hooks')) {
                \Core\Hooks::run('twig.register', $twig);
            }
        });
        if ($twig === null) {
            return null;
        }

        self::$twig[$cacheKey] = $twig;
        return self::$twig[$cacheKey];
    }

    private static function renderHookScripts(string $channel): string
    {
        if (!class_exists('\\Core\\Hooks')) {
            return '';
        }

        $channelKey = self::normalizeHookKeyPart($channel);
        if ($channelKey === '') {
            return '';
        }

        $scripts = \Core\Hooks::filter('html.scripts.' . $channelKey, []);
        if (!is_array($scripts) || $scripts === []) {
            return '';
        }

        $seen = [];
        $html = [];
        foreach ($scripts as $src) {
            $normalizedSrc = self::normalizeScriptSource($src);
            if ($normalizedSrc === '' || isset($seen[$normalizedSrc])) {
                continue;
            }
            $seen[$normalizedSrc] = true;
            $html[] = '<script type="module" src="' . htmlspecialchars($normalizedSrc, ENT_QUOTES, 'UTF-8') . '"></script>';
        }

        return implode("\n", $html);
    }

    private static function resolveHookBool($hookName, $default = false): bool
    {
        $fallback = (bool) $default;
        if (!class_exists('\\Core\\Hooks')) {
            return $fallback;
        }

        $name = trim((string) $hookName);
        if ($name === '') {
            return $fallback;
        }

        return (bool) \Core\Hooks::filter($name, $fallback);
    }

    private static function resolveHookArray($hookName, array $default = []): array
    {
        $fallback = $default;
        if (!class_exists('\\Core\\Hooks')) {
            return $fallback;
        }

        $name = trim((string) $hookName);
        if ($name === '') {
            return $fallback;
        }

        $resolved = \Core\Hooks::filter($name, $fallback);
        return is_array($resolved) ? $resolved : $fallback;
    }

    private static function normalizeScriptSource($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $src = trim(str_replace('\\', '/', $value));
        if ($src === '') {
            return '';
        }
        if (strpos($src, '..') !== false) {
            return '';
        }
        if (preg_match('/^(?:javascript:|data:)/i', $src) === 1) {
            return '';
        }
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }
        if (!str_starts_with($src, '/')) {
            $src = '/' . ltrim($src, '/');
        }

        return $src;
    }

    private static function renderHookSlot(\Twig\Environment $twig, $slotName, $slotContext = []): string
    {
        if (!class_exists('\\Core\\Hooks')) {
            return '';
        }

        $slotKey = self::normalizeHookKeyPart((string) $slotName);
        if ($slotKey === '') {
            return '';
        }

        $fragments = \Core\Hooks::filter('twig.slot.' . $slotKey, []);
        if (!is_array($fragments) || $fragments === []) {
            return '';
        }

        $normalizedFragments = self::normalizeSlotFragments($fragments);
        if ($normalizedFragments === []) {
            return '';
        }

        $orderedFragments = self::orderSlotFragments($normalizedFragments);
        $baseContext = is_array($slotContext) ? $slotContext : [];
        $html = [];
        foreach ($orderedFragments as $fragment) {
            $template = trim((string) $fragment['template']);
            if ($template === '') {
                continue;
            }
            $data = $fragment['data'];
            try {
                $html[] = $twig->render($template, array_merge($baseContext, $data));
            } catch (\Throwable $e) {
                continue;
            }
        }

        return implode("\n", $html);
    }

    /**
     * @param array<int,mixed> $fragments
     * @return array<int,array{id:string,template:string,after:string,before:string,data:array<string,mixed>,order:int}>
     */
    private static function normalizeSlotFragments(array $fragments): array
    {
        $normalized = [];
        $seenIds = [];
        $autoIndex = 0;

        foreach ($fragments as $index => $fragment) {
            if (!is_array($fragment)) {
                continue;
            }

            $template = trim((string) ($fragment['template'] ?? ''));
            if ($template === '') {
                continue;
            }

            $candidateId = self::normalizeHookKeyPart((string) ($fragment['id'] ?? ''));
            if ($candidateId === '') {
                $candidateId = 'fragment-' . (string) $autoIndex;
                $autoIndex += 1;
            }

            $id = $candidateId;
            $suffix = 2;
            while (isset($seenIds[$id])) {
                $id = $candidateId . '-' . (string) $suffix;
                $suffix += 1;
            }
            $seenIds[$id] = true;

            $data = [];
            if (isset($fragment['data']) && is_array($fragment['data'])) {
                $data = $fragment['data'];
            }

            $normalized[] = [
                'id' => $id,
                'template' => $template,
                'after' => self::normalizeHookKeyPart((string) ($fragment['after'] ?? '')),
                'before' => self::normalizeHookKeyPart((string) ($fragment['before'] ?? '')),
                'data' => $data,
                'order' => $index,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int,array{id:string,template:string,after:string,before:string,data:array<string,mixed>,order:int}> $fragments
     * @return array<int,array{id:string,template:string,after:string,before:string,data:array<string,mixed>,order:int}>
     */
    private static function orderSlotFragments(array $fragments): array
    {
        if (count($fragments) < 2) {
            return $fragments;
        }

        $byId = [];
        $orderById = [];
        foreach ($fragments as $fragment) {
            $id = (string) $fragment['id'];
            $byId[$id] = $fragment;
            $orderById[$id] = (int) $fragment['order'];
        }

        $inDegree = [];
        $adjacency = [];
        foreach (array_keys($byId) as $id) {
            $inDegree[$id] = 0;
            $adjacency[$id] = [];
        }

        $edgeSet = [];
        foreach ($fragments as $fragment) {
            $id = (string) $fragment['id'];
            $after = (string) $fragment['after'];
            $before = (string) $fragment['before'];

            if ($after !== '' && $after !== $id && isset($byId[$after])) {
                $edgeKey = $after . '>' . $id;
                if (!isset($edgeSet[$edgeKey])) {
                    $adjacency[$after][] = $id;
                    $inDegree[$id] += 1;
                    $edgeSet[$edgeKey] = true;
                }
            }

            if ($before !== '' && $before !== $id && isset($byId[$before])) {
                $edgeKey = $id . '>' . $before;
                if (!isset($edgeSet[$edgeKey])) {
                    $adjacency[$id][] = $before;
                    $inDegree[$before] += 1;
                    $edgeSet[$edgeKey] = true;
                }
            }
        }

        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = (string) $id;
            }
        }
        usort($queue, function (string $left, string $right) use ($orderById): int {
            return $orderById[$left] <=> $orderById[$right];
        });

        $ordered = [];
        while ($queue !== []) {
            $current = (string) array_shift($queue);
            $ordered[] = $byId[$current];
            foreach ($adjacency[$current] as $neighbor) {
                $inDegree[$neighbor] -= 1;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
            usort($queue, function (string $left, string $right) use ($orderById): int {
                return $orderById[$left] <=> $orderById[$right];
            });
        }

        if (count($ordered) !== count($fragments)) {
            usort($fragments, function (array $left, array $right): int {
                return (int) $left['order'] <=> (int) $right['order'];
            });
            return $fragments;
        }

        return $ordered;
    }

    private static function normalizeHookKeyPart(string $value): string
    {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }
        $normalized = (string) preg_replace('/[^a-z0-9._-]+/', '', $normalized);
        return trim($normalized, '.-');
    }

    private static function isTwigAvailable()
    {
        return self::twigFactory()->isAvailable();
    }

    private static function assertTwigAvailable(): void
    {
        if (self::isTwigAvailable()) {
            return;
        }

        throw new AppError(self::ERR_TWIG_MISSING, 500);
    }

    private static function isTwigFile($file)
    {
        return is_string($file) && str_ends_with($file, '.twig');
    }

}
