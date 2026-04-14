<?php

declare(strict_types=1);

namespace Core\Contracts;

interface ConfigRepositoryInterface
{
    public function get(string $path, $default = null);

    /** @return array<string,mixed> */
    public function getAll(string $scope): array;
}
