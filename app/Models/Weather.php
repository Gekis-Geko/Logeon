<?php

namespace App\Models;

use Core\Models;

class Weather extends Models
{
    protected $table = 'weather';
    protected $primary_key = 'id';
    protected $fillable = [
        '*',
    ];
}
