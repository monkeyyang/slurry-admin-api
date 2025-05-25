<?php

namespace App\Services;

use App\Models\ChargePlan;
use App\Models\AccountGroup;
use App\Models\ChargePlanLog;
use App\Models\GiftCardExchangeRecord;
use App\Models\GiftCardTask;
use App\Models\AccountBalanceLimit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class GiftCardExchangeService
{
    public $giftCardApiClient;
    protected $dataSyncApiUrl = 'https://api.example.com/sync-data'; // 替换为实际的API地址

    /**
     * 构造函数
     *
     * @param GiftCardApiClient $giftCardApiClient
     */
    public function __construct(GiftCardApiClient $giftCardApiClient)
    {
        $this->giftCardApiClient = $giftCardApiClient;
    }

    /**
     * 处理兑换消息
     *
     * @param string $message 消息内容 (例如 "XQPD5D7KJ8TGZT4L /1")
     * @return array 处理结果
     */
    public function processExchangeMessage(string $message): array
    {
        try {
            Log::info('收到兑换消息: ' . $message);

            // 解析消息
            $parts = $this->parseMessage($message);
            if (!$parts) {
                throw new \Exception('消息格式无效');
            }

            $cardNumber = $parts['card_number'];
            $cardType = $parts['card_type'];

            // 验证礼品卡
            $cardInfo = $this->validateGiftCard($cardNumber);
            if (!$cardInfo['is_valid']) {
                throw new \Exception('礼品卡无效: ' . ($cardInfo['message'] ?? '未知原因'));
            }
            $cardInfo['card_number'] = $cardNumber;
            $cardInfo['card_type'] = $cardType;

            // 获取国家代码
            $countryCode = $cardInfo['country_code'];
            var_dump($cardInfo);exit;
            // 选择合适的计划，传入卡余额用于检查账号额度上限
            $plan = $this->selectEligiblePlan($countryCode, $cardType, $cardInfo['balance']);
            if (!$plan) {
                throw new \Exception('没有找到合适的可执行计划，可能所有账号已达额度上限');
            }

            // 确保账号已登录
            $loginResult = $this->ensureAccountLoggedIn($plan->account);
            if (!$loginResult['success']) {
                throw new \Exception('账号登录失败: ' . $loginResult['message']);
            }

            // 加入兑换队列并执行
            $exchangeResult = $this->executeExchange($plan, $cardNumber, $cardInfo);

            // 计算汇率并更新金额
            $rateInfo = $this->calculateRate($countryCode, $cardType, $cardInfo['balance']);

            // 记录信息
            $recordData = $this->recordExchangeInfo($plan, $cardInfo, $rateInfo, $exchangeResult);

            // 同步数据
            $syncResult = $this->syncData($recordData);

            return [
                'success' => true,
                'message' => '兑换成功',
                'data' => [
                    'plan_id' => $plan->id,
                    'account' => $plan->account,
                    'card_number' => $cardNumber,
                    'country' => $countryCode,
                    'balance' => $cardInfo['balance'],
                    'converted_amount' => $rateInfo['converted_amount'],
                    'exchange_rate' => $rateInfo['rate'],
                    'exchange_time' => now()->toDateTimeString(),
                    'task_id' => $exchangeResult['task_id'] ?? null,
                ]
            ];
        } catch (\Exception $e) {
            Log::error('兑换处理失败: ' . $e->getMessage());
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
     */
    public function validateGiftCard(string $cardNumber): array
    {
        try {
            Log::info('验证礼品卡: ' . $cardNumber);

            // 创建查卡任务
            $queryTask = $this->createCardQueryTask([$cardNumber]);
            if ($queryTask['code'] !== 0) {
                throw new \Exception('创建查卡任务失败: ' . ($queryTask['msg'] ?? '未知错误'));
            }

            $taskId = $queryTask['data']['task_id'];
            Log::info('查卡任务创建成功, 任务ID: ' . $taskId);

            // 等待任务完成
            $result = $this->waitForCardQueryTaskComplete($taskId);

            if (!$result) {
                throw new \Exception('查卡任务执行超时或失败');
            }

            // 解析查卡结果
            $taskResult = $this->getCardQueryResult($taskId, $cardNumber);
            if (!$taskResult) {
                throw new \Exception('无法获取查卡结果');
            }

            // 根据查卡结果构造返回数据
            return [
                'is_valid' => str_contains($taskResult['validation'], '有效卡'),
                'country_code' => $this->mapCountryNameToCode($taskResult['country']),
                'balance' => $this->parseBalance($taskResult['balance']),
                'currency' => $this->parseCurrency($taskResult['balance']),
                'message' => $taskResult['msg'],
                'card_number' => $taskResult['cardNumber'],
                'card_type' => 1, // 默认卡类型
            ];
        } catch (\Exception $e) {
            Log::error('礼品卡验证失败: ' . $e->getMessage());
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
        $result = $this->giftCardApiClient->createCardQueryTask($cards);

        // 保存任务记录
        if ($result['code'] === 0 && isset($result['data']['task_id'])) {
            GiftCardTask::create([
                'task_id' => $result['data']['task_id'],
                'type' => GiftCardTask::TYPE_QUERY,
                'status' => GiftCardTask::STATUS_PENDING,
                'request_data' => $cards
            ]);
        }

        return $result;
    }

    /**
     * 等待查卡任务完成
     *
     * @param string $taskId 任务ID
     * @return bool 任务是否成功完成
     */
    protected function waitForCardQueryTaskComplete(string $taskId): bool
    {
        $maxAttempts = config('gift_card.polling.max_attempts', 20);
        $interval = config('gift_card.polling.interval', 3);

        $task = GiftCardTask::where('task_id', $taskId)->first();
        if (!$task) {
            Log::error('查卡任务记录不存在: ' . $taskId);
            return false;
        }

        $task->markAsProcessing();

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $result = $this->giftCardApiClient->getCardQueryTaskStatus($taskId);

            if ($result['code'] !== 0) {
                Log::error('查询查卡任务状态失败: ' . ($result['msg'] ?? '未知错误'));
                continue;
            }

            $status = $result['data']['status'] ?? '';

            // 更新任务状态
            if ($status === 'completed') {
                $task->markAsCompleted($result['data']);
                return true;
            } elseif ($status === 'failed') {
                $task->markAsFailed($result['data']['msg'] ?? '任务失败');
                return false;
            }

            // 等待一段时间后继续查询
            sleep($interval);
        }

        // 如果达到最大尝试次数仍未完成，则标记为失败
        $task->markAsFailed('任务执行超时');
        return false;
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
                $resultData = json_decode($item['result'], true) ?? [];
                return $resultData;
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
            'US-美国' => 'US'
            // 可以根据需要添加更多映射
        ];

        return $countryMap[$countryName] ?? 'UNKNOWN';
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
        if (strpos($balanceStr, '$') === 0) {
            return 'USD';
        } elseif (strpos($balanceStr, '£') === 0) {
            return 'GBP';
        } elseif (strpos($balanceStr, '€') === 0) {
            return 'EUR';
        } elseif (strpos($balanceStr, '¥') === 0) {
            return 'JPY';
        } elseif (strpos($balanceStr, 'AU$') === 0) {
            return 'AUD';
        } elseif (strpos($balanceStr, 'CAD$') === 0 || strpos($balanceStr, 'C$') === 0) {
            return 'CAD';
        }

        // 默认返回USD
        return 'USD';
    }

    /**
     * 选择符合条件的计划
     *
     * @param string $countryCode
     * @param int $cardType
     * @param float $cardBalance 卡余额
     * @return ChargePlan|null
     */
    public function selectEligiblePlan(string $countryCode, int $cardType, float $cardBalance = 0): ?ChargePlan
    {
        try {
            Log::info("选择计划, 国家: {$countryCode}, 卡类型: {$cardType}, 卡余额: {$cardBalance}");

            // 获取状态为处理中的相关国家计划
            $query = ChargePlan::where('country', $countryCode);

            // 可以根据卡类型进行进一步筛选，例如根据优先级或组
            if ($cardType == 1) {
                // 例如卡类型1优先选择高优先级的计划
                $query->orderBy('priority', 'desc');
            } else {
                // 默认按创建时间排序
                $query->orderBy('created_at', 'asc');
            }

            // 获取所有可能的计划
            $plans = $query->get();

            // 过滤出符合执行时间要求的计划
            $now = Carbon::now();
            $eligiblePlans = [];

            foreach ($plans as $plan) {
                // 检查最后执行时间
                $lastExecution = ChargePlanLog::where('plan_id', $plan->id)
                    ->where('action', 'like', '%executed%')
                    ->orderBy('created_at', 'desc')
                    ->first();
                // 是否满足时间间隔要求
                $timeEligible = false;
                if (!$lastExecution) {
                    // 从未执行过，可以立即执行
                    $timeEligible = true;
                } else {
                    // 检查是否已经过了最小执行间隔
                    $lastTime = Carbon::parse($lastExecution->created_at);
                    $minIntervalHours = $plan->interval_hours ?? 24; // 默认间隔为24小时

                    if ($now->diffInHours($lastTime) >= $minIntervalHours) {
                        $timeEligible = true;
                    }
                }

                if ($timeEligible) {
                    $eligiblePlans[] = $plan;
                }
            }

            // 如果没有符合时间要求的计划，返回null
            if (empty($eligiblePlans)) {
                return null;
            }

            // 检查账号余额上限
            foreach ($eligiblePlans as $plan) {
                // 转换卡余额为目标货币金额
                $rateInfo = $this->calculateRate($countryCode, $cardType, $cardBalance);
                $convertedAmount = $rateInfo['converted_amount'] ?? $cardBalance;

                // 检查账号余额上限
                $checkResult = $this->checkAccountBalanceLimit($plan->account, $convertedAmount);

                if ($checkResult['success']) {
                    // 该账号可以接收此次兑换
                    return $plan;
                } else {
                    // 记录日志：账号已达到额度上限
                    Log::info("账号 {$plan->account} 已达到额度上限，切换到其他账号");

                    // 尝试获取替代账号
                    $alternativeAccount = $this->getAlternativeAccount($convertedAmount, $countryCode);
                    if ($alternativeAccount) {
                        Log::info("找到替代账号: {$alternativeAccount->account}");

                        // 查找与替代账号关联的计划
                        $altPlan = ChargePlan::where('account', $alternativeAccount->account)
                            ->where('status', 'processing')
                            ->where('country', $countryCode)
                            ->first();

                        if ($altPlan) {
                            return $altPlan;
                        } else {
                            // 如果没有找到替代账号的计划，创建一个新计划
                            // 注意：这里可以根据实际业务逻辑决定是否需要创建新计划
                            Log::info("替代账号没有关联计划，可以考虑创建新计划");
                        }
                    }
                }
            }

            // 如果所有符合条件的计划都达到了额度上限，且没有找到替代账号，返回null
            return null;
        } catch (\Exception $e) {
            Log::error('选择计划失败: ' . $e->getMessage());
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
        } catch (\Exception $e) {
            Log::error("检查账号 {$account} 余额上限失败: " . $e->getMessage());
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
            $alternativeAccount = AccountBalanceLimit::whereIn('account', $accountsWithPlans)
                ->where('status', AccountBalanceLimit::STATUS_ACTIVE)
                ->whereRaw('current_balance + ? <= balance_limit', [$amount])
                ->orderByRaw('(balance_limit - current_balance) DESC') // 按剩余可用余额排序
                ->first();

            return $alternativeAccount;
        } catch (\Exception $e) {
            Log::error("获取替代账号失败: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 执行兑换
     *
     * @param ChargePlan $plan
     * @param string $cardNumber
     * @param array $cardInfo
     * @return array
     */
    public function executeExchange(ChargePlan $plan, string $cardNumber, array $cardInfo): array
    {
        try {
            Log::info("执行兑换, 计划ID: {$plan->id}, 卡号: {$cardNumber}");

            DB::beginTransaction();

            // 获取当前执行的plan item
            $currentDay = $plan->current_day ?? 1;
            $item = $plan->items()
                ->where('day', $currentDay)
                ->where('status', 'pending')
                ->orderBy('id', 'asc')
                ->first();

            if (!$item) {
                throw new \Exception("找不到待执行的项目");
            }

            // 标记为处理中
            $item->status = 'processing';
            $item->save();

            // 创建兑换任务
            $redemptionData = [
                [
                    'username' => $plan->account,
                    'password' => '', // 假设账号已经登录成功
                    'verify_url' => '',
                    'pin' => $cardNumber
                ]
            ];

            // 创建兑换任务
            $redemptionTask = $this->createRedemptionTask($redemptionData, config('gift_card.redemption.interval', 6));
            if ($redemptionTask['code'] !== 0) {
                throw new \Exception('创建兑换任务失败: ' . ($redemptionTask['msg'] ?? '未知错误'));
            }

            $taskId = $redemptionTask['data']['task_id'];
            Log::info('兑换任务创建成功, 任务ID: ' . $taskId);

            // 等待任务完成
            $result = $this->waitForRedemptionTaskComplete($taskId);
            if (!$result) {
                throw new \Exception('兑换任务执行超时或失败');
            }

            // 解析兑换结果
            $taskResult = $this->getRedemptionResult($taskId, $plan->account, $cardNumber);
            if (!$taskResult) {
                throw new \Exception('无法获取兑换结果');
            }

            // 判断兑换是否成功
            $isSuccess = $taskResult['code'] === 0;

            // 记录结果
            $result = $isSuccess
                ? "兑换成功: " . ($taskResult['msg'] ?? '')
                : "兑换失败: " . ($taskResult['msg'] ?? '');

            // 更新项目状态
            $item->status = $isSuccess ? 'completed' : 'failed';
            $item->executed_at = now();
            $item->result = $result;
            $item->save();

            // 如果兑换成功，更新计划已充值金额
            if ($isSuccess) {
                $plan->charged_amount = ($plan->charged_amount ?? 0) + $item->amount;
                $plan->save();

                // 如果计划属于组，更新组金额
                if ($plan->group_id) {
                    $group = $plan->group;
                    if ($group) {
                        $group->incrementAmount($item->amount);
                    }
                }
            }

            // 创建日志
            ChargePlanLog::create([
                'plan_id' => $plan->id,
                'item_id' => $item->id,
                'day' => $currentDay,
                'time' => Carbon::now()->format('H:i:s'),
                'action' => '礼品卡兑换',
                'status' => $isSuccess ? 'success' : 'failed',
                'details' => "卡号: {$cardNumber}, " . $result
            ]);

            DB::commit();

            return [
                'success' => $isSuccess,
                'transaction_id' => $taskId,
                'amount' => $item->amount,
                'plan_id' => $plan->id,
                'item_id' => $item->id,
                'message' => $taskResult['msg'] ?? '',
                'task_id' => $taskId
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('执行兑换失败: ' . $e->getMessage());
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
     * @return bool 任务是否成功完成
     */
    protected function waitForRedemptionTaskComplete(string $taskId): bool
    {
        $maxAttempts = config('gift_card.polling.max_attempts', 20);
        $interval = config('gift_card.polling.interval', 3);

        $task = GiftCardTask::where('task_id', $taskId)->first();
        if (!$task) {
            Log::error('兑换任务记录不存在: ' . $taskId);
            return false;
        }

        $task->markAsProcessing();

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $result = $this->giftCardApiClient->getRedemptionTaskStatus($taskId);

            if ($result['code'] !== 0) {
                Log::error('查询兑换任务状态失败: ' . ($result['msg'] ?? '未知错误'));
                continue;
            }

            $status = $result['data']['status'] ?? '';

            // 更新任务状态
            if ($status === 'completed') {
                $task->markAsCompleted($result['data']);
                return true;
            } elseif ($status === 'failed') {
                $task->markAsFailed($result['data']['msg'] ?? '任务失败');
                return false;
            }

            // 等待一段时间后继续查询
            sleep($interval);
        }

        // 如果达到最大尝试次数仍未完成，则标记为失败
        $task->markAsFailed('任务执行超时');
        return false;
    }

    /**
     * 获取兑换结果
     *
     * @param string $taskId 任务ID
     * @param string $username 用户名
     * @param string $cardNumber 卡号
     * @return array|null 兑换结果
     */
    protected function getRedemptionResult(string $taskId, string $username, string $cardNumber): ?array
    {
        $task = GiftCardTask::where('task_id', $taskId)->first();
        if (!$task || !$task->isCompleted() || !$task->result_data) {
            return null;
        }

        $items = $task->result_data['items'] ?? [];
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
     */
    public function calculateRate(string $countryCode, int $cardType, float $balance): array
    {
        try {
            Log::info("计算汇率, 国家: {$countryCode}, 卡类型: {$cardType}, 余额: {$balance}");

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
        } catch (\Exception $e) {
            Log::error('计算汇率失败: ' . $e->getMessage());
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
            Log::info('记录兑换信息: ' . $record->id);

            // 如果兑换成功，更新账号余额
            if ($exchangeResult['success']) {
                // 更新账号余额
                $accountLimit = AccountBalanceLimit::where('account', $plan->account)->first();
                if ($accountLimit) {
                    $accountLimit->addBalance($rateInfo['converted_amount']);
                    Log::info("更新账号 {$plan->account} 余额: +{$rateInfo['converted_amount']}");
                }
            }

            return $record->toArray();
        } catch (\Exception $e) {
            Log::error('记录兑换信息失败: ' . $e->getMessage());
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
            Log::info('同步数据: ' . json_encode($data));

            // 这里应该调用实际的API同步数据
            // $response = Http::post($this->dataSyncApiUrl, $data);

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
        } catch (\Exception $e) {
            Log::error('同步数据失败: ' . $e->getMessage());
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
                Log::info("账号 {$account} 已登录或刷新成功");
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
        } catch (\Exception $e) {
            Log::error("确保账号 {$account} 登录失败: " . $e->getMessage());
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
                Log::error('创建登录任务失败: ' . ($result['msg'] ?? '未知错误'));
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
        } catch (\Exception $e) {
            Log::error('创建登录任务异常: ' . $e->getMessage());
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
        } catch (\Exception $e) {
            Log::error('查询登录任务状态异常: ' . $e->getMessage());
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
        } catch (\Exception $e) {
            Log::error('删除用户登录异常: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => '删除用户登录异常: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
}
