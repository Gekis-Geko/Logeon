<?php

/**
 * PHPStan bootstrap — carica solo il composer autoloader.
 * Non avvia il bootstrap applicativo (DB, sessioni, config) per evitare
 * dipendenze runtime durante l'analisi statica.
 */
require_once __DIR__ . '/vendor/autoload.php';

// Costanti richieste da file core/app analizzati staticamente
if (!defined('CONFIG')) {
    define('CONFIG', [
        'debug' => false,
        'cache' => ['enabled' => false, 'ttl' => 0, 'dir' => '/tmp'],
        'dirs' => [
            'base' => '',
            'core' => '',
            'views' => '',
            'assets' => '',
            'imgs' => '',
            'tmp' => '/tmp',
        ],
        'password_length' => 5,
        'session_time_life' => 5400,
        'location_chat_history_hours' => 3,
        'location_whisper_retention_hours' => 24,
        'inventory' => ['capacity_max' => 30, 'stack_max' => 50],
        'chat_commands' => [],
    ]);
}

if (!defined('APP')) {
    define('APP', [
        'baseurl' => '',
        'lang' => 'it',
        'name' => 'Logeon',
        'title' => 'Logeon',
        'description' => '',
        'brand_logo_icon' => '',
        'brand_logo_wordmark' => '',
        'wm_name' => '',
        'wm_email' => '',
        'dba_name' => '',
        'dba_email' => '',
        'support_name' => '',
        'support_email' => '',
        'shop' => ['sell_ratio' => 0.5],
        'oauth_google' => ['enabled' => false, 'client_id' => '', 'client_secret' => '', 'redirect_uri' => ''],
        'frontend' => ['pilot_bundle_mode' => 'auto', 'pilot_bundle_enabled' => false, 'pilot_bundle_version' => ''],
        'theme' => ['enabled' => false, 'active_theme' => '', 'strict_mode' => false, 'allow_custom_js' => false],
    ]);
}

if (!defined('DB')) {
    define('DB', [
        'mysql' => ['host' => '', 'db_name' => '', 'user' => '', 'pwd' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        'crypt_key' => '',
    ]);
}
