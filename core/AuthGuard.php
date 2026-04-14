<?php

declare(strict_types=1);

namespace Core;

use Core\Contracts\DbConnectionProviderInterface;
use Core\Contracts\SessionInterface;
use Core\Database\DbAdapterInterface;
use Core\Http\AppError;

class AuthGuard
{
    private const ADMIN_ABILITIES = [
        'forum.admin',
        'settings.manage',
        'user.manage',
    ];

    private const STAFF_ABILITIES = [
        'weather.manage.location',
        'location.invisible',
        'location.moderation',
    ];

    private const AUTHENTICATED_ABILITIES = [
        'character.rename.request',
        'character.delete.request',
        'dm.send',
        'location.chat.write',
    ];

    /** @var SessionGuard|null */
    private $sessionGuard = null;
    /** @var bool|null */
    private $forceJson = null;
    /** @var DbAdapterInterface|null */
    private static $dbAdapter = null;
    /** @var DbConnectionProviderInterface|null */
    private static $dbProvider = null;
    /** @var SessionInterface|null */
    private static $session = null;

    public static function setDbAdapter(DbAdapterInterface $adapter = null): void
    {
        static::$dbAdapter = $adapter;
    }

    public static function setDbProvider(DbConnectionProviderInterface $provider = null): void
    {
        static::$dbProvider = $provider;
    }

    public static function resetRuntimeState(): void
    {
        static::$dbAdapter = null;
        static::$dbProvider = null;
        static::$session = null;
    }

    private static function db(): DbAdapterInterface
    {
        if (static::$dbAdapter instanceof DbAdapterInterface) {
            return static::$dbAdapter;
        }

        if (static::$dbProvider instanceof DbConnectionProviderInterface) {
            static::$dbAdapter = static::$dbProvider->connection();
            return static::$dbAdapter;
        }

        static::$dbAdapter = AppContext::dbProvider()->connection();
        return static::$dbAdapter;
    }

    public static function setSession(SessionInterface $session = null): void
    {
        static::$session = $session;
    }

    private static function session(): SessionInterface
    {
        if (static::$session instanceof SessionInterface) {
            return static::$session;
        }

        static::$session = AppContext::session();
        return static::$session;
    }

    public static function api(): self
    {
        return (new self())->withJsonResponse(true);
    }

    public static function html(): self
    {
        return (new self())->withJsonResponse(false);
    }

    public static function auto(): self
    {
        return new self();
    }

    public static function isAdmin(): bool
    {
        return (static::getSessionFlag('user_is_administrator') === 1);
    }

    public static function isSuperuser(): bool
    {
        return (static::getSessionFlag('user_is_superuser') === 1);
    }

    public static function isStaff(): bool
    {
        return static::isAdmin() || static::isModerator() || static::isMaster();
    }

    public static function isModerator(): bool
    {
        return (static::getSessionFlag('user_is_moderator') === 1);
    }

    public static function isMaster(): bool
    {
        return (static::getSessionFlag('user_is_master') === 1);
    }

    public static function isAuthenticated(): bool
    {
        return ((int) static::session()->get('user_id')) > 0;
    }

    /**
     * Release the PHP session lock after reading all needed session data.
     * Call this in read-only API endpoints after pulling session values,
     * so that concurrent AJAX requests on the same session (common on
     * shared hosts like Altervista) are not serialised by the session lock.
     */
    public static function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function role(): string
    {
        if (static::isAdmin()) {
            return 'admin';
        }
        if (static::isModerator()) {
            return 'moderator';
        }
        if (static::isMaster()) {
            return 'master';
        }

        return 'player';
    }

    public static function allowSelfOrStaff($ownerUserId): bool
    {
        if (static::isStaff()) {
            return true;
        }
        $ownerUserId = (int) $ownerUserId;
        $me = (int) static::session()->get('user_id');

        return $ownerUserId > 0 && $me > 0 && $ownerUserId === $me;
    }

    public static function can($ability, array $context = []): bool
    {
        $ability = static::normalizeAbility($ability);
        if ($ability === '') {
            return false;
        }

        if (in_array($ability, self::ADMIN_ABILITIES, true)) {
            return static::isAdmin();
        }

        if (in_array($ability, self::STAFF_ABILITIES, true)) {
            return static::isStaff();
        }

        if (in_array($ability, self::AUTHENTICATED_ABILITIES, true)) {
            return static::isAuthenticated();
        }

        if ($ability === 'location.access') {
            if (static::isAdmin()) {
                return true;
            }
            if (array_key_exists('allowed', $context)) {
                return !empty($context['allowed']);
            }
            return static::isAuthenticated();
        }

        return false;
    }

