<?php

namespace App\Models;

use Core\Models;

class Currency extends Models
{
    protected $table = 'currencies';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'code',
        'name',
        'symbol',
        'image',
        'is_default',
        'is_active',
    ];
}
