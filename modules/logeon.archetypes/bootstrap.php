<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\Archetypes\\';
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

    \Core\Hooks::add('twig.slot.character.create.form.extra', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'archetypes-character-create',
            'template' => 'archetypes/character-create-archetype-field.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.game.profile.summary.extra', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'archetypes-game-profile-summary',
            'template' => 'archetypes/profile/summary-item.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.admin.characters.edit.tabs', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'archetypes-admin-characters-edit-tab',
            'template' => 'admin/characters/edit-tab-archetypes.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.admin.characters.edit.panels', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'archetypes-admin-characters-edit-panel',
            'template' => 'admin/characters/edit-panel-archetypes.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.admin.dashboard.archetypes', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'archetypes-admin-dashboard-page',
            'template' => 'admin/pages/archetypes.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    $providerResolver = static function ($current) {
        return new \Modules\Logeon\Archetypes\ArchetypesModuleProvider();
    };
    \Core\Hooks::add('character.archetype.provider', $providerResolver);
    \Core\Hooks::add('archetypes.provider', $providerResolver);

    \Core\Hooks::add('archetypes.config.is_enabled', static function ($default) {
        try {
            $config = (new \Modules\Logeon\Archetypes\Services\ArchetypeService())->getConfig();
            return ((int) ($config['archetypes_enabled'] ?? 1)) === 1;
        } catch (\Throwable $e) {
            return false;
        }
    });
};
