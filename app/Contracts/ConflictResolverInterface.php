<?php

declare(strict_types=1);

namespace App\Contracts;

interface ConflictResolverInterface
{
    public function mode(): string;

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolveConflict(array $context): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function performRoll(array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function evaluateMargin(float $margin): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function closeConflict(array $payload): array;
}
