<?php

declare(strict_types=1);

namespace Core;

use Core\Contracts\ConfigRepositoryInterface;
use Core\Contracts\DbConnectionProviderInterface;
use Core\Contracts\SessionInterface;
use Core\Database\DbAdapterInterface;

class FeatureFlags
{
    private static $cache = null;
    private static $dbAdapter = null;
    /** @var DbConnectionProviderInterface|null */
    private static $dbProvider = null;
    /** @var SessionInterface|null */
    private static $session = null;
    /** @var ConfigRepositoryInterface|null */
    private static $config = null;

    public static function setDbAdapter(DbAdapterInterface $adapter = null): void
    {
        static::$dbAdapter = $adapter;
    }

    public static function setDbProvider(DbConnectionProviderInterface $provider = null): void
    {
        static::$dbProvider = $provider;
    }

    public static function setSession(SessionInterface $session = null): void
    {
        static::$session = $session;
    }

    public static function setConfig(ConfigRepositoryInterface $config = null): void
    {
        static::$config = $config;
    }

    private static function db(): DbAdapterInterface
    {
        if (static::$dbAdapter instanceof DbAdapterInterface) {
            return static::$dbAdapter;
        }

        if (static::$dbProvider instanceof DbConnectionProviderInterface) {
            static::$dbAdapter = static::$dbProvider->connection();
            return static::$dbAdapter;
        }

        static::$dbAdapter = AppContext::dbProvider()->connection();
        return static::$dbAdapter;
    }

    private static function session(): SessionInterface
    {
        if (static::$session instanceof SessionInterface) {
            return static::$session;
        }

        static::$session = AppContext::session();
        return static::$session;
    }

    private static function config(): ConfigRepositoryInterface
    {
        if (static::$config instanceof ConfigRepositoryInterface) {
            return static::$config;
        }

        static::$config = AppContext::config();
        return static::$config;
    }

    private static function getSessionValue($key)
    {
        return static::session()->get((string) $key);
    }

    private static function setSessionValue($key, $value): void
    {
        static::session()->set((string) $key, $value);
    }

    private static function configSessionPrefix(): string
    {
        $prefix = trim((string) static::config()->get('CONFIG.feature_flags.session_prefix', 'config_'));
        if ($prefix === '') {
            return 'config_';
        }

        return $prefix;
    }

    private static function getSessionConfigValue(string $key)
    {
        return static::getSessionValue(static::configSessionPrefix() . $key);
    }

    private static function setSessionConfigValue(string $key, $value): void
    {
        static::setSessionValue(static::configSessionPrefix() . $key, $value);
    }

    private static function normalizeKey($key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return '';
        }
        if (strpos($key, 'feature_') === 0) {
            return $key;
        }

        return 'feature_' . $key;
    }

    private static function parseBool($value, $fallback = false): bool
    {
        if ($value === null || $value === '') {
            return (bool) $fallback;
        }

        if (is_bool($value)) {
            return $value;
        }

        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    public static function all($forceReload = false): array
    {
        if ($forceReload !== true && is_array(static::$cache)) {
            return static::$cache;
        }

        $rows = static::db()->fetchAllPrepared(
            "SELECT `key`, `value`
             FROM sys_configs
             WHERE `key` LIKE 'feature\\_%' ESCAPE '\\\\'",
        );

        $dataset = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $dataset[$row->key] = static::parseBool($row->value, false);
            }
        }

        static::$cache = $dataset;
        return static::$cache;
    }

    public static function get($key, $default = null): bool
    {
        $dbKey = static::normalizeKey($key);
        if ($dbKey === '') {
            return static::parseBool($default, false);
        }

        $sessionValue = static::getSessionConfigValue($dbKey);
        if ($sessionValue !== null && $sessionValue !== '') {
            return static::parseBool($sessionValue, $default);
        }

        $flags = static::all();
        if (array_key_exists($dbKey, $flags)) {
            return (bool) $flags[$dbKey];
        }

        return static::parseBool($default, false);
    }

    public static function isEnabled($key, $default = false): bool
    {
        return static::parseBool(static::get($key, $default), $default);
    }

    public static function set($key, $enabled = true): bool
    {
        $dbKey = static::normalizeKey($key);
        if ($dbKey === '') {
            return false;
        }

        $value = static::parseBool($enabled, false) ? 1 : 0;
        $db = static::db();
        $db->executePrepared(
            'INSERT INTO sys_configs (`key`, `value`, `type`)
             VALUES (?, ?, "number")
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)',
            [$dbKey, (string) $value],
        );

        static::setSessionConfigValue($dbKey, (string) $value);
        if (!is_array(static::$cache)) {
            static::$cache = [];
        }
        static::$cache[$dbKey] = ($value === 1);

        return true;
    }

    public static function setMany($flags = []): bool
    {
        if (!is_array($flags)) {
            return false;
        }

        foreach ($flags as $key => $enabled) {
            static::set($key, $enabled);
        }

        return true;
    }

    public static function invalidate($key = null): void
    {
        if ($key === null) {
            static::$cache = null;
            return;
        }

        $dbKey = static::normalizeKey($key);
        if ($dbKey === '') {
            return;
        }

        if (is_array(static::$cache) && array_key_exists($dbKey, static::$cache)) {
            unset(static::$cache[$dbKey]);
        }
    }

    public static function resetRuntimeState(): void
    {
        static::$cache = null;
        static::$dbAdapter = null;
        static::$dbProvider = null;
        static::$session = null;
        static::$config = null;
    }

    public static function invalidateMany($prefix = null): void
    {
        if ($prefix === null || trim((string) $prefix) === '') {
            static::$cache = null;
            return;
        }

        $prefix = static::normalizeKey($prefix);
        if ($prefix === '') {
            return;
        }

        if (!is_array(static::$cache)) {
            return;
        }

        foreach (array_keys(static::$cache) as $key) {
            if (strpos($key, $prefix) === 0) {
                unset(static::$cache[$key]);
            }
        }
    }

    public static function isEnabledStrict($key, $default = false): bool
    {
        $dbKey = static::normalizeKey($key);
        if ($dbKey === '') {
            return static::parseBool($default, false);
        }

        $sessionValue = static::getSessionConfigValue($dbKey);
        if ($sessionValue !== null && $sessionValue !== '') {
            return static::parseBool($sessionValue, $default);
        }

        $flags = static::all();
        if (array_key_exists($dbKey, $flags)) {
            return (bool) $flags[$dbKey];
        }

        return static::parseBool($default, false);
    }
}
