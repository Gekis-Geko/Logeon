<?php

namespace App\Models;

use Core\Models;

class CharacterEvent extends Models
{
    protected $table = 'character_events';
    protected $primary_key = 'id';
    protected $fillable = [
        '*',
    ];
}
