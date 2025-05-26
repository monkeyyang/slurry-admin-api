<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WechatRoomBindingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'enabled',
        'auto_assign',
        'default_room_id',
        'max_plans_per_room',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'auto_assign' => 'boolean',
    ];

    /**
     * 获取设置（单例模式）
     *
     * @return WechatRoomBindingSetting
     */
    public static function getSettings(): WechatRoomBindingSetting
    {
        $settings = self::first();
        
        if (!$settings) {
            $settings = self::create([
                'enabled' => false,
                'auto_assign' => false,
                'default_room_id' => null,
                'max_plans_per_room' => 10,
            ]);
        }
        
        return $settings;
    }

    /**
     * Convert the model to an array for API responses
     *
     * @return array
     */
    public function toApiArray()
    {
        return [
            'enabled' => $this->enabled,
            'autoAssign' => $this->auto_assign,
            'defaultRoomId' => $this->default_room_id,
            'maxPlansPerRoom' => $this->max_plans_per_room,
        ];
    }
} 