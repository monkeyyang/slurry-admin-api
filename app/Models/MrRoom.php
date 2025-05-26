<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MrRoom extends Model
{
    protected $connection = 'mysql_card'; // 使用mysql_card连接
    protected $table = 'mr_room';
    protected $primaryKey = 'id';
    public $timestamps = false; // mr_room表可能没有标准的timestamps

    protected $fillable = [
        'room_id',
        'room_name',
        'member_count',
        'is_active',
        // 根据实际表结构添加其他字段
    ];

    /**
     * 获取群组的计划绑定数量
     */
    public function getPlanCount()
    {
        return ChargePlanWechatRoomBinding::where('room_id', $this->room_id)->count();
    }

    /**
     * Convert the model to an array for API responses
     *
     * @return array
     */
    public function toApiArray()
    {
        return [
            'id' => (string)$this->id,
            'roomId' => $this->room_id,
            'roomName' => $this->room_name ?? '未知群组',
            'memberCount' => $this->member_count ?? 0,
            'isActive' => $this->is_active ?? true,
            'planCount' => $this->getPlanCount(),
        ];
    }

    /**
     * 获取活跃的群组列表
     */
    public static function getActiveRooms($limit = null, $offset = null)
    {
        $query = self::query();
        
        // 根据实际表结构调整查询条件
        // $query->where('is_active', 1);
        
        if ($limit) {
            $query->limit($limit);
        }
        
        if ($offset) {
            $query->offset($offset);
        }
        
        return $query->get();
    }

    /**
     * 根据room_id获取群组信息
     */
    public static function getByRoomId($roomId)
    {
        return self::where('room_id', $roomId)->first();
    }
} 