<?php

declare(strict_types=1);

namespace Core\Adapters;

use Core\Contracts\ConfigRepositoryInterface;

class ArrayConfigRepository implements ConfigRepositoryInterface
{
    public function get(string $path, $default = null)
    {
        $path = trim($path);
        if ($path === '') {
            return $default;
        }

        $segments = explode('.', $path);
        $scope = strtoupper((string) array_shift($segments));
        $root = $this->root($scope);
        if (!is_array($root)) {
            return $default;
        }

        if (empty($segments)) {
            return $root;
        }

        $cursor = $root;
        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '' || !is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    public function getAll(string $scope): array
    {
        $scope = strtoupper(trim($scope));
        $root = $this->root($scope);
        if (!is_array($root)) {
            return [];
        }

        return $root;
    }

    /** @return array<string,mixed>|null */
    private function root(string $scope): ?array
    {
        if ($scope === 'APP' && defined('APP')) {
            return APP;
        }
        if ($scope === 'CONFIG' && defined('CONFIG')) {
            return CONFIG;
        }

        return null;
    }
}
