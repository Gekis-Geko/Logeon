<?php

namespace App\Models;

use Core\Models;

class HowToPlay extends Models
{
    protected $table = 'how_to_play';
    protected $primary_key = 'id';
    protected $fillable = [
        '*',
    ];
}
