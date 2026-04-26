<?php

declare(strict_types=1);

namespace Core;

class ModuleRuntime
{
    /** @var ModuleRuntime|null */
    private static $instance = null;
    /** @var ModuleManager|null */
    private $manager = null;
    /** @var bool */
    private $bootstrapped = false;

    public static function instance(): self
    {
        if (self::$instance instanceof self) {
            return self::$instance;
        }

        self::$instance = new self();
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function manager(): ModuleManager
    {
        if ($this->manager instanceof ModuleManager) {
            return $this->manager;
        }

        $this->manager = new ModuleManager();
        return $this->manager;
    }

    public function bootActiveModules(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $modules = $this->manager()->getActiveManifests();
        foreach ($modules as $manifest) {
            $entrypoints = is_array($manifest['_entrypoints'] ?? null) ? $manifest['_entrypoints'] : [];
            $bootstrap = trim((string) ($entrypoints['bootstrap'] ?? ''));
            if ($bootstrap === '') {
                continue;
            }

            $file = $this->resolveModuleFile($manifest, $bootstrap);
            if ($file === '') {
                continue;
            }

            $moduleManifest = $manifest;
            $moduleRuntime = $this;
            $result = require $file;
            if (is_callable($result)) {
                call_user_func($result, $moduleRuntime, $moduleManifest);
            }
        }

        $this->bootstrapped = true;
    }

    public function registerRoutes($route): void
    {
        $modules = $this->manager()->getActiveManifests();
        foreach ($modules as $manifest) {
            $entrypoints = is_array($manifest['_entrypoints'] ?? null) ? $manifest['_entrypoints'] : [];
            $routesFile = trim((string) ($entrypoints['routes'] ?? ''));
            if ($routesFile === '') {
                continue;
            }

            $file = $this->resolveModuleFile($manifest, $routesFile);
            if ($file === '') {
                continue;
            }

            $moduleManifest = $manifest;
            $moduleRuntime = $this;
            require $file;
        }
    }

    public function isModuleActive(string $moduleId): bool
    {
        return $this->manager()->isActive($moduleId);
    }

    public function activeModuleIds(): array
    {
        $active = $this->manager()->getActiveManifests();
        return array_keys($active);
    }

    /**
     * Renders the HTML contributed by active modules for a named view slot.
     * Modules register their contributions via:
     *   Hooks::add('view.slot.<slot_name>', function(string $html): string { return $html . '<...>'; });
     * If no module has registered for the slot, returns an empty string.
     */
    public function renderViewSlot(string $slot): string
    {
        if (!class_exists('\\Core\\Hooks')) {
            return '';
        }
        $slot = trim($slot);
        if ($slot === '') {
            return '';
        }
        $html = \Core\Hooks::filter('view.slot.' . $slot, '');
        return is_string($html) ? $html : '';
    }

    public function renderAssetTags(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($channel !== 'game' && $channel !== 'admin') {
            return '';
        }

        $modules = $this->manager()->getActiveManifests();
        if (empty($modules)) {
            return '';
        }

        $tags = [];
        foreach ($modules as $manifest) {
            $assets = is_array($manifest['_assets'] ?? null) ? $manifest['_assets'] : [];
            $cfg = is_array($assets[$channel] ?? null) ? $assets[$channel] : [];

            $css = is_array($cfg['css'] ?? null) ? $cfg['css'] : [];
            foreach ($css as $path) {
                $url = $this->resolveAssetUrl($manifest, (string) $path);
                if ($url === '') {
                    continue;
                }
                $tags[] = '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
            }

            $js = is_array($cfg['js'] ?? null) ? $cfg['js'] : [];
            foreach ($js as $path) {
                $url = $this->resolveAssetUrl($manifest, (string) $path);
                if ($url === '') {
                    continue;
                }
                $tags[] = '<script type="module" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
            }
        }

        if (empty($tags)) {
            return '';
        }

        return implode(PHP_EOL, $tags);
    }

    public function getMenuEntries(string $channel, string $slot, array $context = []): array
    {
        $channel = strtolower(trim($channel));
        $slot = trim($slot);
        $allowedChannels = ['game', 'admin', 'public'];
        if (!in_array($channel, $allowedChannels, true) || $slot === '') {
            return [];
        }

        $isStaff = false;
        if (array_key_exists('is_staff', $context)) {
            $isStaff = ((int) $context['is_staff'] === 1) || ($context['is_staff'] === true);
        }

        $modules = $this->manager()->getActiveManifests();
        if (empty($modules)) {
            return [];
        }

        $entries = [];
        foreach ($modules as $moduleId => $manifest) {
            $menus = is_array($manifest['_menus'] ?? null) ? $manifest['_menus'] : [];
            $slots = is_array($menus[$channel] ?? null) ? $menus[$channel] : [];
            $slotEntries = is_array($slots[$slot] ?? null) ? $slots[$slot] : [];
            if (empty($slotEntries)) {
                continue;
            }

            foreach ($slotEntries as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $requiresStaff = ((int) ($row['requires_staff'] ?? 0) === 1) || (($row['requires_staff'] ?? false) === true);
                if ($requiresStaff && !$isStaff) {
                    continue;
                }

                $label = trim((string) ($row['label'] ?? ''));
                $href = trim((string) ($row['href'] ?? ''));
                if ($label === '' || $href === '') {
                    continue;
                }

                $entries[] = [
                    'module_id' => (string) ($manifest['id'] ?? $moduleId),
                    'label' => $label,
                    'href' => $href,
                    'icon' => trim((string) ($row['icon'] ?? '')),
                    'target' => trim((string) ($row['target'] ?? '')),
                    'rel' => trim((string) ($row['rel'] ?? '')),
                    'class' => trim((string) ($row['class'] ?? '')),
                    'page' => trim((string) ($row['page'] ?? '')),
                    'section' => trim((string) ($row['section'] ?? '')),
                    'section_order' => (int) ($row['section_order'] ?? 100),
                    'order' => (int) ($row['order'] ?? 100),
                ];
            }
        }

        if (empty($entries)) {
            return [];
        }

        usort($entries, function ($a, $b) {
            $cmp = (int) $a['order'] <=> (int) $b['order'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) $a['label'], (string) $b['label']);
        });

        return $entries;
    }

