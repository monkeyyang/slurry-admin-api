<?php

namespace app\common\WechatMsg\Commands;

use app\common\WechatMsg\Common;
use think\Exception;

class OpenCommand implements CommandStrategy
{
    public function execute($input): bool
    {
        // 是否为管理员
        $isManager = Common::isManager($input['data']['from_wxid']);
        if (!$isManager) return false;

        Common::openOrCloseRoomBot($input['data']['room_wxid']);
        Common::requestMsg($input['data']['room_wxid'], '群助手开启成功');
        Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 1, $input['data']['msg'], $input['data']['msgid']);

        // 开启账单再次拉群群信息
        Common::getRoomNoMembers($input['data']['room_wxid']);
        
        return true;
    }
}