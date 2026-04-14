<?php

namespace App\Models;

use Core\Models;

class User extends Models
{
    protected $table = 'users';
    protected $primary_key = 'id';
    protected $fillable = [
        'id',
        'gender',
        'AES_DECRYPT(email, "' . DB['crypt_key'] . '") AS email',
        'is_administrator',
        'is_superuser',
        'is_moderator',
        'is_master',
        'date_created',
        'date_actived',
        'date_last_pass',
        'date_last_signin',
        'date_last_signout',
        'date_last_seed',
    ];
}
