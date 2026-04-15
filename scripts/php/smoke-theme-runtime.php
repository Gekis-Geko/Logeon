<?php

declare(strict_types=1);

/**
 * Theme runtime smoke (CLI).
 *
 * Usage:
 *   C:\xampp\php\php.exe scripts/php/smoke-theme-runtime.php
 */

$root = dirname(__DIR__, 2);

require_once $root . '/configs/db.php';
require_once $root . '/vendor/autoload.php';

use Core\Template;
use Core\Themes\ThemeResolver;

/**
 * @return mysqli|null
 */
function themeSmokeOpenDb()
{
    if (!defined('DB') || !is_array(DB) || !isset(DB['mysql']) || !is_array(DB['mysql'])) {
        return null;
    }

    $cfg = DB['mysql'];
    $host = (string) ($cfg['host'] ?? 'localhost');
    $dbName = (string) ($cfg['db_name'] ?? '');
    $user = (string) ($cfg['user'] ?? 'root');
    $pwd = (string) ($cfg['pwd'] ?? '');
    $port = (int) ($cfg['port'] ?? 3306);
    if ($port <= 0) {
        $port = 3306;
    }

    if ($dbName === '') {
        return null;
    }

    $db = @new mysqli($host, $user, $pwd, $dbName, $port);
    if ($db->connect_errno) {
        return null;
    }

    $db->set_charset('utf8mb4');
    return $db;
}

function themeSmokeTableExists(mysqli $db, string $table): bool
{
    $tableEscaped = $db->real_escape_string($table);
    $result = $db->query("SHOW TABLES LIKE '{$tableEscaped}'");
    if (!($result instanceof mysqli_result)) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $exists;
}

/**
 * @return array<string,mixed>|null
 */
function themeSmokeGetConfig(mysqli $db, string $key): ?array
{
    $keyEscaped = $db->real_escape_string($key);
    $result = $db->query("SELECT id, `key`, `value`, `type` FROM sys_configs WHERE `key` = '{$keyEscaped}' LIMIT 1");
    if (!($result instanceof mysqli_result)) {
        return null;
    }
    $row = $result->fetch_assoc();
    $result->free();
    return is_array($row) ? $row : null;
}

function themeSmokeUpsertConfig(mysqli $db, string $key, string $value, string $type): void
{
    $keyEscaped = $db->real_escape_string($key);
    $valueEscaped = $db->real_escape_string($value);
    $typeEscaped = $db->real_escape_string($type);
    $sql = "INSERT INTO sys_configs (`key`, `value`, `type`) VALUES ('{$keyEscaped}', '{$valueEscaped}', '{$typeEscaped}')
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), `type` = VALUES(`type`)";
    if ($db->query($sql) !== true) {
        throw new RuntimeException('Impossibile aggiornare sys_configs per key=' . $key);
    }
}

function themeSmokeDeleteConfig(mysqli $db, string $key): void
{
    $keyEscaped = $db->real_escape_string($key);
    $db->query("DELETE FROM sys_configs WHERE `key` = '{$keyEscaped}' LIMIT 1");
}

function themeSmokeWriteFile(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    file_put_contents($path, $contents);
}

function themeSmokeDeleteDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            themeSmokeDeleteDir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}

function themeSmokeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = themeSmokeOpenDb();
if (!($db instanceof mysqli)) {
    fwrite(STDOUT, "[SKIP] Theme runtime smoke: DB non disponibile.\n");
    exit(0);
}

if (!themeSmokeTableExists($db, 'sys_configs')) {
    fwrite(STDOUT, "[SKIP] Theme runtime smoke: tabella sys_configs non presente.\n");
    @$db->close();
    exit(0);
}

$themeId = 'smoke-theme';
$themeRoot = $root . '/custom/themes/' . $themeId;
$backupThemeSystemEnabled = themeSmokeGetConfig($db, 'theme_system_enabled');
$backupActiveTheme = themeSmokeGetConfig($db, 'active_theme');
$createdTheme = false;

