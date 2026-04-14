<?php

namespace App\Models;

use Core\Models;

class Nationality extends Models
{
    protected $table = 'nationalities';
    protected $primary_key = 'id';
    protected $fillable = [
        '*',
    ];

}
