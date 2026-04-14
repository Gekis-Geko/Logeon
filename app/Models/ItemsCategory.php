<?php

namespace App\Models;

use Core\Models;

class ItemsCategory extends Models
{
    protected $table = 'item_categories';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'name',
        'description',
        'icon',
        'sort_order',
        'date_created',
        'date_updated',
    ];
}
