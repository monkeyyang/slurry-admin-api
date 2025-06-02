<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YokeAppleAccount extends Model
{
    protected $connection = 'yoke-work'; // 使用yoke-work连接
    protected $table = 'yk_apple_account';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'country',
        'username',
        'password',
        'new_password',
        'security',
        'new_security',
        'birthday',
        'phone',
        'code_url',
        'msg',
        'status',
        'run_unique',
        'init_account',
        'modify_security',
        'modify_password',
        'modify_birthday',
        'enable_2fa',
        'disable_2fa',
        'change_region',
        'target_region',
    ];
}
