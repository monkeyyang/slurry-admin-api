<?php

namespace app\common\WechatMsg\Commands;

use app\common\model\Room;
use app\common\model\RoomLogs;
use app\common\service\StringParser;
use app\common\WechatMsg\Common;

/**
 * 识别卡密
 */
class CodeCommand implements CommandStrategy
{
    public function execute($input): bool
    {
        // 判断是否是找卡信息，找卡信息不处理
        if (strpos($input['data']['msg'], '找卡----') === 0) return false;
        // 逻辑处理
        $isOpen = Room::isOpen($input['data']['room_wxid']);
        if (!$isOpen) return false;
        // 消息是否已处理
        $isHandle = db('room_event')->where('msgid', $input['data']['msgid'])->find();
        if (!empty($isHandle)) return false;
        $roomModel = new \app\api\model\Room();
        $roomInfo  = $roomModel->getInfoByRoomId($input['data']['room_wxid']);
        if ($roomInfo['type'] != 2) return false;
        // 从信息中匹配iTunes卡代码
        $codes = Common::isItunesCode($input['data']['msg']);
        // 初始化变量
        $errorCode = 0;
        $errorMsg  = "❌重复卡片代码\n❌请检查是否多发多卖";
        // 匹配到的卡密信息，判断卡密是否已存在，已存在在报错
        if (!empty($codes)) {
            $repeat = [];
            foreach ($codes as $code) {
                $roomLogsModel = new RoomLogs();
                $isExits       = $roomLogsModel->getInfoByCode($code);
                if (!empty($isExits) && $isExits['room_id'] != $input['data']['room_wxid']) {
                    if (in_array($code, $repeat)) continue;
                    $repeat[]  = $code;
                    $errorCode = 1;
                    $errorMsg  .= "\n------------------------\n" . $code . "\n录入时间：" . date('m/d H:i:s', strtotime($isExits['created_at']));
                }
                if (!empty($isExits)) continue;
                $roomLogsModel->save([
                    'room_id' => $input['data']['room_wxid'],
                    'wxid'    => $input['data']['from_wxid'],
                    'msgid'   => $input['data']['msgid'],
                    'msg'     => $code
                ]);
            }
        }

        if ($errorCode) {
//            $errorMsg .= "\n------------------------\n首次录入时间：".$;
            Common::requestMsg($input['data']['room_wxid'], $errorMsg);
        }
        return true;
    }
}