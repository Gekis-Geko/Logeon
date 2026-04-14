<?php

namespace App\Models;

use Core\Models;

class LifecyclePhaseDefinition extends Models
{
    protected $table = 'lifecycle_phase_definitions';
    protected $primary_key = 'id';
    protected $fillable = [
        'id', 'code', 'name', 'description', 'category', 'sort_order',
        'is_initial', 'is_terminal', 'is_active', 'visible_to_players',
        'color_hex', 'icon', 'meta_json', 'date_created', 'date_updated',
    ];
}
