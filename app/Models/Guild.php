<?php

namespace App\Models;

use Core\Models;

class Guild extends Models
{
    protected $table = 'guilds';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN guild_alignments ON guilds.alignment_id = guild_alignments.id ',
    ];
    protected $fillable = [
        'guilds.*',
        'guild_alignments.name AS alignment_name',
    ];
}
