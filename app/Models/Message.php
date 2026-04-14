<?php

namespace App\Models;

use Core\Models;

class Message extends Models
{
    protected $table = 'messages';
    protected $primary_key = 'id';
    protected $fillable = [
        'messages.id',
        'thread_id',
        'sender_id',
        'recipient_id',
        'body',
        'message_type',
        'is_read',
        'date_created',
    ];
}
