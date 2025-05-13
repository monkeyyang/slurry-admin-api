<?php

namespace App\Services\Wechat\Commands;

use app\api\model\RoomManager;
use App\Services\Wechat\Common;

class AddManagerCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        if (empty($input['data']['at_user_list'])) return false;
        $can = Common::isOpenAndManager($input['data']['room_wxid'], $input['data']['from_wxid']);
        if (!$can) return false;
        $isSuper = Common::isSuper($input['data']['from_wxid']);
        if (!$isSuper) return false;
        foreach ($input['data']['at_user_list'] as $item) {
            try {
                if (empty(trim($item))) continue;
                $roomManager = new RoomManager();
                $manager     = $roomManager->where('wxid', $item)->find();
                if (!empty($manager)) {
                    $manager->save(['is_del' => 0], ['wxid', $item]);
                } else {
                    $roomManager->save(['wxid' => $item]);
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        Common::requestMsg($input['data']['room_wxid'], '添加管理员成功');
        Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 7, $input['data']['msg'], $input['data']['msgid']);
        // 更新群成员中管理员信息
        Common::getRoomMembers($input['data']['room_wxid']);
        return true;
    }
}
