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

    /**
     * @param mixed $value
     * @return mixed
     */
    public function safe($value, $quotes = true);

    /**
     * @param mixed $value
     * @return mixed
     */
    public function ifNotNull($value, $altvalue = false);

    /**
     * @param mixed $value
     * @return mixed
     */
    public function crypt($value);

    /**
     * @param mixed $value
     * @param mixed $alias
     * @return mixed
     */
    public function decrypt($value, $alias = false);
}
