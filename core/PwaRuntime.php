<?php

declare(strict_types=1);

namespace Core;

use Core\Database\DbAdapterFactory;

class PwaRuntime
{
    /**
     * @return array<string,mixed>
     */
    public static function build(): array
    {
        $app = self::appConfig();
        $pwa = self::pwaConfig($app);
        $frontend = self::frontendConfig($app);
        $basePath = self::resolveBasePath((string) ($app['baseurl'] ?? ''));
        $enabled = self::toBool($pwa['enabled'] ?? false);

        $name = self::normalizeText($pwa['name'] ?? ($app['name'] ?? ''), (string) ($app['name'] ?? 'Logeon'));
        $shortName = self::normalizeText($pwa['short_name'] ?? '', $name);
        $description = self::plainText($pwa['description'] ?? ($app['description'] ?? ''));

        $iconPath = self::resolveAppPath(
            $basePath,
            self::normalizeText(
                $pwa['icon_path'] ?? '',
                (string) ($app['brand_logo_icon'] ?? '/favicon.ico'),
            ),
            '/favicon.ico',
        );
        $icon192Path = self::resolveOptionalAppPath($basePath, $pwa['icon_192_path'] ?? '');
        $icon512Path = self::resolveOptionalAppPath($basePath, $pwa['icon_512_path'] ?? '');
        $iconMaskablePath = self::resolveOptionalAppPath($basePath, $pwa['icon_maskable_path'] ?? '');
        $appleTouchIconPath = $icon192Path !== '' ? $icon192Path : $iconPath;

        $scope = self::normalizeScope(self::resolveAppPath($basePath, $pwa['scope'] ?? '/', '/'));
        $startUrl = self::resolveAppPath($basePath, $pwa['start_path'] ?? '/', '/');
        $manifestUrl = self::resolveAppPath($basePath, '/manifest.webmanifest', '/manifest.webmanifest');
        $serviceWorkerUrl = self::resolveAppPath($basePath, '/service-worker.js', '/service-worker.js');
        $themeColor = self::normalizeColor($pwa['theme_color'] ?? '#0d6efd', '#0d6efd');
        $backgroundColor = self::normalizeColor($pwa['background_color'] ?? '#ffffff', '#ffffff');
        $display = self::normalizeEnum($pwa['display'] ?? 'standalone', ['fullscreen', 'standalone', 'minimal-ui', 'browser'], 'standalone');
        $orientation = self::normalizeEnum($pwa['orientation'] ?? 'portrait', ['any', 'natural', 'landscape', 'landscape-primary', 'landscape-secondary', 'portrait', 'portrait-primary', 'portrait-secondary'], 'portrait');
        $cacheEnabled = self::toBool($pwa['cache_enabled'] ?? true);
        $cacheVersion = self::normalizeText(
            $pwa['cache_version'] ?? '',
            (string) ($frontend['pilot_bundle_version'] ?? date('Ymd')),
        );

        $runtime = [
            'enabled' => $enabled,
            'base_path' => $basePath,
            'lang' => self::normalizeText($app['lang'] ?? 'it', 'it'),
            'name' => $name,
            'short_name' => $shortName,
            'description' => $description,
            'scope' => $scope,
            'start_url' => $startUrl,
            'manifest_url' => $manifestUrl,
            'service_worker_url' => $serviceWorkerUrl,
            'theme_color' => $themeColor,
            'background_color' => $backgroundColor,
            'display' => $display,
            'orientation' => $orientation,
            'icon_path' => $iconPath,
            'icon_192_path' => $icon192Path,
            'icon_512_path' => $icon512Path,
            'icon_maskable_path' => $iconMaskablePath,
            'apple_touch_icon_path' => $appleTouchIconPath,
            'cache_enabled' => $cacheEnabled,
            'cache_version' => $cacheVersion,
            'cache_prefix' => 'logeon-pwa-' . self::slug($shortName !== '' ? $shortName : $name),
        ];

        $runtime['icons'] = self::buildIcons($runtime);
        $runtime['precache_urls'] = ($enabled && $cacheEnabled) ? self::buildPrecacheUrls($runtime, $cacheVersion) : [];

        return $runtime;
    }

