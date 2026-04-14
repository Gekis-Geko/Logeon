<?php

namespace App\Models;

use Core\Models;

class Forum extends Models
{
    protected $table = 'forums';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN forum_types ON forums.type = forum_types.id ',
    ];
    protected $fillable = [
        'forums.id',
        'name',
        'description',
        'type',
        'forum_types.title',
        'forums.date_created',
        '(SELECT COUNT(forum_threads.id) FROM forum_threads WHERE forum_threads.father_id IS NULL AND forum_threads.forum_id = forums.id) AS count_thread',
    ];

}
