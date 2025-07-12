<?php

namespace App\Http\Controllers;

use App\Models\WechatMessageLog;
use App\Models\WechatRooms;
use App\Services\WechatMessageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WechatMonitorController extends Controller
{
    protected $wechatMessageService;

    public function __construct(WechatMessageService $wechatMessageService)
    {
        $this->wechatMessageService = $wechatMessageService;
    }

    /**
     * 获取监控面板首页
     */
    public function index(Request $request)
    {
        return view('wechat.monitor.index');
    }

    /**
     * 获取实时统计数据
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $roomId    = $request->get('room_id');
            $startDate = $request->get('start_date');
            $endDate   = $request->get('end_date');

            $stats = $this->wechatMessageService->getMessageStats($roomId, $startDate, $endDate);

            // 获取今日统计
            $todayStats = $this->wechatMessageService->getMessageStats($roomId, now()->startOfDay(), now()->endOfDay());

            // 获取近7天统计
            $weekStats = $this->wechatMessageService->getMessageStats($roomId, now()->subDays(7), now());

            // 获取队列状态
            $queueStats = $this->getQueueStats();

            return response()->json([
                'success' => true,
                'data'    => [
                    'overall' => $stats,
                    'today'   => $todayStats,
                    'week'    => $weekStats,
                    'queue'   => $queueStats
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('获取监控统计失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => '获取统计数据失败'
            ], 500);
        }
    }

    /**
     * 获取消息列表
     */
    public function getMessages(Request $request): JsonResponse
    {
        try {
            $query = WechatMessageLog::query();

            // 筛选条件
            if ($request->has('room_id') && $request->room_id) {
                $query->where('room_id', $request->room_id);
            }

            if ($request->has('status') && $request->status !== '') {
                $query->where('status', $request->status);
            }

            if ($request->has('message_type') && $request->message_type) {
                $query->where('message_type', $request->message_type);
            }

            if ($request->has('from_source') && $request->from_source) {
                $query->where('from_source', $request->from_source);
            }

            if ($request->has('start_date') && $request->start_date) {
                $query->where('created_at', '>=', $request->start_date);
            }

            if ($request->has('end_date') && $request->end_date) {
                $query->where('created_at', '<=', $request->end_date);
            }

            // 排序和分页
            $query->orderBy('created_at', 'desc');
            $pageSize = $request->get('page_size', 20);
            $messages = $query->paginate($pageSize);

            // 处理返回数据，添加群聊名称
            $data         = $messages->toArray();
            $data['data'] = collect($messages->items())->map(function ($message) {
                return [
                    'id'                => $message->id,
                    'room_id'           => $message->room_id,
                    'room_name'         => $message->formatted_room_name, // 使用新添加的访问器
                    'message_type'      => $message->message_type,
                    'message_type_text' => $message->message_type_text,
                    'content'           => $message->content,
                    'content_preview'   => $message->content_preview,
                    'from_source'       => $message->from_source,
                    'status'            => $message->status,
                    'status_text'       => $message->status_text,
                    'retry_count'       => $message->retry_count,
                    'max_retry'         => $message->max_retry,
                    'error_message'     => $message->error_message,
                    'sent_at'           => $message->sent_at ? $message->sent_at->format('Y-m-d H:i:s') : null,
                    'created_at'        => $message->created_at->format('Y-m-d H:i:s'),
                    'updated_at'        => $message->updated_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $data
            ]);
        } catch (\Exception $e) {
            Log::error('获取消息列表失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => '获取消息列表失败'
            ], 500);
        }
    }

    /**
     * 重试失败的消息
     */
    public function retryMessage(Request $request): JsonResponse
    {
        try {
            $messageId = $request->get('message_id');

            if (!$messageId) {
                return response()->json([
                    'success' => false,
                    'message' => '消息ID不能为空'
                ], 400);
            }

            $result = $this->wechatMessageService->retryMessage($messageId);

            if ($result) {
                return response()->json([
                    'success' => true,
                    'message' => '消息重试成功'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => '消息重试失败'
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('重试消息失败', [
                'error'      => $e->getMessage(),
                'message_id' => $request->get('message_id')
            ]);
            return response()->json([
                'success' => false,
                'message' => '重试失败'
            ], 500);
        }
    }

    /**
     * 批量重试失败的消息
     */
    public function batchRetry(Request $request): JsonResponse
    {
        try {
            $messageIds = $request->get('message_ids', []);

            if (empty($messageIds)) {
                return response()->json([
                    'success' => false,
                    'message' => '请选择要重试的消息'
                ], 400);
            }

            $successCount = 0;
            $failedCount  = 0;

            foreach ($messageIds as $messageId) {
                $result = $this->wechatMessageService->retryMessage($messageId);
                if ($result) {
                    $successCount++;
                } else {
                    $failedCount++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "批量重试完成：成功 {$successCount} 条，失败 {$failedCount} 条",
                'data'    => [
                    'success_count' => $successCount,
                    'failed_count'  => $failedCount
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('批量重试消息失败', [
                'error'       => $e->getMessage(),
                'message_ids' => $request->get('message_ids')
            ]);
            return response()->json([
                'success' => false,
                'message' => '批量重试失败'
            ], 500);
        }
    }

    /**
     * 获取房间列表
     */
    public function getRooms(Request $request): JsonResponse
    {
        try {
            // 从消息日志中获取所有使用过的房间ID
            $logRooms = WechatMessageLog::select('room_id')
                ->selectRaw('COUNT(*) as message_count')
                ->groupBy('room_id')
                ->get();

            $roomList = [];

            // 处理每个房间，获取中文名称
            foreach ($logRooms as $logRoom) {
                $roomId = $logRoom->room_id;

                // 从MrRoom模型获取房间信息
                $roomInfo = \App\Models\MrRoom::where('room_id', $roomId)->first();

                $roomName = $roomInfo ? $roomInfo->room_name : $this->formatRoomId($roomId);

                $roomList[] = [
                    'room_id'       => $roomId,
                    'name'          => $roomName,
                    'type'          => 'message',
                    'status'        => 'active',
                    'message_count' => $logRoom->message_count
                ];
            }

            // 按消息数量降序排列
            usort($roomList, function ($a, $b) {
                return $b['message_count'] - $a['message_count'];
            });

            return response()->json([
                'success' => true,
                'data'    => $roomList
            ]);
        } catch (\Exception $e) {
            Log::error('获取房间列表失败', [
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => '获取房间列表失败'
            ], 500);
        }
    }

    /**
     * 格式化房间ID显示
     */
    private function formatRoomId(string $roomId): string
    {
        // 如果包含@chatroom，提取前面的数字部分
        if (str_contains($roomId, '@chatroom')) {
            $parts = explode('@', $roomId);
            return $parts[0] ?? $roomId;
        }

        return $roomId;
    }

    /**
     * 发送测试消息
     */
    public function sendTestMessage(Request $request): JsonResponse
    {
        try {
            $roomId   = $request->get('room_id');
            $content  = $request->get('content', '测试消息 - ' . now()->format('Y-m-d H:i:s'));
            $useQueue = $request->get('use_queue', true);

            if (!$roomId) {
                return response()->json([
                    'success' => false,
                    'message' => '房间ID不能为空'
                ], 400);
            }

            $result = $this->wechatMessageService->sendMessage(
                $roomId,
                $content,
                WechatMessageLog::TYPE_TEXT,
                'monitor-test',
                $useQueue
            );

            if ($result !== false) {
                return response()->json([
                    'success' => true,
                    'message' => '测试消息发送成功',
                    'data'    => [
                        'message_id' => $result,
                        'use_queue'  => $useQueue
                    ]
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => '测试消息发送失败'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('发送测试消息失败', [
                'error'   => $e->getMessage(),
                'room_id' => $request->get('room_id')
            ]);
            return response()->json([
                'success' => false,
                'message' => '发送测试消息失败'
            ], 500);
        }
    }

    /**
     * 获取队列状态
     */
    private function getQueueStats(): array
    {
        try {
            // 获取队列中的任务数量
            $queueSize = DB::table('jobs')
                ->where('queue', config('wechat.queue.name', 'wechat-message'))
                ->count();

            // 获取失败的任务数量
            $failedJobs = DB::table('failed_jobs')
                ->where('payload', 'like', '%SendWechatMessageJob%')
                ->count();

            return [
                'queue_size'  => $queueSize,
                'failed_jobs' => $failedJobs,
                'queue_name'  => config('wechat.queue.name', 'wechat-message')
            ];
        } catch (\Exception $e) {
            Log::error('获取队列状态失败', [
                'error' => $e->getMessage()
            ]);
            return [
                'queue_size'  => 0,
                'failed_jobs' => 0,
                'queue_name'  => 'unknown'
            ];
        }
    }

    /**
     * 获取系统配置
     */
    public function getConfig(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'queue_enabled'    => config('wechat.queue.enabled', true),
                'queue_name'       => config('wechat.queue.name', 'wechat-message'),
                'monitor_enabled'  => config('wechat.monitor.enabled', true),
                'refresh_interval' => config('wechat.monitor.refresh_interval', 5000),
                'page_size'        => config('wechat.monitor.page_size', 20)
            ]
        ]);
    }
}