    /**
     * @param array<string,mixed>|null $runtime
     * @return array<string,mixed>
     */
    public static function manifestPayload(array $runtime = null): array
    {
        $runtime = is_array($runtime) ? $runtime : self::build();

        return [
            'id' => (string) ($runtime['scope'] ?? '/'),
            'name' => (string) ($runtime['name'] ?? 'Logeon'),
            'short_name' => (string) ($runtime['short_name'] ?? 'Logeon'),
            'description' => (string) ($runtime['description'] ?? ''),
            'lang' => (string) ($runtime['lang'] ?? 'it'),
            'start_url' => (string) ($runtime['start_url'] ?? '/'),
            'scope' => (string) ($runtime['scope'] ?? '/'),
            'display' => (string) ($runtime['display'] ?? 'standalone'),
            'orientation' => (string) ($runtime['orientation'] ?? 'portrait'),
            'theme_color' => (string) ($runtime['theme_color'] ?? '#0d6efd'),
            'background_color' => (string) ($runtime['background_color'] ?? '#ffffff'),
            'icons' => is_array($runtime['icons'] ?? null) ? array_values($runtime['icons']) : [],
        ];
    }

    /**
     * @param array<string,mixed>|null $runtime
     */
    public static function serviceWorkerBody(array $runtime = null): string
    {
        $runtime = is_array($runtime) ? $runtime : self::build();

        $cacheName = (string) ($runtime['cache_prefix'] ?? 'logeon-pwa') . '-' . (string) ($runtime['cache_version'] ?? date('Ymd'));
        $cacheEnabled = (($runtime['cache_enabled'] ?? false) === true);
        $precacheUrls = array_values(array_filter(
            array_map('strval', is_array($runtime['precache_urls'] ?? null) ? $runtime['precache_urls'] : []),
            static function (string $url): bool {
                return trim($url) !== '';
            },
        ));

        $precacheJson = json_encode($precacheUrls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($precacheJson === false) {
            $precacheJson = '[]';
        }

        $cacheNameJson = json_encode($cacheName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($cacheNameJson === false) {
            $cacheNameJson = '"logeon-pwa"';
        }

        $cacheEnabledJson = $cacheEnabled ? 'true' : 'false';

        return <<<JS
const CACHE_NAME = {$cacheNameJson};
const CACHE_ENABLED = {$cacheEnabledJson};
const PRECACHE_URLS = {$precacheJson};
const STATIC_ASSET_PATTERN = /\\.(?:css|js|mjs|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf)$/i;

self.addEventListener('install', (event) => {
  if (!CACHE_ENABLED) {
    event.waitUntil(self.skipWaiting());
    return;
  }

  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
      .catch(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME && key.indexOf('logeon-pwa-') === 0)
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

function isPrecachedUrl(url) {
  return PRECACHE_URLS.indexOf(url.pathname) !== -1 || PRECACHE_URLS.indexOf(url.pathname + url.search) !== -1;
}

function shouldCache(request) {
  if (!CACHE_ENABLED) {
    return false;
  }

  if (!request || request.method !== 'GET') {
    return false;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return false;
  }

  return isPrecachedUrl(url) || STATIC_ASSET_PATTERN.test(url.pathname);
}

self.addEventListener('fetch', (event) => {
  if (!shouldCache(event.request)) {
    return;
  }

  event.respondWith(
    caches.match(event.request, { ignoreSearch: true }).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(event.request).then((response) => {
        if (!response || response.status !== 200 || response.type === 'opaque') {
          return response;
        }

        const clone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
        return response;
      });
    })
  );
});
JS;
    }

    /**
     * @return array<string,mixed>
     */
    private static function appConfig(): array
    {
        if (!defined('APP')) {
            return [];
        }

        return APP;
    }

    /**
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private static function pwaConfig(array $app): array
    {
        $raw = $app['pwa'] ?? null;
        $config = is_array($raw) ? $raw : [];
        $overrides = self::loadStoredPwaConfig();

        return array_replace($config, $overrides);
    }

    /**
     * @param array<string,mixed> $app
     * @return array<string,mixed>
     */
    private static function frontendConfig(array $app): array
    {
        $raw = $app['frontend'] ?? null;
        return is_array($raw) ? $raw : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadStoredPwaConfig(): array
    {
        static $config = null;
        if (is_array($config)) {
            return $config;
        }

        $config = [];
        $keyMap = [
            'pwa_enabled' => 'enabled',
            'pwa_name' => 'name',
            'pwa_short_name' => 'short_name',
            'pwa_description' => 'description',
            'pwa_start_path' => 'start_path',
            'pwa_scope' => 'scope',
            'pwa_display' => 'display',
            'pwa_orientation' => 'orientation',
            'pwa_theme_color' => 'theme_color',
            'pwa_background_color' => 'background_color',
            'pwa_icon_path' => 'icon_path',
            'pwa_icon_192_path' => 'icon_192_path',
            'pwa_icon_512_path' => 'icon_512_path',
            'pwa_icon_maskable_path' => 'icon_maskable_path',
            'pwa_cache_enabled' => 'cache_enabled',
            'pwa_cache_version' => 'cache_version',
        ];

        try {
            $db = DbAdapterFactory::createFromConfig();
            $rows = $db->fetchAllPrepared(
                "SELECT `key`, `value` FROM sys_configs WHERE `key` IN (
                    'pwa_enabled',
                    'pwa_name',
                    'pwa_short_name',
                    'pwa_description',
                    'pwa_start_path',
                    'pwa_scope',
                    'pwa_display',
                    'pwa_orientation',
                    'pwa_theme_color',
                    'pwa_background_color',
                    'pwa_icon_path',
                    'pwa_icon_192_path',
                    'pwa_icon_512_path',
                    'pwa_icon_maskable_path',
                    'pwa_cache_enabled',
                    'pwa_cache_version'
                )",
            );

            foreach ($rows as $row) {
                if (!isset($row->key, $keyMap[$row->key])) {
                    continue;
                }

                $configKey = $keyMap[$row->key];
                if ($row->key === 'pwa_enabled' || $row->key === 'pwa_cache_enabled') {
                    $config[$configKey] = ((int) $row->value === 1);
                    continue;
                }

                $config[$configKey] = (string) $row->value;
            }
        } catch (\Throwable $e) {
            $config = [];
        }

        return $config;
    }

    private static function resolveBasePath(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') {
            return '';
        }

        $candidate = (preg_match('#^https?://#i', $baseUrl) === 1) ? $baseUrl : ('https://' . $baseUrl);
        $path = (string) parse_url($candidate, PHP_URL_PATH);
        if ($path === '' || $path === '/') {
            return '';
        }

        return '/' . trim($path, '/');
    }

    private static function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizeText($value, string $default = ''): string
    {
        $text = trim((string) $value);
        return $text !== '' ? $text : $default;
    }

    private static function plainText($value): string
    {
        $text = trim(strip_tags((string) $value));
        return preg_replace('/\s+/', ' ', $text) ?: '';
    }

    private static function normalizeColor($value, string $default): string
    {
        $color = trim((string) $value);
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) === 1) {
            return strtolower($color);
        }

