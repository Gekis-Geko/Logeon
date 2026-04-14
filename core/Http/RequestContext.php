<?php

declare(strict_types=1);

namespace Core\Http;

class RequestContext
{
    /**
     * @return array<string,mixed>
     */
    public static function server(): array
    {
        return RequestData::readGlobals()['server'];
    }

    /**
     * @return array<string,mixed>
     */
    public static function request(): array
    {
        return RequestData::readGlobals()['request'];
    }

    /**
     * @return array<string,string>
     */
    public static function headers(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if ($headers !== false && is_array($headers)) {
                return $headers;
            }
        }

        $server = static::server();
        $headers = [];
        foreach ($server as $name => $value) {
            if ((substr($name, 0, 5) == 'HTTP_') || ($name == 'CONTENT_TYPE') || ($name == 'CONTENT_LENGTH')) {
                $headers[str_replace([' ', 'Http'], ['-', 'HTTP'], ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = (string) $value;
            }
        }

        return $headers;
    }

    public static function header(string $name, string $default = ''): string
    {
        $target = strtolower($name);
        foreach (static::headers() as $key => $value) {
            if (strtolower((string) $key) === $target) {
                return (string) $value;
            }
        }

        return $default;
    }

    public static function isAjaxRequest(): bool
    {
        return strtolower(static::header('X-Requested-With')) === 'xmlhttprequest';
    }

    public static function wantsJson(): bool
    {
        if (static::isAjaxRequest()) {
            return true;
        }

        $accept = strtolower(static::header('Accept'));
        if ($accept !== '' && strpos($accept, 'application/json') !== false) {
            return true;
        }

        $contentType = strtolower(static::header('Content-Type'));
        if ($contentType !== '' && strpos($contentType, 'application/json') !== false) {
            return true;
        }

        return false;
    }
}
