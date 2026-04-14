<?php

namespace App\Models;

use Core\Models;

class ShopInventory extends Models
{
    protected $table = 'shop_inventory';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN shops ON shop_inventory.shop_id = shops.id ',
        ' LEFT JOIN items ON shop_inventory.item_id = items.id ',
        ' LEFT JOIN currencies ON shop_inventory.currency_id = currencies.id ',
    ];
    protected $fillable = [
        'shop_inventory.id',
        'shop_id',
        'item_id',
        'currency_id',
        'price',
        'stock',
        'per_character_limit',
        'per_day_limit',
        'is_active',
        'is_promo',
        'promo_discount',
        'shop_inventory.date_created',
        'shops.name AS shop_name',
        'items.name AS item_name',
        'currencies.code AS currency_code',
    ];
}
