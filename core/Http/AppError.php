<?php

declare(strict_types=1);

namespace Core\Http;

class AppError extends \RuntimeException
{
    /** @var int */
    private $status = 400;
    /** @var string */
    private $errorCode = '';
    /** @var array<string,mixed> */
    private $payload = [];

    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        string $message,
        int $status = 400,
        array $payload = [],
        \Throwable $previous = null,
        string $errorCode = '',
    ) {
        parent::__construct($message, 0, $previous);

        $this->status = $status;
        $this->payload = $payload;
        $this->errorCode = static::normalizeErrorCode($errorCode, $payload);
        if ($this->errorCode !== '' && !array_key_exists('error_code', $this->payload)) {
            $this->payload['error_code'] = $this->errorCode;
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function validation(string $message, array $payload = [], string $errorCode = 'validation_error'): self
    {
        $normalizedErrorCode = trim($errorCode);
        if ($normalizedErrorCode === '') {
            $normalizedErrorCode = 'validation_error';
        }

        return new self($message, 400, $payload, null, $normalizedErrorCode);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function unauthorized(
        string $message = 'Operazione non autorizzata',
        array $payload = [],
        string $errorCode = 'unauthorized',
    ): self {
        return new self($message, 403, $payload, null, $errorCode);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function notFound(
        string $message = 'Risorsa non trovata',
        array $payload = [],
        string $errorCode = 'not_found',
    ): self {
        return new self($message, 404, $payload, null, $errorCode);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function normalizeErrorCode(string $errorCode, array $payload): string
    {
        $errorCode = trim($errorCode);
        if ($errorCode !== '') {
            return $errorCode;
        }

        if (!array_key_exists('error_code', $payload)) {
            return '';
        }

        return trim((string) $payload['error_code']);
    }
}
