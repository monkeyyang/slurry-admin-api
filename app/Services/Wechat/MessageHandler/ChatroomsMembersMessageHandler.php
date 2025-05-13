<?php

namespace App\Services\Wechat\MessageHandler;

use app\common\model\RoomManager;
use Illuminate\Support\Facades\Log;

class ChatroomsMembersMessageHandler implements MessageHandler
{

    public function handle($input)
    {
        var_dump($input);exit;
        Log::info('成员信息', json_encode($input));
//        if (!empty($input['data']['member_list'])) {
//            foreach ($input['data']['member_list'] as $item) {
//                try {
//                    $roomManager = new RoomManager();
//                    // 查询昵称是否已更改，已更改不在更新
////                    $roomInfo = $roomManager->getInfoByWxId($item);
////                    if (empty($roomInfo) || empty($roomInfo['nickname'])) $roomManager->changeManagerInfo($item, $item['wxid']);
//                    $roomManager->changeManagerInfo($item, $item['wxid']);
//                } catch (\Exception $e) {
//                    continue;
//                }
//            }
//        }
    }
}
