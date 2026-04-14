<?php

namespace App\Models;

use Core\Models;

class Location extends Models
{
    protected $table = 'locations';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN maps ON locations.map_id = maps.id ',
        ' LEFT JOIN social_status AS required_status ON locations.min_socialstatus_id = required_status.id ',
    ];
    protected $fillable = [
        'locations.id',
        'map_id',
        'owner_id',
        'locations.name',
        'locations.short_description',
        'locations.description',
        'locations.status',
        'page',
        'map_link',
        'locations.map_x',
        'locations.map_y',
        'locations.icon',
        'locations.image',
        'guests',
        'booking',
        'deadline',
        'cost',
        'locations.min_fame',
        'locations.min_socialstatus_id',
        'locations.is_house',
        'locations.chat_type',
        'is_chat',
        'is_private',
        'locations.access_policy',
        'locations.max_guests',
        'date_created',
        'date_updated',
        'date_deleted',
        'maps.name AS map_name',
        'maps.icon AS map_icon',
        'maps.image AS map_image',
        'maps.render_mode AS map_render_mode',
        'maps.position AS map_position',
        'required_status.name AS required_status_name',
        'required_status.min AS required_status_min',
    ];

}
