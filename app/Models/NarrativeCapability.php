<?php

namespace App\Models;

use Core\Models;

class NarrativeCapability extends Models
{
    protected $table = 'narrative_capabilities';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'name',
        'label',
        'max_impact_allowed',
        'staff_only',
        'date_created',
    ];
}
