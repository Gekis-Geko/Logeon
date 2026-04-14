<?php

declare(strict_types=1);

namespace Core\Logging;

interface LoggerInterface
{
    /**
     * @param mixed $message
     * @param mixed $context
     */
    public function trace($message, $context = false): void;

    public function error(string $message): void;
}