    public function getMenuSections(string $channel, string $slot, array $context = []): array
    {
        $entries = $this->getMenuEntries($channel, $slot, $context);
        if (empty($entries)) {
            return [];
        }

        $sections = [];
        foreach ($entries as $entry) {
            $sectionTitle = trim((string) ($entry['section'] ?? ''));
            if ($sectionTitle === '') {
                $sectionTitle = 'Moduli';
            }
            $sectionKey = strtolower($sectionTitle);

            if (!isset($sections[$sectionKey])) {
                $sections[$sectionKey] = [
                    'key' => $sectionKey,
                    'title' => $sectionTitle,
                    'order' => (int) ($entry['section_order'] ?? 100),
                    'items' => [],
                ];
            }

            if ((int) ($entry['section_order'] ?? 100) < (int) $sections[$sectionKey]['order']) {
                $sections[$sectionKey]['order'] = (int) ($entry['section_order'] ?? 100);
            }
            $sections[$sectionKey]['items'][] = $entry;
        }

        foreach ($sections as $key => $group) {
            $items = $group['items'];
            usort($items, function ($a, $b) {
                $cmp = (int) $a['order'] <=> (int) $b['order'];
                if ($cmp !== 0) {
                    return $cmp;
                }
                return strcasecmp((string) $a['label'], (string) $b['label']);
            });
            $sections[$key]['items'] = $items;
        }

        $rows = array_values($sections);
        usort($rows, function ($a, $b) {
            $cmp = (int) $a['order'] <=> (int) $b['order'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcasecmp((string) $a['title'], (string) $b['title']);
        });

        return $rows;
    }

    private function resolveModuleFile(array $manifest, string $relativePath): string
    {
        $basePath = trim((string) ($manifest['_path'] ?? ''));
        if ($basePath === '') {
            return '';
        }

        $relativePath = ltrim($relativePath, '/');
        if ($relativePath === '') {
            return '';
        }

        $file = $basePath . '/' . $relativePath;
        if (!is_file($file)) {
            return '';
        }

        return $file;
    }

    private function resolveAssetUrl(array $manifest, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^(https?:)?//#i', $path) || strpos($path, '/') === 0) {
            return $path;
        }

        $dir = trim((string) ($manifest['_dir'] ?? ''));
        if ($dir === '') {
            return '';
        }

        return '/modules/' . rawurlencode($dir) . '/' . str_replace('%2F', '/', rawurlencode(ltrim($path, '/')));
    }
}
