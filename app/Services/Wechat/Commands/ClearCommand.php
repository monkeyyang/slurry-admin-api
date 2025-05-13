<?php

namespace App\Services\Wechat\Commands;

use app\api\model\Room;
use app\common\service\Bill;
use App\Services\Wechat\Common;
use think\facade\Cache;

class ClearCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        $isOpenAndManager = Common::isOpenAndManager($input['data']['room_wxid'], $input['data']['from_wxid']);
        if (!$isOpenAndManager) return false;
        $isSuper = Common::isSuper($input['data']['from_wxid']);
        if (!$isSuper) {
            Common::requestMsg($input['data']['room_wxid'], '你没有此权限');
            return false;
        }


        // 查询群信息
        $roomModel = new Room();
        $roomInfo  = $roomModel->getInfoByRoomId($input['data']['room_wxid']);

        $bill      = [
            'room_id'   => $input['data']['room_wxid'],
            'room_name' => $roomInfo['room_name'],
            'msgid'     => $input['data']['msgid'],
            'client_id' => 1,
            'event'     => 4,
            'money'     => -$roomInfo['unsettled'],
            'rate'      => 1,
            'amount'    => -$roomInfo['unsettled'],
            'card_type' => '',
            'remark'    => '',
            'op_id'     => $input['data']['from_wxid']
        ];
        // 更改前金额
        $bill['before_money'] = $roomInfo['unsettled'];
        // 更改后金额
        $bill['bill_money'] = 0;

        $billService        = new Bill();
        $billService->save($bill);
        // 清空账单金额
        $cacheKey = Common::getCacheKey($input['data']['room_wxid'] . '_unsettled');
        Cache::store('redis')->set($cacheKey, 0);

        Common::requestMsg($input['data']['room_wxid'], "清账金额：" . $bill['before_money'] . "\r已成功清账");
        Common::recordMsgLogs($input['data']['room_wxid'], $input['data']['from_wxid'], 6, $input['data']['msg'], $input['data']['msgid']);

        return true;
    }
}
