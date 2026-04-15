<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$targets = [
    $root . DIRECTORY_SEPARATOR . 'app',
    $root . DIRECTORY_SEPARATOR . 'core',
    $root . DIRECTORY_SEPARATOR . 'configs',
    $root . DIRECTORY_SEPARATOR . 'autoload.php',
    $root . DIRECTORY_SEPARATOR . 'index.php',
];

$excludedDirs = [
    $root . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'plugins',
    $root . DIRECTORY_SEPARATOR . 'vendor',
    $root . DIRECTORY_SEPARATOR . 'dist',
    $root . DIRECTORY_SEPARATOR . 'tmp',
];

/**
 * @return array<int, string>
 */
function collectPhpFiles(array $targets, array $excludedDirs): array
{
    $files = [];
    $excludedDirs = array_map(static function (string $path): string {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }, $excludedDirs);

    foreach ($targets as $target) {
        $normalizedTarget = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target);
        if (!file_exists($normalizedTarget)) {
            continue;
        }

        if (is_file($normalizedTarget)) {
            if (substr($normalizedTarget, -4) === '.php') {
                $files[] = $normalizedTarget;
            }
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($normalizedTarget, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (substr($path, -4) !== '.php') {
                continue;
            }

            $dir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fileInfo->getPath());
            $skip = false;
            foreach ($excludedDirs as $excluded) {
                if ($excluded !== '' && strpos($dir, $excluded) === 0) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            $files[] = $path;
        }
    }

    $files = array_values(array_unique($files));
    sort($files);
    return $files;
}

/**
 * @return array{ok:bool, output:string}
 */
function lintFile(string $phpBinary, string $file): array
{
    $command = escapeshellarg($phpBinary) . ' -l ' . escapeshellarg($file) . ' 2>&1';
    $output = shell_exec($command);
    $output = is_string($output) ? trim($output) : 'Unknown lint error';
    $ok = (stripos($output, 'No syntax errors detected') !== false);

    return [
        'ok' => $ok,
        'output' => $output,
    ];
}

$phpBinary = PHP_BINARY;
$files = collectPhpFiles($targets, $excludedDirs);

if (empty($files)) {
    fwrite(STDOUT, "[lint:php] Nessun file PHP trovato.\n");
    exit(0);
}

$errors = [];
foreach ($files as $file) {
    $result = lintFile($phpBinary, $file);
    if (!$result['ok']) {
        $errors[] = [
            'file' => $file,
            'output' => $result['output'],
        ];
    }
}

if (!empty($errors)) {
    fwrite(STDOUT, '[lint:php] Errori trovati: ' . count($errors) . "\n");
    foreach ($errors as $error) {
        fwrite(STDOUT, '- ' . $error['file'] . "\n  " . $error['output'] . "\n");
    }
    exit(1);
}

fwrite(STDOUT, '[lint:php] OK su ' . count($files) . " file.\n");
exit(0);
