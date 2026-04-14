<?php

declare(strict_types=1);

namespace Core\Adapters;

use Core\AuthGuard;
use Core\Contracts\AuthContextInterface;
use Core\SessionStore;

class SessionAuthContext implements AuthContextInterface
{
    public function isAuthenticated(): bool
    {
        return AuthGuard::isAuthenticated();
    }

    public function isAdmin(): bool
    {
        return AuthGuard::isAdmin();
    }

    public function isSuperuser(): bool
    {
        return AuthGuard::isSuperuser();
    }

    public function isModerator(): bool
    {
        return AuthGuard::isModerator();
    }

    public function isMaster(): bool
    {
        return AuthGuard::isMaster();
    }

    public function isStaff(): bool
    {
        return AuthGuard::isStaff();
    }

    public function userId(): int
    {
        return (int) SessionStore::get('user_id');
    }

    public function characterId(): int
    {
        return (int) SessionStore::get('character_id');
    }

    public function role(): string
    {
        return AuthGuard::role();
    }
}
