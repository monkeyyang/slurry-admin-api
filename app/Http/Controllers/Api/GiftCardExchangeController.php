<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGiftCardExchangeJob;
use App\Models\ChargePlan;
use App\Services\GiftCardApiClient;
use App\Services\GiftCardExchangeService;
use App\Services\GiftExchangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\GiftCardExchangeRecord;
use App\Models\ItunesTradeAccountLog;

class GiftCardExchangeController extends Controller
{
    protected GiftCardExchangeService $giftCardExchangeService;

    public function __construct(GiftCardExchangeService $giftCardExchangeService)
    {
        $this->giftCardExchangeService = $giftCardExchangeService;
    }

    /**
     * 测试方法：获取所有处理中的计划并发送登录请求
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function test(Request $request): JsonResponse
    {
        try {
            Log::info('开始执行测试方法：获取处理中的计划并发送登录请求');

            $service = new GiftExchangeService();

            // 获取所有处理中的计划
            $query = ChargePlan::where('status', 'processing');
            $plans = $query->orderBy('created_at', 'asc')->get();

            Log::info('查询到处理中的计划数量: ' . $plans->count());

            if ($plans->isEmpty()) {
                return response()->json([
                    'code' => 0,
                    'message' => '没有找到处理中的计划',
                    'data' => [
                        'plans_count' => 0,
                        'plans' => [],
                        'login_sent' => false
                    ],
                ]);
            }

            // 准备账号数据用于发送登录请求
            $accountsForLogin = [];
            $plansData = [];

            foreach ($plans as $plan) {
                $planData = $plan->toApiArray();
                $plansData[] = $planData;

                // 获取解密后的账号信息
                $decryptedAccountInfo = $service->getDecryptedAccountInfo($plan);

                // 为每个计划准备登录账号信息
                $accountsForLogin[] = [
                    'account' => $decryptedAccountInfo['account'],
                    'password' => $decryptedAccountInfo['password'], // 现在是解密后的密码
                    'verify_url' => $decryptedAccountInfo['verify_url'] ?? ''
                ];
            }

            // 发送异步登录请求
            $service->sendAsyncLoginRequest($accountsForLogin);

            Log::info('已为 ' . count($accountsForLogin) . ' 个账号发送登录请求');

            return response()->json([
                'code' => 0,
                'message' => '成功获取处理中的计划并发送登录请求',
                'data' => [
                    'plans_count' => $plans->count(),
                    'plans' => $plansData,
                    'login_sent' => true,
                    'accounts_sent' => count($accountsForLogin)
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('测试方法执行失败: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);

            return response()->json([
                'code' => 500,
                'message' => '测试方法执行失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 处理礼品卡兑换消息
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function processExchangeMessage(Request $request): JsonResponse
    {
        try {
            $roomId = $request->input('room_wxid','');
            $msgId = $request->input('msgid', '');
            $wxId = $request->input('from_wxid', '');
            $message = $request->input('message', '');

            Log::channel('gift_card_exchange')->error('获取到兑换消息1：'.json_encode([
                'room_id' => $roomId,
                'wxid' => $wxId,
                'msgid' => $msgId,
                'msg' => $message
            ]));

            if(empty($roomId)) {
                 return response()->json([
                    'code' => 400,
                    'message' => '未获取到群聊ID',
                    'data' => null,
                ]);
            }

            if (empty($message)) {
                return response()->json([
                    'code' => 400,
                    'message' => '消息不能为空',
                    'data' => null,
                ]);
            }

            // 检查消息是否已经处理过（防重复）
            if (!empty($msgId)) {
                // 检查是否已有相同msgid的兑换记录
                $existingRecord = ItunesTradeAccountLog::where('msgid', $msgId)
                    ->whereIn('status', [ItunesTradeAccountLog::STATUS_SUCCESS, ItunesTradeAccountLog::STATUS_PENDING])
                    ->first();
                
                if ($existingRecord) {
                    Log::channel('gift_card_exchange')->warning('消息已处理过，忽略重复请求', [
                        'msgid' => $msgId,
                        'room_id' => $roomId,
                        'existing_record_id' => $existingRecord->id,
                        'existing_status' => $existingRecord->status,
                        'message' => $message
                    ]);
                    return response()->json([
                        'code' => 200,
                        'message' => '消息已处理过，忽略重复请求',
                        'data' => [
                            'existing_record_id' => $existingRecord->id,
                            'existing_status' => $existingRecord->status,
                            'processed_time' => $existingRecord->created_at
                        ],
                    ]);
                }
            }

            // 验证消息格式
            $parseResult = $this->giftCardExchangeService->parseMessage($message);
            if (!$parseResult) {
                return response()->json([
                    'code' => 400,
                    'message' => '消息格式无效，正确格式：卡号 /类型（如：XQPD5D7KJ8TGZT4L /1）',
                    'data' => null,
                ]);
            }

            // 检查礼品卡号是否已经被兑换过
            $cardNumber = $parseResult['card_number'];
            $existingCardRecord = ItunesTradeAccountLog::where('code', $cardNumber)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->first();
            
            if ($existingCardRecord) {
                Log::channel('gift_card_exchange')->warning('礼品卡已被兑换过，忽略重复请求', [
                    'card_number' => $cardNumber,
                    'room_id' => $roomId,
                    'existing_record_id' => $existingCardRecord->id,
                    'existing_exchange_time' => $existingCardRecord->exchange_time,
                    'msgid' => $msgId
                ]);
                return response()->json([
                    'code' => 400,
                    'message' => '礼品卡已被兑换过，请勿重复提交',
                    'data' => [
                        'card_number' => $cardNumber,
                        'existing_record_id' => $existingCardRecord->id,
                        'existing_exchange_time' => $existingCardRecord->exchange_time
                    ],
                ]);
            }

            // 生成请求ID用于追踪
            $requestId = uniqid('exchange_', true);

            Log::channel('gift_card_exchange')->info('收到兑换请求，加入队列处理', [
                'request_id' => $requestId,
                'message' => $message,
                'card_number' => $cardNumber,
                'card_type' => $parseResult['card_type']
            ]);

            // 注意：礼品卡兑换的记录将在实际处理过程中记录到 ItunesTradeAccountLog 表

            // 将任务加入队列
            ProcessGiftCardExchangeJob::dispatch([
                'room_id' => $roomId,
                'wxid' => $wxId,
                'msgid' => $msgId,
                'msg' => $message
            ], $requestId);

            return response()->json([
                'code' => 0,
                'message' => '兑换请求已接收，正在队列中处理',
                'data' => [
                    'request_id' => $requestId,
                    'card_number' => $cardNumber,
                    'card_type' => $parseResult['card_type'],
                    'status' => 'queued'
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('处理兑换消息失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '处理兑换消息失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 验证礼品卡
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function validateGiftCard(Request $request): JsonResponse
    {
        try {
            $cardNumber = $request->input('cardNumber');
            if (empty($cardNumber)) {
                return response()->json([
                    'code' => 400,
                    'message' => '卡号不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->validateGiftCard($cardNumber);
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('验证礼品卡失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '验证礼品卡失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取兑换记录
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getExchangeRecords(Request $request): JsonResponse
    {
        try {
            $query = GiftCardExchangeRecord::query();

            // 应用过滤
            if ($request->has('cardNumber')) {
                $query->where('card_number', 'like', '%' . $request->input('cardNumber') . '%');
            }

            if ($request->has('countryCode')) {
                $query->where('country_code', $request->input('countryCode'));
            }

            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->has('planId')) {
                $query->where('plan_id', $request->input('planId'));
            }

            // 应用排序
            $sortField = $request->input('sortField', 'exchange_time');
            $sortOrder = $request->input('sortOrder', 'desc');

            // 有效排序字段
            $validSortFields = ['card_number', 'country_code', 'original_balance', 'converted_amount', 'exchange_time', 'created_at'];
            if (in_array($sortField, $validSortFields)) {
                $query->orderBy($sortField, $sortOrder === 'desc' ? 'desc' : 'asc');
            }

            // 应用分页
            $page = $request->input('page', 1);
            $pageSize = $request->input('pageSize', 10);
            $records = $query->paginate($pageSize, ['*'], 'page', $page);

            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'list' => $records->items() ? collect($records->items())->map(function($record) {
                        return $record->toApiArray();
                    }) : [],
                    'total' => $records->total(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('获取兑换记录失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取兑换记录失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取单个兑换记录详情
     *
     * @param string $id
     * @return JsonResponse
     */
    public function getExchangeRecord(string $id): JsonResponse
    {
        try {
            $record = GiftCardExchangeRecord::findOrFail($id);
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => $record->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('获取兑换记录详情失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取兑换记录详情失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 创建账号登录任务
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createLoginTask(Request $request): JsonResponse
    {
        try {
            $accounts = $request->input('list', []);
            if (empty($accounts)) {
                return response()->json([
                    'code' => 400,
                    'message' => '账号列表不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->giftCardApiClient->createLoginTask($accounts);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('创建登录任务失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '创建登录任务失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 查询登录任务状态
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getLoginTaskStatus(Request $request): JsonResponse
    {
        try {
            $taskId = $request->input('task_id');
            if (empty($taskId)) {
                return response()->json([
                    'code' => 400,
                    'message' => '任务ID不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->getLoginTaskStatus($taskId);

            return response()->json([
                'code' => $result['success'] ? 0 : 500,
                'message' => $result['message'],
                'data' => $result['data'],
            ]);
        } catch (\Exception $e) {
            Log::error('查询登录任务状态失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '查询登录任务状态失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 删除用户登录
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteUserLogins(Request $request): JsonResponse
    {
        try {
            $accounts = $request->input('list', []);
            if (empty($accounts)) {
                return response()->json([
                    'code' => 400,
                    'message' => '账号列表不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->deleteUserLogins($accounts);

            return response()->json([
                'code' => $result['success'] ? 0 : 500,
                'message' => $result['message'],
                'data' => $result['data'],
            ]);
        } catch (\Exception $e) {
            Log::error('删除用户登录失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '删除用户登录失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 刷新用户登录状态
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function refreshUserLogin(Request $request): JsonResponse
    {
        try {
            $account = [
                'id' => $request->input('id', 0),
                'username' => $request->input('username'),
                'password' => $request->input('password', ''),
                'verifyUrl' => $request->input('verifyUrl', '')
            ];

            if (empty($account['username'])) {
                return response()->json([
                    'code' => 400,
                    'message' => '用户名不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->giftCardApiClient->refreshUserLogin($account);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('刷新用户登录状态失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '刷新用户登录状态失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 批量查询礼品卡
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function queryCards(Request $request): JsonResponse
    {
        try {
            $cards = $request->input('list', []);
            if (empty($cards)) {
                return response()->json([
                    'code' => 400,
                    'message' => '卡号列表不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->giftCardApiClient->createCardQueryTask($cards);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('批量查询礼品卡失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '批量查询礼品卡失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 查询卡片查询任务状态
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCardQueryTaskStatus(Request $request): JsonResponse
    {
        try {
            $taskId = $request->input('task_id');
            if (empty($taskId)) {
                return response()->json([
                    'code' => 400,
                    'message' => '任务ID不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->giftCardApiClient->getCardQueryTaskStatus($taskId);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('查询卡片查询任务状态失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '查询卡片查询任务状态失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 批量兑换礼品卡
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function redeemCards(Request $request): JsonResponse
    {
        try {
            $redemptions = $request->input('list', []);
            $interval = $request->input('interval', 6);

            if (empty($redemptions)) {
                return response()->json([
                    'code' => 400,
                    'message' => '兑换信息列表不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->giftCardApiClient->createRedemptionTask(
                $redemptions,
                $interval
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('批量兑换礼品卡失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '批量兑换礼品卡失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 查询兑换任务状态
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getRedemptionTaskStatus(Request $request): JsonResponse
    {
        try {
            $taskId = $request->input('task_id');
            if (empty($taskId)) {
                return response()->json([
                    'code' => 400,
                    'message' => '任务ID不能为空',
                    'data' => null,
                ]);
            }

            $result = $this->giftCardExchangeService->giftCardApiClient->getRedemptionTaskStatus($taskId);

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('查询兑换任务状态失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '查询兑换任务状态失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取查卡历史记录
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getCardQueryHistory(Request $request): JsonResponse
    {
        try {
            $params = [
                'keyword' => $request->input('keyword', ''),
                'start_time' => $request->input('start_time', ''),
                'end_time' => $request->input('end_time', ''),
                'page' => $request->input('page', 1),
                'page_size' => $request->input('page_size', 20)
            ];

            $result = $this->giftCardExchangeService->giftCardApiClient->getCardQueryHistory(
                $params['keyword'],
                $params['start_time'],
                $params['end_time'],
                $params['page'],
                $params['page_size']
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('获取查卡历史记录失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取查卡历史记录失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 获取兑换历史记录
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getRedemptionHistory(Request $request): JsonResponse
    {
        try {
            $params = [
                'keyword' => $request->input('keyword', ''),
                'start_time' => $request->input('start_time', ''),
                'end_time' => $request->input('end_time', ''),
                'page' => $request->input('page', 1),
                'page_size' => $request->input('page_size', 20)
            ];

            $result = $this->giftCardExchangeService->giftCardApiClient->getRedemptionHistory(
                $params['keyword'],
                $params['start_time'],
                $params['end_time'],
                $params['page'],
                $params['page_size']
            );

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('获取兑换历史记录失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '获取兑换历史记录失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 查询兑换任务状态
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getExchangeTaskStatus(Request $request): JsonResponse
    {
        try {
            $requestId = $request->input('request_id');

            if (empty($requestId)) {
                return response()->json([
                    'code' => 400,
                    'message' => '请求ID不能为空',
                    'data' => null,
                ]);
            }

            // 这里可以通过Redis或数据库查询任务状态
            // 暂时返回基本信息
            return response()->json([
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'request_id' => $requestId,
                    'status' => 'processing', // queued, processing, completed, failed
                    'message' => '任务正在处理中'
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('查询兑换任务状态失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '查询兑换任务状态失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }

    /**
     * 测试队列功能
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function testQueue(Request $request): JsonResponse
    {
        try {
            $testMessage = $request->input('message', 'TESTCARD123 /1');
            $requestId = uniqid('test_', true);

            Log::channel('gift_card_exchange')->info('测试队列功能', [
                'request_id' => $requestId,
                'message' => $testMessage
            ]);

            // 分发测试任务到队列
            ProcessGiftCardExchangeJob::dispatch($testMessage, $requestId);

            return response()->json([
                'code' => 0,
                'message' => '测试任务已加入队列',
                'data' => [
                    'request_id' => $requestId,
                    'message' => $testMessage,
                    'queue_connection' => config('gift_card.queue.connection'),
                    'queue_name' => config('gift_card.queue.queue_name')
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('测试队列功能失败: ' . $e->getMessage());
            return response()->json([
                'code' => 500,
                'message' => '测试队列功能失败: ' . $e->getMessage(),
                'data' => null,
            ]);
        }
    }
}
