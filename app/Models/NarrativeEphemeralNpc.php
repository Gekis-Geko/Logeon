<?php

namespace App\Models;

use Core\Models;

class NarrativeEphemeralNpc extends Models
{
    protected $table = 'narrative_ephemeral_npcs';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'event_id',
        'name',
        'description',
        'image',
        'location_id',
        'created_by',
        'created_at',
    ];
}
