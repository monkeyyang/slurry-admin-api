<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGiftCardExchangeJob;
use App\Services\GiftCardApiClient;
use App\Services\GiftCardExchangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\GiftCardExchangeRecord;

class GiftCardExchangeController extends Controller
{
    protected GiftCardExchangeService $giftCardExchangeService;

    public function __construct(GiftCardExchangeService $giftCardExchangeService)
    {
        $this->giftCardExchangeService = $giftCardExchangeService;
    }

    public function test(Request $request)
    {
        try {
            // input {"data":{"at_user_list":[],"from_wxid":"wxid_dvxt3biiotfz12","is_pc":0,"msg":"XJL6FNL4XHY5427X+500#擦拭","msgid":"7732359006730393642","room_wxid":"56204186056@chatroom","timestamp":1748189465,"to_wxid":"56204186056@chatroom","wx_type":1},"type":"MT_RECV_TEXT_MSG","client_id":1,"wxid":"wxid_aiv8hxjw87z012"}
            $roomId = $request->input('room_wxid','');
            $msgId = $request->input('msgid', '');
            $wxId = $request->input('from_wxid', '');
            $message = $request->input('msg', '');

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

            // 验证消息格式
            $parseResult = $this->giftCardExchangeService->parseMessage($message);
            if (!$parseResult) {
                return response()->json([
                    'code' => 400,
                    'message' => '消息格式无效，正确格式：卡号 /类型（如：XQPD5D7KJ8TGZT4L /1）',
                    'data' => null,
                ]);
            }

            // 处理兑换消息
            $giftCardApiClient = new GiftCardApiClient();
            $giftCardExchangeService = new GiftCardExchangeService($giftCardApiClient);
            $giftCardExchangeService->setRoomId($roomId);
            $giftCardExchangeService->setWxId($wxId);
            $giftCardExchangeService->setMsgid($msgId);

            $result = $giftCardExchangeService->processExchangeMessage($message);

            var_dump($result);exit;

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
     * 处理礼品卡兑换消息
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function processExchangeMessage(Request $request): JsonResponse
    {
        try {
            $message = $request->input('message');

            if (empty($message)) {
                return response()->json([
                    'code' => 400,
                    'message' => '消息不能为空',
                    'data' => null,
                ]);
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

            // 生成请求ID用于追踪
            $requestId = uniqid('exchange_', true);

            Log::channel('gift_card_exchange')->info('收到兑换请求，加入队列处理', [
                'request_id' => $requestId,
                'message' => $message,
                'card_number' => $parseResult['card_number'],
                'card_type' => $parseResult['card_type']
            ]);

            // 将任务加入队列
            ProcessGiftCardExchangeJob::dispatch($message, $requestId);

            return response()->json([
                'code' => 0,
                'message' => '兑换请求已接收，正在队列中处理',
                'data' => [
                    'request_id' => $requestId,
                    'card_number' => $parseResult['card_number'],
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
