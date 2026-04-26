<?php

declare(strict_types=1);

/** @var \Core\Router $route */

$ctrl = \Modules\Logeon\Attributes\Controllers\CharacterAttributes::class;
$equipmentSlotsCtrl = \Modules\Logeon\Attributes\Controllers\EquipmentSlots::class;
$itemEquipmentRulesCtrl = \Modules\Logeon\Attributes\Controllers\ItemEquipmentRules::class;

$route->group('/admin', function ($route) use ($ctrl) {
    $route->apiPost('/character-attributes/settings/get', $ctrl . '@adminSettingsGet');
    $route->apiPost('/character-attributes/settings/update', $ctrl . '@adminSettingsUpdate');
    $route->apiPost('/character-attributes/definitions/list', $ctrl . '@adminDefinitionsList');
    $route->apiPost('/character-attributes/definitions/create', $ctrl . '@adminDefinitionsCreate');
    $route->apiPost('/character-attributes/definitions/update', $ctrl . '@adminDefinitionsUpdate');
    $route->apiPost('/character-attributes/definitions/deactivate', $ctrl . '@adminDefinitionsDeactivate');
    $route->apiPost('/character-attributes/definitions/reorder', $ctrl . '@adminDefinitionsReorder');
    $route->apiPost('/character-attributes/rules/get', $ctrl . '@adminRulesGet');
    $route->apiPost('/character-attributes/rules/upsert', $ctrl . '@adminRulesUpsert');
    $route->apiPost('/character-attributes/rules/delete', $ctrl . '@adminRulesDelete');
    $route->apiPost('/character-attributes/recompute', $ctrl . '@adminRecompute');
});

$route->group('/admin', function ($route) use ($equipmentSlotsCtrl, $itemEquipmentRulesCtrl) {
    $route->apiPost('/equipment-slots/list', $equipmentSlotsCtrl . '@list');
    $route->apiPost('/equipment-slots/create', $equipmentSlotsCtrl . '@create');
    $route->apiPost('/equipment-slots/update', $equipmentSlotsCtrl . '@update');
    $route->apiPost('/equipment-slots/delete', $equipmentSlotsCtrl . '@delete');

    $route->apiPost('/item-equipment-rules/list', $itemEquipmentRulesCtrl . '@list');
    $route->apiPost('/item-equipment-rules/create', $itemEquipmentRulesCtrl . '@create');
    $route->apiPost('/item-equipment-rules/update', $itemEquipmentRulesCtrl . '@update');
    $route->apiPost('/item-equipment-rules/delete', $itemEquipmentRulesCtrl . '@delete');
});

$route->group('/profile', function ($route) use ($ctrl) {
    $route->apiPost('/attributes/list', $ctrl . '@profileList');
    $route->apiPost('/attributes/update-values', $ctrl . '@profileUpdateValues');
    $route->apiPost('/attributes/recompute', $ctrl . '@profileRecompute');
});
