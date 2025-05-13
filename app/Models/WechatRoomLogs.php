<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WechatRoomLogs extends Model
{
    protected $table = 'wechat_room_logs';
    protected $primaryKey = 'id';
    protected $guarded = [];
    const UPDATED_AT = 'created_at';


}
