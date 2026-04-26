<?php

declare(strict_types=1);

namespace Core;

use Core\Contracts\DbConnectionProviderInterface;
use Core\Contracts\SessionInterface;
use Core\Database\DbAdapterInterface;
use Core\Http\RequestContext;

class RateLimiter
{
    public const SESSION_KEY = '__rate_limit_store';
    private static $configCache = [];
    private static $dbAdapter = null;
    /** @var DbConnectionProviderInterface|null */
    private static $dbProvider = null;
    /** @var SessionInterface|null */
    private static $session = null;

    public static function setDbAdapter(DbAdapterInterface $adapter = null): void
    {
        self::$dbAdapter = $adapter;
    }

    public static function setDbProvider(DbConnectionProviderInterface $provider = null): void
    {
        self::$dbProvider = $provider;
    }

    public static function resetRuntimeState(): void
    {
        self::$configCache = [];
        self::$dbAdapter = null;
        self::$dbProvider = null;
        self::$session = null;
    }

    public static function invalidateConfigCache($key = null): void
    {
        if ($key === null) {
            self::$configCache = [];
            return;
        }

        $name = trim((string) $key);
        if ($name === '') {
            return;
        }

        if (array_key_exists($name, self::$configCache)) {
            unset(self::$configCache[$name]);
        }
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

    public static function setSession(SessionInterface $session = null): void
    {
        self::$session = $session;
    }

    private static function session(): SessionInterface
    {
        if (self::$session instanceof SessionInterface) {
            return self::$session;
        }

        self::$session = AppContext::session();
        return self::$session;
    }

    private static function getSessionValue($key)
    {
        return self::session()->get((string) $key);
    }

    private static function setSessionValue($key, $value): void
    {
        self::session()->set((string) $key, $value);
    }

    private static function getServerValue(string $key, $default = null)
    {
        $server = RequestContext::server();
        return array_key_exists($key, $server) ? $server[$key] : $default;
    }

    private static function now(): int
    {
        $requestTime = (int) self::getServerValue('REQUEST_TIME', 0);
        if ($requestTime > 0) {
            return $requestTime;
        }

        return time();
    }

    private static function getStore(): array
    {
        $store = self::getSessionValue(self::SESSION_KEY);
        if (!is_array($store)) {
            $store = [];
        }

        return $store;
    }

    private static function setStore(array $store): void
    {
        self::setSessionValue(self::SESSION_KEY, $store);
    }

    private static function resolveIdentifier($identifier = ''): string
    {
        $identifier = trim((string) $identifier);
        if ($identifier !== '') {
            return $identifier;
        }

        $userId = self::getSessionUserId();
        if ($userId > 0) {
            return 'user:' . $userId;
        }

        $ip = (string) self::getServerValue('REMOTE_ADDR', '0.0.0.0');
        return 'ip:' . $ip;
    }

    private static function getSessionUserId(): int
    {
        return (int) self::getSessionValue('user_id');
    }

    private static function key($bucket, $identifier = ''): string
    {
        return trim((string) $bucket) . '|' . self::resolveIdentifier($identifier);
    }

    private static function pruneExpiredStore(array $store, int $now): array
    {
        if (empty($store)) {
            return $store;
        }

        foreach ($store as $key => $entry) {
            if (!is_array($entry)) {
                unset($store[$key]);
                continue;
            }

            $resetAt = (int) ($entry['reset_at'] ?? 0);
            if ($resetAt > 0 && $resetAt <= $now) {
                unset($store[$key]);
            }
        }

        return $store;
    }

    public static function hit($bucket, $limit = 5, $windowSeconds = 60, $identifier = ''): array
    {
        $limit = max(1, (int) $limit);
        $windowSeconds = max(1, (int) $windowSeconds);
        $key = self::key($bucket, $identifier);
        $now = self::now();

        $store = self::pruneExpiredStore(self::getStore(), $now);
        $entry = $store[$key] ?? [
            'count' => 0,
            'reset_at' => $now + $windowSeconds,
        ];

        if (!isset($entry['reset_at']) || (int) $entry['reset_at'] <= $now) {
            $entry['count'] = 0;
            $entry['reset_at'] = $now + $windowSeconds;
        }

        $entry['count'] = (int) $entry['count'] + 1;
        $store[$key] = $entry;
        self::setStore($store);

        $allowed = ((int) $entry['count'] <= $limit);
        $remaining = max(0, $limit - (int) $entry['count']);
        $retryAfter = $allowed ? 0 : max(1, (int) $entry['reset_at'] - $now);

        return [
            'allowed' => $allowed,
            'count' => (int) $entry['count'],
            'limit' => $limit,
            'remaining' => $remaining,
            'reset_at' => (int) $entry['reset_at'],
            'retry_after' => $retryAfter,
        ];
    }

    public static function check($bucket, $limit = 5, $windowSeconds = 60, $identifier = ''): array
    {
        $limit = max(1, (int) $limit);
        $windowSeconds = max(1, (int) $windowSeconds);
        $key = self::key($bucket, $identifier);
        $now = self::now();

        $store = self::pruneExpiredStore(self::getStore(), $now);
        $entry = $store[$key] ?? null;
        if ($entry === null || !isset($entry['reset_at']) || (int) $entry['reset_at'] <= $now) {
            return [
                'allowed' => true,
                'count' => 0,
                'limit' => $limit,
                'remaining' => $limit,
                'reset_at' => $now + $windowSeconds,
                'retry_after' => 0,
            ];
        }

        $count = (int) ($entry['count'] ?? 0);
        $allowed = ($count < $limit);
        $remaining = max(0, $limit - $count);
        $retryAfter = $allowed ? 0 : max(1, (int) $entry['reset_at'] - $now);

        return [
            'allowed' => $allowed,
            'count' => $count,
            'limit' => $limit,
            'remaining' => $remaining,
            'reset_at' => (int) $entry['reset_at'],
            'retry_after' => $retryAfter,
        ];
    }

    public static function clear($bucket, $identifier = ''): void
    {
        $key = self::key($bucket, $identifier);
        $store = self::pruneExpiredStore(self::getStore(), self::now());
        if (array_key_exists($key, $store)) {
            unset($store[$key]);
            self::setStore($store);
        }
    }

    public static function clearAll(): void
    {
        self::setStore([]);
    }

    public static function getConfigInt($key, $default, $min = null, $max = null): int
    {
        $default = (int) $default;
        $value = null;

        $sessionValue = self::getSessionValue('config_' . $key);
        if ($sessionValue !== null && $sessionValue !== '') {
            $value = (int) $sessionValue;
        } else {
            if (!array_key_exists($key, self::$configCache)) {
                $row = self::db()->fetchOnePrepared(
                    'SELECT `value`
                     FROM sys_configs
                     WHERE `key` = ?
                     LIMIT 1',
                    [$key],
                );
                self::$configCache[$key] = (!empty($row) && isset($row->value)) ? (int) $row->value : null;
            }
            $value = self::$configCache[$key];
        }

        if ($value === null) {
            $value = $default;
        }
        if ($min !== null && $value < (int) $min) {
            $value = (int) $min;
        }
        if ($max !== null && $value > (int) $max) {
            $value = (int) $max;
        }

        return (int) $value;
    }

    public static function getRule($prefix, $defaultLimit, $defaultWindow, $minLimit = 1, $maxLimit = 200, $minWindow = 1, $maxWindow = 3600): array
    {
        $prefix = trim((string) $prefix);
        if ($prefix === '') {
            return [
                'limit' => (int) $defaultLimit,
                'window' => (int) $defaultWindow,
            ];
        }

        $limit = self::getConfigInt('rate_' . $prefix . '_limit', $defaultLimit, $minLimit, $maxLimit);
        $window = self::getConfigInt('rate_' . $prefix . '_window_seconds', $defaultWindow, $minWindow, $maxWindow);

        return [
            'limit' => (int) $limit,
            'window' => (int) $window,
        ];
    }
}
