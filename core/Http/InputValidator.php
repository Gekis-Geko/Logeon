<?php

declare(strict_types=1);

namespace Core\Http;

class InputValidator
{
    /**
     * @param mixed $source
     * @return array<string,mixed>
     */
    private static function sourceToArray($source): array
    {
        if (is_array($source)) {
            return $source;
        }

        if (is_object($source)) {
            return get_object_vars($source);
        }

        return [];
    }

    public static function postJsonObject(
        RequestData $request,
        string $key = 'data',
        bool $allowInvalid = false,
        string $message = 'Dati non validi',
        string $errorCode = 'payload_invalid',
    ): object {
        $data = $request->postJson($key, (object) [], false);
        if (is_object($data)) {
            return $data;
        }

        if ($allowInvalid) {
            return (object) [];
        }

        throw AppError::validation($message, [], $errorCode);
    }

    /**
     * @param mixed $source
     */
    public static function string($source, string $key, string $default = ''): string
    {
        $map = self::sourceToArray($source);
        if (!array_key_exists($key, $map) || $map[$key] === null) {
            return $default;
        }

        return trim((string) $map[$key]);
    }

    /**
     * @param mixed $source
     * @param array<int,string> $keys
     */
    public static function firstString($source, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = self::string($source, $key, '');
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @param mixed $source
     */
    public static function integer($source, string $key, int $default = 0): int
    {
        $map = self::sourceToArray($source);
        if (!array_key_exists($key, $map) || $map[$key] === null || $map[$key] === '') {
            return $default;
        }

        return (int) $map[$key];
    }

    /**
     * @param mixed $source
     */
    public static function positiveInt(
        $source,
        string $key,
        string $message,
        string $errorCode = 'validation_error',
    ): int {
        $value = self::integer($source, $key, 0);
        if ($value <= 0) {
            throw AppError::validation($message, [], $errorCode);
        }

        return $value;
    }

    /**
     * @param mixed $source
     */
    public static function boolean($source, string $key, bool $default = false): bool
    {
        $map = self::sourceToArray($source);
        if (!array_key_exists($key, $map)) {
            return $default;
        }

        $value = $map[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return ((int) $value) !== 0;
        }
        if (!is_string($value)) {
            return $default;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'on', 'si'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }

        return $default;
    }

    /**
     * @param mixed $source
     * @return array<int,mixed>
     */
    public static function arrayOfValues($source, string $key, array $default = []): array
    {
        $map = self::sourceToArray($source);
        if (!array_key_exists($key, $map)) {
            return $default;
        }

        $value = $map[$key];
        if (is_array($value)) {
            return $value;
        }

        if (is_scalar($value) && $value !== '') {
            return [$value];
        }

        return $default;
    }
}
