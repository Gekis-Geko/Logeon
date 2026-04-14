<?php

declare(strict_types=1);

namespace Core\Services;

class UploadPaths
{
    public function baseTmpDir(): string
    {
        $base = rtrim(CONFIG['dirs']['tmp'], '/\\') . '/uploads';
        return $this->normalizePath($base);
    }

    public function chunksDir(string $token): string
    {
        return $this->baseTmpDir() . '/chunks/' . $token;
    }

    public function completeDir(): string
    {
        return $this->baseTmpDir() . '/complete';
    }

    public function characterUploadDir(int $characterId): string
    {
        return $this->normalizePath(dirname(__DIR__) . '/../assets/imgs/uploads/characters/' . $characterId);
    }

    public function publicUrlFromPath(string $path): ?string
    {
        if ($path === '' || !file_exists($path)) {
            return null;
        }
        $normalized = str_replace('\\', '/', $path);
        $pos = strpos($normalized, '/assets/');
        if ($pos === false) {
            return null;
        }
        return substr($normalized, $pos);
    }

    public function characterPublicUrl(int $characterId, string $filename): string
    {
        return '/assets/imgs/uploads/characters/' . $characterId . '/' . ltrim($filename, '/');
    }

    public function userAudioDir(int $userId): string
    {
        return $this->normalizePath(dirname(__DIR__) . '/../assets/imgs/uploads/users/' . $userId . '/audio');
    }

    public function userAudioPublicUrl(int $userId, string $filename): string
    {
        return '/assets/imgs/uploads/users/' . $userId . '/audio/' . ltrim($filename, '/');
    }

    public function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        return preg_replace('#/+#', '/', $normalized);
    }
}
