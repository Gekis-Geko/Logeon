<?php

namespace App\Models;

use Core\Models;

class ItemEquipmentRule extends Models
{
    protected $table = 'item_equipment_rules';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN items ON item_equipment_rules.item_id = items.id ',
        ' LEFT JOIN equipment_slots ON item_equipment_rules.slot_id = equipment_slots.id ',
    ];
    protected $fillable = [
        'item_equipment_rules.id',
        'item_id',
        'slot_id',
        'priority',
        'item_equipment_rules.metadata_json AS metadata_json',
        'item_equipment_rules.date_created',
        'item_equipment_rules.date_updated',
        'items.name AS item_name',
        'equipment_slots.`key` AS slot_key',
        'equipment_slots.name AS slot_name',
        'equipment_slots.group_key AS slot_group_key',
        'equipment_slots.sort_order AS slot_sort_order',
    ];
}
