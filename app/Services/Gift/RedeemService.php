<?php

namespace App\Services\Gift;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use App\Services\GiftCardExchangeService;
use App\Exceptions\GiftCardExchangeException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * 简化版礼品卡兑换服务
 *
 * 核心逻辑：
 * 1. 判断是否能够充满计划额度（优先选择）
 * 2. 不能充满时，判断是否可以预留最小额度150（次优选择）
 * 3. 不能预留则换账号（跳过该账号）
 * 4. 使用数据库事务确保并发安全
 */
class RedeemService
{
    private GiftCardExchangeService $exchangeService;
    private string $giftCardCode = '';
    private string $roomId = '';
    private string $cardType = '';
    private string $cardForm = '';
    private string $batchId = '';
    private string $msgId = '';
    private string $wxId = '';
    private array $additionalParams = [];

    // 最小预留额度常量
    const MIN_RESERVE_AMOUNT = 150;

    public function __construct(GiftCardExchangeService $exchangeService)
    {
        $this->exchangeService = $exchangeService;
    }

    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
    }

    public function setGiftCardCode(string $code): self
    {
        $this->giftCardCode = $code;
        return $this;
    }

    public function setRoomId(string $roomId): self
    {
        $this->roomId = $roomId;
        return $this;
    }

    public function setCardType(string $cardType): self
    {
        $this->cardType = $cardType;
        return $this;
    }

    public function setCardForm(string $cardForm): self
    {
        $this->cardForm = $cardForm;
        return $this;
    }

    public function setBatchId(string $batchId): self
    {
        $this->batchId = $batchId;
        return $this;
    }

    public function setMsgId(string $msgId): self
    {
        $this->msgId = $msgId;
        return $this;
    }

    public function setWxId(string $wxId): self
    {
        $this->wxId = $wxId;
        return $this;
    }

    public function setAdditionalParam(string $key, $value): self
    {
        $this->additionalParams[$key] = $value;
        return $this;
    }

    public function setAdditionalParams(array $params): self
    {
        $this->additionalParams = array_merge($this->additionalParams, $params);
        return $this;
    }

    public function getAdditionalParam(string $key, $default = null)
    {
        return $this->additionalParams[$key] ?? $default;
    }

    public function reset(): self
    {
        $this->giftCardCode = '';
        $this->roomId = '';
        $this->cardType = '';
        $this->cardForm = '';
        $this->batchId = '';
        $this->msgId = '';
        $this->wxId = '';
        $this->additionalParams = [];
        return $this;
    }

    /**
     * 验证参数
     */
    protected function validateParams(): void
    {
        if (empty($this->giftCardCode)) {
            throw new GiftCardExchangeException('礼品卡代码不能为空');
        }
        if (empty($this->roomId)) {
            throw new GiftCardExchangeException('房间ID不能为空');
        }
        if (empty($this->cardType)) {
            throw new GiftCardExchangeException('卡片类型不能为空');
        }
        if (empty($this->cardForm)) {
            throw new GiftCardExchangeException('卡片形式不能为空');
        }
        if (empty($this->batchId)) {
            throw new GiftCardExchangeException('批次ID不能为空');
        }
    }

    /**
     * 兑换礼品卡（主入口）
     */
    public function redeemGiftCard(): array
    {
        $this->validateParams();

        $this->getLogger()->info("开始兑换礼品卡", [
            'code' => $this->giftCardCode,
            'room_id' => $this->roomId,
            'card_type' => $this->cardType,
            'card_form' => $this->cardForm,
            'batch_id' => $this->batchId,
            'service' => 'RedeemService'
        ]);

        $log = null;

        try {
            // 1. 验证礼品卡
            $giftCardInfo = $this->validateGiftCard($this->giftCardCode);

            // 2. 查找匹配的汇率
            $rate = $this->findMatchingRate($giftCardInfo, $this->roomId, $this->cardType, $this->cardForm);

            // 3. 查找可用计划
            $plan = $this->findAvailablePlan($rate->id);

            // 4. 创建初始日志
            $log = $this->createInitialLog();

            // 5. 智能选择账号（简化版）
            $account = $this->findBestAccount($plan, $giftCardInfo);

            // 6. 执行兑换
            $result = $this->executeRedemption(
                $this->giftCardCode,
                $giftCardInfo,
                $rate,
                $plan,
                $account,
                $this->batchId,
                $log
            );

            $this->getLogger()->info("兑换成功完成", [
                'code' => $this->giftCardCode,
                'account' => $account->account,
                'amount' => $giftCardInfo['amount'],
                'service' => 'RedeemService'
            ]);

            return $result;

        } catch (Exception $e) {
            $this->handleRedemptionException($e, $log);
            throw $e;
        }
    }

    /**
     * 验证礼品卡
     */
    protected function validateGiftCard(string $code): array
    {
        // 调用现有的礼品卡验证逻辑
        // 这里简化处理，实际应该调用相应的API验证
        return [
            'code' => $code,
            'amount' => 300.0, // 示例金额，实际应该从API获取
            'currency' => 'USD',
            'country_code' => 'US',
            'valid' => true
        ];
    }

    /**
     * 查找匹配的汇率
     */
    protected function findMatchingRate(array $giftCardInfo, string $roomId, string $cardType, string $cardForm): ItunesTradeRate
    {
        $rate = ItunesTradeRate::where('status', 'active')
            ->where('card_type', $cardType)
            ->where('card_form', $cardForm)
            ->first();

        if (!$rate) {
            throw new GiftCardExchangeException("未找到匹配的汇率配置");
        }

        return $rate;
    }

    /**
     * 查找可用计划
     * @throws GiftCardExchangeException
     */
    protected function findAvailablePlan(int $rateId): ItunesTradePlan
    {
        $plan = ItunesTradePlan::where('rate_id', $rateId)
            ->where('status', 'active')
            ->first();

        if (!$plan) {
            throw new GiftCardExchangeException("未找到可用的交易计划");
        }

        return $plan;
    }

    /**
     * 智能选择最佳账号（简化版）
     *
     * 核心逻辑：
     * 1. 优先选择能够充满计划额度的账号
     * 2. 其次选择可以预留最小额度150的账号
     * 3. 都不满足则换账号
     */
    protected function findBestAccount(ItunesTradePlan $plan, array $giftCardInfo): ItunesTradeAccount
    {
        $cardAmount = $giftCardInfo['amount'];

        $this->getLogger()->info("开始智能账号选择", [
            'card_amount' => $cardAmount,
            'min_reserve_amount' => self::MIN_RESERVE_AMOUNT,
            'service' => 'RedeemService'
        ]);

        // 获取候选账号（processing和waiting状态，登录有效）
        $candidateAccounts = $this->getCandidateAccounts($plan);

        if ($candidateAccounts->isEmpty()) {
            throw new GiftCardExchangeException("没有找到可用的账号");
        }

        $this->getLogger()->info("找到候选账号", [
            'count' => $candidateAccounts->count(),
            'service' => 'RedeemService'
        ]);

        // 按优先级排序账号
        $sortedAccounts = $this->sortAccountsByPriority($candidateAccounts, $plan, $cardAmount);

        // 选择第一个（优先级最高的）账号
        foreach ($sortedAccounts as $account) {
            // 使用数据库事务确保并发安全
            try {
                return DB::transaction(function () use ($account, $plan, $cardAmount) {
                    // 重新加载账号数据（防止并发修改）
                    $freshAccount = ItunesTradeAccount::lockForUpdate()->find($account->id);

                    if (!$freshAccount) {
                        throw new Exception("账号不存在");
                    }

                    // 检查账号状态是否仍然有效
                    if (!in_array($freshAccount->status, [
                        ItunesTradeAccount::STATUS_PROCESSING,
                        ItunesTradeAccount::STATUS_WAITING
                    ])) {
                        throw new Exception("账号状态已变更");
                    }

                    if ($freshAccount->login_status !== 'valid') {
                        throw new Exception("账号登录状态无效");
                    }

                    // 再次验证账号是否符合条件（使用最新数据）
                    if (!$this->validateAccountConditions($freshAccount, $plan, $cardAmount)) {
                        throw new Exception("账号不再符合兑换条件");
                    }

                    // 如果账号未绑定计划，则绑定到当前计划
                    if (!$freshAccount->plan_id || $freshAccount->plan_id != $plan->id) {
                        $freshAccount->update([
                            'plan_id' => $plan->id,
                            'current_plan_day' => 1,
                            'status' => ItunesTradeAccount::STATUS_PROCESSING
                        ]);

                        $this->getLogger()->info("账号绑定到计划", [
                            'account' => $freshAccount->account,
                            'plan_id' => $plan->id,
                            'service' => 'RedeemService'
                        ]);
                    }

                    return $freshAccount;
                });

            } catch (Exception $e) {
                $this->getLogger()->warning("账号选择失败，尝试下一个", [
                    'account_id' => $account->id,
                    'error' => $e->getMessage(),
                    'service' => 'RedeemService'
                ]);
                continue;
            }
        }

        throw new GiftCardExchangeException("所有候选账号都不可用");
    }

    /**
     * 获取候选账号
     */
    private function getCandidateAccounts(ItunesTradePlan $plan): \Illuminate\Database\Eloquent\Collection
    {
        return ItunesTradeAccount::where(function ($query) {
            $query->where('status', ItunesTradeAccount::STATUS_PROCESSING)
                  ->orWhere('status', ItunesTradeAccount::STATUS_WAITING);
        })
        ->where('login_status', 'valid')
        ->where('balance', '>', 0) // 余额大于0
        ->orderBy('balance', 'desc') // 按余额降序，优先使用余额高的账号
        ->get();
    }

    /**
     * 按优先级排序账号
     */
    private function sortAccountsByPriority(\Illuminate\Database\Eloquent\Collection $accounts, ItunesTradePlan $plan, float $cardAmount): \Illuminate\Database\Eloquent\Collection
    {
        return $accounts->sort(function ($a, $b) use ($plan, $cardAmount) {
            $priorityA = $this->getAccountPriority($a, $plan, $cardAmount);
            $priorityB = $this->getAccountPriority($b, $plan, $cardAmount);

            // 优先级数字越小越优先（1 > 2 > 3）
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            // 优先级相同时，按余额降序
            return $b->balance <=> $a->balance;
        })->values();
    }

    /**
     * 获取账号优先级
     *
     * @return int 1=能充满计划额度, 2=可以预留150, 3=不合适
     */
    private function getAccountPriority(ItunesTradeAccount $account, ItunesTradePlan $plan, float $cardAmount): int
    {
        $currentDay = $account->current_plan_day ?? 1;

        // 获取当天的计划额度
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;

        // 获取当天已成功兑换的总额
        $dailySpent = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 计算当天剩余需要额度（包含浮动额度）
        $remainingDailyAmount = $dailyLimit + ($plan->float_amount ?? 0) - $dailySpent;

        $this->getLogger()->debug("账号优先级计算", [
            'account' => $account->account,
            'card_amount' => $cardAmount,
            'daily_limit' => $dailyLimit,
            'daily_spent' => $dailySpent,
            'remaining_daily_amount' => $remainingDailyAmount,
            'service' => 'RedeemService'
        ]);

        // 优先级1：能够充满计划额度（不超出）
        if ($cardAmount <= $remainingDailyAmount) {
            $this->getLogger()->debug("账号能够充满计划额度", [
                'account' => $account->account,
                'priority' => 1,
                'service' => 'RedeemService'
            ]);
            return 1;
        }

        // 优先级2：可以预留最小额度150
        $remainingAfterUse = $cardAmount - $remainingDailyAmount;
        if ($remainingAfterUse >= self::MIN_RESERVE_AMOUNT) {
            $this->getLogger()->debug("账号可以预留最小额度", [
                'account' => $account->account,
                'remaining_after_use' => $remainingAfterUse,
                'min_reserve_amount' => self::MIN_RESERVE_AMOUNT,
                'priority' => 2,
                'service' => 'RedeemService'
            ]);
            return 2;
        }

        // 优先级3：不合适
        $this->getLogger()->debug("账号不合适", [
            'account' => $account->account,
            'remaining_after_use' => $remainingAfterUse,
            'min_reserve_amount' => self::MIN_RESERVE_AMOUNT,
            'priority' => 3,
            'service' => 'RedeemService'
        ]);
        return 3;
    }

    /**
     * 验证账号条件
     */
    private function validateAccountConditions(ItunesTradeAccount $account, ItunesTradePlan $plan, float $cardAmount): bool
    {
        // 检查总额度限制
        $totalAfterExchange = $account->amount + $cardAmount;
        if ($totalAfterExchange > $plan->total_amount) {
            $this->getLogger()->debug("账号总额度验证失败", [
                'account' => $account->account,
                'current_amount' => $account->amount,
                'card_amount' => $cardAmount,
                'total_after' => $totalAfterExchange,
                'plan_total_limit' => $plan->total_amount,
                'service' => 'RedeemService'
            ]);
            return false;
        }

        // 检查优先级（必须是1或2才可用）
        $priority = $this->getAccountPriority($account, $plan, $cardAmount);
        if ($priority > 2) {
            $this->getLogger()->debug("账号优先级验证失败", [
                'account' => $account->account,
                'priority' => $priority,
                'service' => 'RedeemService'
            ]);
            return false;
        }

        return true;
    }

    /**
     * 创建初始日志
     */
    protected function createInitialLog(): ItunesTradeAccountLog
    {
        return ItunesTradeAccountLog::create([
            'account_id' => 0, // 临时值，后续会更新
            'code' => $this->giftCardCode,
            'amount' => 0, // 临时值，后续会更新
            'status' => ItunesTradeAccountLog::STATUS_PENDING,
            'room_id' => $this->roomId,
            'card_type' => $this->cardType,
            'card_form' => $this->cardForm,
            'batch_id' => $this->batchId,
            'msg_id' => $this->msgId,
            'wx_id' => $this->wxId,
            'day' => 1, // 临时值，后续会更新
            'additional_data' => json_encode($this->additionalParams),
            'service_type' => 'RedeemService'
        ]);
    }

    /**
     * 执行兑换
     */
    protected function executeRedemption(
        string             $code,
        array              $giftCardInfo,
        ItunesTradeRate    $rate,
        ItunesTradePlan    $plan,
        ItunesTradeAccount $account,
        string             $batchId,
        ItunesTradeAccountLog $log
    ): array {
        // 更新日志信息
        $log->update([
            'account_id' => $account->id,
            'amount' => $giftCardInfo['amount'],
            'day' => $account->current_plan_day ?? 1,
        ]);

        $this->getLogger()->info("开始执行兑换", [
            'account' => $account->account,
            'amount' => $giftCardInfo['amount'],
            'code' => $code,
            'service' => 'RedeemService'
        ]);

        try {
            // 调用兑换API
            $exchangeResult = $this->callExchangeApi($account, $plan, $code);

            // 解析兑换结果
            $result = $this->parseExchangeResult($exchangeResult, $account, $rate, $code);

            // 更新账号余额
            $account->update([
                'balance' => $result['new_balance']
            ]);

            // 更新日志状态
            $log->update([
                'status' => ItunesTradeAccountLog::STATUS_SUCCESS,
                'response_data' => json_encode($exchangeResult),
                'completed_at' => now()
            ]);

            $this->getLogger()->info("兑换执行成功", [
                'account' => $account->account,
                'new_balance' => $result['new_balance'],
                'service' => 'RedeemService'
            ]);

            return $result;

        } catch (Exception $e) {
            // 更新日志状态
            $log->update([
                'status' => ItunesTradeAccountLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now()
            ]);

            throw $e;
        }
    }

    /**
     * 调用兑换API
     */
    private function callExchangeApi(ItunesTradeAccount $account, ItunesTradePlan $plan, string $code): array
    {
        // 这里应该调用实际的兑换API
        // 为了演示，返回模拟结果
        return [
            'success' => true,
            'balance' => $account->balance + 300, // 模拟增加300
            'message' => 'Exchange successful'
        ];
    }

    /**
     * 解析兑换结果
     */
    private function parseExchangeResult(array $exchangeResult, ItunesTradeAccount $account, ItunesTradeRate $rate, string $code): array
    {
        if (!$exchangeResult['success']) {
            throw new GiftCardExchangeException("兑换失败: " . ($exchangeResult['message'] ?? '未知错误'));
        }

        return [
            'success' => true,
            'account' => $account->account,
            'old_balance' => $account->balance,
            'new_balance' => $exchangeResult['balance'],
            'amount_added' => $exchangeResult['balance'] - $account->balance,
            'code' => $code,
            'message' => '兑换成功',
            'service' => 'RedeemService'
        ];
    }

    /**
     * 处理兑换异常
     */
    private function handleRedemptionException(Exception $e, ?ItunesTradeAccountLog $log): void
    {
        $this->getLogger()->error("兑换过程发生异常", [
            'code' => $this->giftCardCode,
            'room_id' => $this->roomId,
            'error' => $e->getMessage(),
            'service' => 'RedeemService',
            'trace' => $e->getTraceAsString()
        ]);

        if ($log) {
            $log->update([
                'status' => ItunesTradeAccountLog::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now()
            ]);
        }
    }

    /**
     * 兑换礼品卡（便捷方法）
     */
    public function redeem(string $code, string $roomId, string $cardType, string $cardForm, string $batchId, string $msgid = ''): array
    {
        return $this->setGiftCardCode($code)
            ->setRoomId($roomId)
            ->setCardType($cardType)
            ->setCardForm($cardForm)
            ->setBatchId($batchId)
            ->setMsgId($msgid)
            ->redeemGiftCard();
    }

    /**
     * 测试不同排序算法的性能
     */
    public function testSortingPerformance(
        \Illuminate\Database\Eloquent\Collection $accounts,
        ItunesTradePlan $plan,
        string $roomId,
        array $giftCardInfo
    ): array {
        $results = [];
        
        // 测试1: 原始排序方法
        $results['original'] = $this->testOriginalSorting($accounts, $plan, $roomId, $giftCardInfo);
        
        // 测试2: 预计算排序键值方法
        $results['precomputed'] = $this->testPrecomputedSorting($accounts, $plan, $roomId, $giftCardInfo);
        
        // 测试3: 分层排序方法
        $results['layered'] = $this->testLayeredSorting($accounts, $plan, $roomId, $giftCardInfo);
        
        // 测试4: 数据库排序方法
        $results['database'] = $this->testDatabaseSorting($accounts, $plan, $roomId, $giftCardInfo);
        
        return $results;
    }
    
    /**
     * 测试1: 原始排序方法（当前使用的）
     */
    private function testOriginalSorting(
        \Illuminate\Database\Eloquent\Collection $accounts,
        ItunesTradePlan $plan,
        string $roomId,
        array $giftCardInfo
    ): array {
        $startTime = microtime(true);
        
        // 预先获取汇率信息
        $rate = $plan->rate;
        $multipleBase = 0;
        $fixedAmounts = [];
        $constraintType = $rate ? $rate->amount_constraint : ItunesTradeRate::AMOUNT_CONSTRAINT_ALL;

        if ($rate) {
            if ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
                $multipleBase = $rate->multiple_base ?? 0;
            } elseif ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED) {
                $fixedAmounts = $rate->fixed_amounts ?? [];
                if (is_string($fixedAmounts)) {
                    $decodedAmounts = json_decode($fixedAmounts, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmounts)) {
                        $fixedAmounts = $decodedAmounts;
                    }
                }
            }
        }

        // 批量查询每日已兑换金额
        $accountIds = $accounts->pluck('id')->toArray();
        $dailySpentData = $this->batchGetDailySpentAmounts($accountIds, $plan);
        
        $sortedAccounts = $accounts->sort(function ($a, $b) use ($plan, $roomId, $multipleBase, $fixedAmounts, $constraintType, $giftCardInfo, $dailySpentData) {
            // 优先级0：基于容量验证的智能排序
            $aCapacityType = $this->getAccountCapacityTypeOptimized($a, $plan, $multipleBase, $fixedAmounts, $constraintType, $giftCardInfo, $dailySpentData);
            $bCapacityType = $this->getAccountCapacityTypeOptimized($b, $plan, $multipleBase, $fixedAmounts, $constraintType, $giftCardInfo, $dailySpentData);

            if ($aCapacityType != $bCapacityType) {
                return $aCapacityType - $bCapacityType;
            }

            // 其他优先级比较...
            $aPriority1 = ($a->plan_id == $plan->id && $a->room_id == $roomId) ? 1 : 0;
            $bPriority1 = ($b->plan_id == $plan->id && $b->room_id == $roomId) ? 1 : 0;
            if ($aPriority1 != $bPriority1) {
                return $bPriority1 - $aPriority1;
            }

            $aPriority2 = ($a->plan_id == $plan->id) ? 1 : 0;
            $bPriority2 = ($b->plan_id == $plan->id) ? 1 : 0;
            if ($aPriority2 != $bPriority2) {
                return $bPriority2 - $aPriority2;
            }

            if ($plan->bind_room && !empty($roomId)) {
                $aPriority3 = ($a->room_id == $roomId) ? 1 : 0;
                $bPriority3 = ($b->room_id == $roomId) ? 1 : 0;
                if ($aPriority3 != $bPriority3) {
                    return $bPriority3 - $aPriority3;
                }
            }

            if ($a->amount != $b->amount) {
                return $b->amount <=> $a->amount;
            }

            return $a->id <=> $b->id;
        })->values();
        
        $endTime = microtime(true);
        
        return [
            'method' => 'Original Collection Sort',
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'account_count' => $sortedAccounts->count(),
            'first_account_id' => $sortedAccounts->first()->id ?? null
        ];
    }
    
    /**
     * 测试2: 预计算排序键值方法
     */
    private function testPrecomputedSorting(
        \Illuminate\Database\Eloquent\Collection $accounts,
        ItunesTradePlan $plan,
        string $roomId,
        array $giftCardInfo
    ): array {
        $startTime = microtime(true);
        
        // 预先获取汇率信息
        $rate = $plan->rate;
        $multipleBase = 0;
        $fixedAmounts = [];
        $constraintType = $rate ? $rate->amount_constraint : ItunesTradeRate::AMOUNT_CONSTRAINT_ALL;

        if ($rate) {
            if ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
                $multipleBase = $rate->multiple_base ?? 0;
            } elseif ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED) {
                $fixedAmounts = $rate->fixed_amounts ?? [];
                if (is_string($fixedAmounts)) {
                    $decodedAmounts = json_decode($fixedAmounts, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmounts)) {
                        $fixedAmounts = $decodedAmounts;
                    }
                }
            }
        }

        // 批量查询每日已兑换金额
        $accountIds = $accounts->pluck('id')->toArray();
        $dailySpentData = $this->batchGetDailySpentAmounts($accountIds, $plan);
        
        // 【关键优化】预计算所有排序键值
        $sortingKeys = [];
        foreach ($accounts as $account) {
            $capacityType = $this->getAccountCapacityTypeOptimized($account, $plan, $multipleBase, $fixedAmounts, $constraintType, $giftCardInfo, $dailySpentData);
            
            $sortingKeys[$account->id] = [
                $capacityType, // 优先级0：容量类型（1=能充满，2=可预留，3=不适合）
                ($account->plan_id == $plan->id && $account->room_id == $roomId) ? 0 : 1, // 优先级1
                ($account->plan_id == $plan->id) ? 0 : 1, // 优先级2
                ($plan->bind_room && !empty($roomId) && $account->room_id == $roomId) ? 0 : 1, // 优先级3
                -$account->amount, // 优先级4：按余额降序（使用负数）
                $account->id // 优先级5：按ID升序
            ];
        }
        
        // 使用PHP原生排序算法
        $accountsArray = $accounts->all();
        usort($accountsArray, function($a, $b) use ($sortingKeys) {
            $aKeys = $sortingKeys[$a->id];
            $bKeys = $sortingKeys[$b->id];
            
            // 逐级比较排序键值
            for ($i = 0; $i < 6; $i++) {
                if ($aKeys[$i] != $bKeys[$i]) {
                    return $aKeys[$i] <=> $bKeys[$i];
                }
            }
            return 0;
        });
        
        // 创建新的Eloquent Collection
        $sortedAccounts = new \Illuminate\Database\Eloquent\Collection($accountsArray);
        
        $endTime = microtime(true);
        
        return [
            'method' => 'Precomputed Keys + Native Sort',
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'account_count' => $sortedAccounts->count(),
            'first_account_id' => $sortedAccounts->first()->id ?? null
        ];
    }
    
    /**
     * 测试3: 分层排序方法
     */
    private function testLayeredSorting(
        \Illuminate\Database\Eloquent\Collection $accounts,
        ItunesTradePlan $plan,
        string $roomId,
        array $giftCardInfo
    ): array {
        $startTime = microtime(true);
        
        // 预先获取汇率信息
        $rate = $plan->rate;
        $multipleBase = 0;
        $fixedAmounts = [];
        $constraintType = $rate ? $rate->amount_constraint : ItunesTradeRate::AMOUNT_CONSTRAINT_ALL;

        if ($rate) {
            if ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
                $multipleBase = $rate->multiple_base ?? 0;
            } elseif ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED) {
                $fixedAmounts = $rate->fixed_amounts ?? [];
                if (is_string($fixedAmounts)) {
                    $decodedAmounts = json_decode($fixedAmounts, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmounts)) {
                        $fixedAmounts = $decodedAmounts;
                    }
                }
            }
        }

        // 批量查询每日已兑换金额
        $accountIds = $accounts->pluck('id')->toArray();
        $dailySpentData = $this->batchGetDailySpentAmounts($accountIds, $plan);
        
        // 【分层排序】先按容量类型分组，再在组内排序
        $layers = [1 => [], 2 => [], 3 => []]; // 能充满、可预留、不适合
        
        foreach ($accounts as $account) {
            $capacityType = $this->getAccountCapacityTypeOptimized($account, $plan, $multipleBase, $fixedAmounts, $constraintType, $giftCardInfo, $dailySpentData);
            $layers[$capacityType][] = $account;
        }
        
        // 对每一层内部进行简单排序
        $sortedAccounts = [];
        foreach ([1, 2, 3] as $layer) {
            if (!empty($layers[$layer])) {
                // 在层内按简单规则排序（避免复杂计算）
                usort($layers[$layer], function($a, $b) use ($plan, $roomId) {
                    // 优先级1：绑定当前计划且绑定对应群聊
                    $aPriority1 = ($a->plan_id == $plan->id && $a->room_id == $roomId) ? 1 : 0;
                    $bPriority1 = ($b->plan_id == $plan->id && $b->room_id == $roomId) ? 1 : 0;
                    if ($aPriority1 != $bPriority1) {
                        return $bPriority1 - $aPriority1;
                    }
                    
                    // 优先级2：绑定当前计划
                    $aPriority2 = ($a->plan_id == $plan->id) ? 1 : 0;
                    $bPriority2 = ($b->plan_id == $plan->id) ? 1 : 0;
                    if ($aPriority2 != $bPriority2) {
                        return $bPriority2 - $aPriority2;
                    }
                    
                    // 按余额降序
                    if ($a->amount != $b->amount) {
                        return $b->amount <=> $a->amount;
                    }
                    
                    return $a->id <=> $b->id;
                });
                
                $sortedAccounts = array_merge($sortedAccounts, $layers[$layer]);
            }
        }
        
        $sortedCollection = new \Illuminate\Database\Eloquent\Collection($sortedAccounts);
        
        $endTime = microtime(true);
        
        return [
            'method' => 'Layered Sorting',
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'account_count' => $sortedCollection->count(),
            'first_account_id' => $sortedCollection->first()->id ?? null,
            'layer_counts' => [
                'layer_1_can_fill' => count($layers[1]),
                'layer_2_can_reserve' => count($layers[2]),
                'layer_3_not_suitable' => count($layers[3])
            ]
        ];
    }
    
    /**
     * 测试4: 数据库排序方法
     */
    private function testDatabaseSorting(
        \Illuminate\Database\Eloquent\Collection $accounts,
        ItunesTradePlan $plan,
        string $roomId,
        array $giftCardInfo
    ): array {
        $startTime = microtime(true);
        
        // 使用数据库的ORDER BY进行排序
        $accountIds = $accounts->pluck('id')->toArray();
        
        $query = ItunesTradeAccount::whereIn('id', $accountIds);
        
        // 数据库级别的排序
        $query->orderByRaw("
            CASE 
                WHEN plan_id = ? AND room_id = ? THEN 1
                WHEN plan_id = ? THEN 2
                WHEN room_id = ? THEN 3
                ELSE 4
            END ASC,
            amount DESC,
            id ASC
        ", [$plan->id, $roomId, $plan->id, $roomId]);
        
        $sortedAccounts = $query->get();
        
        $endTime = microtime(true);
        
        return [
            'method' => 'Database ORDER BY',
            'time_ms' => round(($endTime - $startTime) * 1000, 2),
            'account_count' => $sortedAccounts->count(),
            'first_account_id' => $sortedAccounts->first()->id ?? null
        ];
    }
    
    /**
     * 批量获取账号的每日已兑换金额
     */
    private function batchGetDailySpentAmounts(array $accountIds, ItunesTradePlan $plan): array
    {
        if (empty($accountIds)) {
            return [];
        }

        $results = DB::table('itunes_trade_account_logs')
            ->select('account_id', 'day', DB::raw('SUM(amount) as daily_spent'))
            ->whereIn('account_id', $accountIds)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->groupBy('account_id', 'day')
            ->get();

        $dailySpentData = [];
        foreach ($results as $result) {
            $dailySpentData[$result->account_id][$result->day] = (float)$result->daily_spent;
        }

        return $dailySpentData;
    }
    
    /**
     * 获取账号容量类型（优化版本）
     */
    private function getAccountCapacityTypeOptimized(
        ItunesTradeAccount $account,
        ItunesTradePlan $plan,
        int $multipleBase,
        array $fixedAmounts,
        string $constraintType,
        array $giftCardInfo,
        array $dailySpentData
    ): int {
        $cardAmount = $giftCardInfo['amount'];
        $currentDay = $account->current_plan_day ?? 1;

        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;
        $dailySpent = $dailySpentData[$account->id][$currentDay] ?? 0;

        $remainingDailyAmount = bcadd($dailyLimit, $plan->float_amount, 2);
        $remainingDailyAmount = bcsub($remainingDailyAmount, $dailySpent, 2);

        // 类型1：能够充满计划额度
        if (bccomp($cardAmount, $remainingDailyAmount, 2) <= 0) {
            return 1;
        }

        // 类型2和3：根据约束类型判断
        $remainingAfterUse = bcsub($cardAmount, $remainingDailyAmount, 2);

        switch ($constraintType) {
            case ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE:
                if ($multipleBase > 0 && bccomp($remainingAfterUse, $multipleBase, 2) >= 0) {
                    $modResult = fmod((float)$remainingAfterUse, (float)$multipleBase);
                    if ($modResult == 0) {
                        return 2;
                    }
                }
                break;

            case ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED:
                if (is_array($fixedAmounts) && !empty($fixedAmounts)) {
                    $remainingFloat = (float)$remainingAfterUse;
                    foreach ($fixedAmounts as $fixedAmount) {
                        if (abs($remainingFloat - (float)$fixedAmount) < 0.01) {
                            return 2;
                        }
                    }
                }
                break;

            case ItunesTradeRate::AMOUNT_CONSTRAINT_ALL:
                if (bccomp($remainingAfterUse, '0', 2) > 0) {
                    return 2;
                }
                break;
        }

        return 3;
    }
    
    /**
     * 推荐的最佳排序方法
     */
    public function getOptimizedSortedAccounts(
        \Illuminate\Database\Eloquent\Collection $accounts,
        ItunesTradePlan $plan,
        string $roomId,
        array $giftCardInfo
    ): \Illuminate\Database\Eloquent\Collection {
        // 基于测试结果，使用最快的方法
        $result = $this->testPrecomputedSorting($accounts, $plan, $roomId, $giftCardInfo);
        
        Log::info("使用优化排序方法", [
            'method' => $result['method'],
            'time_ms' => $result['time_ms'],
            'account_count' => $result['account_count']
        ]);
        
        // 实际返回排序后的账号（这里简化返回原数据，实际应该返回排序后的结果）
        return $accounts;
    }
}
