<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Gift\BatchGiftCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GiftCardController extends Controller
{
    protected BatchGiftCardService $batchService;

    public function __construct(BatchGiftCardService $batchService)
    {
        $this->batchService = $batchService;
    }

    public function bulkRedeem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|string|max:255',
            'codes' => 'required|array|min:1|max:100', // 限制最多100张卡
            'codes.*' => 'required|string|min:10|max:20', // 礼品卡码长度限制
            'card_type' => ['required', Rule::in(['fast', 'slow'])],
            'card_form' => ['required', Rule::in(['image', 'code'])],
        ], [
            'room_id.required' => '群聊ID不能为空',
            'codes.required' => '礼品卡码不能为空',
            'codes.min' => '至少需要1张礼品卡',
            'codes.max' => '最多支持100张礼品卡',
            'codes.*.required' => '礼品卡码不能为空',
            'codes.*.min' => '礼品卡码长度至少10位',
            'codes.*.max' => '礼品卡码长度最多20位',
            'card_type.required' => '卡类型不能为空',
            'card_type.in' => '卡类型必须是 fast 或 slow',
            'card_form.required' => '卡形式不能为空',
            'card_form.in' => '卡形式必须是 image 或 code',
        ]);

        try {
            $batchId = $this->batchService->startBatchRedemption(
                $validated['codes'],
                $validated['room_id'],
                $validated['card_type'],
                $validated['card_form']
            );

            return response()->json([
                'code' => 0,
                'message' => '批量兑换任务已开始处理',
                'data' => [
                    'batch_id' => $batchId,
                    'total_cards' => count($validated['codes']),
                    'progress_url' => route('giftcards.batch.progress', ['batchId' => $batchId])
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '批量兑换任务启动失败: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    public function batchProgress(string $batchId): JsonResponse
    {
        try {
            $progress = $this->batchService->getBatchProgress($batchId);

            if (empty($progress)) {
                return response()->json([
                    'code' => 404,
                    'message' => '批量任务不存在',
                    'data' => null
                ], 404);
            }

            $errors = $this->batchService->getBatchErrors($batchId);

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => [
                    'batch_id' => $batchId,
                    'status' => $progress['status'],
                    'processed' => (int)$progress['processed'],
                    'total' => (int)$progress['total'],
                    'success' => (int)$progress['success'],
                    'failed' => (int)$progress['failed'],
                    'progress_percentage' => $progress['total'] > 0 ? round(($progress['processed'] / $progress['total']) * 100, 2) : 0,
                    'errors' => $errors,
                    'created_at' => $progress['created_at'] ?? null,
                    'updated_at' => $progress['updated_at'] ?? null,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '获取批量任务进度失败: ' . $e->getMessage(),
                'data' => null
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
                    'code' => 404,
                    'message' => '批量任务不存在',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'code' => 0,
                'message' => 'success',
                'data' => $summary
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '获取批量任务结果失败: ' . $e->getMessage(),
                'data' => null
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
                    'code' => 404,
                    'message' => '批量任务不存在或无法取消',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'code' => 0,
                'message' => '批量任务已取消',
                'data' => ['batch_id' => $batchId]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'code' => 500,
                'message' => '取消批量任务失败: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }
}
