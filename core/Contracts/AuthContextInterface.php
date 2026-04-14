<?php

declare(strict_types=1);

namespace Core\Contracts;

interface AuthContextInterface
{
    public function isAuthenticated(): bool;

    public function isAdmin(): bool;

    public function isSuperuser(): bool;

    public function isModerator(): bool;

    public function isMaster(): bool;

    public function isStaff(): bool;

    public function userId(): int;

    public function characterId(): int;

    public function role(): string;
}
