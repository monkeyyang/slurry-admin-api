<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MrRoomBill extends Model
{
    protected $connection = 'mysql_card'; // 使用mysql_card连接
    protected $table = 'mr_room_bill';
    protected $primaryKey = 'id';
    public $timestamps = false; // mr_room_bill表可能没有标准的timestamps

    protected $fillable = [
        'room_id', // 群聊ID
        'room_name', // 群聊名称
        'msgid', // 微信消息ID
        'client_id',
        'oid', // 操作对象ID，一般用撤回时撤回的对象是谁
        'event', // 操作事件类型, 1乘法，2除法，3撤回
        'money', // 面值
        'rate', // 汇率
        'fee', // 税率
        'amount', // 总额，例如event=1 ，amount=money*rate
        'card_type', // 国家代码，比如美国为US
        'before_money', // 变动前待群聊待结算金额，取值mr_room的unsettled
        'bill_money', // 变动后金额，mr_room的unsettled+amount
        'remark', // 礼品卡类型，比如苹果礼品卡iTunes
        'op_id', // 微信发送消息者wxid
        'op_name', // 微信发送消息者的微信昵称
        'code', // 礼品卡代码
        'content', //
        'note', // 备注
        'status', // 状态
        'is_settle', // 是否已结算标识，默认0
        'is_del' // 是否删除标识 默认0
    ];
}
