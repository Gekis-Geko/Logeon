<?php

namespace App\Models;

use Core\Models;

class Character extends Models
{
    protected $table = 'characters';
    protected $primary_key = 'id';
    protected $joins = [
        ' LEFT JOIN users ON characters.user_id = users.id ',
        ' LEFT JOIN social_status ON characters.socialstatus_id = social_status.id ',
        ' LEFT JOIN (SELECT character_id, MIN(job_id) AS job_id FROM character_jobs WHERE is_active = 1 GROUP BY character_id) AS _pj ON _pj.character_id = characters.id ',
        ' LEFT JOIN jobs ON jobs.id = _pj.job_id ',
    ];
    protected $fillable = [
        'characters.*',
        'AES_DECRYPT(email, "' . DB['crypt_key'] . '") AS email',
        'social_status.id AS socialstatus_id',
        'social_status.name AS socialstatus_name',
        'social_status.icon AS socialstatus_icon',
        'social_status.description AS socialstatus_description',
        'social_status.shop_discount AS socialstatus_shop_discount',
        'social_status.unlock_home AS socialstatus_unlock_home',
        'social_status.quest_tier AS socialstatus_quest_tier',
        'jobs.name AS job_name',
        'jobs.icon AS job_icon',
    ];
}
