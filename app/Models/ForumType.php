<?php

namespace App\Models;

use Core\Models;

class ForumType extends Models
{
    protected $table = 'forum_types';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'title',
        'subtitle',
        'is_on_game',
    ];

}
