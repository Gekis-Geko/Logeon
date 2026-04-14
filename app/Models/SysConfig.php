<?php

namespace App\Models;

use Core\Models;

class SysConfig extends Models
{
    protected $table = 'sys_configs';
    protected $primary_key = 'id';
    protected $fillable = [
        '*',
    ];
}
