<?php

namespace App\Models;

use Core\Models;

class GuildAlignment extends Models
{
    protected $table = 'guild_alignments';
    protected $primary_key = 'id';
    protected $fillable = [
        'guild_alignments.*',
    ];
}
