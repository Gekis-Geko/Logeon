<?php

namespace App\Models;

use Core\Models;

class GuildRoleLocation extends Models
{
    protected $table = 'guild_role_locations';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN guilds ON guild_role_locations.guild_id = guilds.id ',
        ' LEFT JOIN guild_roles ON guild_role_locations.role_id = guild_roles.id ',
        ' LEFT JOIN locations ON guild_role_locations.location_id = locations.id ',
    ];
    protected $fillable = [
        'guild_role_locations.*',
        'guilds.name AS guild_name',
        'guild_roles.name AS role_name',
        'locations.name AS location_name',
    ];
}
