<?php

declare(strict_types=1);

namespace Core;

class Cache
{
    private static function enabled(): bool
    {
        if (!defined('CONFIG')) {
            return false;
        }
        return CONFIG['cache']['enabled'];
    }

    private static function dir(): string
    {
        if (!defined('CONFIG')) {
            return __DIR__ . '/../tmp/cache';
        }
        return (string) CONFIG['cache']['dir'];
    }

    private static function ttl(int|null $ttl = null): int
    {
        if ($ttl !== null && $ttl > 0) {
            return (int) $ttl;
        }
        if (!defined('CONFIG')) {
            return 300;
        }
        return (int) CONFIG['cache']['ttl'];
    }

    private static function path(string $key): string
    {
        $hash = sha1((string) $key);
        return rtrim(self::dir(), '/\\') . '/' . $hash . '.cache';
    }

    private static function ensureDir(): void
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    public static function get(string $key): mixed
    {
        if (!self::enabled()) {
            return null;
        }

        $path = self::path($key);
        if (!file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $payload = self::decodePayload($raw);
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

    public static function set(string $key, mixed $value, int|null $ttl = null): bool
    {
        if (!self::enabled()) {
            return false;
        }

        self::ensureDir();
        $exp = self::ttl($ttl);
        $payload = [
            'exp' => ($exp > 0) ? (time() + $exp) : 0,
            'value' => $value,
        ];

        $path = self::path($key);
        return (bool) @file_put_contents($path, serialize($payload));
    }

    public static function forget(string $key): bool
    {
        if (!self::enabled()) {
            return false;
        }

        $path = self::path($key);
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
