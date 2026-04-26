<?php

use Core\Hooks;
use Core\AuthGuard;
use App\Services\AuthService;
use Core\FeatureFlags;
use Core\RateLimiter;
use Core\SessionGuard;
use Core\AuditLogService;
use Core\CurrencyLogs;
use Core\UploadManager;
use Core\Database\DbAdapterFactory;
use Core\Models;

$dbAdapter = DbAdapterFactory::createFromConfig();
AuthGuard::setDbAdapter($dbAdapter);
AuthService::setDbAdapter($dbAdapter);
FeatureFlags::setDbAdapter($dbAdapter);
RateLimiter::setDbAdapter($dbAdapter);
SessionGuard::setDbAdapter($dbAdapter);
AuditLogService::setDbAdapter($dbAdapter);
CurrencyLogs::setDbAdapter($dbAdapter);
UploadManager::setDbAdapter($dbAdapter);
Models::setDbAdapter($dbAdapter);

if (class_exists('\\Core\\ModuleRuntime')) {
    \Core\ModuleRuntime::instance()->bootActiveModules();
}

// Bootstrap custom logic here.
// Esempio:
// Hooks::add('twig.register', function ($twig) {
//     // $twig->addFunction(...);
// });
