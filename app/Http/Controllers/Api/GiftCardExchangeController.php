<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

            Log::info('收到兑换请求: ' . $message);
            $result = $this->giftCardExchangeService->processExchangeMessage($message);

            if ($result['success']) {
                return response()->json([
                    'code' => 0,
                    'message' => 'ok',
                    'data' => $result['data'],
                ]);
            } else {
                return response()->json([
                    'code' => 500,
                    'message' => $result['message'],
                    'data' => null,
                ]);
            }
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
}