    public static function enforceAbility($ability, array $context = [], string $message = 'Accesso non autorizzato'): void
    {
        if (!static::can($ability, $context)) {
            throw AppError::unauthorized($message);
        }
    }

    public static function enforceNotRestricted(int $userId, string $message = 'Operazione non autorizzata'): void
    {
        if (self::isAdmin()) {
            return;
        }
        if (\Users::isRestricted($userId)) {
            throw AppError::unauthorized($message);
        }
    }

    public static function ensureInviteAllowed(int $ownerCharacterId, int $targetCharacterId, int $invitePolicy, string $message = null): void
    {
        if (self::isAdmin()) {
            return;
        }

        if ($invitePolicy === 2) {
            throw AppError::validation(static::policyMessage($message, 'Questo personaggio non accetta inviti'));
        }

        if ($invitePolicy === 1) {
            if (!static::hasSharedGuild($ownerCharacterId, $targetCharacterId)) {
                throw AppError::validation(static::policyMessage($message, 'Questo personaggio accetta inviti solo dalla gilda'));
            }
        }
    }

    public static function ensureDmAllowed(int $senderCharacterId, int $targetCharacterId, int $dmPolicy, string $message = null): void
    {
        if (self::isAdmin()) {
            return;
        }

        if ($dmPolicy === 2) {
            throw AppError::validation(static::policyMessage($message, 'Questo personaggio non accetta messaggi privati'));
        }

        if ($dmPolicy === 1) {
            if (!static::hasSharedGuild($senderCharacterId, $targetCharacterId)) {
                throw AppError::validation(static::policyMessage($message, 'Questo personaggio accetta messaggi solo dai membri della stessa gilda'));
            }
        }
    }

    public function withJsonResponse(bool $force): self
    {
        $this->forceJson = $force;
        return $this;
    }

    public function requireUser(): int
    {
        $this->guard()->check('user_id');
        return $this->getSessionUserId();
    }

    public function requireCharacter(): int
    {
        $this->guard()->check('character_id');
        return $this->getSessionCharacterId();
    }

    public function requireUserCharacter(): void
    {
        $this->requireUser();
        $this->requireCharacter();
    }

    public function requireStaff(string $message = 'Accesso non autorizzato'): void
    {
        $this->requireUser();
        if (!self::isStaff()) {
            throw AppError::unauthorized($message);
        }
    }

    public function requireAbility($ability, array $context = [], string $message = 'Accesso non autorizzato'): void
    {
        $this->requireUser();
        self::enforceAbility($ability, $context, $message);
    }

    private function guard(): SessionGuard
    {
        if ($this->sessionGuard instanceof SessionGuard) {
            if ($this->forceJson !== null) {
                $this->sessionGuard->withJsonResponse($this->forceJson);
            }
            return $this->sessionGuard;
        }

        $this->sessionGuard = SessionGuard::default();
        if ($this->forceJson !== null) {
            $this->sessionGuard->withJsonResponse($this->forceJson);
        }
        return $this->sessionGuard;
    }

    private function getSessionUserId(): int
    {
        return (int) $this->getSessionValue('user_id');
    }

    private function getSessionCharacterId(): int
    {
        return (int) $this->getSessionValue('character_id');
    }

    private function getSessionValue($key)
    {
        return static::session()->get((string) $key);
    }

    private static function getSessionFlag(string $key): int
    {
        return (int) static::session()->get($key);
    }

    private static function normalizeAbility($ability): string
    {
        return trim((string) $ability);
    }

    private static function policyMessage(?string $message, string $fallback): string
    {
        $value = trim((string) $message);
        if ($value !== '') {
            return $value;
        }

        return $fallback;
    }

    private static function hasSharedGuild(int $sourceCharacterId, int $targetCharacterId): bool
    {
        return static::sharedGuildCount($sourceCharacterId, $targetCharacterId) > 0;
    }

    private static function sharedGuildCount(int $sourceCharacterId, int $targetCharacterId): int
    {
        $shared = static::db()->fetchOnePrepared(
            'SELECT COUNT(*) AS tot
             FROM guild_members gm1
             INNER JOIN guild_members gm2 ON gm1.guild_id = gm2.guild_id
             WHERE gm1.character_id = ?
               AND gm2.character_id = ?',
            [$sourceCharacterId, $targetCharacterId],
        );

        if (empty($shared) || !isset($shared->tot)) {
            return 0;
        }

        return (int) $shared->tot;
    }
}
