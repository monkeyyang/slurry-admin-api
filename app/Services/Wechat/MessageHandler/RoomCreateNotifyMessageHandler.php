<?php

namespace app\common\WechatMsg\MessageHandler;

// 群创建通知
use app\common\model\Room;

class RoomCreateNotifyMessageHandler implements MessageHandler
{

    public function handle($input)
    {
        $room = new Room();
        $room->changeRoomInfo($input['data'], $input['data']['room_wxid']);
    }
}