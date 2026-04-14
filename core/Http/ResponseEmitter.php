<?php

declare(strict_types=1);

namespace Core\Http;

class ResponseEmitter
{
    public const DEFAULT_JSON_FLAGS = 0;

    /**
     * @return array<string,mixed>
     */
    public static function emit(ApiResponse $response): array
    {
        return static::json(
            $response->payload(),
            $response->status(),
            $response->headers(),
            $response->jsonFlags(),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     * @return array<string,mixed>
     */
    public static function json(array $payload = [], int $status = 200, array $headers = [], int $jsonFlags = self::DEFAULT_JSON_FLAGS): array
    {
        static::sendStatus($status);
        static::sendHeader('Content-Type', 'application/json; charset=UTF-8');
        static::sendHeaders($headers);
        $json = json_encode($payload, $jsonFlags);
        if ($json === false) {
            $retryFlags = $jsonFlags;
            if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
                $retryFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
            }

            $json = json_encode($payload, $retryFlags);
        }

        if ($json === false) {
            error_log('[Logeon][ResponseEmitter] json_encode failed: ' . json_last_error_msg());
            $json = '{"error":"Errore di sistema","error_code":"response_json_encode_failed"}';
        }

        echo $json;

        return $payload;
    }

    public static function html(string $body, int $status = 200, array $headers = []): string
    {
        static::sendStatus($status);
        static::sendHeader('Content-Type', 'text/html; charset=UTF-8');
        static::sendHeaders($headers);

        echo $body;

        return $body;
    }

    private static function sendStatus(int $status): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);
    }

    private static function sendHeader(string $name, string $value): void
    {
        if (headers_sent()) {
            return;
        }

        header($name . ': ' . $value);
    }

    /**
     * @param array<string,string> $headers
     */
    private static function sendHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            static::sendHeader($name, (string) $value);
        }
    }
}
