<?php

namespace App\Services\Wechat\Commands;

use App\Services\Wechat\Common;

class OutCardCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        $can = Common::isOpenAndManager($input['data']['room_wxid'], $input['data']['from_wxid']);
        if (!$can) return false;
        Common::setRoomType($input['data']['room_wxid'], 1);
        Common::requestMsg($input['data']['room_wxid'], '当前群组已被设定为出卡群');
        Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 11, $input['data']['msg'], $input['data']['msgid']);
        return true;
    }
}
