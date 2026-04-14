<?php

declare(strict_types=1);

namespace Core\Http;

class ApiResponse
{
    /** @var array<string,mixed> */
    private $payload = [];
    /** @var int */
    private $status = 200;
    /** @var array<string,string> */
    private $headers = [];
    /** @var int */
    private $jsonFlags = 0;

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public function __construct(array $payload = [], int $status = 200, array $headers = [], int $jsonFlags = 0)
    {
        $this->payload = $payload;
        $this->status = $status;
        $this->headers = $headers;
        $this->jsonFlags = $jsonFlags;
    }

    /**
     * Transitional helper: keeps legacy payload shape unchanged.
     *
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public static function json(array $payload = [], int $status = 200, array $headers = [], int $jsonFlags = 0): self
    {
        return new self($payload, $status, $headers, $jsonFlags);
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function jsonFlags(): int
    {
        return $this->jsonFlags;
    }
}
