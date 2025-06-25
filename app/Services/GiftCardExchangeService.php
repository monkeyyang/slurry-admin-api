<?php

namespace App\Services;

use App\Models\ChargePlan;
use App\Models\AccountGroup;
use App\Models\ChargePlanItem;
use App\Models\ChargePlanLog;
use App\Models\ChargePlanWechatRoomBinding;
use App\Models\GiftCardExchangeRecord;
use App\Models\GiftCardTask;
use App\Models\AccountBalanceLimit;
use App\Models\MrRoom;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;

class GiftCardExchangeService
{
    public GiftCardApiClient $giftCardApiClient;
    public ItunesTradeService $itunesTradeService;
    public string $roomId;
    public string $wxid;
    public string $msgid;
    public MrRoom $roomInfo;
    public $tradeConfig;
    public bool $isOpenRoomBind = false;
    protected string $dataSyncApiUrl = 'http://106.52.250.202:6666'; // 替换为实际的API地址
    private int $giftCardAmount = 0;
    private $currentPlanItem;

    /**
     * 构造函数
     *
     * @param GiftCardApiClient $giftCardApiClient
     */
    public function __construct(GiftCardApiClient $giftCardApiClient)
    {
        $this->giftCardApiClient = $giftCardApiClient;
        $this->itunesTradeService = new ItunesTradeService();
    }

    /**
     * 设置群聊信息
     *
     * @param string $roomId
     * @return MrRoom
     */
    public function setRoomId(string $roomId): MrRoom
    {
        $this->roomId = $roomId;
        $this->roomInfo = MrRoom::getByRoomId($roomId);
        return $this->roomInfo;
    }

    public function setWxId(string $wxid): void
    {
        $this->wxid = $wxid;
    }

    public function setMsgid(string $msgid): void
    {
        $this->msgid = $msgid;
    }

    /**
     * 获取汇率设定
     *
     * @param $countryCode
     * @param $cardType
     * @param $balance
     * @return array|null
     */
    public function getExchangeRate($countryCode, $cardType, $balance): ?array
    {
        // 参数基础校验
        if (empty($countryCode)) {
            throw new InvalidArgumentException('国家代码不能为空');
        }
        // 白名单验证
        if (!in_array($cardType, [0, 1], true)) {
            throw new InvalidArgumentException('卡类型必须为代码或卡图');
        }
        // 获取交易配置
        $tradeConfig = $this->itunesTradeService->getCountryConfig($countryCode);
        if(empty($tradeConfig)|| !isset($tradeConfig['fastCard'])) {
            throw new RuntimeException(sprintf(
                '未找到国家[%s]，快卡的汇率配置',
                $countryCode
            ));
        }
        $fastCardConfig = $tradeConfig['fastCard'];
        // 转换
        $cardType = $cardType == 1 ? 'image' : 'code';
        // 配置存在性检查
        if (!isset($fastCardConfig[$cardType]) && $fastCardConfig[$cardType]['enabled']) {
            throw new RuntimeException(sprintf(
                '未找到国家[%s]、快卡类型为[%s]的汇率配置，或该卡类型未开启',
                $countryCode,
                $cardType
            ));
        }
        // 配置有效性验证
        $rateConfig = $fastCardConfig[$cardType];
        if (!isset($rateConfig['rate']) || $rateConfig['rate'] <= 0) {
            throw new RuntimeException('汇率配置异常: 汇率值缺失或非正数');
        }
        // 是否有面额或倍数校验
        if($rateConfig['maxAmount'] && $rateConfig['maxAmount'] < $balance) {
            throw new RuntimeException(sprintf(
                '当前卡密面额[%s]超出设定的最大面额[%s]',
                $balance,
                $rateConfig['maxAmount']
            ));
        }
        if($rateConfig['minAmount'] && $rateConfig['minAmount'] > $balance) {
            throw new RuntimeException(sprintf(
                '当前卡密面额[%s]小出设定的最小面额[%s]',
                $balance,
                $rateConfig['minAmount']
            ));
        }
        if($rateConfig['amountConstraint'] == 'multiple' && $rateConfig['multipleBase'] > 0 && $balance%$rateConfig['multipleBase'] != 0) {
            throw new RuntimeException(sprintf(
                '当前卡密面额[%s]不符合设定的倍数要求[%s]',
                $balance,
                $rateConfig['multipleBase']
            ));
        }

        $this->tradeConfig = $rateConfig;
        return $rateConfig;
    }

