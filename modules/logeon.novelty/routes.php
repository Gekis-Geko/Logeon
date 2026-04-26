<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\Novelty\Controllers\Novelties::class;

$route->group('/admin', function ($route) use ($ctrl) {
    $route->apiPost('/news/list', $ctrl . '@adminList');
    $route->apiPost('/news/create', $ctrl . '@create');
    $route->apiPost('/news/update', $ctrl . '@update');
    $route->apiPost('/news/delete', $ctrl . '@adminDelete');
});

$route->group('/list', function ($route) use ($ctrl) {
    $route->apiPost('/news', $ctrl . '@list');
});

