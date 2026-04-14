<?php

declare(strict_types=1);

namespace Core\Contracts;

interface SessionInterface
{
    public function get(string $key);

    public function set(string $key, $value): void;

    public function delete(string $key): void;

    public function has(string $key): bool;

    public function regenerate(): void;

    public function destroy(): void;
}
