<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ChargePlanWechatRoomBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'room_id',
        'bound_at',
    ];

    protected $casts = [
        'bound_at' => 'datetime',
    ];

    /**
     * The charge plan that the binding belongs to
     */
    public function plan()
    {
        return $this->belongsTo(ChargePlan::class, 'plan_id');
    }

    /**
     * Get the wechat room info from the card database
     */
    public function getWechatRoomInfo()
    {
        return DB::connection('mysql_card')
            ->table('mr_room')
            ->where('room_id', $this->room_id)
            ->first();
    }

    /**
     * Convert the model to an array for API responses
     *
     * @return array
     */
    public function toApiArray()
    {
        $roomInfo = $this->getWechatRoomInfo();
        
        return [
            'id' => (string)$this->id,
            'planId' => (string)$this->plan_id,
            'roomId' => $this->room_id,
            'roomName' => $roomInfo->room_name ?? '未知群组',
            'boundAt' => $this->bound_at ? $this->bound_at->format('Y-m-d H:i:s') : null,
            'createdAt' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
        ];
    }
} 