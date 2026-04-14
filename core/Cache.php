<?php

declare(strict_types=1);

namespace Core;

class Cache
{
    private static function enabled(): bool
    {
        return (defined('CONFIG') && isset(CONFIG['cache']['enabled']) && CONFIG['cache']['enabled'] === true);
    }

    private static function dir(): string
    {
        if (defined('CONFIG') && isset(CONFIG['cache']['dir']) && CONFIG['cache']['dir'] !== '') {
            return (string) CONFIG['cache']['dir'];
        }
        if (defined('CONFIG') && isset(CONFIG['dirs']['tmp'])) {
            return (string) CONFIG['dirs']['tmp'] . '/cache';
        }
        return __DIR__ . '/../tmp/cache';
    }

    private static function ttl($ttl = null): int
    {
        if ($ttl !== null && $ttl > 0) {
            return (int) $ttl;
        }
        if (defined('CONFIG') && isset(CONFIG['cache']['ttl'])) {
            return (int) CONFIG['cache']['ttl'];
        }
        return 300;
    }

    private static function path($key): string
    {
        $hash = sha1((string) $key);
        return rtrim(static::dir(), '/\\') . '/' . $hash . '.cache';
    }

    private static function ensureDir(): void
    {
        $dir = static::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public static function get($key)
    {
        if (!static::enabled()) {
            return null;
        }

        $path = static::path($key);
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $payload = static::decodePayload($raw);
        if ($payload === null) {
            @unlink($path);
            return null;
        }

        if ($payload['exp'] !== 0 && $payload['exp'] < time()) {
            @unlink($path);
            return null;
        }

        return $payload['value'] ?? null;
    }

    public static function set($key, $value, $ttl = null): bool
    {
        if (!static::enabled()) {
            return false;
        }

        static::ensureDir();
        $exp = static::ttl($ttl);
        $payload = [
            'exp' => ($exp > 0) ? (time() + $exp) : 0,
            'value' => $value,
        ];

        $path = static::path($key);
        return (bool) @file_put_contents($path, serialize($payload));
    }

    public static function forget($key): bool
    {
        if (!static::enabled()) {
            return false;
        }

        $path = static::path($key);
        if (file_exists($path)) {
            return @unlink($path);
        }
        return false;
    }

    private static function decodePayload(string $raw): ?array
    {
        $payload = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($payload) || !array_key_exists('exp', $payload)) {
            return null;
        }

        return $payload;
    }
}
