<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PmMapping extends Model
{
    protected $fillable = [
        'github_username',
        'slack_user_id',
    ];
}
