<?php

namespace App\Models;

use Core\Models;

class Shop extends Models
{
    protected $table = 'shops';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'name',
        'type',
        'location_id',
        'is_active',
    ];
}
