<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class WechatBotUsers extends Model
{
    protected $table = 'wechat_bot_users';

    /**
     * 根据wxid获取机器人用户
     *
     * @param string $wxid
     * @return mixed
     */
    public static function getUserByWxid(string $wxid): mixed
    {
        return self::where('wxid', $wxid)->active()->first();
    }

    /**
     * 获取未删除的机器人用户
     *
     * @param Builder $query
     * @return Builder
     */
    public static function scopeActive(Builder $query): Builder
    {
        return $query->where('is_del', 0);
    }
}
