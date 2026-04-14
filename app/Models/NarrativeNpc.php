<?php

namespace App\Models;

use Core\Models;

class NarrativeNpc extends Models
{
    protected $table = 'narrative_npcs';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'name',
        'description',
        'image',
        'group_type',
        'group_id',
        'is_active',
        'created_by',
        'date_created',
        'date_updated',
    ];
}
