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
        self::$dbAdapter = $adapter;
    }

    public static function setDbProvider(DbConnectionProviderInterface $provider = null): void
    {
        self::$dbProvider = $provider;
    }

    public static function setSession(SessionInterface $session = null): void
    {
        self::$session = $session;
    }

    public static function setConfig(ConfigRepositoryInterface $config = null): void
    {
        self::$config = $config;
    }

    private static function db(): DbAdapterInterface
    {
        if (self::$dbAdapter instanceof DbAdapterInterface) {
            return self::$dbAdapter;
        }

        if (self::$dbProvider instanceof DbConnectionProviderInterface) {
            self::$dbAdapter = self::$dbProvider->connection();
            return self::$dbAdapter;
        }

        self::$dbAdapter = AppContext::dbProvider()->connection();
        return self::$dbAdapter;
    }

    private static function session(): SessionInterface
    {
        if (self::$session instanceof SessionInterface) {
            return self::$session;
        }

        self::$session = AppContext::session();
        return self::$session;
    }

    private static function config(): ConfigRepositoryInterface
    {
        if (self::$config instanceof ConfigRepositoryInterface) {
            return self::$config;
        }

        self::$config = AppContext::config();
        return self::$config;
    }

    private static function getSessionValue($key)
    {
        return self::session()->get((string) $key);
    }

    private static function setSessionValue($key, $value): void
    {
        self::session()->set((string) $key, $value);
    }

    private static function configSessionPrefix(): string
    {
        $prefix = trim((string) self::config()->get('CONFIG.feature_flags.session_prefix', 'config_'));
        if ($prefix === '') {
            return 'config_';
        }

        return $prefix;
    }

    private static function getSessionConfigValue(string $key)
    {
        return self::getSessionValue(self::configSessionPrefix() . $key);
    }

    private static function setSessionConfigValue(string $key, $value): void
    {
        self::setSessionValue(self::configSessionPrefix() . $key, $value);
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
        if ($forceReload !== true && is_array(self::$cache)) {
            return self::$cache;
        }

        $rows = self::db()->fetchAllPrepared(
            "SELECT `key`, `value`
             FROM sys_configs
             WHERE `key` LIKE 'feature\\_%' ESCAPE '\\\\'",
        );

        $dataset = [];
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $dataset[$row->key] = self::parseBool($row->value, false);
            }
        }

        self::$cache = $dataset;
        return self::$cache;
    }

    public static function get($key, $default = null): bool
    {
        $dbKey = self::normalizeKey($key);
        if ($dbKey === '') {
            return self::parseBool($default, false);
        }

        $sessionValue = self::getSessionConfigValue($dbKey);
        if ($sessionValue !== null && $sessionValue !== '') {
            return self::parseBool($sessionValue, $default);
        }

        $flags = self::all();
        if (array_key_exists($dbKey, $flags)) {
            return (bool) $flags[$dbKey];
        }

        return self::parseBool($default, false);
    }

    public static function isEnabled($key, $default = false): bool
    {
        return self::parseBool(self::get($key, $default), $default);
    }

    public static function set($key, $enabled = true): bool
    {
        $dbKey = self::normalizeKey($key);
        if ($dbKey === '') {
            return false;
        }

        $value = self::parseBool($enabled, false) ? 1 : 0;
        $db = self::db();
        $db->executePrepared(
            'INSERT INTO sys_configs (`key`, `value`, `type`)
             VALUES (?, ?, "number")
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)',
            [$dbKey, (string) $value],
        );

        self::setSessionConfigValue($dbKey, (string) $value);
        if (!is_array(self::$cache)) {
            self::$cache = [];
        }
        self::$cache[$dbKey] = ($value === 1);

        return true;
    }

    public static function setMany($flags = []): bool
    {
        if (!is_array($flags)) {
            return false;
        }

        foreach ($flags as $key => $enabled) {
            self::set($key, $enabled);
        }

        return true;
    }

    public static function invalidate($key = null): void
    {
        if ($key === null) {
            self::$cache = null;
            return;
        }

        $dbKey = self::normalizeKey($key);
        if ($dbKey === '') {
            return;
        }

        if (is_array(self::$cache) && array_key_exists($dbKey, self::$cache)) {
            unset(self::$cache[$dbKey]);
        }
    }

    public static function resetRuntimeState(): void
    {
        self::$cache = null;
        self::$dbAdapter = null;
        self::$dbProvider = null;
        self::$session = null;
        self::$config = null;
    }

    public static function invalidateMany($prefix = null): void
    {
        if ($prefix === null || trim((string) $prefix) === '') {
            self::$cache = null;
            return;
        }

        $prefix = self::normalizeKey($prefix);
        if ($prefix === '') {
            return;
        }

        if (!is_array(self::$cache)) {
            return;
        }

        foreach (array_keys(self::$cache) as $key) {
            if (strpos($key, $prefix) === 0) {
                unset(self::$cache[$key]);
            }
        }
    }

    public static function isEnabledStrict($key, $default = false): bool
    {
        $dbKey = self::normalizeKey($key);
        if ($dbKey === '') {
            return self::parseBool($default, false);
        }

        $sessionValue = self::getSessionConfigValue($dbKey);
        if ($sessionValue !== null && $sessionValue !== '') {
            return self::parseBool($sessionValue, $default);
        }

        $flags = self::all();
        if (array_key_exists($dbKey, $flags)) {
            return (bool) $flags[$dbKey];
        }

        return self::parseBool($default, false);
    }
}
