<?php

namespace app\common\WechatMsg\Commands;

use app\common\model\Room;
use app\common\model\RoomCodes;
use app\common\model\RoomLogs;
use app\common\model\RoomManager;
use app\common\WechatMsg\Common;
use think\cache\driver\Redis;
use think\facade\Cache;
use think\facade\Log;

class QueryCodeCommand implements CommandStrategy
{
    public function execute($input): bool
    {
        // 逻辑处理
        $isOpen = Room::isOpen($input['data']['room_wxid']);
        if (!$isOpen) return false;
        // 是否是机器人
        $managerModel = new RoomManager();
        $managerInfo = $managerModel->getInfoByWxId($input['data']['from_wxid']);
        if(!empty($managerInfo['is_bot'])) return false;
        // 消息是否已处理
        $isHandle = db('room_event')->where('msgid', $input['data']['msgid'])->find();
        if (!empty($isHandle)) return false;
        $roomModel = new \app\api\model\Room();
        $roomInfo  = $roomModel->getInfoByRoomId($input['data']['room_wxid']);
        if ($roomInfo['type'] != 2 && $roomInfo['type'] != 1) return false;
        // 从信息中匹配iTunes卡代码
        $codes = Common::isCode($input['data']['msg']);
        // 初始化变量
        $errorCode = 0;
        $errorMsg  = "代码重复\n------------------------";
        // 初始化可用CODE数组
        $validCodes = [];
        if (!empty($codes)) {
            // 初始化重复CODE数组
            $repeat = [];
            foreach ($codes as $code) {
                if($code == 'TOTAL BALANCE BILL' || $code == 'REGULAR PURCHASE' || $code == 'APPLE CARD') continue;
                // 代码长度少于10位忽略
                if(strlen($code) < 10) continue;
                $codesModel = new RoomCodes();
                $codeInfo = $codesModel->getInfoByCode($code, $roomInfo['type']);
                if (!empty($codeInfo) && $codeInfo['room_id'] != $input['data']['room_wxid']) {
                    if (in_array($code, $repeat)) continue;
                    $repeat[]  = $code;
                    $errorCode = 1;
                    $errorMsg  .= "\n" . $code;
                }
                if (!empty($codeInfo)) continue;
                try {
                    // 写入数据库
                    $id = $codesModel->insertGetId([
                        'room_id' => $input['data']['room_wxid'],
                        'wxid'    => $input['data']['from_wxid'],
                        'msgid'   => $input['data']['msgid'],
                        'code'    => $code
                    ]);
                    if (empty($id)) continue;
                    $validCodes[$id] = $code;
                } catch (\Exception $e) {
                    $repeat[]  = $code;
                    $errorCode = 1;
                    $errorMsg  .= "\n" . $code;
                    continue;
                }
            }
        }
        Common::log($input['data']['room_wxid'], $input['data']['from_wxid'], '--------------------------------------');
        Common::log($input['data']['room_wxid'], $input['data']['from_wxid'], json_encode($validCodes));
        Log::write('-------重复代码-------' . $errorMsg);
        // 发送错误代码消息通知
        // 当前分支错误通知到指定群
//        if ($errorCode) {
//            $errorMsg.="\n------------------------\n消息来源：".$roomInfo['room_name'];
//            Common::requestMsg('19188063175@chatroom', $errorMsg);
//        }
        // 成功代码进入Redis队列等待查询
        // 当前分支不调用六月接口
//        if (!empty($validCodes)) {
//            // 发送等待验证消息
//            //Common::requestMsg($input['data']['room_wxid'], '请等待代码验证');
//            // 写入redis队列
//            $config    = [
//                'host'     => 'r-j6cb0qsk94l01okxl8pd.redis.rds.aliyuncs.com',
//                'port'     => '6379',
//                'password' => 'r-j6cb0qsk94l01okxl8:Quan112211'
//            ];
//            $redis     = new Redis($config);
//            $queueName = 'code_request_api_queue';
//            $element   = json_encode(['room_id' => $input['data']['room_wxid'], 'code' => $validCodes]);
//            $redis->rpush($queueName, $element);
//            Common::log($input['data']['room_wxid'], $input['data']['from_wxid'], $element);
//        }


        return true;
    }
}