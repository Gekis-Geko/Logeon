<?php

namespace App\Models;

use Core\Models;

class NarrativeCapabilityGrant extends Models
{
    protected $table = 'narrative_capability_grants';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'grantee_type',
        'grantee_ref',
        'capability',
        'max_impact_level',
        'scope_restriction',
        'date_created',
    ];
}
