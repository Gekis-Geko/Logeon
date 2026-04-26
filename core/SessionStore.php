<?php

declare(strict_types=1);

namespace Core;

class SessionStore
{
    public static function get($key)
    {
        if (!static::has($key)) {
            return null;
        }

        return $_SESSION[$key];
    }

    public static function set($key, $value): void
    {
        self::ensureSessionArray();
        $_SESSION[$key] = $value;
    }

    public static function delete($key): void
    {
        if (!static::has($key)) {
            return;
        }

        unset($_SESSION[$key]);
    }

    public static function has($key): bool
    {
        if (!isset($_SESSION)) {
            return false;
        }

        return array_key_exists($key, $_SESSION);
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
    }

    private static function ensureSessionArray(): void
    {
        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }
}
