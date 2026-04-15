<?php

declare(strict_types=1);

/**
 * Core Auth/Session runtime sanity check (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-core-auth-runtime.php
 */

$root = dirname(__DIR__, 2);

$bootstrap = [
    $root . '/configs/config.php',
    $root . '/configs/db.php',
    $root . '/configs/app.php',
    $root . '/vendor/autoload.php',
];

foreach ($bootstrap as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "[FAIL] Missing bootstrap file: {$file}\n");
        exit(1);
    }
    require_once $file;
}

$customBootstrap = $root . '/custom/bootstrap.php';
if (is_file($customBootstrap)) {
    require_once $customBootstrap;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SERVER['REQUEST_TIME']) || (int) $_SERVER['REQUEST_TIME'] <= 0) {
    $_SERVER['REQUEST_TIME'] = time();
}

use App\Services\AuthPasswordChangeService;
use App\Services\AuthPasswordResetService;
use App\Services\AuthService;
use App\Services\AuthSigninService;
use Core\AuthGuard;
use Core\Database\DbAdapterFactory;
use Core\Database\MysqliDbAdapter;
use Core\Http\AppError;
use Core\SessionGuard;

/**
 * @param array<string,mixed> $data
 */
function setSessionData(array $data): void
{
    $_SESSION = [];
    foreach ($data as $key => $value) {
        $_SESSION[$key] = $value;
    }
}

function assertOrFail(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $adapter = DbAdapterFactory::createFromConfig();
    assertOrFail($adapter instanceof MysqliDbAdapter, 'DbAdapterFactory must return MysqliDbAdapter.');

    // Auth runtime split: class availability + adapter wiring + method contracts
    assertOrFail(class_exists(AuthService::class), 'AuthService class not found.');
    assertOrFail(class_exists(AuthSigninService::class), 'AuthSigninService class not found.');
    assertOrFail(class_exists(AuthPasswordResetService::class), 'AuthPasswordResetService class not found.');
    assertOrFail(class_exists(AuthPasswordChangeService::class), 'AuthPasswordChangeService class not found.');

    AuthService::setDbAdapter($adapter);
    $authServiceRef = new ReflectionClass(AuthService::class);
    $dbProp = $authServiceRef->getProperty('dbAdapter');
    $dbProp->setAccessible(true);
    assertOrFail($dbProp->getValue() === $adapter, 'AuthService::setDbAdapter wiring check failed.');

    $signinService = new AuthSigninService($adapter);
    assertOrFail(is_callable([$signinService, 'signin']), 'AuthSigninService::signin must be callable.');
    assertOrFail(is_callable([$signinService, 'signout']), 'AuthSigninService::signout must be callable.');

    $resetService = new AuthPasswordResetService($adapter);
    assertOrFail(is_callable([$resetService, 'resetPassword']), 'AuthPasswordResetService::resetPassword must be callable.');
    assertOrFail(is_callable([$resetService, 'resetPasswordConfirm']), 'AuthPasswordResetService::resetPasswordConfirm must be callable.');

    $changeService = new AuthPasswordChangeService($adapter);
    assertOrFail(is_callable([$changeService, 'changePassword']), 'AuthPasswordChangeService::changePassword must be callable.');

    $userRow = $adapter->query('SELECT id, session_version FROM users ORDER BY id ASC LIMIT 1')->first();
    $userId = (!empty($userRow) && isset($userRow->id)) ? (int) $userRow->id : 1;
    $sessionVersion = (!empty($userRow) && isset($userRow->session_version)) ? (int) $userRow->session_version : null;

    $checks = 0;
    $skipped = 0;
    $now = time();

    $checks += 10;

    // SessionGuard: missing_session
    $reason = null;
    setSessionData(['last_activity' => $now]);
    SessionGuard::default()
        ->withJsonResponse(true)
        ->withFailureHandler(function ($r) use (&$reason): void {
            $reason = (string) $r;
        })
        ->check('user_id');
    assertOrFail($reason === 'missing_session', 'SessionGuard missing_session check failed.');
    $checks++;

    // SessionGuard: timeout
    $sessionLifetime = isset(CONFIG['session_time_life']) ? (int) CONFIG['session_time_life'] : 1800;
    if ($sessionLifetime <= 0) {
        $sessionLifetime = 1800;
    }
    $reason = null;
    setSessionData([
        'user_id' => $userId,
        'last_activity' => $now - ($sessionLifetime + 60),
    ]);
    SessionGuard::default()
        ->withJsonResponse(true)
        ->withFailureHandler(function ($r) use (&$reason): void {
            $reason = (string) $r;
        })
        ->check('user_id');
    assertOrFail($reason === 'timeout', 'SessionGuard timeout check failed.');
    $checks++;

    // SessionGuard: session_version_mismatch (only if DB user is available)
    if ($sessionVersion !== null) {
        $reason = null;
        setSessionData([
            'user_id' => $userId,
            'user_session_version' => $sessionVersion + 999,
            'user_session_version_checked_at' => 0,
            'last_activity' => $now,
        ]);
        SessionGuard::default()
            ->withJsonResponse(true)
            ->withFailureHandler(function ($r) use (&$reason): void {
                $reason = (string) $r;
            })
            ->check('user_id');
        assertOrFail($reason === 'session_version_mismatch', 'SessionGuard session_version_mismatch check failed.');
        $checks++;
    } else {
        $skipped++;
    }

    // AuthGuard: requireUser + requireCharacter + ability policy
    setSessionData([
        'user_id' => $userId,
        'character_id' => 1,
        'user_is_administrator' => 0,
        'user_is_moderator' => 0,
        'user_is_master' => 0,
        'user_session_version' => null, // skip DB session version check in this block
        'last_activity' => $now,
    ]);

    $resolvedUser = AuthGuard::api()->requireUser();
    assertOrFail($resolvedUser === $userId, 'AuthGuard requireUser returned unexpected ID.');
    $checks++;

    $resolvedCharacter = AuthGuard::api()->requireCharacter();
    assertOrFail($resolvedCharacter === 1, 'AuthGuard requireCharacter returned unexpected ID.');
    $checks++;

    $denied = false;
    try {
        AuthGuard::api()->requireAbility('settings.manage');
    } catch (AppError $e) {
        $denied = ($e->status() === 403);
    }
    assertOrFail($denied, 'AuthGuard ability denial check failed.');
    $checks++;

    $_SESSION['user_is_administrator'] = 1;
    AuthGuard::api()->requireAbility('settings.manage');
    $checks++;

    fwrite(STDOUT, "[OK] Core auth/session sanity check passed ({$checks} checks");
    if ($skipped > 0) {
        fwrite(STDOUT, ", {$skipped} skipped");
    }
    fwrite(STDOUT, ").\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
