<?php

declare(strict_types=1);

namespace Core\Contracts;

interface ClockInterface
{
    public function now(): \DateTimeImmutable;
}
