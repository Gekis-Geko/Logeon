<?php

use App\Services\AuthGoogleService;
use App\Services\AuthService;
use App\Services\SystemEventService;
use Core\AppContext;
use Core\Hooks;

/** @return array<string,mixed> */
$googleAuthBaseContext = function (): array {
    return [
        'enabled' => AuthGoogleService::isEnabled(),
    ];
};

$route->get('/manifest.webmanifest', 'Pwa@manifest');
$route->get('/service-worker.js', 'Pwa@serviceWorker');

/** @var \Core\Router $route */
$route->get('/', function () use ($googleAuthBaseContext) {
    $installedAt = null;
    if (defined('INSTALL_META') && is_array(INSTALL_META) && array_key_exists('installed_at', INSTALL_META)) {
        $installedAt = INSTALL_META['installed_at'];
    }

    $db = AppContext::dbProvider()->connection();

    $safeCount = function (string $sql) use ($db): int {
        try {
            $row = $db->fetchOnePrepared($sql, []);
            return (int) ($row->cnt ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    };

    // Core metrics: only query tables that belong to the core schema.
    $metrics = [
        'online_users' => $safeCount(
            'SELECT COUNT(*) AS cnt
             FROM characters
             WHERE date_last_signin IS NOT NULL
               AND date_last_signin > IFNULL(date_last_signout, \'1970-01-01 00:00:00\')
               AND DATE_ADD(date_last_seed, INTERVAL 20 MINUTE) > NOW()',
        ),
        'forum_threads_week' => $safeCount(
            'SELECT COUNT(*) AS cnt
             FROM forum_threads
             WHERE father_id IS NULL
               AND date_created >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
        ),
        // Optional module metrics default to 0; active modules populate them via the hook below.
        'quests_week' => 0,
        'active_events' => 0,
    ];

    // Active modules can augment the metrics array by registering:
    //   Hooks::add('landing.metrics', function(array $metrics, $db): array { ... return $metrics; });
    $metrics = Hooks::filter('landing.metrics', $metrics, $db);
    if (!is_array($metrics)) {
        $metrics = [];
    }

    $newsFeed = [];
    $systemEventsFeed = [];
    try {
        $newsFeed = Hooks::filter('novelty.homepage_feed', [], 6);
    } catch (\Throwable $e) {
        $newsFeed = [];
    }
    try {
        $systemEventsFeed = (new SystemEventService($db))->listForHomepageFeed(6);
    } catch (\Throwable $e) {
        $systemEventsFeed = [];
    }

    $googleAuth = array_merge($googleAuthBaseContext(), [
        'error_code' => (string) (AppContext::session()->get('google_auth_error_code') ?? ''),
        'error_message' => (string) (AppContext::session()->get('google_auth_error_message') ?? ''),
        'toast_type' => (string) (AppContext::session()->get('google_auth_toast_type') ?? ''),
        'open_create_character' => ((int) (AppContext::session()->get('google_auth_open_create_character') ?? 0) === 1),
        'open_select_character' => ((int) (AppContext::session()->get('google_auth_open_select_character') ?? 0) === 1),
        'select_characters' => (array) (AppContext::session()->get('google_auth_select_characters') ?? []),
        'prefill_name' => (string) (AppContext::session()->get('google_auth_prefill_name') ?? ''),
    ]);
    AppContext::session()->delete('google_auth_error_code');
    AppContext::session()->delete('google_auth_error_message');
    AppContext::session()->delete('google_auth_toast_type');
    AppContext::session()->delete('google_auth_open_create_character');
    AppContext::session()->delete('google_auth_open_select_character');
    AppContext::session()->delete('google_auth_select_characters');
    AppContext::session()->delete('google_auth_prefill_name');

    return AppContext::templateRenderer()->render('index.twig', [
        'landing_metrics' => [
            'online_users' => (int) ($metrics['online_users'] ?? 0),
            'quests_week' => (int) ($metrics['quests_week'] ?? 0),
            'forum_threads_week' => (int) ($metrics['forum_threads_week'] ?? 0),
            'active_events' => (int) ($metrics['active_events'] ?? 0),
        ],
        'system_state' => [
            'version' => '0.7.9',
            'installed_at' => $installedAt,
            'updates' => 'Nessun update in sospeso',
            'baseline' => 'Runtime core consolidato (HTTP/Auth/DB) e naming Game/Admin allineato',
        ],
        'home_news_feed' => is_array($newsFeed) ? $newsFeed : [],
        'home_system_events_feed' => is_array($systemEventsFeed) ? $systemEventsFeed : [],
        'google_auth' => $googleAuth,
    ]);
});
$route->get('/auth/google/start', function () {
    AuthGoogleService::redirectToGoogle();
});
$route->get('/auth/google/callback', function () {
    AuthGoogleService::handleCallback();
});
$route->get('/rules', function () use ($googleAuthBaseContext) {
    $viewModes = (new \App\Services\SettingsService())->getDocsViewModes();
    return AppContext::templateRenderer()->render('rules.twig', [
        'google_auth' => $googleAuthBaseContext(),
        'view_mode' => $viewModes['rules_view_mode'],
    ]);
});
$route->apiPost('/rules/list', 'Rules@publicList');
$route->get('/storyboard', function () use ($googleAuthBaseContext) {
    $viewModes = (new \App\Services\SettingsService())->getDocsViewModes();
    return AppContext::templateRenderer()->render('storyboard.twig', [
        'google_auth' => $googleAuthBaseContext(),
        'view_mode' => $viewModes['storyboard_view_mode'],
    ]);
});
$route->apiPost('/storyboards/list', 'Storyboards@publicList');
$route->get('/how-to-play', function () use ($googleAuthBaseContext) {
    $viewModes = (new \App\Services\SettingsService())->getDocsViewModes();
    return AppContext::templateRenderer()->render('how_to_play.twig', [
        'google_auth' => $googleAuthBaseContext(),
        'view_mode' => $viewModes['how_to_play_view_mode'],
    ]);
});
$route->apiPost('/how-to-play/list', 'HowToPlays@publicList');
$route->get('/archetypes', function () use ($googleAuthBaseContext) {
    $viewModes = (new \App\Services\SettingsService())->getDocsViewModes();
    return AppContext::templateRenderer()->render('public/archetypes.twig', [
        'google_auth' => $googleAuthBaseContext(),
        'view_mode' => $viewModes['archetypes_view_mode'],
    ]);
});
$route->get('/shared/chat-archive/{token}', function ($token) use ($googleAuthBaseContext) {
    return AppContext::templateRenderer()->render('public/chat_archive_shared.twig', [
        'google_auth' => $googleAuthBaseContext(),
        'archive_token' => $token,
    ]);
});

$route->get('/reset-password/{token}', function ($token) use ($googleAuthBaseContext) {
    return AppContext::templateRenderer()->render('sys/reset_password.twig', [
        'token' => $token,
        'google_auth' => $googleAuthBaseContext(),
    ]);
});
$route->get('/verify-email/{token}', function ($token) use ($googleAuthBaseContext) {
    try {
        $result = AuthService::verifyEmailToken((string) $token);
    } catch (\Throwable $e) {
        $result = [
            'status' => 'invalid',
            'message' => 'Verifica email non disponibile in questo momento.',
        ];
    }

    return AppContext::templateRenderer()->render('sys/verify_email.twig', [
        'verification' => [
            'status' => (string) ($result['status'] ?? 'invalid'),
            'message' => (string) ($result['message'] ?? 'Verifica non riuscita.'),
        ],
        'google_auth' => $googleAuthBaseContext(),
    ]);
});
