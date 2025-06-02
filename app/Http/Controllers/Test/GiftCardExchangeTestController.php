<?php

namespace App\Http\Controllers\Test;

use App\Http\Controllers\Controller;
use App\Models\ChargePlanItem;
use App\Services\GiftCardExchangeService;
use App\Services\GiftCardApiClient;
use App\Models\ChargePlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GiftCardExchangeTestController extends Controller
{
    protected GiftCardExchangeService $giftCardExchangeService;

    public function __construct(GiftCardExchangeService $giftCardExchangeService)
    {
        $this->giftCardExchangeService = $giftCardExchangeService;
    }

    public function testExchange(Request $request)
    {
        try {
            // 设置测试参数
            $roomId = '44769140035@chatroom'; // 测试群组ID
            $wxid = 'wxid_test'; // 测试微信ID
            $msgid = 'msg_' . time(); // 生成测试消息ID
            $cardNumber = $request->input('card_number', 'XW3D3TDMLX3LPGYQ'); // 测试卡号
            $cardType = $request->input('card_type', 1); // 测试卡类型

            // 设置服务参数
            $this->giftCardExchangeService->setRoomId($roomId);
            $this->giftCardExchangeService->setWxId($wxid);
            $this->giftCardExchangeService->setMsgid($msgid);

            // 模拟交易配置
            $this->giftCardExchangeService->tradeConfig = [
                'rate' => 6.5,
                'enabled' => true,
                'minAmount' => 1,
                'maxAmount' => 1000,
                'amountConstraint' => 'multiple',
                'multipleBase' => 5
            ];

            // 模拟礼品卡查询结果
            $mockCardInfo = [
                'is_valid' => true,
                'country_code' => 'CA',
                'balance' => 3.45,
                'currency' => 'USD',
                'message' => '查询成功',
                'card_number' => $cardNumber,
                'card_type' => $cardType
            ];

            // 模拟兑换结果
            $mockExchangeResult = [
                'code' => 0,
                'data' => [
                    'task_id' => '0905860a-5a1f-4072-b563-300119b3fdf3',
                    'status' => 'completed',
                    'items' => [
                        [
                            'data_id' => 'salmonS1120@icloud.com:' . $cardNumber,
                            'status' => 'completed',
                            'msg' => '兑换成功,加载金额:$3.45,ID总金额:$30.11',
                            'result' => [
                                'code' => 0,
                                'msg' => '兑换成功,加载金额:$3.45,ID总金额:$30.11',
                                'username' => 'salmonS1120@icloud.com',
                                'total' => '$30.11',
                                'fund' => '$3.45',
                                'available' => ''
                            ],
                            'update_time' => '2025-05-29 02:00:03'
                        ]
                    ],
                    'msg' => '任务已完成',
                    'update_time' => '2025-05-29 02:00:03'
                ],
                'msg' => '执行成功'
            ];

            // 模拟计划数据
            $mockPlan = new ChargePlan([
                'id' => 35,
                'account' => 'salmonS1120@icloud.com',
                'password' => 'test_password',
                'country' => 'CA',
                'status' => 'processing',
                'total_amount' => 1000,
                'charged_amount' => 0,
                'multiple_base' => 5,
                'current_day' => 1
            ]);

            // 模拟计划项
            $mockPlanItem = new ChargePlanItem([
                'id' => 1,
                'plan_id' => 35,
                'day' => 1,
                'amount' => 0,
                'status' => 'pending',
                'executed_amount' => 0
            ]);

            // 记录测试结果
            Log::channel('gift_card_exchange')->info('测试结果', [
                'room_id' => $roomId,
                'wxid' => $wxid,
                'msgid' => $msgid,
                'card_number' => $cardNumber,
                'card_type' => $cardType,
                'card_info' => $mockCardInfo,
                'exchange_result' => $mockExchangeResult,
                'trade_config' => $this->giftCardExchangeService->tradeConfig
            ]);

            // 构造返回结果
            $result = [
                'success' => true,
                'message' => '测试完成',
                'data' => [
                    'card_info' => $mockCardInfo,
                    'exchange_result' => $mockExchangeResult,
                    'plan' => $mockPlan->toArray(),
                    'plan_item' => $mockPlanItem->toArray(),
                    'trade_config' => $this->giftCardExchangeService->tradeConfig
                ]
            ];

            return response()->json($result);

        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error('测试失败: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => '测试失败: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function testPlan()
    {
        try {
            // 模拟计划数据
            $mockPlan = new ChargePlan([
                'id' => 35,
                'account' => 'salmonS1120@icloud.com',
                'password' => 'test_password',
                'country' => 'CA',
                'status' => 'processing',
                'total_amount' => 1000,
                'charged_amount' => 0,
                'multiple_base' => 5,
                'current_day' => 1
            ]);

            // 模拟计划项
            $mockItems = [
                new ChargePlanItem([
                    'id' => 1,
                    'plan_id' => 35,
                    'day' => 1,
                    'amount' => 0,
                    'status' => 'pending',
                    'executed_amount' => 0
                ])
            ];

            // 模拟计划日志
            $mockLogs = [
                new \App\Models\ChargePlanLog([
                    'id' => 1,
                    'plan_id' => 35,
                    'item_id' => 1,
                    'account' => 'salmonS1120@icloud.com',
                    'action' => 'execute',
                    'status' => 'success',
                    'amount' => 3.45,
                    'rate' => 6.5,
                    'msg' => '兑换成功',
                    'details' => json_encode([
                        'card_number' => 'XW3D3TDMLX3LPGYQ',
                        'card_type' => 1,
                        'country_code' => 'CA'
                    ])
                ])
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'plan' => $mockPlan->toArray(),
                    'items' => collect($mockItems)->map->toArray(),
                    'logs' => collect($mockLogs)->map->toArray()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '获取计划信息失败: ' . $e->getMessage()
            ], 500);
        }
    }
}
