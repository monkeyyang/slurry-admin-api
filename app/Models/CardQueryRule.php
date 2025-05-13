<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardQueryRule extends Model
{
    protected $fillable = [
        'first_interval',
        'second_interval',
        'is_active',
        'remark'
    ];
    
    /**
     * 获取当前活跃的查询规则
     * 
     * @return self|null
     */
    public static function getActiveRule()
    {
        return self::where('is_active', true)->latest()->first();
    }
} 