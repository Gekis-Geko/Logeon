<?php

declare(strict_types=1);

namespace Core\Models;

use Core\AuditLogService;
use Core\Router;

class ModelAudit
{
    public function writeItem(array $payload): void
    {
        AuditLogService::writeFromUrl(Router::currentUri(), $payload);
    }

    public function writeList(array $payload): void
    {
        AuditLogService::writeFromUrl(Router::currentUri(), $payload);
    }

    public function writeDelete(array $payload): void
    {
        AuditLogService::writeFromUrl(Router::currentUri(), $payload);
    }

    public function writeTruncate(array $payload): void
    {
        AuditLogService::writeFromUrl(Router::currentUri(), $payload);
    }
}
