<?php

declare(strict_types=1);

/**
 * Theme validation utility (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/themes-validate.php
 */

$root = dirname(__DIR__, 2);
$themesRoot = $root . '/custom/themes';

if (!is_dir($themesRoot)) {
    fwrite(STDOUT, "[INFO] Nessuna cartella temi trovata in custom/themes.\n");
    exit(0);
}

$themeDirs = array_values(array_filter(glob($themesRoot . '/*'), static function ($path) {
    return is_dir($path);
}));

if (empty($themeDirs)) {
    fwrite(STDOUT, "[INFO] Nessun tema da validare.\n");
    exit(0);
}

$requiredManifestKeys = ['id', 'name', 'version', 'compat', 'shell'];
$requiredShellKeys = ['public_layout', 'game_layout'];
$requiredExtendsByShell = [
    'public_layout' => 'layouts/layout.twig',
    'game_layout' => 'app/layouts/layout.twig',
];

$hasErrors = false;

foreach ($themeDirs as $themeDir) {
    $themeName = basename($themeDir);
    fwrite(STDOUT, "[RUN] Tema {$themeName}\n");

    $manifestPath = $themeDir . '/theme.json';
    if (!is_file($manifestPath) || !is_readable($manifestPath)) {
        fwrite(STDERR, "[FAIL] {$themeName}: theme.json mancante o non leggibile.\n");
        $hasErrors = true;
        continue;
    }

    $raw = @file_get_contents($manifestPath);
    $manifest = json_decode((string) $raw, true);
    if (!is_array($manifest)) {
        fwrite(STDERR, "[FAIL] {$themeName}: theme.json non valido (JSON parse error).\n");
        $hasErrors = true;
        continue;
    }

    $missing = [];
    foreach ($requiredManifestKeys as $key) {
        if (!array_key_exists($key, $manifest)) {
            $missing[] = $key;
        }
    }
    if (!empty($missing)) {
        fwrite(STDERR, '[FAIL] ' . $themeName . ': campi obbligatori mancanti: ' . implode(', ', $missing) . ".\n");
        $hasErrors = true;
    }

    $manifestId = trim((string) ($manifest['id'] ?? ''));
    if ($manifestId === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $manifestId)) {
        fwrite(STDERR, "[FAIL] {$themeName}: id tema non valido.\n");
        $hasErrors = true;
    } elseif ($manifestId !== $themeName) {
        fwrite(STDERR, "[FAIL] {$themeName}: id manifest ({$manifestId}) diverso dal nome cartella.\n");
        $hasErrors = true;
    }

    $compat = $manifest['compat'] ?? null;
    if (!is_array($compat) || trim((string) ($compat['core'] ?? '')) === '') {
        fwrite(STDERR, "[FAIL] {$themeName}: compat.core mancante.\n");
        $hasErrors = true;
    }

    $shell = $manifest['shell'] ?? null;
    if (!is_array($shell)) {
        fwrite(STDERR, "[FAIL] {$themeName}: shell non valido.\n");
        $hasErrors = true;
        continue;
    }

    $viewsRoot = $themeDir . '/views';
    if (!is_dir($viewsRoot)) {
        fwrite(STDERR, "[FAIL] {$themeName}: cartella views mancante.\n");
        $hasErrors = true;
        continue;
    }

    foreach ($requiredShellKeys as $shellKey) {
        $tpl = trim((string) ($shell[$shellKey] ?? ''));
        if ($tpl === '') {
            fwrite(STDERR, "[FAIL] {$themeName}: shell.{$shellKey} mancante.\n");
            $hasErrors = true;
            continue;
        }
        $tpl = ltrim(str_replace('\\', '/', $tpl), '/');
        if ($tpl === '' || strpos($tpl, '..') !== false || substr($tpl, -5) !== '.twig') {
            fwrite(STDERR, "[FAIL] {$themeName}: shell.{$shellKey} non valido.\n");
            $hasErrors = true;
            continue;
        }
        $absoluteTpl = $viewsRoot . '/' . $tpl;
        if (!is_file($absoluteTpl)) {
            fwrite(STDERR, "[FAIL] {$themeName}: template shell non trovato: {$tpl}.\n");
            $hasErrors = true;
            continue;
        }
        $tplContent = @file_get_contents($absoluteTpl);
        if (!is_string($tplContent)) {
            fwrite(STDERR, "[FAIL] {$themeName}: {$tpl} non leggibile.\n");
            $hasErrors = true;
            continue;
        }

        $fallbackLayout = $requiredExtendsByShell[$shellKey] ?? '';
        if ($fallbackLayout !== '') {
            $extendsPattern = '/\{%\s*extends\s+[\'"]' . preg_quote($fallbackLayout, '/') . '[\'"]\s*%\}/i';
            if (preg_match($extendsPattern, $tplContent) !== 1) {
                fwrite(STDERR, "[FAIL] {$themeName}: {$tpl} deve estendere {$fallbackLayout} (contract v1).\n");
                $hasErrors = true;
                continue;
            }
        }

        $hasShellBlock =
            preg_match('/\{%\s*block\s+content\s*%\}/i', $tplContent) === 1
            || preg_match('/\{%\s*block\s+public_page_content\s*%\}/i', $tplContent) === 1
            || preg_match('/\{%\s*block\s+game_page_content\s*%\}/i', $tplContent) === 1
            || preg_match('/\{%\s*block\s+public_shell\s*%\}/i', $tplContent) === 1
            || preg_match('/\{%\s*block\s+game_shell\s*%\}/i', $tplContent) === 1;
        if (!$hasShellBlock) {
            fwrite(STDERR, "[FAIL] {$themeName}: {$tpl} non espone blocchi shell/content validi.\n");
            $hasErrors = true;
            continue;
        }
    }

    $assets = $manifest['assets'] ?? [];
    if (is_array($assets)) {
        foreach ($assets as $channel => $list) {
            if (!is_array($list)) {
                fwrite(STDERR, "[FAIL] {$themeName}: assets.{$channel} deve essere un array.\n");
                $hasErrors = true;
                continue;
            }
            foreach ($list as $assetPath) {
                $asset = ltrim(str_replace('\\', '/', trim((string) $assetPath)), '/');
                if ($asset === '' || strpos($asset, '..') !== false) {
                    fwrite(STDERR, "[FAIL] {$themeName}: path asset non valido in assets.{$channel}.\n");
                    $hasErrors = true;
                    continue;
                }
                $absoluteAsset = $themeDir . '/assets/' . $asset;
                if (!is_file($absoluteAsset)) {
                    fwrite(STDERR, "[FAIL] {$themeName}: asset mancante {$asset} (channel {$channel}).\n");
                    $hasErrors = true;
                }
            }
        }
    }

    fwrite(STDOUT, "[OK] {$themeName}: validazione completata.\n");
}

if ($hasErrors) {
    fwrite(STDERR, "[FAIL] Validazione temi: errori rilevati.\n");
    exit(1);
}

fwrite(STDOUT, "[OK] Validazione temi completata senza errori.\n");
exit(0);
