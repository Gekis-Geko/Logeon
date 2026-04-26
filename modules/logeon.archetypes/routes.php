<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\Archetypes\Controllers\Archetypes::class;

// Game HTML page
$route->get('/game/archetypes/', function () {
    $guard = \Core\AuthGuard::html();
    $characterId = (int) $guard->requireCharacter();
    (new \App\Services\PresenceService())->touchCharacter($characterId);

    $provider = \Modules\Logeon\Archetypes\Services\ArchetypeProviderRegistry::provider();
    $payload = $provider->publicList();
    $config = is_array($payload['config'] ?? null) ? $payload['config'] : [];
    $rows = is_array($payload['dataset'] ?? null) ? $payload['dataset'] : [];
    $enabled = \Modules\Logeon\Archetypes\Services\ArchetypeConfigAccessor::isEnabled($config);

    return \Core\AppContext::templateRenderer()->render('app/archetypes.twig', [
        'archetypes_enabled' => $enabled,
        'archetypes_config' => $config,
        'archetypes_rows' => $rows,
    ]);
});

// Game-facing API endpoints
$route->apiPost('/archetypes/list', $ctrl . '@publicList');
$route->apiPost('/archetypes/docs/list', $ctrl . '@publicDocsList');
$route->apiPost('/archetypes/my', $ctrl . '@characterArchetypes');
$route->apiPost('/archetypes/assign', $ctrl . '@assignArchetype');
$route->apiPost('/archetypes/remove', $ctrl . '@removeArchetype');

// Admin API endpoints
$route->group('/admin', function ($route) use ($ctrl) {
    $route->apiPost('/archetypes/list', $ctrl . '@adminList');
    $route->apiPost('/archetypes/create', $ctrl . '@adminCreate');
    $route->apiPost('/archetypes/update', $ctrl . '@adminUpdate');
    $route->apiPost('/archetypes/delete', $ctrl . '@adminDelete');
    $route->apiPost('/archetypes/config/get', $ctrl . '@adminConfigGet');
    $route->apiPost('/archetypes/config/update', $ctrl . '@adminConfigUpdate');
    $route->apiPost('/archetypes/character/list', $ctrl . '@adminCharacterArchetypes');
    $route->apiPost('/archetypes/character/assign', $ctrl . '@adminAssignArchetype');
    $route->apiPost('/archetypes/character/remove', $ctrl . '@adminRemoveArchetype');
});
