<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\Factions\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $file = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

return static function ($moduleRuntime = null, $moduleManifest = null): void {
    if (!class_exists('\\Core\\Hooks')) {
        return;
    }

    \Core\Hooks::add('twig.view_paths', static function ($paths) {
        if (!is_array($paths)) {
            $paths = [];
        }
        $viewPath = __DIR__ . '/views';
        if (!in_array($viewPath, $paths, true)) {
            $paths[] = $viewPath;
        }
        return $paths;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.factions', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'factions-admin-dashboard-page',
            'template' => 'factions/admin/pages/factions.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('app.module_endpoints', static function ($endpoints) {
        if (!is_array($endpoints)) {
            $endpoints = [];
        }
        $endpoints['factionsAdminList'] = '/admin/factions/list';
        return $endpoints;
    });

    $notificationActionUrlResolver = static function ($url) {
        return '/game/factions';
    };
    \Core\Hooks::add('faction.notification.action_url', $notificationActionUrlResolver);
    \Core\Hooks::add('factions.notification.action_url', $notificationActionUrlResolver);

    \Core\Hooks::add('faction.provider', static function ($current) {
        return new \Modules\Logeon\Factions\FactionsModuleProvider();
    });
};
