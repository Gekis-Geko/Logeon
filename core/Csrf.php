<?php

declare(strict_types=1);

namespace Core;

use Core\Contracts\SessionInterface;
use Core\Http\AppError;
use Core\Http\RequestContext;
use Core\Http\RequestData;

class Csrf
{
    private static $session_key = 'csrf_token';
    /** @var SessionInterface|null */
    private static $session = null;

    private static function getSessionToken()
    {
        return self::getSessionValue(self::$session_key);
    }

    private static function setSessionToken($token): void
    {
        self::setSessionValue(self::$session_key, $token);
    }

    private static function getSessionValue($key)
    {
        return self::session()->get((string) $key);
    }

    private static function setSessionValue($key, $value): void
    {
        self::session()->set((string) $key, $value);
    }

    public static function setSession(SessionInterface $session = null): void
    {
        self::$session = $session;
    }

    public static function resetRuntimeState(): void
    {
        self::$session = null;
    }

    private static function session(): SessionInterface
    {
        if (self::$session instanceof SessionInterface) {
            return self::$session;
        }

        self::$session = AppContext::session();
        return self::$session;
    }

    private static function getServerValue(string $key, $default = null)
    {
        $server = RequestContext::server();
        return array_key_exists($key, $server) ? $server[$key] : $default;
    }

    public static function token(): string
    {
        $token = self::getSessionToken();
        if (empty($token)) {
            $token = bin2hex(random_bytes(32));
            self::setSessionToken($token);
        }

        return $token;
    }

    public static function validate(): bool
    {
        $session_token = self::getSessionToken();
        if (empty($session_token)) {
            $session_token = self::token();
        }

        $request_token = self::getRequestToken();
        if (empty($request_token) || !hash_equals($session_token, $request_token)) {
            throw AppError::unauthorized('Token CSRF non valido.', [], 'csrf_invalid');
        }

        return true;
    }

    private static function getRequestToken(): ?string
    {
        $request = RequestData::fromGlobals();
        $csrfHeader = self::getServerValue('HTTP_X_CSRF_TOKEN');
        if (!empty($csrfHeader)) {
            return $csrfHeader;
        }

        $token = $request->post('_csrf');
        if (!empty($token)) {
            return $token;
        }

        $token = $request->post('csrf_token');
        if (!empty($token)) {
            return $token;
        }

        $payload = $request->postJson('data', [], true);
        if (is_array($payload) && !empty($payload['_csrf'])) {
            return $payload['_csrf'];
        }

        return null;
    }
}
