<?php

namespace App\Models;

use Core\Models;

class Storyboard extends Models
{
    protected $table = 'storyboards';
    protected $primary_key = 'id';
    protected $fillable = [
        '*',
    ];

}
