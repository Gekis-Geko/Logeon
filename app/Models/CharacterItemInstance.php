<?php

namespace App\Models;

use Core\Models;

class CharacterItemInstance extends Models
{
    protected $table = 'character_item_instances';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN items ON character_item_instances.item_id = items.id ',
    ];
    protected $fillable = [
        'character_item_instances.id',
        'character_id',
        'item_id',
        'is_equipped',
        'slot',
        'durability',
        'meta_json',
        'character_item_instances.date_created',
        'items.name AS item_name',
        'items.description AS item_description',
        'items.image AS item_image',
        'items.price AS item_price',
        'items.type AS item_type',
        'items.is_stackable AS item_is_stackable',
        'items.is_equippable AS item_is_equippable',
        'items.equip_slot AS item_equip_slot',
    ];
}
