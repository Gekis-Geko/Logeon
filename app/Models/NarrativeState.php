<?php

namespace App\Models;

use Core\Models;

class NarrativeState extends Models
{
    protected $table = 'narrative_states';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'code',
        'name',
        'description',
        'category',
        'scope',
        'stack_mode',
        'max_stacks',
        'conflict_group',
        'priority',
        'is_active',
        'visible_to_players',
        'metadata_json',
        'date_created',
        'date_updated',
    ];
}
