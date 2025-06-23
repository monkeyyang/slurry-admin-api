<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Gift\BatchGiftCardService;
use App\Services\Gift\GiftCardService;
use App\Models\ItunesTradeAccountLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Throwable;

class GiftCardController extends Controller
{
    protected BatchGiftCardService $batchService;
    protected GiftCardService      $giftCardService;

    public function __construct(BatchGiftCardService $batchService, GiftCardService $giftCardService)
    {
        $this->batchService    = $batchService;
        $this->giftCardService = $giftCardService;
    }

    /**
     * 兑换接口
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Throwable
     */
    public function bulkRedeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id'   => 'required|string|max:255',
            'msgid'     => 'nullable|string|max:255',
            'wxid'      => 'nullable|string|max:255',
            'codes'     => 'required|array|min:1|max:100', // 限制最多100张卡
            'codes.*'   => 'required|string|min:10|max:20', // 礼品卡码长度限制
            'card_type' => ['required', Rule::in(['fast', 'slow'])],
            'card_form' => ['required', Rule::in(['image', 'code'])],
        ], [
            'room_id.required'   => '群聊ID不能为空',
            'codes.required'     => '礼品卡码不能为空',
            'codes.min'          => '至少需要1张礼品卡',
            'codes.max'          => '最多支持100张礼品卡',
            'codes.*.required'   => '礼品卡码不能为空',
            'codes.*.min'        => '礼品卡码长度至少10位',
            'codes.*.max'        => '礼品卡码长度最多20位',
            'card_type.required' => '卡类型不能为空',
            'card_type.in'       => '卡类型必须是 fast 或 slow',
            'card_form.required' => '卡形式不能为空',
            'card_form.in'       => '卡形式必须是 image 或 code',
        ]);

        try {
            // 1. 检查重复的礼品卡码
            $duplicateCodes = $this->checkDuplicateCodes($validated['codes']);

            // 过滤掉重复的卡
            $validCodes = array_diff($validated['codes'], $duplicateCodes);
            if (empty($validCodes)) {
                return response()->json([
                    'code'    => 400,
                    'message' => '所有礼品卡码都已处理过，无新卡需要兑换',
                    'data'    => [
                        'duplicate_codes'  => $duplicateCodes,
                        'total_duplicates' => count($duplicateCodes)
                    ]
                ], 400);
            }

            // 2. 开始批量兑换任务（只处理有效的卡）
            // 使用批量服务处理兑换（新的属性设置方式）
            $batchId = $this->batchService
                ->setGiftCardCodes(array_values($validCodes)) // 重新索引数组
                ->setRoomId($validated['room_id'])
                ->setCardType($validated['card_type'])
                ->setCardForm($validated['card_form'])
                ->setMsgId($validated['msgid'] ?? '')
                ->setWxId($validated['wxid'] ?? '')
                ->startBatchRedemption();

            $response = [
                'code'    => 0,
                'message' => '批量兑换任务已开始处理',
                'data'    => [
                    'batch_id'     => $batchId,
                    'total_cards'  => count($validCodes),
                    'progress_url' => route('giftcards.batch.progress', ['batchId' => $batchId])
                ]
            ];

            // 如果有重复的卡，在响应中说明
            if (!empty($duplicateCodes)) {
                $response['message']                    = '批量兑换任务已开始处理，已跳过重复的卡';
                $response['data']['skipped_duplicates'] = $duplicateCodes;
                $response['data']['skipped_count']      = count($duplicateCodes);
                $response['data']['original_total']     = count($validated['codes']);
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('批量兑换任务启动失败', [
                'error'   => $e->getMessage(),
                'codes'   => $validated['codes'],
                'room_id' => $validated['room_id']
            ]);

            return response()->json([
                'code'    => 500,
                'message' => '批量兑换任务启动失败: ' . $e->getMessage(),
                'data'    => null
            ], 500);
        }
    }

    /**
     * 检查重复的礼品卡码
     */
    private function checkDuplicateCodes(array $codes): array
    {
        // 检查数据库中是否已存在这些礼品卡的处理记录
        $existingCodes = ItunesTradeAccountLog::whereIn('code', $codes)
            ->whereIn('status', [
                ItunesTradeAccountLog::STATUS_PENDING,
                ItunesTradeAccountLog::STATUS_SUCCESS
            ])
            ->pluck('code')
            ->toArray();

        if (!empty($existingCodes)) {
            Log::warning('检测到重复的礼品卡码', [
                'duplicate_codes' => $existingCodes,
                'total_submitted' => count($codes),
                'duplicate_count' => count($existingCodes)
            ]);
        }

        return $existingCodes;
    }

    public function batchProgress(string $batchId): JsonResponse
    {
        try {
            $progress = $this->batchService->getBatchProgress($batchId);

            if (empty($progress)) {
                return response()->json([
                    'code'    => 404,
                    'message' => '批量任务不存在',
                    'data'    => null
                ], 404);
            }

            $errors = $this->batchService->getBatchErrors($batchId);

            return response()->json([
                'code'    => 0,
                'message' => 'success',
                'data'    => [
                    'batch_id'            => $batchId,
                    'status'              => $progress['status'],
                    'processed'           => (int)$progress['processed'],
                    'total'               => (int)$progress['total'],
                    'success'             => (int)$progress['success'],
                    'failed'              => (int)$progress['failed'],
                    'progress_percentage' => $progress['total'] > 0 ? round(($progress['processed'] / $progress['total']) * 100, 2) : 0,
                    'errors'              => $errors,
                    'created_at'          => $progress['created_at'] ?? null,
                    'updated_at'          => $progress['updated_at'] ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '获取批量任务进度失败: ' . $e->getMessage(),
                'data'    => null
            ], 500);
        }
    }

    /**
     * 获取批量任务的详细结果
     */
    public function batchResults(string $batchId): JsonResponse
    {
        try {
            $summary = $this->batchService->getBatchSummary($batchId);

            if (empty($summary)) {
                return response()->json([
                    'code'    => 404,
                    'message' => '批量任务不存在',
                    'data'    => null
                ], 404);
            }

            return response()->json([
                'code'    => 0,
                'message' => 'success',
                'data'    => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '获取批量任务结果失败: ' . $e->getMessage(),
                'data'    => null
            ], 500);
        }
    }

    /**
     * 取消批量任务
     */
    public function cancelBatch(string $batchId): JsonResponse
    {
        try {
            $result = $this->batchService->cancelBatch($batchId);

            if (!$result) {
                return response()->json([
                    'code'    => 404,
                    'message' => '批量任务不存在或无法取消',
                    'data'    => null
                ], 404);
            }

            return response()->json([
                'code'    => 0,
                'message' => '批量任务已取消',
                'data'    => ['batch_id' => $batchId]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code'    => 500,
                'message' => '取消批量任务失败: ' . $e->getMessage(),
                'data'    => null
            ], 500);
        }
    }
}