        return $default;
    }

    /**
     * @param array<int,string> $allowed
     */
    private static function normalizeEnum($value, array $allowed, string $default): string
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }

    private static function normalizeScope(string $path): string
    {
        $normalized = self::normalizePath($path);
        return substr($normalized, -1) === '/' ? $normalized : ($normalized . '/');
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . ltrim($path, '/');
    }

    private static function resolveAppPath(string $basePath, $path, string $default = '/'): string
    {
        $raw = trim((string) $path);
        if ($raw === '') {
            $raw = $default;
        }

        if (preg_match('#^(?:https?:)?//#i', $raw) === 1) {
            return $raw;
        }

        $basePath = trim($basePath);
        $basePrefix = ($basePath === '' || $basePath === '/') ? '' : ('/' . trim($basePath, '/'));

        $normalized = '/' . ltrim($raw, '/');
        if ($basePrefix !== '' && ($normalized === $basePrefix || strpos($normalized, $basePrefix . '/') === 0)) {
            return $normalized;
        }

        if ($basePrefix === '') {
            return $normalized;
        }

        if ($normalized === '/') {
            return $basePrefix . '/';
        }

        return $basePrefix . $normalized;
    }

    private static function resolveOptionalAppPath(string $basePath, $path): string
    {
        $raw = trim((string) $path);
        if ($raw === '') {
            return '';
        }

        return self::resolveAppPath($basePath, $raw, '/');
    }

    /**
     * @param array<string,mixed> $runtime
     * @return array<int,array<string,string>>
     */
    private static function buildIcons(array $runtime): array
    {
        $icons = [];
        $seen = [];
        $candidates = [
            ['src' => (string) ($runtime['icon_192_path'] ?? ''), 'purpose' => 'any'],
            ['src' => (string) ($runtime['icon_512_path'] ?? ''), 'purpose' => 'any'],
            ['src' => (string) ($runtime['icon_path'] ?? ''), 'purpose' => 'any'],
            ['src' => (string) ($runtime['icon_maskable_path'] ?? ''), 'purpose' => 'maskable'],
        ];

        foreach ($candidates as $candidate) {
            $src = trim((string) $candidate['src']);
            if ($src === '') {
                continue;
            }

            $purpose = trim((string) $candidate['purpose']);
            $key = $src . '|' . $purpose;
            if (isset($seen[$key])) {
                continue;
            }

            $entry = ['src' => $src];
            $type = self::detectMimeType($src);
            if ($type !== '') {
                $entry['type'] = $type;
            }

            $size = self::detectIconSize($src, (string) ($runtime['base_path'] ?? ''));
            if ($size !== '') {
                $entry['sizes'] = $size;
            }

            if ($purpose !== 'any') {
                $entry['purpose'] = $purpose;
            }

            $icons[] = $entry;
            $seen[$key] = true;
        }

        return $icons;
    }

    private static function detectMimeType(string $path): string
    {
        $extension = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
        $map = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
        ];

        return $map[$extension] ?? '';
    }

    private static function detectIconSize(string $urlPath, string $basePath): string
    {
        $file = self::filesystemPathForUrl($urlPath, $basePath);
        if ($file === '' || !is_file($file)) {
            return '';
        }

        $size = @getimagesize($file);
        if (!is_array($size)) {
            return '';
        }

        $width = (int) $size[0];
        $height = (int) $size[1];
        if ($width <= 0 || $height <= 0) {
            return '';
        }

        return $width . 'x' . $height;
    }

    /**
     * @param array<string,mixed> $runtime
     * @return array<int,string>
     */
    private static function buildPrecacheUrls(array $runtime, string $bundleVersion): array
    {
        $basePath = (string) ($runtime['base_path'] ?? '');
        $urls = [];

        $fixedCandidates = [
            (string) ($runtime['icon_path'] ?? ''),
            (string) ($runtime['icon_192_path'] ?? ''),
            (string) ($runtime['icon_512_path'] ?? ''),
            '/favicon.ico',
            '/assets/vendor/bootstrap-5.3.8/dist/css/bootstrap.css',
            '/assets/vendor/bootstrap-icons-1.13.1/bootstrap-icons.css',
            '/assets/css/framework.css',
            '/assets/css/style.css',
            '/assets/css/custom.css',
            '/assets/js/jquery/jquery.min.js',
            '/assets/js/plugins/popperjs-2.11.7/popperjs.min.js',
            '/assets/vendor/bootstrap-5.3.8/dist/js/bootstrap.min.js',
        ];

        foreach ($fixedCandidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            $url = self::resolveAppPath($basePath, $candidate, '/');
            if (!self::pathExistsForUrl($url, $basePath)) {
                continue;
            }

            $urls[] = $url;
        }

        $versionedCandidates = [
            '/assets/js/dist/runtime-core.bundle.js',
            '/assets/js/dist/public-core.bundle.js',
            '/assets/js/dist/game-core.bundle.js',
            '/assets/js/dist/game-bootstrap.bundle.js',
        ];

        foreach ($versionedCandidates as $candidate) {
            $url = self::resolveAppPath($basePath, $candidate, '/');
            if (!self::pathExistsForUrl($url, $basePath)) {
                continue;
            }

            $urls[] = $url . '?v=' . rawurlencode($bundleVersion);
        }

        return array_values(array_unique($urls));
    }

    private static function pathExistsForUrl(string $urlPath, string $basePath): bool
    {
        $file = self::filesystemPathForUrl($urlPath, $basePath);
        return $file !== '' && is_file($file);
    }

    private static function filesystemPathForUrl(string $urlPath, string $basePath): string
    {
        if ($urlPath === '' || preg_match('#^(?:https?:)?//#i', $urlPath) === 1) {
            return '';
        }

        $projectRoot = dirname(__DIR__);
        $path = (string) (parse_url($urlPath, PHP_URL_PATH) ?: $urlPath);
        $path = '/' . ltrim($path, '/');

        $basePrefix = ($basePath === '' || $basePath === '/') ? '' : ('/' . trim($basePath, '/'));
        if ($basePrefix !== '' && ($path === $basePrefix || strpos($path, $basePrefix . '/') === 0)) {
            $path = substr($path, strlen($basePrefix));
            $path = $path === '' ? '/' : $path;
        }

        return rtrim($projectRoot, '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'app';
        return trim($value, '-') ?: 'app';
    }
}
