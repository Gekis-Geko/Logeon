<?php

namespace App\Models;

use Core\Models;

class Map extends Models
{
    protected $table = 'maps';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'name',
        'description',
        'status',
        'initial',
        'position',
        'mobile',
        'image',
        'render_mode',
        'icon',
        'meteo',
    ];

}
