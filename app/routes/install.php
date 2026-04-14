<?php

use Core\AppContext;

/** @var \Core\Router $route */
$route->group('/install', function ($route) {
    $route->get('/', function () {
        return AppContext::templateRenderer()->render('sys/install.twig');
    });
    $route->apiPost('/status', 'Installer@status');
    $route->apiPost('/validate-app', 'Installer@validateApp');
    $route->apiPost('/test-db', 'Installer@testDb');
    $route->apiPost('/write-config', 'Installer@writeConfig');
    $route->apiPost('/init-db', 'Installer@initDb');
    $route->apiPost('/create-admin', 'Installer@createAdmin');
    $route->apiPost('/finalize', 'Installer@finalize');
});
