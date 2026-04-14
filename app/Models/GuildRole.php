<?php

namespace App\Models;

use Core\Models;

class GuildRole extends Models
{
    protected $table = 'guild_roles';
    protected $primary_key = 'id';
    protected $fillable = [
        'guild_roles.*',
    ];
}
