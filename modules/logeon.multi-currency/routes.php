<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\MultiCurrency\Controllers\AdminCurrencies::class;

$route->group('/admin/multi-currencies', function ($route) use ($ctrl) {
    $route->apiPost('/list', $ctrl . '@list');
    $route->apiPost('/create', $ctrl . '@create');
    $route->apiPost('/update', $ctrl . '@update');
    $route->apiPost('/delete', $ctrl . '@delete');
});

$route->group('/admin/currencies', function ($route) use ($ctrl) {
    $route->apiPost('/list', $ctrl . '@list');
    $route->apiPost('/create', $ctrl . '@create');
    $route->apiPost('/update', $ctrl . '@update');
    $route->apiPost('/delete', $ctrl . '@delete');
});
