<?php

namespace app\common\WechatMsg\Commands;

use app\common\WechatMsg\Common;

class CloseCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        $isOpenAndManager = Common::isOpenAndManager($input['data']['room_wxid'], $input['data']['from_wxid']);
        if (!$isOpenAndManager) return false;
        Common::openOrCloseRoomBot($input['data']['room_wxid'], 'stop');
        Common::requestMsg($input['data']['room_wxid'], '群助手已关闭');
        Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 2, $input['data']['msg'], $input['data']['msgid']);
        return true;
    }
}