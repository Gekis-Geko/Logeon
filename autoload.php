<?php

ini_set('display_errors', 0);

require_once __DIR__ . '/configs/config.php';

$dbConfigPath = __DIR__ . '/configs/db.php';
$dbExamplePath = __DIR__ . '/configs/db.example.php';

if (!file_exists($dbConfigPath)) {
    if (file_exists($dbExamplePath)) {
        if (!@copy($dbExamplePath, $dbConfigPath)) {
            http_response_code(500);
            echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Setup richiesto</title>'
                . '<style>body{font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 16px}'
                . 'code{background:#f0f0f0;padding:2px 6px;border-radius:4px}</style></head><body>'
                . '<h2>Setup richiesto</h2>'
                . '<p>Il file <code>configs/db.php</code> non esiste e non è stato possibile crearlo automaticamente.</p>'
                . '<p>Rinomina manualmente <code>configs/db.example.php</code> in <code>configs/db.php</code>, '
                . 'poi torna qui per avviare il processo di installazione.</p>'
                . '</body></html>';
            exit;
        }
    } else {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Setup richiesto</title>'
            . '<style>body{font-family:sans-serif;max-width:600px;margin:80px auto;padding:0 16px}'
            . 'code{background:#f0f0f0;padding:2px 6px;border-radius:4px}</style></head><body>'
            . '<h2>Setup richiesto</h2>'
            . '<p>Il file <code>configs/db.php</code> non esiste e non è stato trovato nemmeno <code>configs/db.example.php</code>.</p>'
            . '<p>Crea il file <code>configs/db.php</code> a partire dalla documentazione di installazione.</p>'
            . '</body></html>';
        exit;
    }
}

require_once $dbConfigPath;
require_once __DIR__ . '/configs/app.php';

$composer = __DIR__ . '/vendor/autoload.php';
if (!file_exists($composer)) {
    throw new RuntimeException('Composer autoload non trovato. Esegui "composer install".');
}

require_once $composer;

if (true == CONFIG['debug']) {
    error_reporting(E_ALL & ~E_NOTICE);
    ini_set('display_errors', 1);
}

$legacyControllersPath = __DIR__ . '/app/controllers/';
spl_autoload_register(function ($class) use ($legacyControllersPath) {
    if (!is_string($class) || $class === '') {
        return;
    }
    if (strpos($class, '\\') !== false) {
        return;
    }

    $filePath = $legacyControllersPath . $class . '.php';
    if (is_file($filePath)) {
        require_once $filePath;
    }
});

$customBootstrap = __DIR__ . '/custom/bootstrap.php';
if (file_exists($customBootstrap)) {
    require_once $customBootstrap;
}

require_once __DIR__ . '/app/routes.php';
