<?php

namespace App\Models;

use Core\Models;

class MessageThread extends Models
{
    protected $table = 'messages_threads';
    protected $primary_key = 'id';
    protected $fillable = [
        'messages_threads.id',
        'character_one',
        'character_two',
        'subject',
        'last_message_body',
        'last_message_type',
        'last_sender_id',
        'date_last_message',
        'date_created',
    ];
}
