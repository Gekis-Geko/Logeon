<?php

namespace App\Models;

use Core\Models;

class GuildRoleScope extends Models
{
    protected $table = 'guild_role_scopes';
    protected $primary_key = 'id';
    protected $fillable = [
        'guild_role_scopes.*',
    ];
}
