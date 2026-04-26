<?php

declare(strict_types=1);

namespace Core\Database;

interface DbAdapterInterface
{
    public function lastInsertId(): int;

    /**
     * @return mixed
     */
    public function query(string $sql);

    /**
     * @param array<int,mixed> $params
     * @return mixed
     */
    public function queryPrepared(string $sql, array $params = []);

    /**
     * @param array<int,mixed> $params
     */
    public function executePrepared(string $sql, array $params = []): bool;

    /**
     * @param array<int,mixed> $params
     * @return mixed
     */
    public function fetchOnePrepared(string $sql, array $params = []);

    /**
     * @param array<int,mixed> $params
     * @return array<int,mixed>
     */
    public function fetchAllPrepared(string $sql, array $params = []): array;

    public function safe(mixed $value, bool $quotes = true): string|array;

    public function ifNotNull(mixed $value, mixed $altvalue = false): mixed;

    public function crypt(mixed $value): string;

    public function decrypt(mixed $value, mixed $alias = false): string;
}
