<?php

declare(strict_types=1);

namespace Core;

use Core\Http\ApiResponse;
use Core\Http\RequestContext;
use Core\Http\ResponseEmitter;

class Redirect
{
    private static function shouldRespondJson(): bool
    {
        if (\Core\Router::shouldRespondJson()) {
            return true;
        }

        return RequestContext::wantsJson();
    }

    private static function redirectPayload(string $url): array
    {
        return [
            'error' => 'redirect',
            'error_code' => 'redirect_required',
            'redirect' => $url,
        ];
    }

    private static function send(string $url, int $code = 301): void
    {
        if (self::shouldRespondJson()) {
            ResponseEmitter::emit(ApiResponse::json(self::redirectPayload($url)));
            return;
        }

        if (headers_sent()) {
            $safe = self::escapeUrl($url);
            echo '<script>location.href="' . $safe . '";</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . $safe . '"></noscript>';
            return;
        }

        header('Location: ' . $url, true, $code);
    }

    public static function route(string $route): void
    {
        self::send($route, 301);

        return;
    }

    public static function url(string $url): void
    {
        self::send($url, 301);

        return;
    }

    public static function back(): void
    {
        $server = RequestContext::server();
        $ref = isset($server['HTTP_REFERER']) ? $server['HTTP_REFERER'] : '/';
        self::send($ref, 301);

        return;
    }

    private static function escapeUrl(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}
