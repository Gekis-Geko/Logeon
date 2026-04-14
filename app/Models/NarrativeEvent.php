<?php

namespace App\Models;

use Core\Models;

class NarrativeEvent extends Models
{
    protected $table = 'narrative_events';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'title',
        'event_type',
        'event_mode',
        'status',
        'closed_at',
        'closed_by',
        'scope',
        'impact_level',
        'description',
        'entity_refs',
        'location_id',
        'visibility',
        'tags',
        'source_system',
        'source_ref_id',
        'meta_json',
        'created_by',
        'created_at',
    ];
}
