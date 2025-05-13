<?php

namespace App\Services\Wechat\Commands;

use app\api\model\RoomBill;
use App\Services\Wechat\Common;

class QueryCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        $can = Common::isOpenAndManager($input['data']['room_wxid'], $input['data']['from_wxid']);
        if (!$can) return false;
        // 查询只能查询整合后账单
        $roomModel     = new \app\api\model\Room();
        $roomInfo      = $roomModel->getInfoByRoomId($input['data']['room_wxid']);
        $roomBillModel = new RoomBill();
        if($input['data']['msg'] == '/账单总额' || $input['data']['msg'] == '/账单金额') {
            $msg       = '账单金额：'.$roomInfo['unsettled'];
            // 发送消息
            Common::requestMsg($input['data']['room_wxid'], $msg);
            // 记录消息日志
            Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 4, $input['data']['msg'], $input['data']['msgid']);

            return true;
        }
        // 查询所有账单
        $billLists      = $roomBillModel->checkBill($input['data']['room_wxid']);
        $integrateLists = [];
        if (!empty($roomInfo['integrate_at'])) {
            $integrateLists = $roomBillModel->beforeIntegrateBill($input['data']['room_wxid'], $roomInfo['integrate_at']);
        }

        // 汇总账单总额
        $billMoney = 0;
        $msg       = '账单金额：';
        foreach ($billLists as $item) {
            $billMoney += $item['amount'];
        }
        $msg .= $billMoney;
        // 是否有整合的订单，整合订单要从订单中去除
        $integrateMoney = 0;
        $integrateIds   = [];
        if (!empty($integrateLists)) {
            foreach ($integrateLists as $item) {
                $integrateMoney += $item['amount'];
                $integrateIds[] = $item['id'];
            }
        }
        if ($integrateMoney > 0) {
            $msg .= "\r+" . $integrateMoney . "=" . $integrateMoney . " 整合";
        } elseif ($integrateMoney < 0) {
            $msg .= "\r" . $integrateMoney . "=" . $integrateMoney . " 整合";
        }
        foreach ($billLists as $item) {
            if (in_array($item['id'], $integrateIds)) continue;
            $type = '';
            if ($item['money'] > 0) $type = '+';
            $msg .= "\r" . $type . floatval($item['money']);
            if (floatval($item['rate']) != 1) {
                if ($item['event'] == 2) {
                    $item['event'] = '/';
                } else {
                    $item['event'] = '*';
                }
                $msg .= $item['event'] . floatval($item['rate']);
            }
            $msg .= '=' . floatval($item['amount']);
            if (!empty($item['code'])) $msg .= "\n" . $item['code'];
//            if (!empty($item['card_type'])) $msg .= '#' . $item['card_type'];
//            if (!empty($item['remark'])) $msg .= '#' . $item['remark'];
//            if (!empty($item['note'])) $msg .= '#' . $item['note'];
        }
        // 发送消息
        Common::requestMsg($input['data']['room_wxid'], $msg);
        // 记录消息日志
        Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 4, $input['data']['msg'], $input['data']['msgid']);
        return true;
    }
}
