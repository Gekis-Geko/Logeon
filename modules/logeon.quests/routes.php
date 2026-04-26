<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$adminCtrl = \Modules\Logeon\Quests\Controllers\AdminQuests::class;
$ctrl = \Modules\Logeon\Quests\Controllers\Quests::class;

$route->group('/quests', function ($route) use ($ctrl) {
    $route->apiPost('/list', $ctrl . '@list');
    $route->apiPost('/get', $ctrl . '@get');
    $route->apiPost('/history/list', $ctrl . '@historyList');
    $route->apiPost('/history/get', $ctrl . '@historyGet');
    $route->apiPost('/participation/join', $ctrl . '@participationJoin');
    $route->apiPost('/participation/leave', $ctrl . '@participationLeave');
    $route->apiPost('/staff/instances/list', $ctrl . '@staffInstancesList');
    $route->apiPost('/staff/step/confirm', $ctrl . '@staffStepConfirm');
    $route->apiPost('/staff/instance/status-set', $ctrl . '@staffInstanceStatusSet');
    $route->apiPost('/staff/instance/force-progress', $ctrl . '@staffInstanceForceProgress');
    $route->apiPost('/staff/closure/get', $ctrl . '@staffClosureGet');
    $route->apiPost('/staff/closure/finalize', $ctrl . '@staffClosureFinalize');
});

$route->group('/game/quests', function ($route) {
    $route->get('/history', function () {
        $characterId = \Core\AuthGuard::html()->requireCharacter();
        $db = \Core\AppContext::dbProvider()->connection();
        $presence = new \App\Services\PresenceService($db);
        $presence->touchCharacter((int) $characterId);

        return \Core\AppContext::templateRenderer()->render('app/quest_history.twig', ['character_id' => $characterId]);
    });
});

$route->group('/admin/quests', function ($route) use ($adminCtrl) {
    $route->apiPost('/definitions/list', $adminCtrl . '@definitionsList');
    $route->apiPost('/definitions/create', $adminCtrl . '@definitionsCreate');
    $route->apiPost('/definitions/update', $adminCtrl . '@definitionsUpdate');
    $route->apiPost('/definitions/publish', $adminCtrl . '@definitionsPublish');
    $route->apiPost('/definitions/archive', $adminCtrl . '@definitionsArchive');
    $route->apiPost('/definitions/delete', $adminCtrl . '@definitionsDelete');
    $route->apiPost('/definitions/reorder', $adminCtrl . '@definitionsReorder');
    $route->apiPost('/steps/list', $adminCtrl . '@stepsList');
    $route->apiPost('/steps/upsert', $adminCtrl . '@stepsUpsert');
    $route->apiPost('/steps/delete', $adminCtrl . '@stepsDelete');
    $route->apiPost('/steps/reorder', $adminCtrl . '@stepsReorder');
    $route->apiPost('/conditions/list', $adminCtrl . '@conditionsList');
    $route->apiPost('/conditions/upsert', $adminCtrl . '@conditionsUpsert');
    $route->apiPost('/conditions/delete', $adminCtrl . '@conditionsDelete');
    $route->apiPost('/outcomes/list', $adminCtrl . '@outcomesList');
    $route->apiPost('/outcomes/upsert', $adminCtrl . '@outcomesUpsert');
    $route->apiPost('/outcomes/delete', $adminCtrl . '@outcomesDelete');
    $route->apiPost('/instances/list', $adminCtrl . '@instancesList');
    $route->apiPost('/instances/get', $adminCtrl . '@instancesGet');
    $route->apiPost('/instances/assign', $adminCtrl . '@instancesAssign');
    $route->apiPost('/instances/status/set', $adminCtrl . '@instancesStatusSet');
    $route->apiPost('/instances/step/set', $adminCtrl . '@instancesStepSet');
    $route->apiPost('/closures/list', $adminCtrl . '@closuresList');
    $route->apiPost('/closures/get', $adminCtrl . '@closuresGet');
    $route->apiPost('/closures/upsert', $adminCtrl . '@closuresUpsert');
    $route->apiPost('/rewards/list', $adminCtrl . '@rewardsList');
    $route->apiPost('/rewards/assign', $adminCtrl . '@rewardsAssign');
    $route->apiPost('/rewards/remove', $adminCtrl . '@rewardsRemove');
    $route->apiPost('/links/list', $adminCtrl . '@linksList');
    $route->apiPost('/links/upsert', $adminCtrl . '@linksUpsert');
    $route->apiPost('/links/delete', $adminCtrl . '@linksDelete');
    $route->apiPost('/logs/list', $adminCtrl . '@logsList');
    $route->apiPost('/maintenance/run', $adminCtrl . '@maintenanceRun');
});
