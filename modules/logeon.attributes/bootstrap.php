<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Modules\\Logeon\\Attributes\\';
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

    \Core\Hooks::add('twig.slot.admin.dashboard.character-attributes', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'attributes-admin-dashboard-page',
            'template' => 'admin/pages/character-attributes.twig',
            'after' => '',
            'before' => '',
            'data' => [],
        ];
        return $fragments;
    });

    \Core\Hooks::add('twig.slot.game.profile.modals', static function ($fragments) {
        if (!is_array($fragments)) {
            $fragments = [];
        }
        $fragments[] = [
            'id' => 'attributes-game-profile-edit-modal',
            'template' => 'app/modals/profile/edit-attributes.twig',
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
        $endpoints['equipmentSlotsList'] = '/admin/equipment-slots/list';
        $endpoints['equipmentSlotsCreate'] = '/admin/equipment-slots/create';
        $endpoints['equipmentSlotsUpdate'] = '/admin/equipment-slots/update';
        $endpoints['equipmentSlotsDelete'] = '/admin/equipment-slots/delete';
        $endpoints['itemEquipmentRulesList'] = '/admin/item-equipment-rules/list';
        $endpoints['itemEquipmentRulesCreate'] = '/admin/item-equipment-rules/create';
        $endpoints['itemEquipmentRulesUpdate'] = '/admin/item-equipment-rules/update';
        $endpoints['itemEquipmentRulesDelete'] = '/admin/item-equipment-rules/delete';
        return $endpoints;
    });

    \Core\Hooks::add('attribute.provider', static function ($current) {
        return new \Modules\Logeon\Attributes\AttributesModuleProvider();
    });
};
