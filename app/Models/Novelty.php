<?php

namespace App\Models;

use Core\Models;

class Novelty extends Models
{
    protected $table = 'news';
    protected $primary_key = 'id';
    protected $fillable = [
        'news.id',
        'news.title',
        'news.body',
        'news.excerpt',
        'news.image',
        'news.type',
        'news.is_published',
        'news.is_pinned',
        'news.author_id',
        'news.date_created',
        'news.date_published',
        'news.date_updated',
    ];

}
