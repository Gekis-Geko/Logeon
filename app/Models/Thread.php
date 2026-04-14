<?php

namespace App\Models;

use Core\Models;

class Thread extends Models
{
    protected $table = 'forum_threads';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN forums ON forum_threads.forum_id = forums.id ',
        ' LEFT JOIN forum_types ON forums.type = forum_types.id',
        ' LEFT JOIN characters ON forum_threads.character_id = characters.id ',
    ];
    protected $fillable = [
        'forum_threads.id',
        'father_id',
        'forum_threads.title',
        'body',
        'forums.description AS forum_description',
        'forums.type AS forum_type',
        'is_important',
        'is_closed',
        'forum_threads.date_created',
        'forum_threads.date_updated',
        'forums.id AS forum_id',
        'forums.name AS forum_name',
        'forum_types.id AS forum_type_id',
        'forum_types.title AS forum_type_name',
        'forum_types.is_on_game AS forum_type_is_on_game',
        'characters.id AS character_id',
        'characters.name',
    ];

}
