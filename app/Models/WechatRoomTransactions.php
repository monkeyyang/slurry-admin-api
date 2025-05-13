<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WechatRoomTransactions extends Model
{
    protected $table = 'wechat_room_transactions';
    protected $fillable = [
        'room_id',
        'msgid',
        'client_id',
        'oid',
        'event',
        'money',
        'rate',
        'fee',
        'amount',
        'card_type',
        'before_money',
        'country',
        'wxid',
        'code',
        'remark',
        'is_del'
    ];

    // 关联群聊
    public function wechatRoom(): BelongsTo
    {
        return $this->belongsTo(WechatRooms::class, 'room_id');
    }

    // 关联机器人用户
    public function botUser(): BelongsTo {
        return $this->belongsTo(WechatBotUsers::class, 'wxid');
    }
}
