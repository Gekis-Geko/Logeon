<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\Weather\Controllers\Weathers::class;

// Advanced weather endpoints (climate engine)
$route->group('/weather', function ($route) use ($ctrl) {
    $route->apiPost('/climate-areas', $ctrl . '@climateAreaList');
    $route->apiPost('/climate-areas/create', $ctrl . '@climateAreaCreate');
    $route->apiPost('/climate-areas/update', $ctrl . '@climateAreaUpdate');
    $route->apiPost('/climate-areas/delete', $ctrl . '@climateAreaDelete');
    $route->apiPost('/climate-areas/assign', $ctrl . '@climateAreaAssign');

    $route->apiPost('/types', $ctrl . '@weatherTypeList');
    $route->apiPost('/types/create', $ctrl . '@weatherTypeCreate');
    $route->apiPost('/types/update', $ctrl . '@weatherTypeUpdate');
    $route->apiPost('/types/delete', $ctrl . '@weatherTypeDelete');

    $route->apiPost('/seasons', $ctrl . '@seasonList');
    $route->apiPost('/seasons/create', $ctrl . '@seasonCreate');
    $route->apiPost('/seasons/update', $ctrl . '@seasonUpdate');
    $route->apiPost('/seasons/delete', $ctrl . '@seasonDelete');

    $route->apiPost('/zones', $ctrl . '@climateZoneList');
    $route->apiPost('/zones/create', $ctrl . '@climateZoneCreate');
    $route->apiPost('/zones/update', $ctrl . '@climateZoneUpdate');
    $route->apiPost('/zones/delete', $ctrl . '@climateZoneDelete');

    $route->apiPost('/profiles', $ctrl . '@profileList');
    $route->apiPost('/profiles/upsert', $ctrl . '@profileUpsert');
    $route->apiPost('/profiles/delete', $ctrl . '@profileDelete');
    $route->apiPost('/profiles/weights', $ctrl . '@profileWeightsList');
    $route->apiPost('/profiles/weights/sync', $ctrl . '@profileWeightsSync');

    $route->apiPost('/assignments', $ctrl . '@assignmentList');
    $route->apiPost('/assignments/upsert', $ctrl . '@assignmentUpsert');
    $route->apiPost('/assignments/delete', $ctrl . '@assignmentDelete');

    $route->apiPost('/overrides', $ctrl . '@weatherOverrideList');
    $route->apiPost('/overrides/upsert', $ctrl . '@weatherOverrideUpsert');
    $route->apiPost('/overrides/delete', $ctrl . '@weatherOverrideDelete');
});
