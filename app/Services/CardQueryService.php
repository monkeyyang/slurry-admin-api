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

             Log::channel('card_query')->info("准备查询 " . count($cardsToQuery) . " 张卡密");

            // 准备请求参数
            $requestParams = [
                'list' => $cardsToQuery
            ];

            // 调用外部API查询卡密
            $response = Http::post('http://172.16.229.189:8080/api/batch_query/new', $requestParams);
            Log::channel('card_query')->info("请求地址 172.16.229.189");
            // 处理API响应
            if ($response->successful()) {
                $responseData = $response->json();

                // 保存请求参数以便命令行显示
                $responseData['request_params'] = $requestParams;
                if (!empty($responseData['data']['task_id'])) {
                    $taskId = $responseData['data']['task_id'];
                     Log::channel('card_query')->info("获取到任务ID: {$taskId}，开始检查任务状态");

                    $checker = new TaskStatusCheckerService($taskId);
                    $taskResult = $checker->checkUntilCompleted();
                    if(!empty($taskResult)) {
                         Log::channel('card_query')->info("任务 {$taskId} 完成，开始处理结果");

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
                                        $isValid = ($balanceValue > 0);
                                        Log::channel('card_query')->info("卡密余额解析: 原始值=[{$balanceStr}], 提取数值=[{$numericBalance}], 转换结果=[{$balanceValue}], 有效性=[" . ($isValid ? '有效' : '无效') . "]");
                                    }

                                    $record->is_valid = $isValid;

                                    if ($isValid) {
                                        $validCount++;
                                    } else {
                                        $invalidCount++;
                                    }

                                    // 如果卡密有效，则标记为完成
                                    if ($isValid) {
                                        $cardDetails[] = [
                                            'code' => $item['data_id'],
                                            'balance' => $parseJson['balance'],
                                        ];
                                        $record->is_completed = true;
                                    }

                                    $record->save();
                                     Log::channel('card_query')->info("保存卡密记录: ID={$record->id}, 卡号={$record->card_code}, 查询次数={$record->query_count}, 有效性=" . ($record->is_valid ? '有效' : '无效'));
                                }
                            }
                        }

                        // 再构建详细信息数组，保证is_valid状态正确
//                        foreach ($taskResult['data']['items'] ?? [] as $item) {
//                            $parseJson = json_decode($item['result'], true);
//                            $balance = $parseJson['balance'] ?? 'N/A';
//                            $validation = $parseJson['validation'] ?? 'N/A';
//
//                            // 获取已更新的记录
//                            $record = CardQueryRecord::where('card_code', $item['data_id'])->first();
//
//                            $cardDetails[] = [
//                                'card_code' => $item['data_id'],
//                                'balance' => $balance,
//                                'balance_value' => isset($parseJson['balance']) ? preg_replace('/[^0-9.]/', '', $parseJson['balance']) : 0,
//                                'validation' => $validation,
//                                'is_valid' => $record ? $record->is_valid : false, // 使用数据库记录的有效性
//                                'query_count' => $record ? $record->query_count : 0
//                            ];
//                        }

                        // 保存到日志方便调试
                         Log::channel('card_query')->info("卡密详细信息: " . json_encode($cardDetails));

                         Log::channel('card_query')->info("卡密处理完成，总共处理了 {$processedCount} 条记录，有效: {$validCount}，无效: {$invalidCount}");

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
                 Log::channel('card_query')->error('卡密查询API调用失败: ' . $response->body());
                return [
                    'code' => 500,
                    'message' => '卡密查询API调用失败',
                    'error' => $response->body()
                ];
            }

        } catch (\Exception $e) {
             Log::channel('card_query')->error('查询卡密失败: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return [
                'code' => 500,
                'message' => '查询卡密失败: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
}
