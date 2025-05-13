<?php

namespace app\common\WechatMsg\Commands;

use app\api\model\Room;
use app\api\model\RoomBill;
use app\common\WechatMsg\Common;

class IntegrateCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        $isOpenAndManager = Common::isOpenAndManager($input['data']['room_wxid'], $input['data']['from_wxid']);
        if (!$isOpenAndManager) return false;
        $roomBillModel = new RoomBill();
        // 查询所有账单
        $billLists = $roomBillModel->checkBill($input['data']['room_wxid']);
        // 汇总账单总额
        $billMoney = 0;
        $cats      = [];
        $msg       = '账单金额：';
        foreach ($billLists as $item) {
            $rate = number_format(floatval($item['rate']), 2, '.', '');
            // 分类
            if (!empty($item['card_type'])) {
                $cats[$item['card_type']][$rate] = 0;
            } else {
                $cats['others'][$rate] = 0;
            }
            $billMoney += $item['amount'];
        }

        $msg .= $billMoney;

        // 设置已整合标识
        $roomModel = new Room();
        $roomModel->save(['is_integrate' => 1, 'integrate_at' => date('Y-m-d H:i:s')], ['room_id' => $input['data']['room_wxid']]);
        Common::requestMsg($input['data']['room_wxid'], $msg);
        Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 5, $input['data']['msg'], $input['data']['msgid']);

        return true;
    }
}