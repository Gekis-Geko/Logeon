<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\SocialStatus\\';
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

    \Core\Hooks::add('twig.slot.admin.dashboard.social-status', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'social-status-admin-dashboard-page',
            'template' => 'admin/pages/social-status.twig',
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
        $endpoints['socialStatusList'] = '/admin/social-status/list';
        return $endpoints;
    });

    \Core\Hooks::add('social_status.provider', static function ($current) {
        return new \Modules\Logeon\SocialStatus\SocialStatusModuleProvider();
    });
};