try {
    fwrite(STDOUT, "[STEP] create temporary theme structure\n");

    $manifest = [
        'id' => $themeId,
        'name' => 'Smoke Theme',
        'version' => '1.0.0',
        'compat' => [
            'core' => '>=0.1.0 <99.0.0',
        ],
        'shell' => [
            'public_layout' => 'layouts/theme-public.twig',
            'game_layout' => 'app/layouts/theme-game.twig',
        ],
        'assets' => [
            'public_css' => ['css/public.css'],
            'game_css' => ['css/game.css'],
            'public_js' => ['js/public.js'],
            'game_js' => ['js/game.js'],
        ],
    ];

    themeSmokeWriteFile($themeRoot . '/theme.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    themeSmokeWriteFile(
        $themeRoot . '/views/layouts/theme-public.twig',
        "{% extends 'layouts/layout.twig' %}\n{% block public_page_content %}{% block content %}{% endblock %}{% endblock %}\n",
    );
    themeSmokeWriteFile(
        $themeRoot . '/views/app/layouts/theme-game.twig',
        "{% extends 'app/layouts/layout.twig' %}\n{% block game_page_content %}{% block content %}{% endblock %}{% endblock %}\n",
    );
    themeSmokeWriteFile($themeRoot . '/assets/css/public.css', "/* smoke public css */\n");
    themeSmokeWriteFile($themeRoot . '/assets/css/game.css', "/* smoke game css */\n");
    themeSmokeWriteFile($themeRoot . '/assets/js/public.js', "/* smoke public js */\n");
    themeSmokeWriteFile($themeRoot . '/assets/js/game.js', "/* smoke game js */\n");
    $createdTheme = true;

    fwrite(STDOUT, "[STEP] activate temporary theme via sys_configs\n");
    themeSmokeUpsertConfig($db, 'theme_system_enabled', '1', 'number');
    themeSmokeUpsertConfig($db, 'active_theme', $themeId, 'string');
    Template::resetRuntimeState();

    $resolver = new ThemeResolver();

    fwrite(STDOUT, "[STEP] resolve public template with active theme\n");
    $publicRuntime = $resolver->resolveForTemplate('index.twig');
    themeSmokeAssert((string) ($publicRuntime['context'] ?? '') === 'public', 'Context public non corretto.');
    themeSmokeAssert((bool) (($publicRuntime['theme']['active'] ?? false) === true), 'Tema non attivo su public.');
    themeSmokeAssert((string) ($publicRuntime['theme']['id'] ?? '') === $themeId, 'ID tema public non coerente.');
    $publicViews = (array) ($publicRuntime['views_paths'] ?? []);
    themeSmokeAssert(!empty($publicViews), 'views_paths public vuoto.');
    themeSmokeAssert(strpos((string) $publicViews[0], '/custom/themes/' . $themeId . '/views') !== false, 'Lookup public non prioritizza il tema.');

    fwrite(STDOUT, "[STEP] resolve game template with active theme\n");
    $gameRuntime = $resolver->resolveForTemplate('app.twig');
    themeSmokeAssert((string) ($gameRuntime['context'] ?? '') === 'game', 'Context game non corretto.');
    themeSmokeAssert((bool) (($gameRuntime['theme']['active'] ?? false) === true), 'Tema non attivo su game.');

    fwrite(STDOUT, "[STEP] ensure admin bypasses theme\n");
    $adminRuntime = $resolver->resolveForTemplate('admin/dashboard.twig');
    themeSmokeAssert((string) ($adminRuntime['context'] ?? '') === 'admin', 'Context admin non corretto.');
    themeSmokeAssert((bool) (($adminRuntime['theme']['active'] ?? false) === false), 'Admin non deve usare tema attivo.');

    fwrite(STDOUT, "[STEP] verify fallback on missing theme\n");
    themeSmokeUpsertConfig($db, 'active_theme', '__missing_theme__', 'string');
    Template::resetRuntimeState();
    $fallbackRuntime = (new ThemeResolver())->resolveForTemplate('index.twig');
    themeSmokeAssert((bool) (($fallbackRuntime['theme']['active'] ?? false) === false), 'Fallback core non attivo su tema mancante.');

    fwrite(STDOUT, "[OK] Theme runtime smoke passed.\n");
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] Theme runtime smoke failed: ' . $e->getMessage() . PHP_EOL);
    if ($db instanceof mysqli) {
        if (is_array($backupThemeSystemEnabled)) {
            themeSmokeUpsertConfig($db, (string) $backupThemeSystemEnabled['key'], (string) $backupThemeSystemEnabled['value'], (string) $backupThemeSystemEnabled['type']);
        } else {
            themeSmokeDeleteConfig($db, 'theme_system_enabled');
        }
        if (is_array($backupActiveTheme)) {
            themeSmokeUpsertConfig($db, (string) $backupActiveTheme['key'], (string) $backupActiveTheme['value'], (string) $backupActiveTheme['type']);
        } else {
            themeSmokeDeleteConfig($db, 'active_theme');
        }
        @mysqli_close($db);
    }
    if ($createdTheme) {
        themeSmokeDeleteDir($themeRoot);
    }
    exit(1);
}

if (is_array($backupThemeSystemEnabled)) {
    themeSmokeUpsertConfig($db, (string) $backupThemeSystemEnabled['key'], (string) $backupThemeSystemEnabled['value'], (string) $backupThemeSystemEnabled['type']);
} else {
    themeSmokeDeleteConfig($db, 'theme_system_enabled');
}
if (is_array($backupActiveTheme)) {
    themeSmokeUpsertConfig($db, (string) $backupActiveTheme['key'], (string) $backupActiveTheme['value'], (string) $backupActiveTheme['type']);
} else {
    themeSmokeDeleteConfig($db, 'active_theme');
}

if ($createdTheme) {
    themeSmokeDeleteDir($themeRoot);
}

if ($db instanceof mysqli) {
    @$db->close();
}

exit(0);
