<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\CardQueryRule;
use App\Models\CardQueryRecord;
use Carbon\Carbon;

class CardQueryService
{
    /**
     * 批量查询卡密
     * 
     * @param int $batchSize 每批处理的记录数量
     * @param string|null $cutoffDate 截止日期，只查询在此日期之后的数据
     * @return array 包含状态码、消息和数据的数组
     */
    public function batchQueryCards(int $batchSize = 100, string $cutoffDate = null): array
    {
        try {
            // 设置查询的时间范围限制
            $cutoffDateTime = $cutoffDate ? Carbon::parse($cutoffDate) : Carbon::parse('2025-05-13 21:00:00');
            Log::info("设置查询时间范围限制: {$cutoffDateTime->toDateTimeString()}");
            
            // 获取当前活跃的查询规则
            $rule = CardQueryRule::getActiveRule();
            if (!$rule) {
                return [
                    'code' => 400,
                    'message' => '未找到有效的查询规则',
                    'data' => null
                ];
            }
            
            // 使用分块处理避免一次性加载所有数据
            $cardsToQuery = [];
            $now = Carbon::now();
            $totalProcessed = 0;
            
            // 构建基础查询
            $baseQuery = DB::connection('mysql_card')
                        ->table('mr_room_bill')
                        ->whereNotNull('code')
                        ->where('code', '!=', '')
                        ->where('remark', 'iTunes')
                        ->where('is_del', 0);
                        
            // 添加日期筛选条件
            // 注意：这里假设mr_room_bill表有created_at或类似的日期字段
            // 请根据实际表结构调整字段名
            $baseQuery = $baseQuery->where(function($query) use ($cutoffDateTime) {
                $query->where('created_at', '>=', $cutoffDateTime)
                      ->orWhere('updated_at', '>=', $cutoffDateTime)
                      ->orWhere('add_time', '>=', $cutoffDateTime); // 适配不同可能的时间字段名
            });
            
            // 查询总数以供日志记录
            $totalCount = (clone $baseQuery)->count();
            var_dump($totalCount);exit;
            
            Log::info("开始卡密查询，符合时间条件的数据库中共有 {$totalCount} 条记录");
            
            if ($totalCount == 0) {
                return [
                    'code' => 0,
                    'message' => "没有在 {$cutoffDateTime->toDateTimeString()} 之后的卡密数据",
                    'data' => []
                ];
            }
            
            // 使用分块查询处理大量数据
            $baseQuery->select('id', 'code')
                ->orderBy('id')
                ->chunk($batchSize, function($cardData) use (&$cardsToQuery, $now, &$totalProcessed) {
                    foreach ($cardData as $card) {
                        // 增加计数
                        $totalProcessed++;
                        
                        // 只处理前100条记录，避免API调用过多
                        if (count($cardsToQuery) >= 100) {
                            continue;
                        }
                        
                        // 检查是否已有查询记录
                        $record = CardQueryRecord::where('card_code', $card->code)->first();
                        
                        if (!$record) {
                            // 新卡密，创建记录
                            $record = CardQueryRecord::create([
                                'card_code' => $card->code,
                                'query_count' => 0,
                            ]);
                            
                            $cardsToQuery[] = [
                                'id' => count($cardsToQuery),
                                'pin' => $card->code
                            ];
                        } else if (!$record->is_completed && 
                                ($record->next_query_at === null || $record->next_query_at <= $now)) {
                            // 已有记录但需要再次查询
                            $cardsToQuery[] = [
                                'id' => count($cardsToQuery),
                                'pin' => $card->code
                            ];
                        }
                        // 已完成查询或未到查询时间的跳过
                    }
                    
                    // 在每个块处理后清理内存
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                });
            
            Log::info("处理完成，总共处理了 {$totalProcessed} 条记录，需要查询的卡密数量: " . count($cardsToQuery));
            
            if (empty($cardsToQuery)) {
                return [
                    'code' => 0,
                    'message' => '所有卡密已完成查询或未到查询时间',
                    'data' => []
                ];
            }
            
            // 准备请求参数
            $requestParams = [
                'list' => $cardsToQuery
            ];

            // 调用外部API查询卡密
            $response = Http::post('http://47.76.200.188:8080/api/batch_query/new', $requestParams);
            
            // 处理API响应
            if ($response->successful()) {
                $responseData = $response->json();
                
                // 保存请求参数以便输出
                $responseData['request_params'] = $requestParams;
                
                // 更新查询记录
                foreach ($responseData['data'] ?? [] as $result) {
                    $cardCode = $result['pin'] ?? null;
                    $isValid = isset($result['status']) && $result['status'] === 'valid';
                    
                    if ($cardCode) {
                        $record = CardQueryRecord::where('card_code', $cardCode)->first();
                        
                        if ($record) {
                            $record->query_count += 1;
                            
                            if ($record->query_count == 1) {
                                $record->first_query_at = now();
                            } else {
                                $record->second_query_at = now();
                            }
                            
                            $record->is_valid = $isValid;
                            $record->response_data = json_encode($result);
                            
                            // 如果卡密有效或者已查询两次，则标记为完成
                            if ($isValid || $record->query_count >= 2) {
                                $record->is_completed = true;
                            } else {
                                // 计算下次查询时间
                                $record->calculateNextQueryTime($rule);
                            }
                            
                            $record->save();
                        }
                    }
                }
                
                return [
                    'code' => 0,
                    'message' => "卡密查询成功，共查询 " . count($cardsToQuery) . " 条记录",
                    'data' => $responseData,
                    'request_params' => $requestParams
                ];
            } else {
                Log::error('卡密查询API调用失败: ' . $response->body());
                return [
                    'code' => 500,
                    'message' => '卡密查询API调用失败',
                    'error' => $response->body()
                ];
            }
            
        } catch (\Exception $e) {
            Log::error('批量查询卡密失败: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
                'code' => 500,
                'message' => '批量查询卡密失败: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
} 