<?php

declare(strict_types=1);

namespace Core\Logging;

use Core\Http\AppError;
use Core\Logs;

class LegacyLoggerAdapter implements LoggerInterface
{
    public function trace($message, $context = false): void
    {
        Logs::trace($message, $context);
    }

    public function error(string $message): void
    {
        Logs::trace('Errore: ' . $message);
        throw AppError::validation($message);
    }
}
