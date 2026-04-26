<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $modulePrefix = 'Modules\\Logeon\\Quests\\';
    if (str_starts_with($class, $modulePrefix)) {
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($modulePrefix)));
        $file = __DIR__ . '/src/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }

    $questServicePrefix = 'App\\Services\\Quest';
    if (str_starts_with($class, $questServicePrefix)) {
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen('App\\Services\\')));
        $file = __DIR__ . '/src/Services/' . $relative . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

return static function ($moduleRuntime = null, $moduleManifest = null): void {
    \Modules\Logeon\Quests\QuestModuleBootstrap::registerHooks();
    \Modules\Logeon\Quests\QuestModuleBootstrap::bootstrapTriggers();
};
