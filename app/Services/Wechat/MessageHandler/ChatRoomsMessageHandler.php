<?php

namespace app\common\WechatMsg\MessageHandler;

use app\common\model\Room;

// 获取群组列表
class ChatRoomsMessageHandler implements MessageHandler
{

    public function handle($input)
    {
        $room = new Room();
        $room->saveRoomList($input);
    }
}