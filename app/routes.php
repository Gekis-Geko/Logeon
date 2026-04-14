<?php

use Core\Router;
use Core\Redirect;
use Core\ModuleRuntime;

use Core\Csrf;
use Core\Http\AppError;
use App\Services\InstallerService;

$route = new Router();

$installerService = new InstallerService();
$isLocked   = $installerService->isLocked();
$currentUri = Router::currentUri();
$isInstallUri = preg_match('#^/install(?:/.*)?$#', $currentUri) === 1;

// Su URI di install: blocca solo se il lock file esiste (installazione già completata).
// Non chiamare isInstalled() qui — ha il side-effect di scrivere il lock file se
// detectLegacyInstall() trova le tabelle DB (creato da init-db), bloccando prematuramente
// la chiamata a /install/create-admin.
if ($isInstallUri) {
    if ($isLocked) {
        Redirect::url('/');
        return;
    }
} else {
    // Su URI normali: reindirizza all'installer se l'installazione non è ancora completa.
    $isInstalled = $installerService->isInstalled();
    if (!$isInstalled) {
        Redirect::url('/install');
        return;
    }
}

$customRoutes = __DIR__ . '/../custom/routes.php';
if (file_exists($customRoutes)) {
    require_once $customRoutes;
}

$route->before('POST', '.*', function () {
    Csrf::validate();
});

$route->set404(function () {
    throw AppError::notFound('Pagina non trovata');
});

$routesRoot = __DIR__ . '/routes';
require $routesRoot . '/public.php';
require $routesRoot . '/install.php';
require $routesRoot . '/game.php';
require $routesRoot . '/api.php';

if (class_exists('\\Core\\ModuleRuntime')) {
    ModuleRuntime::instance()->bootActiveModules();
    ModuleRuntime::instance()->registerRoutes($route);
}

$route->run();