    /**
     * 处理兑换消息
     *
     * @param string $message 消息内容 (例如 "XQPD5D7KJ8TGZT4L /1")
     * @return array
     */
    public function processExchangeMessage(string $message): array
    {
        try {
            Log::channel('gift_card_exchange')->info('收到兑换消息: ' . $message);

            // 解析消息
            $parts = $this->parseMessage($message);
            if (!$parts) {
                throw new Exception('消息格式无效');
            }
            // 从消息中获取代码和类型（卡密或卡图）
            $cardNumber = $parts['card_number'];
            $cardType = $parts['card_type'];

            // 验证礼品卡
            $cardInfo = $this->validateGiftCard($cardNumber);
            if (!$cardInfo['is_valid']) {
                throw new Exception('礼品卡无效: ' . ($cardInfo['message'] ?? '未知原因'));
            }
            $cardInfo['card_number'] = $cardNumber;
            $cardInfo['card_type'] = $cardType;

            // 获取国家代码
            $countryCode = $cardInfo['country_code'];
            Log::channel('gift_card_exchange')->info('查询礼品卡结果：', $cardInfo);

            // 是否符合交易设置（是否设置对应汇率）
            $this->getExchangeRate($countryCode, $cardType, $cardInfo['balance']);

            // 选择合适的计划，传入卡余额用于检查账号额度上限
            $plan = $this->selectEligiblePlan($countryCode, $cardType, $cardInfo['balance']);
            if (!$plan) {
                throw new Exception('没有找到合适的可执行计划，可能所有账号已达额度上限');
            }

            // 确保账号已登录
//            $loginResult = $this->ensureAccountLoggedIn($plan->account, $plan->password);
//            if (!$loginResult['success']) {
//                throw new Exception('账号登录失败: ' . $loginResult['message']);
//            }


            // 执行兑换
            $exchangeResult = $this->executeExchange($plan, $cardInfo['card_number'], $cardInfo);

            // 同步数据到微信
            $msg = "兑换成功\n---------\n";
            $msg .= $exchangeResult['message'];
            Log::channel('gift_card_exchange')->info('兑换结果：'. $msg);
            return [
                'success' => true,
                'message' => '兑换处理成功',
                'data' => $exchangeResult
            ];

        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('兑换处理失败: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 解析兑换消息
     *
     * @param string $message
     * @return array|null
     */
    public function parseMessage(string $message): ?array
    {
        // 解析格式为 "卡号 /类型" 的消息
        $message = trim($message);
        $pattern = '/^([A-Z0-9]+)\s*\/\s*(\d+)$/i';

        if (preg_match($pattern, $message, $matches)) {
            return [
                'card_number' => $matches[1],
                'card_type' => (int) $matches[2]
            ];
        }

        return null;
    }

    /**
     * 验证礼品卡
     *
     * @param string $cardNumber
     * @return array
     * @throws Exception
     */
    public function validateGiftCard(string $cardNumber): array
    {
        try {
            Log::channel('gift_card_exchange')->info('验证礼品卡: ' . $cardNumber);
//            $originalData = '{"code":0,"data":{"task_id":"025fcb72-3e15-4ce7-bc6e-06eccaf2332d","status":"completed","items":[{"data_id":"XV9T2PXQFCVNDG5G","status":"completed","msg":"\u67e5\u8be2\u6210\u529f","result":"{\"code\":0,\"msg\":\"\u67e5\u8be2\u6210\u529f\",\"country\":\"Canada\",\"countryCode\":\"ca\",\"balance\":\"$50.00\",\"cardNumber\":\"8082\",\"validation\":\"Good card\"}","update_time":"2025-06-10 02:13:51"}],"msg":"\u5df2\u5b8c\u6210","update_time":"2025-06-10 02:13:51"},"msg":"\u67e5\u8be2\u6210\u529f"}';
//            $result = json_decode($originalData, true);
            // 创建查卡任务
            $queryTask = $this->createCardQueryTask([$cardNumber]);
            if ($queryTask['code'] !== 0) {
                throw new Exception('创建查卡任务失败: ' . ($queryTask['msg'] ?? '未知错误'));
            }

            $taskId = $queryTask['data']['task_id'];
            Log::channel('gift_card_exchange')->info('查卡任务创建成功, 任务ID: ' . $taskId);

            // 等待任务完成
            $result = $this->waitForCardQueryTaskComplete($taskId);
            Log::channel('gift_card_exchange')->info('查询原始结果：'. json_encode($result));
            if (!$result) {
                throw new Exception('查卡任务执行超时或失败');
            }

            // 解析查卡结果
            $taskResult = $this->parseResult($result, $cardNumber);
            if (!$taskResult) {
                throw new Exception('无法获取查卡结果', -1);
            }

            // 检查查卡结果是否包含错误
            if (isset($taskResult['code']) && $taskResult['code'] !== 0) {
                throw new Exception('查卡失败: ' . ($taskResult['msg'] ?? '未知错误'));
            }

            // 处理国家代码，确保它是有效的国家代码格式
            $countryCode = $taskResult['countryCode'] ?? 'UNKNOWN';

            // 验证国家代码格式（应该是2-3位字母，不区分大小写）
            if (!preg_match('/^[A-Za-z]{2,3}$/i', $countryCode)) {
                // 如果不是标准格式，尝试从country字段提取
                if (!empty($taskResult['country'])) {
                    $countryCode = $this->mapCountryNameToCode($taskResult['country']);
                } else {
                    Log::channel('gift_card_exchange')->warning('无效的国家代码格式: ' . $countryCode);
                    $countryCode = 'UNKNOWN';
                }
            } else {
                // 确保国家代码是大写格式
                $countryCode = strtoupper($countryCode);
            }

            // 根据查卡结果构造返回数据
            return [
                'is_valid' => isset($taskResult['validation']) && stripos($taskResult['validation'], 'Good') !== false,
                'country_code' => $countryCode,
                'balance' => $this->parseBalance($taskResult['balance'] ?? '0'),
                'currency' => $this->parseCurrency($taskResult['balance'] ?? '$0'),
                'message' => $taskResult['msg'] ?? '查询成功',
                'card_number' => $taskResult['cardNumber'] ?? $cardNumber,
                'card_type' => 1, // 默认卡类型
            ];
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('礼品卡验证失败1: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 创建查卡任务
     *
     * @param array $cardNumbers 卡号列表
     * @return array 任务创建结果
     */
    protected function createCardQueryTask(array $cardNumbers): array
    {
        $cards = [];
        foreach ($cardNumbers as $index => $cardNumber) {
            $cards[] = [
                'id' => $index,
                'pin' => $cardNumber
            ];
        }

        // 调用API客户端创建查卡任务
        // 保存任务记录
//        if ($result['code'] === 0 && isset($result['data']['task_id'])) {
//            GiftCardTask::create([
//                'task_id' => $result['data']['task_id'],
//                'type' => GiftCardTask::TYPE_QUERY,
//                'status' => GiftCardTask::STATUS_PENDING,
//                'request_data' => $cards
//            ]);
//        }

        return $this->giftCardApiClient->createCardQueryTask($cards);
    }

    /**
     * 等待查卡任务完成
     *
     * @param string $taskId 任务ID
     * @return bool|array
     */
    protected function waitForCardQueryTaskComplete(string $taskId): bool|array
    {
        $maxAttempts = config('gift_card.polling.max_attempts', 500);
        $interval = config('gift_card.polling.interval', 3);

//        $task = GiftCardTask::where('task_id', $taskId)->first();
//        if (!$task) {
//            Log::channel('gift_card_exchange')->error('查卡任务记录不存在: ' . $taskId);
//            return false;
//        }

//        $task->markAsProcessing();

        for ($attempt = 0; $attempt < 500; $attempt++) {
            $result = $this->giftCardApiClient->getCardQueryTaskStatus($taskId);

            if ($result['code'] !== 0) {
                Log::channel('gift_card_exchange')->error('查询查卡任务状态失败: ' . ($result['msg'] ?? '未知错误'));
                continue;
            }

            $status = $result['data']['status'] ?? '';

            // 更新任务状态
            if ($status === 'completed') {
                return $result;
//                return true;
            } elseif ($status === 'failed') {
                Log::channel('gift_card_exchange')->error('查卡原始消息Failed: ' , $result);
//                $task->markAsFailed($result['data']['msg'] ?? '任务失败');
                return false;
            }

            // 等待一段时间后继续查询
//            sleep($interval);
            usleep(200*1000);
        }

        // 如果达到最大尝试次数仍未完成，则标记为失败
//        $task->markAsFailed('任务执行超时');
        return false;
    }

    protected function parseResult(array $result, string $cardNumber): ?array
    {
        if(!empty($result)) {
            foreach($result['data']['items'] as $k => $item) {
                if ($item['data_id'] === $cardNumber && $item['status'] === 'completed') {
                    // 解析结果JSON字符串
                    $itemJson = json_decode($item['result'], true);

                    // 确保解析成功
                    if (!$itemJson || !is_array($itemJson)) {
                        Log::channel('gift_card_exchange')->error('解析查卡结果JSON失败: ' . $item['result']);
                        return null;
                    }

                    // 处理国家代码
                    if (empty($itemJson['countryCode']) && !empty($itemJson['country'])) {
                        // 从country字段提取国家代码
                        $countryInfo = explode('-', $itemJson['country']);
                        $itemJson['countryCode'] = trim($countryInfo[0]);
                    }

                    return $itemJson;
                }
            }
        }
        return null;
    }

    /**
     * 获取查卡结果
     *
     * @param string $taskId 任务ID
     * @param string $cardNumber 卡号
     * @return array|null 查卡结果
     */
    protected function getCardQueryResult(string $taskId, string $cardNumber): ?array
    {
        $task = GiftCardTask::where('task_id', $taskId)->first();
        if (!$task || !$task->isCompleted() || !$task->result_data) {
            return null;
        }

        $items = $task->result_data['items'] ?? [];
        foreach ($items as $item) {
            if ($item['data_id'] === $cardNumber && $item['status'] === 'completed') {
                // 解析结果JSON字符串
                return json_decode($item['result'], true) ?? [];
            }
        }

        return null;
    }

    /**
     * 将国家名称映射为国家代码
     *
     * @param string $countryName 国家名称
     * @return string 国家代码
     */
    protected function mapCountryNameToCode(string $countryName): string
    {
        // 先清理输入，移除多余的空白字符
        $countryName = trim($countryName);

        // 检查是否已经是国家代码格式（不区分大小写）
        if (preg_match('/^[A-Za-z]{2,3}$/i', $countryName)) {
            return strtoupper($countryName);
        }

        // 检查是否包含无效字符（可能是错误信息） - 在数组访问之前检查
        if (preg_match('/[\[\]0-9]+/', $countryName)) {
            Log::channel('gift_card_exchange')->error('尝试映射无效的国家名称（可能是错误信息）: ' . $countryName);
            return 'UNKNOWN';
        }

        $countryMap = [
            '美国' => 'US',
            '加拿大' => 'CA',
            '英国' => 'GB',
            '德国' => 'DE',
            '法国' => 'FR',
            '意大利' => 'IT',
            '西班牙' => 'ES',
            '澳大利亚' => 'AU',
            '新西兰' => 'NZ',
            '日本' => 'JP',
            '韩国' => 'KR',
            '中国香港' => 'HK',
            '中国台湾' => 'TW',
            '新加坡' => 'SG',
            'US-美国' => 'US',
            'CA-加拿大' => 'CA'
            // 可以根据需要添加更多映射
        ];

        // 现在安全地访问数组
        if (isset($countryMap[$countryName])) {
            return $countryMap[$countryName];
        }

        Log::channel('gift_card_exchange')->warning('未找到国家映射: ' . $countryName);
        return 'UNKNOWN';
    }

    /**
     * 解析余额字符串中的数值
     *
     * @param string $balanceStr 余额字符串，如"$100.00"
     * @return float 解析后的数值
     */
    protected function parseBalance(string $balanceStr): float
    {
        // 移除货币符号和非数字字符，只保留数字和小数点
        $numericStr = preg_replace('/[^0-9.]/', '', $balanceStr);
        return (float) $numericStr;
    }

    /**
     * 从余额字符串中解析货币类型
     *
     * @param string $balanceStr 余额字符串，如"$100.00"
     * @return string 货币类型
     */
    protected function parseCurrency(string $balanceStr): string
    {
        if (str_starts_with($balanceStr, '$')) {
            return 'USD';
        } elseif (str_starts_with($balanceStr, '£')) {
            return 'GBP';
        } elseif (str_starts_with($balanceStr, '€')) {
            return 'EUR';
        } elseif (str_starts_with($balanceStr, '¥')) {
            return 'JPY';
        } elseif (str_starts_with($balanceStr, 'AU$')) {
            return 'AUD';
        } elseif (str_starts_with($balanceStr, 'CAD$') || str_starts_with($balanceStr, 'C$')) {
            return 'CAD';
        }

        // 默认返回USD
        return 'USD';
    }

    /**
     * 获取所有可执行的计划
     *
     * @param $countryCode
     * @param $cardType
     * @return mixed
     */
    private function getProcessingPlans($countryCode, $cardType): mixed
    {
        // 获取状态为处理中的相关国家计划
        $query = ChargePlan::where('status','processing')->where('country', $countryCode);
        // 可以根据卡类型进行进一步筛选，例如根据优先级或组
//        if ($cardType == 1) {
//            // 例如卡类型1优先选择高优先级的计划
//            $query->orderBy('priority', 'desc');
//        } else {
            // 默认按创建时间排序
        $query->orderBy('created_at', 'asc');
//        }

        // 记录 SQL 查询到日志（带参数）
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        Log::channel('gift_card_exchange')->info('执行的 SQL 查询', [
            'sql' => $sql,
            'bindings' => $bindings,
        ]);
        // 获取所有可能的计划
        return $query->get();
    }

    /**
     * 筛选符合条件的计划
     *
     * @param Collection $plans
     * @return ChargePlan|null
     */
    private function filterPlan(Collection $plans): ?ChargePlan
    {
        try {
            // 是否开启绑定群组
            $wechatRoomBindingService = new WechatRoomBindingService();
            $bindStatus = $wechatRoomBindingService->getBindingStatus();
            $this->isOpenRoomBind = !empty($bindStatus) && $bindStatus['enabled'];

            // 如果开启群组绑定，获取当前群组绑定的计划
            if ($this->isOpenRoomBind) {
                if (empty($this->roomId)) {
                    Log::channel('gift_card_exchange')->error('群组绑定已开启，但未设置roomId');
                    return null;
                }

                // 检查群组是否已绑定计划
                $roomBinds = ChargePlanWechatRoomBinding::where('room_id', $this->roomId)->get();
                if ($roomBinds->isNotEmpty()) {
                    // 已绑定计划，检查绑定的计划是否有效且在计划列表中
                    foreach ($roomBinds as $binding) {
                        $plan = $plans->firstWhere('id', $binding->plan_id);
                        if ($plan && $this->isPlanValid($plan)) {
                            Log::channel('gift_card_exchange')->info("使用群组 {$this->roomId} 已绑定的计划 {$plan->id}");
                            return $plan;
                        }
                    }
                }
                // 没有绑定计划或绑定的计划无效，找第一个符合条件的计划并绑定
                foreach ($plans as $plan) {
                    if ($this->isPlanValid($plan)) {
                        try {
                            DB::beginTransaction();
                            $wechatRoomBindingService->bindPlanToRoom($plan->id, $this->roomId);
                            DB::commit();
                            Log::channel('gift_card_exchange')->info("自动绑定计划 {$plan->id} 到群组 {$this->roomId}");
                            return $plan;
                        } catch (\Exception $e) {
                            DB::rollBack();
                            Log::channel('gift_card_exchange')->error("自动绑定计划失败: " . $e->getMessage());
                            // 绑定失败不影响使用该计划，继续返回
                            return $plan;
                        }
                    }
                }


                Log::channel('gift_card_exchange')->info("未找到符合条件的计划1");
                return null;
            } else {
                // 未开启群组绑定，直接返回第一个符合条件的计划
                foreach ($plans as $plan) {
                    if ($this->isPlanValid($plan)) {
                        Log::channel('gift_card_exchange')->info("使用未绑定群组的计划 {$plan->id}");
                        return $plan;
                    }
                }
                Log::channel('gift_card_exchange')->info("未找到符合条件的计划2");
                return null;
            }
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("筛选计划时发生错误: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 检查计划是否有效
     *
     * @param ChargePlan $plan
     * @return bool
     */
    private function isPlanValid(ChargePlan $plan): bool
    {
        try {
            // 默认当日为第一天
            $plan->current_day = 1;

            // 1. 检查计划状态和额度
            if ($plan->status === 'completed') {
                Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 已完成");
                return false;
            }
            Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 通过状态验证");
            if ($plan->charged_amount >= $plan->total_amount) {
                Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 已达到总额度");
                return false;
            }
            Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 通过额度验证");
            // 2. 检查礼品卡金额是否超出计划剩余额度
            $remainingAmount = $plan->total_amount - $plan->charged_amount;
            if ($this->giftCardAmount > $remainingAmount) {
                Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 礼品卡金额 {$this->giftCardAmount} 超出剩余额度 {$remainingAmount}");
                return false;
            }
            Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 通过礼品卡金额是否超出计划剩余额度验证");
            // 3. 检查面值倍数要求
            if ($plan->multiple_base > 0) {
                if ($this->giftCardAmount % $plan->multiple_base !== 0) {
                    Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 礼品卡金额 {$this->giftCardAmount} 不符合倍数要求 {$plan->multiple_base}");
                    return false;
                }
            }
            Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 通过面值倍数验证");
            // 4. 检查最后成功的执行时间
            $lastExecution = ChargePlanLog::where('plan_id', $plan->id)
                ->where('action', 'gift_card_exchange')
                ->where('status', 'success')
                ->orderBy('created_at', 'desc')
                ->first();

            Log::channel('gift_card_exchange')->info("调试：计划 {$plan->id}[{$plan->account}] 最后执行记录", [
                'lastExecution' => $lastExecution ? $lastExecution->toArray() : null
            ]);

            if ($lastExecution)
            {
                $lastExecutionTime = Carbon::parse($lastExecution->created_at);
                $now = Carbon::now();

                // 如果最后执行时间在5分钟内，跳过该计划
                if ($now->diffInMinutes($lastExecutionTime) <= 5) {
                    Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 最后执行时间在5分钟内，跳过该计划", [
                        'lastExecutionTime' => $lastExecutionTime->format('Y-m-d H:i:s'),
                        'diffInMinutes' => $now->diffInMinutes($lastExecutionTime)
                    ]);
                    return false;
                }

                // 计算当前属于第几日
                $firstExecution = ChargePlanLog::where('plan_id', $plan->id)
                    ->where('action', 'gift_card_exchange')
                    ->where('status', 'success')
                    ->orderBy('created_at', 'asc')
                    ->first();

                if ($firstExecution) {
                    $firstExecutionTime = Carbon::parse($firstExecution->created_at);

                    // 重新计算当前应该在第几天
                    $currentDay = $this->calculateCurrentDay($plan, $firstExecutionTime, $now);

                    // 将当前日赋值给计划current_day属性
                    $plan->current_day = $currentDay;

                    Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 当前天数计算", [
                        'firstExecutionTime' => $firstExecutionTime->format('Y-m-d H:i:s'),
                        'calculatedCurrentDay' => $currentDay
                    ]);

                    // 获取当前日的计划项
                    $currentDayItem = ChargePlanItem::where('plan_id', $plan->id)
                        ->where('day', $currentDay)
                        ->first();

                    if ($currentDayItem) {
                        // 检查当前天是否已完成
                        if ($currentDayItem->status === 'completed') {
                            Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 第 {$currentDay} 天已完成，检查是否可以进入下一天");

                            // 检查是否可以进入下一天（24小时间隔）
                            if (!$this->canProceedToNextDay($plan, $currentDay)) {
                                Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 第 {$currentDay} 天已完成但24小时未满，跳过该计划");
                                return false;
                            }

                            // 尝试进入下一天
                            $nextDay = $currentDay + 1;
                            $nextDayItem = ChargePlanItem::where('plan_id', $plan->id)
                                ->where('day', $nextDay)
                                ->first();

                            if ($nextDayItem && $nextDayItem->status === 'pending') {
                                $plan->current_day = $nextDay;
                                $currentDay = $nextDay;
                                $currentDayItem = $nextDayItem;
                                Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 进入第 {$nextDay} 天");
                            } else {
                                Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 没有可用的下一天计划项");
                                return false;
                            }
                        }

                        // 检查是否超出当日可兑换余额
                        $executedAmount = $this->getDailyExecutedAmount($plan, $currentDay);
                        $remainingDayAmount = $currentDayItem->max_amount - $executedAmount;

                        if ($this->giftCardAmount > $remainingDayAmount) {
                            Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 第 {$currentDay} 天礼品卡金额 {$this->giftCardAmount} 超出当日剩余额度 {$remainingDayAmount}");
                            return false;
                        }
                    } else {
                        Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 第 {$currentDay} 天没有对应的计划项");
                        return false;
                    }
                }
            }

            Log::channel('gift_card_exchange')->info("计划 {$plan->id}[{$plan->account}] 通过所有验证");
            return true;
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("验证计划 {$plan->id}[{$plan->account}] 时发生错误: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 选择符合条件的计划
     *
     * @param string $countryCode
     * @param int $cardType
     * @param float $cardBalance 卡余额
     * @return ChargePlan|null
     * @throws Exception
     */
    public function selectEligiblePlan(string $countryCode, int $cardType, float $cardBalance = 0): ?ChargePlan
    {
        try {

            Log::channel('gift_card_exchange')->info("选择计划, 国家: {$countryCode}, 卡类型: {$cardType}, 卡余额: {$cardBalance}");
            $this->giftCardAmount = $cardBalance;
            // 获取所有可能的计划
            $plans = $this->getProcessingPlans($countryCode, $cardType);

            // 过滤出符合执行时间要求的计划
            $plan = $this->filterPlan($plans);

            // 没有符合要求的计划返回NULL
            if(empty($plan)) return null;

            // 解密密码
            $service = new GiftExchangeService();
            $decryptedAccountInfo = $service->getDecryptedAccountInfo($plan);

            // 将解密后的密码设置回计划对象（临时修改，不保存到数据库）
            $plan->password = $decryptedAccountInfo['password'];

            return $plan;
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('选择计划失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 检查账号余额上限
     *
     * @param string $account 账号
     * @param float $amount 要兑换的金额
     * @return array 检查结果
     */
    protected function checkAccountBalanceLimit(string $account, float $amount): array
    {
        try {
            // 获取账号余额限制记录
            $balanceLimit = AccountBalanceLimit::where('account', $account)->first();

            // 如果没有找到记录，假设该账号没有限制
            if (!$balanceLimit) {
                return [
                    'success' => true,
                    'message' => '账号未设置额度限制',
                    'account' => $account
                ];
            }

            // 更新检查时间
            $balanceLimit->updateCheckedTime();

            // 检查账号状态和余额上限
            if ($balanceLimit->canRedeemAmount($amount)) {
                return [
                    'success' => true,
                    'message' => '账号可以接收兑换',
                    'account' => $account,
                    'current_balance' => $balanceLimit->current_balance,
                    'balance_limit' => $balanceLimit->balance_limit,
                    'amount' => $amount
                ];
            } else {
                return [
                    'success' => false,
                    'message' => "账号已达到额度上限或不可用",
                    'account' => $account,
                    'current_balance' => $balanceLimit->current_balance,
                    'balance_limit' => $balanceLimit->balance_limit,
                    'amount' => $amount
                ];
            }
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error("检查账号 {$account} 余额上限失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '检查账号余额上限异常: ' . $e->getMessage(),
                'account' => $account
            ];
        }
    }

    /**
     * 获取替代账号
     *
     * @param float $amount 要兑换的金额
     * @param string $countryCode 国家代码
     * @return AccountBalanceLimit|null 替代账号信息
     */
    protected function getAlternativeAccount(float $amount, string $countryCode): ?AccountBalanceLimit
    {
        try {
            // 查找符合条件的账号：
            // 1. 状态为激活
            // 2. 当前余额 + 要兑换的金额 <= 余额上限
            // 3. 已经关联了相应国家的计划
            // 4. 按剩余可用余额由高到低排序

            // 获取相应国家的所有账号
            $accountsWithPlans = ChargePlan::where('country', $countryCode)
                ->where('status', 'processing')
                ->pluck('account')
                ->unique()
                ->toArray();

            if (empty($accountsWithPlans)) {
                return null;
            }

            // 查找符合条件的账号
            return AccountBalanceLimit::whereIn('account', $accountsWithPlans)
                ->where('status', AccountBalanceLimit::STATUS_ACTIVE)
                ->whereRaw('current_balance + ? <= balance_limit', [$amount])
                ->orderByRaw('(balance_limit - current_balance) DESC') // 按剩余可用余额排序
                ->first();
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error("获取替代账号失败: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 执行兑换操作
     *
     * @param ChargePlan $plan
     * @param string $cardNumber
     * @param array $cardInfo
     * @return array
     * @throws Exception
     */
    public function executeExchange(ChargePlan $plan, string $cardNumber, array $cardInfo): array
    {
        try {
            Log::channel('gift_card_exchange')->info("执行兑换, 计划ID: {$plan->id}, 账号: {$plan->account}, 卡号: {$cardNumber}");

            // 获取当前执行的plan item
            $currentDay = $plan->current_day ?? 1;
            $item = $plan->items()
                ->where('day', $currentDay)
                ->orderBy('id', 'asc')
                ->first();

            if (!$item) {
                throw new Exception("找不到待执行的项目");
            }
            $this->currentPlanItem = $item;

            // 创建兑换任务
            $redemptionData = [
                [
                    'username' => $plan->account ?? '',
                    //'password' => $plan->password ?? '',
                    'password' => '',
                    'verify_url' => $plan->verify_url ?? '',
                    'pin' => $cardNumber
                ]
            ];

            // 创建兑换任务
            $redemptionTask = $this->giftCardApiClient->createRedemptionTask($redemptionData, config('gift_card.redemption.interval', 6));
            if ($redemptionTask['code'] !== 0) {
                throw new Exception('创建兑换任务失败: ' . ($redemptionTask['msg'] ?? '未知错误'));
            }

            $taskId = $redemptionTask['data']['task_id'];
//            $taskId = 'ttt';
            Log::channel('gift_card_exchange')->info('兑换任务创建成功, 任务ID: ' . $taskId);
//            $taskId = 'test';
            // 等待任务完成
            $result = $this->waitForRedemptionTaskComplete($taskId);
            if (empty($result)) {
                throw new Exception('兑换任务执行超时或失败');
            }

            // 解析兑换结果
            $exchangeData = [
                'success' => false,
                'message' => '兑换失败',
                'data' => [
                    'account' => $plan->account,
                    'amount' => 0,
                    'rate' => $this->tradeConfig['rate'],
                    'total_amount' => 0,
                    'status' => 'failed',
                    'msg' => '兑换失败',
                    'details' => json_encode([
                        'card_number' => $cardNumber,
                        'card_type' => $cardInfo['card_type'],
                        'country_code' => $cardInfo['country_code'],
                        'api_response' => $result
                    ])
                ]
            ];

            // 解析API响应中的具体兑换结果
            foreach($result['data']['items'] as $chargeItem) {
                if($chargeItem['data_id'] != $plan->account.":".$cardNumber) continue;

                Log::channel('gift_card_exchange')->info('兑换item详情：', $chargeItem);

                if(!$chargeItem['result']['code']) { // code为0表示成功
                    $exchangeData['success'] = true;
                    $exchangeData['message'] = sprintf(
                        "%s:%s兑换成功\n汇率：%s\n%s",
                        $plan->account,
                        $cardNumber,
                        $this->tradeConfig['rate'],
                        $chargeItem['msg'] ?? ''
                    );
                    $exchangeData['data'] = [
                        'account' => $plan->account,
                        'amount' => $this->parseBalance($chargeItem['result']['fund']),
                        'rate' => $this->tradeConfig['rate'],
                        'total_amount' => $this->parseBalance($chargeItem['result']['total']),
                        'status' => 'success',
                        'msg' => $chargeItem['msg'] ?? '',
                        'details' => json_encode([
                            'card_number' => $cardNumber,
                            'card_type' => $cardInfo['card_type'],
                            'country_code' => $cardInfo['country_code'],
                            'api_response' => $result
                        ])
                    ];
                } else {
                    $exchangeData['message'] = sprintf(
                        "%s:%s兑换失败\n原因：%s",
                        $plan->account,
                        $cardNumber,
                        $chargeItem['msg'] ?? '未知原因'
                    );
                    $exchangeData['data']['msg'] = $chargeItem['msg'] ?? '兑换失败';
                }
                break;
            }

            return $exchangeData;

        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error("执行兑换失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 创建兑换任务
     *
     * @param array $redemptions 兑换信息列表
     * @param int $interval 同一账户兑换多张卡时的时间间隔
     * @return array 任务创建结果
     */
    protected function createRedemptionTask(array $redemptions, int $interval = 6): array
    {
        // 调用API客户端创建兑换任务
        $result = $this->giftCardApiClient->createRedemptionTask($redemptions, $interval);

        // 保存任务记录
        if ($result['code'] === 0 && isset($result['data']['task_id'])) {
            GiftCardTask::create([
                'task_id' => $result['data']['task_id'],
                'type' => GiftCardTask::TYPE_REDEEM,
                'status' => GiftCardTask::STATUS_PENDING,
                'request_data' => [
                    'redemptions' => $redemptions,
                    'interval' => $interval
                ]
            ]);
        }

        return $result;
    }

    /**
     * 等待兑换任务完成
     *
     * @param string $taskId 任务ID
     * @return bool|array
     */
    protected function waitForRedemptionTaskComplete(string $taskId): bool|array
    {
        $maxAttempts = config('gift_card.polling.max_attempts', 500);
        $interval = config('gift_card.polling.interval', 3);

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $result = $this->giftCardApiClient->getRedemptionTaskStatus($taskId);
//            $original = '{"code":0,"data":{"task_id":"5cc6e4c0-aa37-4d1e-a92f-a966c3293848","status":"completed","items":[{"data_id":"greenE1502@icloud.com:XV9T2PXQFCVNDG5G","status":"completed","msg":"\u5151\u6362\u6210\u529f,\u52a0\u8f7d\u91d1\u989d:$500.00,ID\u603b\u91d1\u989d:$500.00","result":"{\"code\":0,\"msg\":\"\u5151\u6362\u6210\u529f,\u52a0\u8f7d\u91d1\u989d:$500.00,ID\u603b\u91d1\u989d:$500.00\",\"username\":\"croadG1429@icloud.com\",\"total\":\"$500.00\",\"fund\":\"$500.00\",\"available\":\"\"}","update_time":"2025-06-07 03:06:07"}],"msg":"\u4efb\u52a1\u5df2\u5b8c\u6210","update_time":"2025-06-07 03:06:07"},"msg":"\u6267\u884c\u6210\u529f"}';
//            $result = json_decode($original, true);
            // 记录原始响应
            Log::channel('gift_card_exchange')->info('原始响应数据: ' . json_encode($result));

            if ($result['code'] !== 0) {
                Log::channel('gift_card_exchange')->error('查询兑换任务状态失败: ' . ($result['msg'] ?? '未知错误'));
                continue;
            }

            // 验证数据结构
            if (!isset($result['data']) || !is_array($result['data'])) {
                Log::channel('gift_card_exchange')->error('任务状态数据结构无效: ' . json_encode($result));
                continue;
            }

            $status = $result['data']['status'] ?? '';
            Log::channel('gift_card_exchange')->info('当前任务状态: ' . $status);

            // 更新任务状态
            if ($status === 'completed') {
                // 验证完成状态的数据结构
                if (!isset($result['data']['items']) || !is_array($result['data']['items'])) {
                    Log::channel('gift_card_exchange')->error('任务完成但数据结构无效: ' . json_encode($result));
                    return false;
                }

                // 处理每个item的result字段
                foreach ($result['data']['items'] as &$item) {
                    if (isset($item['result']) && is_string($item['result'])) {
                        try {
                            $decodedResult = json_decode($item['result'], true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $item['result'] = $decodedResult;
                            }
                        } catch (\Exception $e) {
                            Log::channel('gift_card_exchange')->error('解析result JSON失败: ' . $e->getMessage());
                        }
                    }
                }

                return $result;
            } elseif ($status === 'failed') {
                Log::channel('gift_card_exchange')->error('任务执行失败: ' . ($result['data']['msg'] ?? '未知原因'));
                return false;
            }

            // 等待一段时间后继续查询 200毫秒刷新
            usleep(200 * 1000);
        }

        Log::channel('gift_card_exchange')->error('任务执行超时');
        return false;
    }

    /**
     * 获取兑换结果
     *
     * @param string $username 用户名
     * @param string $cardNumber 卡号
     * @return array|null 兑换结果
     */
    protected function getRedemptionResult($result, string $username, string $cardNumber): ?array
    {
        $items = $result['items'] ?? [];
        $dataId = "{$username}:{$cardNumber}";

        foreach ($items as $item) {
            if ($item['data_id'] === $dataId && $item['status'] === 'completed') {
                // 解析结果JSON字符串
                $resultData = json_decode($item['result'], true) ?? [];
                return $resultData;
            }
        }

        return null;
    }

    /**
     * 计算汇率
     *
     * @param string $countryCode
     * @param int $cardType
     * @param float $balance
     * @return array
     * @throws Exception
     */
    public function calculateRate(string $countryCode, int $cardType, float $balance): array
    {
        try {
            Log::channel('gift_card_exchange')->info("计算汇率, 国家: {$countryCode}, 卡类型: {$cardType}, 余额: {$balance}");

            // 根据国家和卡类型获取汇率配置
            // 这里应该使用实际的汇率配置，可以从数据库获取
            $rateConfig = $this->getRateConfiguration($countryCode, $cardType);

            $rate = $rateConfig['rate'] ?? 6.5; // 默认汇率
            $convertedAmount = $balance * $rate;

            return [
                'original_amount' => $balance,
                'rate' => $rate,
                'converted_amount' => $convertedAmount,
                'original_currency' => $rateConfig['original_currency'] ?? 'USD',
                'target_currency' => $rateConfig['target_currency'] ?? 'CNY'
            ];
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('计算汇率失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取汇率配置
     *
     * @param string $countryCode
     * @param int $cardType
     * @return array
     */
    protected function getRateConfiguration(string $countryCode, int $cardType): array
    {
        // 根据国家和卡类型获取对应的汇率配置
        // 这里应该从数据库或配置文件中读取
        $config = [
            'US' => [
                1 => ['rate' => 6.8, 'original_currency' => 'USD', 'target_currency' => 'CNY'],
                2 => ['rate' => 6.7, 'original_currency' => 'USD', 'target_currency' => 'CNY'],
            ],
            'GB' => [
                1 => ['rate' => 8.5, 'original_currency' => 'GBP', 'target_currency' => 'CNY'],
                2 => ['rate' => 8.4, 'original_currency' => 'GBP', 'target_currency' => 'CNY'],
            ],
            // 更多国家...
        ];

        return $config[$countryCode][$cardType] ?? ['rate' => 6.5, 'original_currency' => 'USD', 'target_currency' => 'CNY'];
    }

    /**
     * 记录兑换信息
     *
     * @param ChargePlan $plan
     * @param array $cardInfo
     * @param array $rateInfo
     * @param array $exchangeResult
     * @return array
     */
    public function recordExchangeInfo(ChargePlan $plan, array $cardInfo, array $rateInfo, array $exchangeResult): array
    {
        try {
            $recordData = [
                'plan_id' => $plan->id,
                'item_id' => $exchangeResult['item_id'] ?? null,
                'account' => $plan->account,
                'card_number' => $cardInfo['card_number'] ?? '',
                'card_type' => $cardInfo['card_type'] ?? 1,
                'country_code' => $cardInfo['country_code'],
                'original_balance' => $cardInfo['balance'],
                'original_currency' => $rateInfo['original_currency'],
                'exchange_rate' => $rateInfo['rate'],
                'converted_amount' => $rateInfo['converted_amount'],
                'target_currency' => $rateInfo['target_currency'],
                'transaction_id' => $exchangeResult['transaction_id'],
                'status' => $exchangeResult['success'] ? 'success' : 'failed',
                'details' => json_encode([
                    'card_details' => $cardInfo,
                    'rate_details' => $rateInfo,
                    'exchange_details' => $exchangeResult
                ]),
                'exchange_time' => now()->toDateTimeString(),
            ];

            // 保存到数据库
            $record = GiftCardExchangeRecord::create($recordData);
            Log::channel('gift_card_exchange')->info('记录兑换信息: ' . $record->id);

            // 如果兑换成功，更新账号余额
            if ($exchangeResult['success']) {
                // 更新账号余额
                $accountLimit = AccountBalanceLimit::where('account', $plan->account)->first();
                if ($accountLimit) {
                    $accountLimit->addBalance($rateInfo['converted_amount']);
                    Log::channel('gift_card_exchange')->info("更新账号 {$plan->account} 余额: +{$rateInfo['converted_amount']}");
                }
            }

            return $record->toArray();
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('记录兑换信息失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 同步数据到其他系统
     *
     * @param array $data
     * @return array
     */
    public function syncData(array $data): array
    {
        try {
            Log::channel('gift_card_exchange')->info('同步数据到微信: ' . json_encode($data));
            $requestData = [

            ];
            // 这里应该调用实际的API同步数据
//            send_msg_to_wechat('44769140035@chatroom', );
            $response = Http::post($this->dataSyncApiUrl, $data);

            // 模拟API响应
            $mockResponse = [
                'success' => true,
                'message' => 'Data synchronized successfully',
                'sync_id' => 'SYNC' . uniqid()
            ];

            return [
                'success' => true,
                'sync_id' => $mockResponse['sync_id'],
                'message' => $mockResponse['message']
            ];
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('同步数据失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 确保账号已登录
     *
     * @param string $account 账号
     * @param string $password 密码，可选
     * @param string $verifyUrl 验证URL，可选
     * @return array 登录结果
     */
    protected function ensureAccountLoggedIn(string $account, string $password = '', string $verifyUrl = ''): array
    {
        try {
            // 先尝试刷新登录状态
            $refreshResult = $this->giftCardApiClient->refreshUserLogin([
                'id' => 0,
                'username' => $account,
                'password' => $password,
                'verifyUrl' => $verifyUrl
            ]);

            if ($refreshResult['code'] === 0) {
                // 成功刷新，账号已登录
                Log::channel('gift_card_exchange')->info("账号 {$account} 已登录或刷新成功");
                return [
                    'success' => true,
                    'message' => $refreshResult['data']['msg'] ?? '账号已登录',
                    'data' => $refreshResult['data']
                ];
            }

            // 如果没有密码，无法创建登录任务
            if (empty($password)) {
                return [
                    'success' => false,
                    'message' => '账号未登录，且未提供密码',
                    'data' => null
                ];
            }

            // 创建登录任务
            $loginTask = $this->createLoginTask($account, $password, $verifyUrl);
            if (!$loginTask['success']) {
                return $loginTask;
            }

            return [
                'success' => true,
                'message' => '登录任务已创建，账号将在后台登录',
                'data' => $loginTask['data']
            ];
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error("确保账号 {$account} 登录失败: " . $e->getMessage());
            return [
                'success' => false,
                'message' => '登录过程异常: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 创建登录任务
     *
     * @param string $account 账号
     * @param string $password 密码
     * @param string $verifyUrl 验证URL，可选
     * @return array 登录任务创建结果
     */
    public function createLoginTask(string $account, string $password, string $verifyUrl = ''): array
    {
        try {
            $accounts = [
                [
                    'id' => 1,
                    'username' => $account,
                    'password' => $password,
                    'VerifyUrl' => $verifyUrl
                ]
            ];

            $result = $this->giftCardApiClient->createLoginTask($accounts);

            if ($result['code'] !== 0) {
                Log::channel('gift_card_exchange')->error('创建登录任务失败: ' . ($result['msg'] ?? '未知错误'));
                return [
                    'success' => false,
                    'message' => '创建登录任务失败: ' . ($result['msg'] ?? '未知错误'),
                    'data' => null
                ];
            }

            $taskId = $result['data']['task_id'];

            // 保存任务记录
            GiftCardTask::create([
                'task_id' => $taskId,
                'type' => GiftCardTask::TYPE_LOGIN,
                'status' => GiftCardTask::STATUS_PENDING,
                'request_data' => $accounts
            ]);

            return [
                'success' => true,
                'message' => '登录任务创建成功',
                'data' => [
                    'task_id' => $taskId
                ]
            ];
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('创建登录任务异常: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => '创建登录任务异常: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 查询登录任务状态
     *
     * @param string $taskId 任务ID
     * @return array 任务状态
     */
    public function getLoginTaskStatus(string $taskId): array
    {
        try {
            $result = $this->giftCardApiClient->getLoginTaskStatus($taskId);

            if ($result['code'] !== 0) {
                return [
                    'success' => false,
                    'message' => '查询登录任务失败: ' . ($result['msg'] ?? '未知错误'),
                    'data' => null
                ];
            }

            // 更新任务状态
            $task = GiftCardTask::where('task_id', $taskId)->first();
            if ($task) {
                $status = $result['data']['status'] ?? '';

                if ($status === 'completed') {
                    $task->markAsCompleted($result['data']);
                } elseif ($status === 'failed') {
                    $task->markAsFailed($result['data']['msg'] ?? '任务失败');
                } elseif ($status === 'running') {
                    $task->markAsProcessing();
                }
            }

            return [
                'success' => true,
                'message' => 'ok',
                'data' => $result['data']
            ];
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('查询登录任务状态异常: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => '查询登录任务状态异常: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 删除用户登录
     *
     * @param array|string $accounts 账号或账号列表
     * @return array 删除结果
     */
    public function deleteUserLogins($accounts): array
    {
        try {
            // 转换为数组格式
            $accountList = is_array($accounts) ? $accounts : [$accounts];

            $requestData = [];
            foreach ($accountList as $account) {
                $requestData[] = [
                    'username' => $account
                ];
            }

            $result = $this->giftCardApiClient->deleteUserLogins($requestData);

            return [
                'success' => $result['code'] === 0,
                'message' => $result['msg'] ?? '',
                'data' => $result['data'] ?? null
            ];
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error('删除用户登录异常: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => '删除用户登录异常: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * 计算当前应该在第几天
     */
    private function calculateCurrentDay(ChargePlan $plan, Carbon $firstExecutionTime, Carbon $now): int
    {
        // 获取所有已完成的天数
        $completedDays = ChargePlanItem::where('plan_id', $plan->id)
            ->where('status', 'completed')
            ->orderBy('day')
            ->pluck('day');

        if ($completedDays->isEmpty()) {
            return 1; // 如果没有完成的天数，返回第1天
        }

        $lastCompletedDay = $completedDays->max();

        // 检查最后完成天的完成时间
        $lastCompletionTime = ChargePlanLog::where('plan_id', $plan->id)
            ->where('day', $lastCompletedDay)
            ->where('action', 'gift_card_exchange')
            ->where('status', 'success')
            ->latest('created_at')
            ->value('created_at');

        if ($lastCompletionTime) {
            $completionTime = Carbon::parse($lastCompletionTime);
            $hoursElapsed = $now->diffInHours($completionTime);

            // 如果距离最后完成时间超过24小时，可以进入下一天
            if ($hoursElapsed >= 24) {
                return $lastCompletedDay + 1;
            }
        }

        // 否则继续当前天（最后完成的天数）
        return $lastCompletedDay;
    }

    /**
     * 检查是否可以进入下一天（24小时间隔检查）
     */
    private function canProceedToNextDay(ChargePlan $plan, int $currentDay): bool
    {
        $now = Carbon::now();

        // 获取当前天的最后执行时间
        $lastExecutionTime = ChargePlanLog::where('plan_id', $plan->id)
            ->where('day', $currentDay)
            ->where('action', 'gift_card_exchange')
            ->where('status', 'success')
            ->latest('created_at')
            ->value('created_at');

        if ($lastExecutionTime) {
            $executionTime = Carbon::parse($lastExecutionTime);
            $hoursElapsed = $now->diffInHours($executionTime);

            // 需要等待24小时才能进入下一天
            return $hoursElapsed >= 24;
        }

        return false;
    }

    /**
     * 获取当日已执行金额
     */
    private function getDailyExecutedAmount(ChargePlan $plan, int $currentDay): float
    {
        return ChargePlanLog::where('plan_id', $plan->id)
            ->where('day', $currentDay)
            ->where('action', 'gift_card_exchange')
            ->where('status', 'success')
            ->sum('amount') ?? 0;
    }
}
