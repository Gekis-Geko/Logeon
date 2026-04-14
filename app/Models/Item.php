<?php

namespace App\Models;

use Core\Models;

class Item extends Models
{
    protected $table = 'items';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN item_categories ON items.category_id = item_categories.id ',
        ' LEFT JOIN item_rarities ON items.rarity_id = item_rarities.id ',
    ];
    protected $fillable = [
        'items.id AS id',
        'items.name AS name',
        'items.slug AS slug',
        'items.category_id AS category_id',
        'items.description AS description',
        'items.icon AS icon',
        'items.image AS image',
        'items.value AS value',
        'items.price AS price',
        'items.type AS type',
        'items.rarity AS rarity',
        'items.rarity_id AS rarity_id',
        'COALESCE(item_rarities.name, "") AS rarity_name',
        'COALESCE(item_rarities.color_hex, "") AS rarity_color',
        'items.stackable AS stackable',
        'items.is_stackable AS is_stackable',
        'items.max_stack AS max_stack',
        'items.usable AS usable',
        'items.consumable AS consumable',
        'items.tradable AS tradable',
        'items.droppable AS droppable',
        'items.destroyable AS destroyable',
        'items.weight AS weight',
        'items.cooldown AS cooldown',
        'items.script_effect AS script_effect',
        'items.applies_state_id AS applies_state_id',
        'items.removes_state_id AS removes_state_id',
        'items.state_intensity AS state_intensity',
        'items.state_duration_value AS state_duration_value',
        'items.state_duration_unit AS state_duration_unit',
        'items.metadata_json AS metadata_json',
        'items.is_equippable AS is_equippable',
        'items.equip_slot AS equip_slot',
        'items.created_at AS created_at',
        'items.updated_at AS updated_at',
        'items.date_created AS date_created',
        'item_categories.name AS category_name',
    ];
}
