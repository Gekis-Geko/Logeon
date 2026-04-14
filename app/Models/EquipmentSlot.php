<?php

namespace App\Models;

use Core\Models;

class EquipmentSlot extends Models
{
    protected $table = 'equipment_slots';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        '`key` AS `key`',
        'name',
        'description',
        'icon',
        'group_key',
        'sort_order',
        'is_active',
        'max_equipped',
        'date_created',
        'date_updated',
    ];
}
