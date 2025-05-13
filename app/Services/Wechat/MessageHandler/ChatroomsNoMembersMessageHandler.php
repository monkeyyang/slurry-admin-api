<?php

namespace App\Services\Wechat\MessageHandler;

use app\common\model\Room;

class ChatroomsNoMembersMessageHandler implements MessageHandler
{

    public function handle($input)
    {
        (new Room())->changeRoomInfo($input['data'], $input['data']['wxid']);
    }
}
