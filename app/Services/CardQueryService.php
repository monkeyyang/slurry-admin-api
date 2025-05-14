<?php

namespace App\Services;

use App\Services\Gift\TaskStatusCheckerService;
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
            $cutoffDateTime = $cutoffDate ? Carbon::parse($cutoffDate) : Carbon::parse('2025-05-14 01:28:00');
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
            // 根据实际表结构调整时间字段名
            $baseQuery->where('created_at', '>=', $cutoffDateTime);

            // 查询总数以供日志记录
            $totalCount = (clone $baseQuery)->count();
//            var_dump('CardQueryService SQL: ' . $baseQuery->toSql(), $baseQuery->getBindings());exit;
            Log::info("开始卡密查询，符合时间条件的数据库中共有 {$totalCount} 条记录");

            if ($totalCount == 0) {
                return [
                    'code' => 0,
                    'message' => "没有在 {$cutoffDateTime->toDateTimeString()} 之后的卡密数据",
                    'data' => []
                ];
            }

            // 使用分块查询处理大量数据
            $cardData = $baseQuery->select('id', 'code')
                ->orderBy('id')
                ->get()->toArray();
            if(!empty($cardData)) {
                foreach($cardData as $card) {
                    foreach ($cardData as $card) {
                         // 增加计数
                         $totalProcessed++;

                         // 只处理前100条记录，避免API调用过多
//                         if (count($cardsToQuery) >= 100) {
//                             continue;
//                         }

                         // 检查是否已有查询记录
                         $record = CardQueryRecord::where('card_code', $card->code)->first();

                         if (!$record) {
                             // 新卡密，创建记录
                             $record = CardQueryRecord::create([
                                 'card_code' => $card->code,
                                 'query_count' => 0,
                             ]);

                             $cardsToQuery[$card->code] = [
                                 'id' => count($cardsToQuery),
                                 'pin' => $card->code
                             ];
                         } else if (!$record->is_completed &&
                                 ($record->next_query_at === null || $record->next_query_at <= $now)) {
                             // 已有记录但需要再次查询
                             $cardsToQuery[$card->code] = [
                                 'id' => count($cardsToQuery),
                                 'pin' => $card->code
                             ];
                         }
                         // 已完成查询或未到查询时间的跳过
                     }
                }
            }
//                echo 'SQL: ' . $baseQuery->toSql() . PHP_EOL;
//                print_r($baseQuery->getBindings());exit;
//            var_dump($cardData);exit;
                // ->chunk($batchSize, function($cardData) use (&$cardsToQuery, $now, &$totalProcessed) {
                //     foreach ($cardData as $card) {
                //         // 增加计数
                //         $totalProcessed++;

                //         // 只处理前100条记录，避免API调用过多
                //         if (count($cardsToQuery) >= 100) {
                //             continue;
                //         }

                //         // 检查是否已有查询记录
                //         $record = CardQueryRecord::where('card_code', $card->code)->first();

                //         if (!$record) {
                //             // 新卡密，创建记录
                //             $record = CardQueryRecord::create([
                //                 'card_code' => $card->code,
                //                 'query_count' => 0,
                //             ]);

                //             $cardsToQuery[] = [
                //                 'id' => count($cardsToQuery),
                //                 'pin' => $card->code
                //             ];
                //         } else if (!$record->is_completed &&
                //                 ($record->next_query_at === null || $record->next_query_at <= $now)) {
                //             // 已有记录但需要再次查询
                //             $cardsToQuery[] = [
                //                 'id' => count($cardsToQuery),
                //                 'pin' => $card->code
                //             ];
                //         }
                //         // 已完成查询或未到查询时间的跳过
                //     }

                //     // 在每个块处理后清理内存
                //     if (function_exists('gc_collect_cycles')) {
                //         gc_collect_cycles();
                //     }
                // });

            Log::info("处理完成，总共处理了 {$totalProcessed} 条记录，需要查询的卡密数量: " . count($cardsToQuery));

            if (empty($cardsToQuery)) {
                return [
                    'code' => 0,
                    'message' => '所有卡密已完成查询或未到查询时间',
                    'data' => []
                ];
            }

            // 准备请求参数 - 修复批量查询问题
            $newQuery = [];
            foreach ($cardsToQuery as $item) {
                $newQuery[] = $item;
            }
            $requestParams = [
                'list' => $newQuery
            ];

