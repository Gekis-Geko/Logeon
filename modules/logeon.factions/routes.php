<?php

declare(strict_types=1);

/** @var \Core\Router $route */
$ctrl = \Modules\Logeon\Factions\Controllers\Factions::class;

$route->group('/factions', function ($route) use ($ctrl) {
    $route->apiPost('/list', $ctrl . '@list');
    $route->apiPost('/get', $ctrl . '@get');
    $route->apiPost('/my', $ctrl . '@myFactions');
    $route->apiPost('/members', $ctrl . '@getFactionMembers');
    $route->apiPost('/relations', $ctrl . '@getFactionRelations');
    $route->apiPost('/leave', $ctrl . '@leaveFaction');
    $route->apiPost('/join-request/send', $ctrl . '@sendJoinRequest');
    $route->apiPost('/join-request/withdraw', $ctrl . '@withdrawJoinRequest');
    $route->apiPost('/join-request/my', $ctrl . '@myJoinRequests');
    $route->apiPost('/leader/requests', $ctrl . '@leaderListJoinRequests');
    $route->apiPost('/leader/request/review', $ctrl . '@reviewJoinRequest');
    $route->apiPost('/leader/invite', $ctrl . '@leaderInviteMember');
    $route->apiPost('/leader/expel', $ctrl . '@leaderExpelMember');
    $route->apiPost('/leader/relation', $ctrl . '@leaderProposeRelation');
});

$route->group('/admin/factions', function ($route) use ($ctrl) {
    $route->apiPost('/list', $ctrl . '@adminList');
    $route->apiPost('/get', $ctrl . '@adminGet');
    $route->apiPost('/create', $ctrl . '@adminCreate');
    $route->apiPost('/update', $ctrl . '@adminUpdate');
    $route->apiPost('/delete', $ctrl . '@adminDelete');
    $route->apiPost('/members/list', $ctrl . '@adminMemberList');
    $route->apiPost('/members/add', $ctrl . '@adminMemberAdd');
    $route->apiPost('/members/update', $ctrl . '@adminMemberUpdate');
    $route->apiPost('/members/remove', $ctrl . '@adminMemberRemove');
    $route->apiPost('/relations/list', $ctrl . '@adminRelationList');
    $route->apiPost('/relations/set', $ctrl . '@adminRelationSet');
    $route->apiPost('/relations/remove', $ctrl . '@adminRelationRemove');
});

$route->group('/game/factions', function ($route) {
    $route->get('/', function () {
        $characterId = \Core\AuthGuard::html()->requireCharacter();
        $db = \Core\AppContext::dbProvider()->connection();
        $presence = new \App\Services\PresenceService($db);
        $presence->touchCharacter((int) $characterId);

        return \Core\AppContext::templateRenderer()->render('factions/game/factions.twig');
    });
});
