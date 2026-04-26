<?php

namespace Modules\Logeon\Weather\Models;

use Core\Models;

class Weather extends Models
{
    protected $table = 'weather';
    protected $primary_key = 'id';
    protected $fillable = [
        '*',
    ];
}