//            $cardsToQuery[0]['pin'] = 'XW5JNT5QJNTHW2X7';
//            $cards[0] = $cardsToQuery[0];
//            $cards[1] = $cardsToQuery[1];
//            $requestParams = [
//                'list' => $cards
//            ];
//            var_dump($requestParams);exit;
            // 调用外部API查询卡密
            $response = Http::post('http://47.76.200.188:8080/api/batch_query/new', $requestParams);

            // 处理API响应
            if ($response->successful()) {
                $responseData = $response->json();

                // 保存请求参数以便命令行显示
                $responseData['request_params'] = $requestParams;
                if (!empty($responseData['data']['task_id'])) {
                    $taskId = $responseData['data']['task_id'];
                    Log::info("获取到任务ID: {$taskId}，开始检查任务状态");

                    $checker = new TaskStatusCheckerService($taskId);
                    $taskResult = $checker->checkUntilCompleted();
                    if(!empty($taskResult)) {
                        Log::info("任务 {$taskId} 完成，开始处理结果");

                        $processedCount = 0;
                        $validCount = 0;
                        $invalidCount = 0;

                        // 创建卡密详细信息数组
                        $cardDetails = [];

                        // 先更新查询记录，以便获取正确的有效性判断结果
                        foreach ($taskResult['data']['items'] ?? [] as $item) {
                            // 解析result字段中的嵌套JSON
                            $parseJson = json_decode($item['result'], true);

                            if (!empty($item['data_id'])) {
                                $processedCount++;
                                $record = CardQueryRecord::where('card_code', $item['data_id'])->first();

                                if ($record) {
                                    $record->query_count += 1;

                                    if ($record->query_count == 1) {
                                        $record->first_query_at = now();
                                    } else {
                                        $record->second_query_at = now();
                                    }

                                    // 保存响应数据
                                    $record->response_data = $item['result'];

                                    // 修复余额判断逻辑
                                    $isValid = false;
                                    if (isset($parseJson['balance'])) {
                                        $balanceStr = $parseJson['balance'];
                                        $numericBalance = preg_replace('/[^0-9.]/', '', $balanceStr);
                                        $balanceValue = (float) $numericBalance;
                                        $isValid = ($balanceValue <= 0);
                                        Log::info("卡密余额解析: 原始值=[{$balanceStr}], 提取数值=[{$numericBalance}], 转换结果=[{$balanceValue}], 有效性=[" . ($isValid ? '有效' : '无效') . "]");
                                    }

                                    $record->is_valid = $isValid;

                                    if ($isValid) {
                                        $validCount++;
                                    } else {
                                        $invalidCount++;
                                    }

                                    // 如果卡密有效或者已查询两次，则标记为完成
                                    if ($isValid || $record->query_count >= 2) {
                                        $record->is_completed = true;
                                    } else {
                                        // 计算下次查询时间
                                        $record->calculateNextQueryTime($rule);
                                    }

                                    $record->save();
                                    Log::info("保存卡密记录: ID={$record->id}, 卡号={$record->card_code}, 查询次数={$record->query_count}, 有效性=" . ($record->is_valid ? '有效' : '无效'));
                                }
                            }
                        }

                        // 再构建详细信息数组，保证is_valid状态正确
                        foreach ($taskResult['data']['items'] ?? [] as $item) {
                            $parseJson = json_decode($item['result'], true);
                            $balance = isset($parseJson['balance']) ? $parseJson['balance'] : 'N/A';
                            $validation = isset($parseJson['validation']) ? $parseJson['validation'] : 'N/A';

                            // 获取已更新的记录
                            $record = CardQueryRecord::where('card_code', $item['data_id'])->first();

                            $cardDetails[] = [
                                'card_code' => $item['data_id'],
                                'balance' => $balance,
                                'balance_value' => isset($parseJson['balance']) ? preg_replace('/[^0-9.]/', '', $parseJson['balance']) : 0,
                                'validation' => $validation,
                                'is_valid' => $record ? $record->is_valid : false, // 使用数据库记录的有效性
                                'query_count' => $record ? $record->query_count : 0
                            ];
                        }

                        // 保存到日志方便调试
                        Log::info("卡密详细信息: " . json_encode($cardDetails));

                        Log::info("卡密处理完成，总共处理了 {$processedCount} 条记录，有效: {$validCount}，无效: {$invalidCount}");

                        return [
                            'code' => 0,
                            'message' => "卡密查询成功，共查询 " . count($cardsToQuery) . " 条记录，处理结果: {$processedCount} 条",
                            'data' => $responseData,
                            'cards' => $cardDetails,
                            'stats' => [
                                'total' => $processedCount,
                                'valid' => $validCount,
                                'invalid' => $invalidCount
                            ]
                        ];
                    } else {
                        Log::error("任务 {$taskId} 查询结果为空");
                        return [
                            'code' => 500,
                            'message' => "任务查询结果为空",
                            'data' => $responseData
                        ];
                    }
                }

                // 如果没有任务ID，返回原始响应
                return [
                    'code' => 0,
                    'message' => "卡密查询已提交，但没有返回任务ID",
                    'data' => $responseData
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

    /**
     * 查询指定卡密列表
     *
     * @param array $cardsToQuery 要查询的卡密列表
     * @return array 查询结果
     */
    public function queryCards(array $cardsToQuery): array
    {
        try {
            if (empty($cardsToQuery)) {
                return [
                    'code' => 0,
                    'message' => "没有需要查询的卡密",
                    'data' => []
                ];
            }

            Log::info("准备查询 " . count($cardsToQuery) . " 张卡密");

            // 准备请求参数
            $requestParams = [
                'list' => $cardsToQuery
            ];

            // 调用外部API查询卡密
            $response = Http::post('http://47.76.200.188:8080/api/batch_query/new', $requestParams);

            // 处理API响应
            if ($response->successful()) {
                $responseData = $response->json();

                // 保存请求参数以便命令行显示
                $responseData['request_params'] = $requestParams;
                if (!empty($responseData['data']['task_id'])) {
                    $taskId = $responseData['data']['task_id'];
                    Log::info("获取到任务ID: {$taskId}，开始检查任务状态");

                    $checker = new TaskStatusCheckerService($taskId);
                    $taskResult = $checker->checkUntilCompleted();
                    if(!empty($taskResult)) {
                        Log::info("任务 {$taskId} 完成，开始处理结果");

                        $processedCount = 0;
                        $validCount = 0;
                        $invalidCount = 0;

                        // 创建卡密详细信息数组
                        $cardDetails = [];

                        // 先更新查询记录，以便获取正确的有效性判断结果
                        foreach ($taskResult['data']['items'] ?? [] as $item) {
                            // 解析result字段中的嵌套JSON
                            $parseJson = json_decode($item['result'], true);

                            if (!empty($item['data_id'])) {
                                $processedCount++;
                                $record = CardQueryRecord::where('card_code', $item['data_id'])->first();

                                if ($record) {
                                    $record->query_count += 1;

                                    if ($record->query_count == 1) {
                                        $record->first_query_at = now();
                                    } else {
                                        $record->second_query_at = now();
                                    }

                                    // 保存响应数据
                                    $record->response_data = $item['result'];

                                    // 修复余额判断逻辑
                                    $isValid = false;
                                    if (isset($parseJson['balance'])) {
                                        $balanceStr = $parseJson['balance'];
                                        $numericBalance = preg_replace('/[^0-9.]/', '', $balanceStr);
                                        $balanceValue = (float) $numericBalance;
                                        $isValid = ($balanceValue <= 0);
                                        Log::info("卡密余额解析: 原始值=[{$balanceStr}], 提取数值=[{$numericBalance}], 转换结果=[{$balanceValue}], 有效性=[" . ($isValid ? '有效' : '无效') . "]");
                                    }

                                    $record->is_valid = $isValid;

                                    if ($isValid) {
                                        $validCount++;
                                    } else {
                                        $invalidCount++;
                                    }

                                    // 如果卡密有效，则标记为完成
                                    if ($isValid) {
                                        $record->is_completed = true;
                                    }

                                    $record->save();
                                    Log::info("保存卡密记录: ID={$record->id}, 卡号={$record->card_code}, 查询次数={$record->query_count}, 有效性=" . ($record->is_valid ? '有效' : '无效'));
                                }
                            }
                        }

                        // 再构建详细信息数组，保证is_valid状态正确
                        foreach ($taskResult['data']['items'] ?? [] as $item) {
                            $parseJson = json_decode($item['result'], true);
                            $balance = isset($parseJson['balance']) ? $parseJson['balance'] : 'N/A';
                            $validation = isset($parseJson['validation']) ? $parseJson['validation'] : 'N/A';

                            // 获取已更新的记录
                            $record = CardQueryRecord::where('card_code', $item['data_id'])->first();

                            $cardDetails[] = [
                                'card_code' => $item['data_id'],
                                'balance' => $balance,
                                'balance_value' => isset($parseJson['balance']) ? preg_replace('/[^0-9.]/', '', $parseJson['balance']) : 0,
                                'validation' => $validation,
                                'is_valid' => $record ? $record->is_valid : false, // 使用数据库记录的有效性
                                'query_count' => $record ? $record->query_count : 0
                            ];
                        }

                        // 保存到日志方便调试
                        Log::info("卡密详细信息: " . json_encode($cardDetails));

                        Log::info("卡密处理完成，总共处理了 {$processedCount} 条记录，有效: {$validCount}，无效: {$invalidCount}");

                        return [
                            'code' => 0,
                            'message' => "卡密查询成功，共查询 " . count($cardsToQuery) . " 条记录，处理结果: {$processedCount} 条",
                            'data' => $responseData,
                            'cards' => $cardDetails,
                            'stats' => [
                                'total' => $processedCount,
                                'valid' => $validCount,
                                'invalid' => $invalidCount
                            ]
                        ];
                    } else {
                        Log::error("任务 {$taskId} 查询结果为空");
                        return [
                            'code' => 500,
                            'message' => "任务查询结果为空",
                            'data' => $responseData
                        ];
                    }
                }

                // 如果没有任务ID，返回原始响应
                return [
                    'code' => 0,
                    'message' => "卡密查询已提交，但没有返回任务ID",
                    'data' => $responseData
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
            Log::error('查询卡密失败: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
                'code' => 500,
                'message' => '查询卡密失败: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
}
