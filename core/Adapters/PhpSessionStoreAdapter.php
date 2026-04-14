<?php

declare(strict_types=1);

namespace Core\Adapters;

use Core\Contracts\SessionInterface;
use Core\SessionStore;

class PhpSessionStoreAdapter implements SessionInterface
{
    public function get(string $key)
    {
        return SessionStore::get($key);
    }

    public function set(string $key, $value): void
    {
        SessionStore::set($key, $value);
    }

    public function delete(string $key): void
    {
        SessionStore::delete($key);
    }

    public function has(string $key): bool
    {
        return SessionStore::has($key);
    }

    public function regenerate(): void
    {
        SessionStore::regenerate();
    }

    public function destroy(): void
    {
        SessionStore::destroy();
    }
}
