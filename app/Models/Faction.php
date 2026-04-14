<?php

namespace App\Models;

use Core\Models;

class Faction extends Models
{
    protected $table = 'factions';
    protected $primary_key = 'id';
    protected $fillable = [
        'id', 'code', 'name', 'description', 'type', 'scope', 'alignment',
        'power_level', 'is_public', 'is_active', 'color_hex', 'icon',
        'meta_json', 'date_created', 'date_updated',
    ];
}
