<?php

declare(strict_types=1);

namespace Core;

use App\Services\AuthService;
use Core\Contracts\DbConnectionProviderInterface;
use Core\Contracts\SessionInterface;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;
use Core\Http\RequestContext;

class SessionGuard
{
    /** @var callable|null */
    private $onFail;
    /** @var int */
    private $timeoutSeconds;
    /** @var bool|null */
    private $forceJson = null;
    /** @var DbAdapterInterface|null */
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
        self::$dbAdapter = null;
        self::$dbProvider = null;
        self::$session = null;
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

    private function getSessionValue($key)
    {
        return self::session()->get((string) $key);
    }

    private function setSessionValue($key, $value): void
    {
        self::session()->set((string) $key, $value);
    }

    private function getServerValue(string $key, $default = null)
    {
        $server = RequestContext::server();
        return array_key_exists($key, $server) ? $server[$key] : $default;
    }

    public function __construct(callable $onFail = null, $timeoutSeconds = null)
    {
        $this->onFail = $onFail;
        if ($timeoutSeconds !== null && (int) $timeoutSeconds > 0) {
            $this->timeoutSeconds = (int) $timeoutSeconds;
        } else {
            $this->timeoutSeconds = defined('CONFIG') ? (int) CONFIG['session_time_life'] : 5400;
        }
    }

    public static function default(): self
    {
        return new self();
    }

    public function withFailureHandler(callable $handler): self
    {
        $this->onFail = $handler;
        return $this;
    }

    public function withTimeoutSeconds(int $seconds): self
    {
        if ($seconds > 0) {
            $this->timeoutSeconds = $seconds;
        }
        return $this;
    }

    public function withJsonResponse(bool $force): self
    {
        $this->forceJson = $force;
        return $this;
    }

    public function check($name = null): void
    {
        if (!$this->hasSessionValue($name)) {
            $this->fail('missing_session');
            return;
        }

        $this->enforceTimeout();
        $this->enforceSessionVersion();
    }

    public function enforceTimeout(): void
    {
        $time = $this->now();
        $timeout_duration = $this->timeoutSeconds;

        $lastActivity = $this->getSessionValue('last_activity');
        if ($lastActivity && ($time - $lastActivity) > $timeout_duration) {
            $this->fail('timeout');
            return;
        }

        $this->setSessionValue('last_activity', $time);
    }

    public function enforceSessionVersion(): void
    {
        $user_id = $this->getSessionUserId();
        $session_version = $this->getSessionValue('user_session_version');
        if (empty($user_id) || $session_version === null) {
            return;
        }

        $now = $this->now();
        $last_checked = $this->getSessionValue('user_session_version_checked_at');
        $interval = $this->sessionVersionCheckInterval();
        if (!empty($last_checked) && ($now - (int) $last_checked) < $interval) {
            return;
        }

        $currentVersion = $this->getCurrentSessionVersion($user_id);
        if ($currentVersion === null || $currentVersion !== (int) $session_version) {
            $this->fail('session_version_mismatch');
            return;
        }

        $this->setSessionValue('user_session_version_checked_at', $now);
    }

    private function getSessionUserId(): int
    {
        return (int) $this->getSessionValue('user_id');
    }

    private function now(): int
    {
        $requestTime = (int) $this->getServerValue('REQUEST_TIME', 0);
        if ($requestTime > 0) {
            return $requestTime;
        }

        return time();
    }

    private function hasSessionValue($key): bool
    {
        $name = trim((string) $key);
        if ($name === '') {
            return true;
        }

        return !empty($this->getSessionValue($name));
    }

    private function sessionVersionCheckInterval(): int
    {
        $interval = (int) $this->getSessionValue('config_session_version_check_seconds');
        if ($interval <= 0) {
            return 60;
        }

        return $interval;
    }

    private function getCurrentSessionVersion(int $userId): ?int
    {
        $row = self::db()->fetchOnePrepared(
            'SELECT session_version
             FROM users
             WHERE id = ?
             LIMIT 1',
            [$userId],
        );

        if (empty($row) || !isset($row->session_version)) {
            return null;
        }

        return (int) $row->session_version;
    }

    private function fail(string $reason): void
    {
        if ($this->onFail !== null) {
            call_user_func($this->onFail, $reason);
            return;
        }

        AuthService::signout();
        if ($this->shouldRespondJson()) {
            throw AppError::unauthorized('Sessione scaduta', [], $this->sessionErrorCode($reason));
        }

        Redirect::url('/');
    }

    private function shouldRespondJson(): bool
    {
        if ($this->forceJson !== null) {
            return $this->forceJson === true;
        }

        if (RequestContext::wantsJson()) {
            return true;
        }

        return \Core\Router::shouldRespondJson();
    }

    private function sessionErrorCode(string $reason): string
    {
        if ($reason === 'missing_session') {
            return 'session_missing';
        }
        if ($reason === 'timeout') {
            return 'session_expired';
        }
        if ($reason === 'session_version_mismatch') {
            return 'session_revoked';
        }

        return 'session_invalid';
    }
}
