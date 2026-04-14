<?php

namespace App\Models;

use Core\Models;

class Rule extends Models
{
    protected $table = 'rules';
    protected $primary_key = 'id';
    protected $fillable = [
        '*',
    ];

}
