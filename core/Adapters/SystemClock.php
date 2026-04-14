<?php

declare(strict_types=1);

namespace Core\Adapters;

use Core\Contracts\ClockInterface;

class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
