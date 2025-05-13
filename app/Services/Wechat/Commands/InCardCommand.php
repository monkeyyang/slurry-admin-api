<?php

namespace App\Services\Wechat\Commands;

use App\Services\Wechat\Common;

class InCardCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        $can = Common::isOpenAndManager($input['data']['room_wxid'], $input['data']['from_wxid']);
        if (!$can) return false;
        Common::setRoomType($input['data']['room_wxid'], 2);
        Common::requestMsg($input['data']['room_wxid'], '当前群组已被设定为收卡群');
        Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 12, $input['data']['msg'], $input['data']['msgid']);
        return true;
    }
}
