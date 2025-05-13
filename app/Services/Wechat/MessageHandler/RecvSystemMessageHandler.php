<?php

namespace app\common\WechatMsg\MessageHandler;

use app\common\model\Room;

/**
 * 微信系统消息处理（修改群名）
 */
class RecvSystemMessageHandler implements MessageHandler
{

    public function handle($input)
    {
        if (!empty($input['data']['raw_msg'])) {
            // 判断是否为修改群名操作
            if(strpos($input['data']['raw_msg'],'修改群名为') !== false) {
                $pattern = '/“(.*?)”/';
                preg_match($pattern, $input['data']['raw_msg'], $matches);
                if (isset($matches[1])) {
                    $room['nickname'] = $matches[1];
                    $roomModel = new Room();
                    $roomModel->changeRoomInfo($room, $input['data']['room_wxid'], true);
                }
            }
        }
    }
}