<?php

namespace App\Jobs;

use App\Models\CardQueryRecord;
use App\Models\CardQueryRule;
use App\Services\CardQueryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProcessCardQueryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 失败尝试次数
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     */
    public int $timeout = 300;

    /**
     * 创建新的任务实例
     */
    public function __construct()
    {
        // 构造函数不需要参数
    }

    /**
     * 执行任务
     */
    public function handle(CardQueryService $cardQueryService): void
    {
        try {
            Log::info("开始执行卡密查询队列任务");

            // 1. 先同步新卡密记录
            $this->syncNewCardRecords();

            // 2. 获取查询规则
            $rule = CardQueryRule::getActiveRule();
            if (!$rule) {
                Log::error("卡密查询任务: 未找到有效的查询规则");
                return;
            }

            // 3. 执行第一阶段查询 (query_count = 0)
            $this->processFirstStageQuery($cardQueryService, $rule);

            // 4. 执行第二阶段查询 (query_count = 1)
//            $this->processSecondStageQuery($cardQueryService, $rule);

            Log::info("卡密查询队列任务完成");

        } catch (\Exception $e) {
            Log::error("卡密查询队列任务失败: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    /**
     * 同步新卡密记录到 card_query_records 表
     */
    private function syncNewCardRecords(): void
    {
        try {
            // 获取最后同步的记录ID
            $lastId = DB::table('system_variables')
                ->where('name', 'last_synced_card_bill_id')
                ->value('value') ?? 0;

            Log::info("开始同步卡密记录，上次同步ID: {$lastId}");

            // 设置查询的时间范围限制
            $cutoffDateTime = Carbon::parse('2025-05-18 20:30:00');
            Log::info("设置查询时间范围限制: {$cutoffDateTime->toDateTimeString()}");

            // 从mr_room_bill获取新记录
            $newRecords = DB::connection('mysql_card')
                ->table('mr_room_bill')
                ->where('id', '>', $lastId)
                ->where('remark', 'iTunes')
                ->whereNotNull('code')
                ->where('code', '!=', '')
                ->where('is_del', 0)
                ->where('created_at','>', $cutoffDateTime)
                ->orderBy('id')
                ->limit(1000) // 限制处理量
                ->get();

            if ($newRecords->isEmpty()) {
                Log::info("没有新的卡密记录需要同步");
                return;
            }

            Log::info("找到 " . $newRecords->count() . " 条新卡密记录需要同步");

            $insertCount = 0;
            $maxId = $lastId;

            foreach ($newRecords as $record) {
                // 更新最大ID
                $maxId = max($maxId, $record->id);

                // 检查是否已存在
                $exists = CardQueryRecord::where('card_code', $record->code)->exists();
                if (!$exists) {
                    // 创建新记录
                    CardQueryRecord::create([
                        'card_code' => $record->code,
                        'query_count' => 0,
                        'is_valid' => false,
                        'is_completed' => false,
                        'first_query_at' => null,
                        'second_query_at' => null,
                        'next_query_at' => null,
                        'response_data' => null,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                    $insertCount++;
                }
            }

            // 更新最后同步的ID
            DB::table('system_variables')
                ->updateOrInsert(
                    ['name' => 'last_synced_card_bill_id'],
                    ['value' => $maxId, 'updated_at' => Carbon::now()]
                );

            Log::info("成功同步 {$insertCount} 条新卡密记录，最新同步ID: {$maxId}");

        } catch (\Exception $e) {
            Log::error("同步卡密记录失败: " . $e->getMessage());
        }
    }

    /**
     * 处理第一阶段查询 (query_count = 0)
     */
    private function processFirstStageQuery(CardQueryService $cardQueryService, CardQueryRule $rule): void
    {
        try {
            $now = Carbon::now();
            $firstIntervalMinutes = $rule->first_interval;
            $cutoffTime = $now->copy()->subMinutes($firstIntervalMinutes);

            Log::info("开始第一阶段查询，查询截止时间: {$cutoffTime->toDateTimeString()}，间隔: {$firstIntervalMinutes} 分钟");

            // 查找符合条件的记录
            $records = CardQueryRecord::where('query_count', 0)
                ->where('is_completed', 0)
                ->where('created_at', '<=', $cutoffTime)
                ->limit(100) // 限制每次查询量
                ->pluck('card_code');

            if ($records->isEmpty()) {
                Log::info("没有需要进行第一阶段查询的卡密");
                return;
            }

            Log::info("找到 " . $records->count() . " 条记录需要进行第一阶段查询");

            // 准备查询参数
            $cardsToQuery = [];
            foreach ($records as $code) {
                $cardsToQuery[] = [
                    'id' => count($cardsToQuery),
                    'pin' => $code
                ];
            }

            // 执行查询
            $result = $cardQueryService->queryCards($cardsToQuery);
            Log::info('执行结果：', $result);
            if ($result['code'] === 0 && !empty($result['cards'])) {
                $this->sendMsgToWechat($result['cards']);
                Log::info("第一阶段查询成功，处理结果");
            } else {
                Log::error("第一阶段查询失败: " . ($result['message'] ?? '未知错误'));
            }
        } catch (\Exception $e) {
            Log::error("处理第一阶段查询失败: " . $e->getMessage());
        }
    }

    /**
     * 处理第二阶段查询 (query_count = 1)
     */
    private function processSecondStageQuery(CardQueryService $cardQueryService, CardQueryRule $rule): void
    {
        try {
            $now = Carbon::now();
            $secondIntervalMinutes = $rule->second_interval;
            $cutoffTime = $now->copy()->subMinutes($secondIntervalMinutes);

            Log::info("开始第二阶段查询，查询截止时间: {$cutoffTime->toDateTimeString()}，间隔: {$secondIntervalMinutes} 分钟");

            // 查找符合条件的记录
            $records = CardQueryRecord::where('query_count', 1)
                ->where('is_completed', 0)
                ->where('first_query_at', '<=', $cutoffTime)
                ->limit(100) // 限制每次查询量
                ->get();

            if ($records->isEmpty()) {
                Log::info("没有需要进行第二阶段查询的卡密");
                return;
            }

            Log::info("找到 " . $records->count() . " 条记录需要进行第二阶段查询");

            // 准备查询参数
            $cardsToQuery = [];
            foreach ($records as $record) {
                $cardsToQuery[$record->card_code] = [
                    'id' => count($cardsToQuery),
                    'pin' => $record->card_code
                ];
            }

            // 执行查询
            $result = $cardQueryService->queryCards($cardsToQuery);
            // 完成所有第二阶段查询的记录
            foreach ($records as $record) {
                if ($record->query_count == 2) {
                    $record->is_completed = 1;
                    $record->save();
                }
            }
            if ($result['code'] === 0 && !empty($result['cards'])) {
                Log::info("第二阶段查询成功，处理结果");
                $this->sendMsgToWechat($result['cards']);

            } else {
                Log::error("第二阶段查询失败: " . ($result['message'] ?? '未知错误'));
            }

        } catch (\Exception $e) {
            Log::error("处理第二阶段查询失败: " . $e->getMessage());
        }
    }

    /**
     * 发送通知
     *
     * @param $cards
     * @return void
     */
    private function sendMsgToWechat($cards): void
    {
        if(!empty($cards)) {
            $content = "⚠️检查卡密异常/被赎回：\n⚠️异常卡密撤回账单：\n";
            foreach($cards as $k => $item) {
                $content .= $item['code']."[{$item['balance']}]";
                if(($k+1) < count($cards)) $content.="\n";
            }
            Log::info($content);
            send_msg_to_wechat('44769140035@chatroom', $content);
        }
    }
}
