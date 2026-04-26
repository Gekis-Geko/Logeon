<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\Weather\\';
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

    \Core\Hooks::add('twig.slot.admin.dashboard.weather', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'weather-admin-dashboard-overview-legacy',
            'template' => 'admin/pages/weather-overview.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.weather-overview', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'weather-admin-dashboard-overview',
            'template' => 'admin/pages/weather-overview.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.weather-catalogs', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'weather-admin-dashboard-catalogs',
            'template' => 'admin/pages/weather-catalogs.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.weather-profiles', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'weather-admin-dashboard-profiles',
            'template' => 'admin/pages/weather-profiles.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.weather-overrides', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'weather-admin-dashboard-overrides',
            'template' => 'admin/pages/weather-overrides.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.game.navbar.weather.season', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'weather-navbar-season',
            'template' => 'app/layout/navbar-season.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('weather.provider', static function ($current) {
        return new \Modules\Logeon\Weather\WeatherModuleProvider();
    });

    \Core\Hooks::add('auth.abilities.staff', static function ($abilities) {
        if (!is_array($abilities)) {
            $abilities = [];
        }
        if (!in_array('weather.manage.location', $abilities, true)) {
            $abilities[] = 'weather.manage.location';
        }
        return $abilities;
    });
};
