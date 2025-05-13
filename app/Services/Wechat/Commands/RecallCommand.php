<?php

namespace App\Services\Wechat\Commands;

use app\api\model\RoomBill;
use app\common\model\Room;
use app\common\service\Bill;
use App\Services\Wechat\Common;
use think\facade\Cache;

class RecallCommand implements CommandStrategy
{

    public function execute($input): bool
    {
        $roomId = $input['data']['room_wxid'];
        $wxId = $input['data']['from_wxid'];

        $isOpenAndManager = Common::isOpenAndManager($roomId, $wxId);
        if (!$isOpenAndManager) {
            Common::log('调试日志', $wxId, '撤回失败：权限不足');
            return false;
        }

        try {
            $isSuper = Common::isSuper($wxId);

            // 非超级管理员需要检查撤回标记
            // if (!$isSuper) {
                $recallKey = $roomId . '#' . $wxId . '#recall';
                if (Cache::get($recallKey) == 1) {
                    Common::log('调试日志', $wxId, '撤回失败：需要先加账才能再次撤回');
                    throw new \Exception('需要先加账才能再次撤回');
                }
            // }

            // 获取撤回锁
            $lockKey = 'recall_lock_' . $roomId;
            if (Cache::store('redis')->get($lockKey)) {
                Common::log('调试日志', $wxId, '撤回失败：操作太频繁');
                throw new \Exception('操作太频繁，请稍后再试');
            }
            Cache::store('redis')->set($lockKey, 1, 5);

            $roomModel = new Room();
            $roomInfo = $roomModel->getInfoByRoomId($roomId);
            $roomBillModel = new RoomBill();

            // 获取该用户在该群的最后一条加账记录
            $query = $roomBillModel
            ->where('room_id', $roomId)
            ->where('op_id', $wxId)
            ->where('is_del', 0);

            // 只有当整合时间存在时才添加这个条件
            if (!empty($roomInfo['integrate_at'])) {
                $query = $query->where('created_at', '>=', $roomInfo['integrate_at']);
            }

            $lastInfo = $query->where('event', '<>', '3')
            ->order('id desc')->find();

            if (empty($lastInfo)) {
                Common::log('调试日志', $wxId, '撤回失败：无可撤回记录');
                throw new \Exception('no recall');
            }

            \think\Db::startTrans();
            try {
                // 创建撤回记录
                $billModel = new RoomBill();
                $data = $lastInfo->toArray();
                unset($data['id']);

                // 记录原始数据
                Common::log('调试日志', $wxId, "原始记录：money={$lastInfo['money']}, amount={$lastInfo['amount']}, 当前余额={$roomInfo['unsettled']}");

                // 设置撤回记录数据
                $data['msgid'] = $input['data']['msgid'];
                $data['money'] = bcmul($lastInfo['money'], '-1', 2);  // 金额取反
                $data['amount'] = bcmul($lastInfo['amount'], '-1', 2); // 实际金额取反
                $data['oid'] = $lastInfo['id'];
                $data['before_money'] = $roomInfo['unsettled'];
                $data['event'] = 3;  // 标记为撤回记录
                $data['op_id'] = $wxId;

                // 计算新余额：当前群余额减去被撤回记录的金额
                $billMoney = bcsub($roomInfo['unsettled'], $lastInfo['amount'], 2);
                $data['bill_money'] = $billMoney;

                Common::log('调试日志', $wxId, "撤回计算：当前余额={$roomInfo['unsettled']}, 撤回金额={$lastInfo['amount']}, 新余额={$billMoney}");

                // 保存撤回记录
                $billModel->save($data);

                // 更新原记录状态为已删除
                $roomBillModel->where('id', $lastInfo['id'])->update(['is_del' => 1]);

                // 直接更新群组余额
                $roomModel->where('room_id', $roomId)->update([
                    'unsettled' => $billMoney,
                    'changed_at' => date('Y-m-d H:i:s')
                ]);

                // 更新缓存
                $cacheKey = Common::getCacheKey($roomId . '_unsettled');
                Cache::store('redis')->set($cacheKey, $billMoney);

                \think\Db::commit();

                // 记录成功日志
                Common::log('调试日志', $wxId, "撤回成功：撤回金额={$lastInfo['amount']}，新余额={$billMoney}");

                // 非超级管理员撤回成功后设置标记
                // if (!$isSuper) {
                    Cache::set($recallKey, 1);
                    Common::log('调试日志', $wxId, "撤回成功：设置撤回标记 {$recallKey}=1");
                // }

                // 发送消息
                $msg = "账单金额：{$billMoney}\n" . $this->formatRecallMessage($lastInfo) . "已撤回";
                Common::requestMsg($roomId, $msg);
                Common::recordMsgLogs($roomId, $wxId, 3, $input['data']['msg'], $input['data']['msgid'], $msg);

            } catch (\Exception $e) {
                \think\Db::rollback();
                Common::log('调试日志', $wxId, "撤回失败：事务处理异常，" . $e->getMessage());
                throw $e;
            } finally {
                Cache::store('redis')->rm($lockKey);
            }

            return true;
        } catch (\Exception $e) {
            Common::log('调试日志', $wxId, "撤回异常：" . $e->getMessage());

            // 通知错误消息
            $errorMsg = '撤回失败';
            if ($errorMsg !== 'no recall') {  // 如果不是无可撤回记录，才发送消息
                Common::requestMsg($roomId, $errorMsg);
            }

            return false;
        }
    }

    /**
     * 格式化撤回消息
     */
    private function formatRecallMessage($item)
    {
        $msg = '';
        $type = $item['money'] > 0 ? '+' : '';
        $msg .= $type . floatval($item['money']);

        if (floatval($item['rate']) > 1) {
            $event = $item['event'] == 2 ? '/' : '*';
            $msg .= $event . floatval($item['rate']);
        }

        $msg .= '=' . floatval($item['amount']);

        if (!empty($item['code'])) {
            $msg .= $item['code'] . "\n";
        }

        return $msg;
    }
}
