<?php

declare(strict_types=1);

namespace Core\Templates;

class TwigEnvironmentFactory
{
    public function isAvailable(): bool
    {
        return class_exists('\\Twig\\Environment') && class_exists('\\Twig\\Loader\\FilesystemLoader');
    }

    /**
     * @param string|string[] $viewsPath
     */
    public function create($viewsPath, string $cacheDir, bool $debug, ?callable $configure = null)
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $cache = false;
        if (!$debug) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }
            $cache = $cacheDir;
        }

        $paths = is_array($viewsPath) ? $viewsPath : [$viewsPath];
        $loader = new \Twig\Loader\FilesystemLoader();
        foreach ($paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            $trimmed = trim($path);
            if ($trimmed === '') {
                continue;
            }
            if (!is_dir($trimmed)) {
                continue;
            }
            $loader->addPath($trimmed);
        }
        $twig = new \Twig\Environment($loader, [
            'cache' => $cache,
            'debug' => $debug,
        ]);

        if ($configure) {
            $configure($twig);
        }

        return $twig;
    }
}
