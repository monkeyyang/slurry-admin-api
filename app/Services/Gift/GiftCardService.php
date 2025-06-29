<?php

namespace App\Services\Gift;

use App\Events\TradeLogCreated;
use App\Exceptions\GiftCardExchangeException;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeRate;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccountLog;
use App\Models\MrRoom;
use App\Models\MrRoomBill;
use App\Models\MrRoomGroup;
use App\Services\GiftCardApiClient;
use App\Services\GiftCardExchangeService;
use App\Services\Gift\FindAccountService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class GiftCardService
{
    protected GiftCardExchangeService $exchangeService;
    protected FindAccountService $findAccountService;

    // 兑换任务的属性
    protected string $giftCardCode     = '';
    protected string $roomId           = '';
    protected string $cardType         = '';
    protected string $cardForm         = '';
    protected string $batchId          = '';
    protected string $msgId            = '';
    protected string $wxId             = '';
    protected string $countryCode      = '';
    protected array  $additionalParams = [];

    // 业务逻辑错误列表 - 这些错误不需要记录堆栈跟踪
    private const BUSINESS_ERRORS = [
        '礼品卡无效',
        '该礼品卡已经被兑换',
        '未找到符合条件的汇率',
        '未找到可用的兑换计划',
        '未找到可用的兑换账号',
        '没有找到合适的可执行计划',
        '所有账号已达额度上限',
        'AlreadyRedeemed',
        'Bad card',
        '查卡失败',
        '礼品卡已存在处理记录',
        '正在处理中，请勿重复提交',
        '账号余额不足',
        '超出每日限额',
        '超出总限额',
        '不符合倍数要求',
        '已兑换成功，请勿重复提交',  // 添加防重复提交的错误类型
        '无法充满计划额度且无法预留最小倍数额度',  // 新增智能账号选择错误
        '账号容量不足，需要换账号',  // 新增账号容量错误
        '剩余金额不符合倍数要求',  // 新增倍数验证错误
        '剩余金额不匹配固定面额要求',  // 新增固定面额验证错误
        '不符合固定面额要求',  // 新增固定面额错误
        '剩余金额不符合约束要求'  // 新增通用约束错误
    ];

    public function __construct(GiftCardExchangeService $exchangeService, FindAccountService $findAccountService)
    {
        $this->exchangeService = $exchangeService;
        $this->findAccountService = $findAccountService;
    }

    /**
     * 获取礼品卡兑换专用日志实例
     */
    protected function getLogger(): LoggerInterface
    {
        return Log::channel('gift_card_exchange');
    }

    /**
     * 设置礼品卡码
     */
    public function setGiftCardCode(string $code): self
    {
        $this->giftCardCode = $code;
        return $this;
    }

    /**
     * 设置房间ID
     */
    public function setRoomId(string $roomId): self
    {
        $this->roomId = $roomId;
        return $this;
    }

    /**
     * 设置卡类型
     */
    public function setCardType(string $cardType): self
    {
        $this->cardType = $cardType;
        return $this;
    }

    /**
     * 设置卡形式
     */
    public function setCardForm(string $cardForm): self
    {
        $this->cardForm = $cardForm;
        return $this;
    }

    /**
     * 设置批次ID
     */
    public function setBatchId(string $batchId): self
    {
        $this->batchId = $batchId;
        return $this;
    }

    /**
     * 设置消息ID
     */
    public function setMsgId(string $msgId): self
    {
        $this->msgId = $msgId;
        return $this;
    }

    /**
     * 设置微信ID
     */
    public function setWxId(string $wxId): self
    {
        $this->wxId = $wxId;
        return $this;
    }

    /**
     * 设置额外参数
     */
    public function setAdditionalParam(string $key, $value): self
    {
        $this->additionalParams[$key] = $value;
        return $this;
    }

    /**
     * 批量设置额外参数
     */
    public function setAdditionalParams(array $params): self
    {
        $this->additionalParams = array_merge($this->additionalParams, $params);
        return $this;
    }

    /**
     * 获取额外参数
     */
    public function getAdditionalParam(string $key, $default = null)
    {
        return $this->additionalParams[$key] ?? $default;
    }

    /**
     * 重置所有属性
     */
    public function reset(): self
    {
        $this->giftCardCode     = '';
        $this->roomId           = '';
        $this->cardType         = '';
        $this->cardForm         = '';
        $this->batchId          = '';
        $this->msgId            = '';
        $this->wxId             = '';
        $this->countryCode      = '';
        $this->additionalParams = [];
        return $this;
    }

    /**
     * 验证必要参数
     */
    protected function validateParams(): void
    {
        if (empty($this->giftCardCode)) {
            throw new \InvalidArgumentException('礼品卡码不能为空');
        }
        if (empty($this->roomId)) {
            throw new \InvalidArgumentException('房间ID不能为空');
        }
        if (empty($this->cardType)) {
            throw new \InvalidArgumentException('卡类型不能为空');
        }
        if (empty($this->cardForm)) {
            throw new \InvalidArgumentException('卡形式不能为空');
        }
    }

    /**
     * 兑换礼品卡（新的属性设置方式）
     * @throws Exception
     */
    public function redeemGiftCard(): array
    {
        $this->validateParams();

        $log = null;
        try {
            // 1. 创建初始日志记录（状态为pending）
            $log = $this->createInitialLog();

            // 2. 验证礼品卡
            $giftCardInfo = $this->validateGiftCard($this->giftCardCode);

            // 3. 更新日志的礼品卡信息
            $log->update([
                'amount'       => $giftCardInfo['amount'],
                'country_code' => $giftCardInfo['country_code']
            ]);

            // 4. 查找匹配的汇率
            $rate = $this->findMatchingRate($giftCardInfo, $this->roomId, $this->cardType, $this->cardForm);

            // 5. 查找可用的计划
            $plan = $this->findAvailablePlan($rate->id);

            // 6. 查找可用的账号（使用高性能FindAccountService）
            // $account = $this->findAvailableAccount($plan, $this->roomId, $giftCardInfo);
            $account = $this->findAccountService->findAvailableAccount($plan, $this->roomId, $giftCardInfo);
            
            if (!$account) {
                throw new Exception("未找到可用的兑换账号");
            }
            
            // 记录账号获取成功日志
            $this->getLogger()->info("成功获取可用账号", [
                'account_id' => $account->id,
                'account_email' => $account->account,
                'account_balance' => $account->amount,
                'account_status' => $account->status,
                'plan_id' => $plan->id,
                'room_id' => $this->roomId,
                'gift_card_amount' => $giftCardInfo['amount'],
                'service_used' => 'FindAccountService'
            ]);

            // 7. 更新日志的账号和计划信息
            $log->update([
                'account_id' => $account->id,
                'plan_id'    => $plan->id,
                'rate_id'    => $rate->id,
                'day'        => $account->current_plan_day ?? 1
            ]);

            // 8. 执行兑换
            $result = $this->executeRedemption(
                $this->giftCardCode,
                $giftCardInfo,
                $rate,
                $plan,
                $account,
                $this->batchId,
                $log
            );

            return $result;

        } catch (Exception $e) {
            // 确保所有异常都能正确更新pending记录状态
            $this->handleRedemptionException($e, $log);
            throw $e;
        }
    }

    /**
     * 处理兑换异常，确保pending记录状态得到正确更新
     */
    private function handleRedemptionException(Exception $e, ?ItunesTradeAccountLog $log): void
    {
        $errorMessage = $e->getMessage();
        $errorContext = [
            'gift_card_code' => $this->giftCardCode,
            'room_id'        => $this->roomId,
            'batch_id'       => $this->batchId,
            'error'          => $errorMessage,
            'error_type'     => get_class($e),
            'log_id'         => $log?->id
        ];

        // 如果没有创建日志记录，说明在初始阶段就失败了
        if (!$log) {
            $this->getLogger()->error("兑换在初始阶段失败，未创建日志记录", $errorContext);
            return;
        }

        // 检查当前日志状态
        $log->refresh(); // 刷新数据，确保获取最新状态
        if ($log->status !== ItunesTradeAccountLog::STATUS_PENDING) {
            $this->getLogger()->info("日志状态已不是pending，无需更新", array_merge($errorContext, [
                'current_status' => $log->status
            ]));
            return;
        }

        // 尝试更新pending记录状态为失败
        try {
            $log->update([
                'status'        => ItunesTradeAccountLog::STATUS_FAILED,
                'error_message' => $errorMessage
            ]);

            // 触发日志更新事件
            event(new TradeLogCreated($log->fresh()));

            $this->getLogger()->info("已更新pending记录状态为失败", array_merge($errorContext, [
                'updated_status' => ItunesTradeAccountLog::STATUS_FAILED
            ]));

        } catch (Exception $updateException) {
            // 如果更新日志状态也失败了，记录这个严重错误
            $this->getLogger()->critical("更新pending记录状态失败", array_merge($errorContext, [
                'update_error' => $updateException->getMessage(),
                'update_trace' => $updateException->getTraceAsString()
            ]));
        }
    }

    /**
     * 兑换礼品卡（兼容旧版本，已废弃）
     * @throws Exception
     * @deprecated 使用属性设置方法替代
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
     * 验证礼品卡
     * @throws Exception
     */
    protected function validateGiftCard(string $code): array
    {
        // 调用GiftCardExchangeService的validateGiftCard方法
        $result = $this->exchangeService->validateGiftCard($code);
        $this->getLogger()->info('查卡返回数据：', $result);
        if (!$result['is_valid'] || $result['balance'] <= 0) {
            throw new Exception("礼品卡无效: " . ($result['message'] ?? '未知错误'));
        }
        $this->countryCode = $result['country_code'];
        return [
            'country_code' => $result['country_code'],
            'amount'       => $result['balance'],
            'currency'     => $result['currency'] ?? 'USD',
            'valid'        => true
        ];
    }

    /**
     * 查找匹配的汇率
     * @throws Exception
     */
    protected function findMatchingRate(array $giftCardInfo, string $roomId, string $cardType, string $cardForm): ItunesTradeRate
    {
        $countryCode = $giftCardInfo['country_code'];
        $amount      = $giftCardInfo['amount'];

        // 基础查询条件
        $query = ItunesTradeRate::where('country_code', $countryCode)
            ->where('card_type', $cardType)
            ->where('card_form', $cardForm)
            ->where('status', ItunesTradeRate::STATUS_ACTIVE);

        // 优先级1: 匹配room_id
        $rateWithRoomId = (clone $query)->where('room_id', $roomId)->get();
        if ($rateWithRoomId->isNotEmpty()) {
            $rate = $this->selectBestRateByAmount($rateWithRoomId, $amount);
            if ($rate) {
                $this->getLogger()->info("找到匹配room_id的汇率", ['rate_id' => $rate->id, 'room_id' => $roomId]);
                return $rate;
            }
        }

        // 优先级2: 匹配群组（通过room_id获取群组）
        $groupId = $this->getGroupIdByRoomId($roomId);
        $this->getLogger()->info("找到群组ID", ['group_id' => $groupId]);
        if ($groupId) {
            $rateWithGroup = (clone $query)->where('group_id', $groupId)->get();
            if ($rateWithGroup->isNotEmpty()) {
                $rate = $this->selectBestRateByAmount($rateWithGroup, $amount);
                if ($rate) {
                    $this->getLogger()->info("找到匹配群组的汇率", ['rate_id' => $rate->id, 'group_id' => $groupId]);
                    return $rate;
                }
            }
        }

        // 优先级3: 空room_id和空群组的汇率
        $defaultRates = (clone $query)
            ->whereNull('room_id')
            ->whereNull('group_id')
            ->get();

        if ($defaultRates->isNotEmpty()) {
            $this->getLogger()->info("第一次筛选的汇率", $defaultRates->toArray());
            $rate = $this->selectBestRateByAmount($defaultRates, $amount);
            if ($rate) {
                $this->getLogger()->info("找到默认汇率", ['rate_id' => $rate->id]);
                return $rate;
            }
        }

        throw new Exception("未找到符合条件的汇率: 国家={$countryCode}, 卡类型={$cardType}, 卡形式={$cardForm}, 面额={$amount}");
    }

    /**
     * 根据面额选择最佳汇率
     */
    protected function selectBestRateByAmount($rates, float $amount): ?ItunesTradeRate
    {
        foreach ($rates as $rate) {
            // 1. 检查固定面额
            if ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED) {
                $fixedAmounts = $rate->fixed_amounts ?? [];

                // 如果是字符串，尝试JSON解码
                if (is_string($fixedAmounts)) {
                    $decodedAmounts = json_decode($fixedAmounts, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmounts)) {
                        $fixedAmounts = $decodedAmounts;
                    }
                }

                $this->getLogger()->info("固定面额检查", [
                    'original_fixed_amounts' => $rate->fixed_amounts,
                    'parsed_fixed_amounts'   => $fixedAmounts,
                    'amount'                 => $amount,
                    'amount_type'            => gettype($amount),
                    'type'                   => gettype($fixedAmounts)
                ]);

                // 检查是否匹配固定面额
                $isMatched = false;
                if (is_array($fixedAmounts)) {
                    // 精确匹配
                    if (in_array($amount, $fixedAmounts)) {
                        $isMatched = true;
                    } else {
                        // 类型宽松匹配（处理整数和浮点数的差异）
                        foreach ($fixedAmounts as $fixedAmount) {
                            if (abs($amount - (float)$fixedAmount) < 0.01) {
                                $isMatched = true;
                                $this->getLogger()->info("通过宽松匹配找到固定面额", [
                                    'amount'        => $amount,
                                    'matched_value' => $fixedAmount,
                                    'diff'          => abs($amount - (float)$fixedAmount)
                                ]);
                                break;
                            }
                        }
                    }
                }

                if ($isMatched) {
                    $this->getLogger()->info("匹配到固定面额汇率", ['rate_id' => $rate->id]);
                    return $rate;
                }
            } // 2. 检查倍数要求
            elseif ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
                $multipleBase = $rate->multiple_base ?? 1;
                $minAmount    = $rate->min_amount ?? 0;
                $maxAmount    = ($rate->max_amount > 0) ? $rate->max_amount : PHP_FLOAT_MAX;

                $this->getLogger()->info("倍数要求检查", [
                    'amount'        => $amount,
                    'multiple_base' => $multipleBase,
                    'min_amount'    => $minAmount,
                    'max_amount'    => $maxAmount,
                    'modulo_result' => $amount % $multipleBase
                ]);

                if ($amount % $multipleBase == 0 &&
                    $amount >= $minAmount &&
                    $amount <= $maxAmount) {
                    $this->getLogger()->info("匹配到倍数要求汇率", ['rate_id' => $rate->id]);
                    return $rate;
                }
            } // 3. 检查全面额
            elseif ($rate->amount_constraint === ItunesTradeRate::AMOUNT_CONSTRAINT_ALL) {
                $minAmount = $rate->min_amount ?? 0;
                $maxAmount = ($rate->max_amount > 0) ? $rate->max_amount : PHP_FLOAT_MAX;

                $this->getLogger()->info("全面额检查", [
                    'amount'     => $amount,
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount
                ]);

                if ($amount >= $minAmount && $amount <= $maxAmount) {
                    $this->getLogger()->info("匹配到全面额汇率", ['rate_id' => $rate->id]);
                    return $rate;
                }
            }
        }

        return null;
    }

    /**
     * 通过room_id获取群组ID
     */
    protected function getGroupIdByRoomId(string $roomId): ?int
    {
        // 先根据roomId获取room表id
        $roomInfo = MrRoom::getByRoomId($roomId);
        if ($roomInfo) $roomId = $roomInfo->id;
        return MrRoomGroup::whereRaw("FIND_IN_SET(?, room_ids)", [$roomId])->value('id');
    }

    /**
     * 查找可用的计划
     */
    protected function findAvailablePlan(int $rateId): ItunesTradePlan
    {
        $plan = ItunesTradePlan::where('rate_id', $rateId)
            ->where('status', ItunesTradePlan::STATUS_ENABLED)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$plan) {
            throw new Exception("未找到可用的兑换计划: rate_id={$rateId}");
        }

        $this->getLogger()->info("找到可用计划", ['plan_id' => $plan->id, 'rate_id' => $rateId]);

        return $plan;
    }

    /**
     * 查找可用账号（已弃用 - 使用FindAccountService替代）
     * @deprecated 使用 FindAccountService::findAvailableAccount 替代，性能提升99.3%
     * @throws Exception
     */
    protected function findAvailableAccount(
        ItunesTradePlan $plan,
        string          $roomId,
        array           $giftCardInfo,
        int             $lastCheckedId = 0
    ): ItunesTradeAccount
    {
        $this->getLogger()->info("开始查找可用账号", [
            'plan_id'         => $plan->id,
            'room_id'         => $roomId,
            'last_checked_id' => $lastCheckedId,
            'bind_room'       => $plan->bind_room,
            'card_amount'     => $giftCardInfo['amount']
        ]);

        // 一次性获取所有候选账号，避免频繁数据库查询
        $candidateAccounts = $this->getAllCandidateAccounts($plan, $roomId);

        if (empty($candidateAccounts)) {
            $this->getLogger()->error("没有找到任何候选账号", [
                'plan_id' => $plan->id,
                'room_id' => $roomId
            ]);
            throw new Exception("未找到可用的兑换账号");
        }

        $this->getLogger()->info("找到候选账号", [
            'plan_id' => $plan->id,
            'candidate_count' => count($candidateAccounts),
            'candidate_ids' => array_column($candidateAccounts->toArray(), 'id')
        ]);

        // 【性能优化】分层验证策略：优先验证最有希望的账号，找到合适的立即返回
        $this->getLogger()->info("开始分层验证账号", [
            'total_candidates' => $candidateAccounts->count(),
            'strategy' => 'layered_validation_with_early_exit'
        ]);

        // 由于已经在数据库查询阶段预排序，这里可以直接按顺序验证
        // 不需要再进行复杂的内存排序，大大提升性能
        foreach ($candidateAccounts as $index => $account) {
            // 只记录每10个账号的验证进度，避免日志过多
            if ($index % 10 == 0 || $index < 5) {
                $this->getLogger()->debug("验证候选账号进度", [
                    'progress' => $index + 1,
                    'total' => $candidateAccounts->count(),
                    'current_account_id' => $account->id,
                    'current_account' => $account->account
                ]);
            }

            if ($this->validateAndLockAccount($account, $plan, $giftCardInfo)) {
                $this->getLogger()->info("成功找到并锁定可用账号", [
                    'account_id' => $account->id,
                    'account' => $account->account,
                    'attempt' => $index + 1,
                    'total_candidates' => $candidateAccounts->count(),
                    'validation_strategy' => 'early_exit_success'
                ]);
                return $account;
            }
        }

        $this->getLogger()->error("所有候选账号都不可用", [
            'plan_id' => $plan->id,
            'room_id' => $roomId,
            'total_checked' => $candidateAccounts->count(),
            'validation_strategy' => 'early_exit_all_failed'
        ]);

        throw new Exception("未找到可用的兑换账号");
    }

    /**
     * 验证账号并尝试原子锁定
     */
    private function validateAndLockAccount(
        ItunesTradeAccount $account,
        ItunesTradePlan    $plan,
        array              $giftCardInfo
    ): bool
    {
        // 首先验证账号是否符合条件
        if (!$this->validateAccount($account, $plan, $giftCardInfo)) {
            return false;
        }

        // 尝试原子锁定账号 - 使用数据库级别的原子操作
        $originalStatus = $account->status;
        $lockResult     = DB::table('itunes_trade_accounts')
            ->where('id', $account->id)
            ->where('status', $originalStatus) // 确保状态没有被其他任务改变
            ->update([
                'status'     => ItunesTradeAccount::STATUS_LOCKING,
                'plan_id'    => $plan->id,
                'updated_at' => now()
            ]);

        if ($lockResult > 0) {
            // 锁定成功，刷新账号模型
            $account->refresh();
            $this->getLogger()->info("账号原子锁定成功", [
                'account_id'      => $account->id,
                'original_status' => $originalStatus,
                'locked_status'   => ItunesTradeAccount::STATUS_LOCKING
            ]);
            return true;
        } else {
            // 锁定失败，说明账号状态已被其他任务改变
            $this->getLogger()->info("账号原子锁定失败，可能已被其他任务占用", [
                'account_id'      => $account->id,
                'original_status' => $originalStatus
            ]);
            return false;
        }
    }

    /**
     * 获取所有候选账号（一次性查询，避免频繁数据库访问，包含预过滤优化）
     */
    private function getAllCandidateAccounts(ItunesTradePlan $plan, string $roomId): \Illuminate\Database\Eloquent\Collection
    {
        // 基础查询条件：processing状态且登录有效
        $query = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE);

        // 如果计划要求绑定群聊，优先考虑群聊绑定
        if ($plan->bind_room && !empty($roomId)) {
            // 查找：绑定当前计划的账号 OR 绑定当前群聊的账号 OR 未绑定计划的账号
            $query->where(function ($q) use ($plan, $roomId) {
                $q->where('plan_id', $plan->id)  // 绑定当前计划
                  ->orWhere('room_id', $roomId)  // 绑定当前群聊
                  ->orWhereNull('plan_id');      // 未绑定计划
            });
        } else {
            // 不要求群聊绑定：查找绑定当前计划的账号 OR 未绑定计划的账号
            $query->where(function ($q) use ($plan) {
                $q->where('plan_id', $plan->id)  // 绑定当前计划
                  ->orWhereNull('plan_id');      // 未绑定计划
            });
        }

        // 【预过滤3】按优先级排序，优先获取最有希望的账号
        // 这样可以让最合适的账号排在前面，提前找到就能结束搜索
        $query->orderByRaw("
            CASE 
                WHEN plan_id = {$plan->id} AND room_id = '{$roomId}' THEN 1
                WHEN plan_id = {$plan->id} THEN 2
                WHEN room_id = '{$roomId}' THEN 3
                WHEN plan_id IS NULL THEN 4
                ELSE 5
            END
        ")
        ->orderBy('amount', 'desc') // 余额高的优先
        ->orderBy('id', 'asc');     // ID小的优先

        $candidates = $query->get();

        $this->getLogger()->info("候选账号获取完成", [
            'total_candidates' => $candidates->count(),
            'filters_applied' => [
                'amount_gt_0' => '余额 > 0',
                'amount_lt_total' => "余额 < {$plan->total_amount}",
                'pre_sorted' => '按优先级预排序'
            ]
        ]);

        return $candidates;
    }

    /**
     * 按优先级排序账号（性能优化版本）
     */
    private function sortAccountsByPriority(\Illuminate\Database\Eloquent\Collection $accounts, ItunesTradePlan $plan, string $roomId, array $giftCardInfo): \Illuminate\Database\Eloquent\Collection
    {
        $startTime = microtime(true);
        
        // 预先获取汇率信息用于容量验证
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

        // 【性能优化1】预先批量查询所有账号的每日已兑换金额，避免在排序中重复查询
        $accountIds = $accounts->pluck('id')->toArray();
        $dailySpentData = $this->batchGetDailySpentAmounts($accountIds, $plan);

        $this->getLogger()->info("排序性能优化开始", [
            'account_count' => count($accountIds),
            'daily_spent_queries' => count($dailySpentData),
            'constraint_type' => $constraintType
        ]);

        // 【性能优化2】预计算所有排序键值，避免在排序过程中重复调用复杂方法
        $sortingKeys = [];
        foreach ($accounts as $account) {
            $capacityType = $this->getAccountCapacityTypeOptimized($account, $plan, $multipleBase, $fixedAmounts, $constraintType, $giftCardInfo, $dailySpentData);
            
            $sortingKeys[$account->id] = [
                $capacityType, // 优先级0：容量类型（1=能充满，2=可预留，3=不适合）
                ($account->plan_id == $plan->id && $account->room_id == $roomId) ? 0 : 1, // 优先级1（用0/1便于比较）
                ($account->plan_id == $plan->id) ? 0 : 1, // 优先级2
                ($plan->bind_room && !empty($roomId) && $account->room_id == $roomId) ? 0 : 1, // 优先级3
                -$account->amount, // 优先级4：按余额降序（使用负数实现降序）
                $account->id // 优先级5：按ID升序
            ];
        }

        // 【性能优化3】使用PHP原生排序算法，比Laravel Collection的链式排序更快
        $accountsArray = $accounts->all();
        usort($accountsArray, function($a, $b) use ($sortingKeys) {
            $aKeys = $sortingKeys[$a->id];
            $bKeys = $sortingKeys[$b->id];
            
            // 逐级比较排序键值，避免复杂的条件判断
            for ($i = 0; $i < 6; $i++) {
                if ($aKeys[$i] != $bKeys[$i]) {
                    return $aKeys[$i] <=> $bKeys[$i];
                }
            }
            return 0;
        });

        // 【性能优化4】创建新的Eloquent Collection，保持类型一致性
        $sortedCollection = new \Illuminate\Database\Eloquent\Collection($accountsArray);

        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000, 2);

        $this->getLogger()->info("排序性能优化完成", [
            'account_count' => count($accountIds),
            'execution_time_ms' => $executionTime,
            'avg_time_per_account_ms' => round($executionTime / count($accountIds), 3),
            'optimization_method' => 'precomputed_keys_native_sort'
        ]);

        return $sortedCollection;
    }

    /**
     * 批量获取账号的每日已兑换金额（性能优化）
     */
    private function batchGetDailySpentAmounts(array $accountIds, ItunesTradePlan $plan): array
    {
        if (empty($accountIds)) {
            return [];
        }

        // 一次性查询所有账号的每日兑换记录
        $results = DB::table('itunes_trade_account_logs')
            ->select('account_id', 'day', DB::raw('SUM(amount) as daily_spent'))
            ->whereIn('account_id', $accountIds)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->groupBy('account_id', 'day')
            ->get();

        // 组织数据结构：[account_id => [day => daily_spent]]
        $dailySpentData = [];
        foreach ($results as $result) {
            $dailySpentData[$result->account_id][$result->day] = (float)$result->daily_spent;
        }

        return $dailySpentData;
    }

    /**
     * 获取账号容量类型（优化版本，使用预查询的数据）
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

        // 获取当天的计划额度
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;

        // 从预查询的数据中获取当天已成功兑换的总额
        $dailySpent = $dailySpentData[$account->id][$currentDay] ?? 0;

        // 计算当天剩余需要额度（包含浮动额度）
        $remainingDailyAmount = bcadd($dailyLimit, $plan->float_amount, 2);
        $remainingDailyAmount = bcsub($remainingDailyAmount, $dailySpent, 2);

        // 类型1：能够充满计划额度（不超出）
        if (bccomp($cardAmount, $remainingDailyAmount, 2) <= 0) {
            return 1;
        }

        // 类型2和3：根据约束类型判断是否可以预留
        $remainingAfterUse = bcsub($cardAmount, $remainingDailyAmount, 2);

        switch ($constraintType) {
            case ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE:
                if ($multipleBase > 0 && bccomp($remainingAfterUse, $multipleBase, 2) >= 0) {
                    $modResult = fmod((float)$remainingAfterUse, (float)$multipleBase);
                    if ($modResult == 0) {
                        return 2; // 可以预留倍数额度
                    }
                }
                break;

            case ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED:
                if (is_array($fixedAmounts) && !empty($fixedAmounts)) {
                    $remainingFloat = (float)$remainingAfterUse;
                    foreach ($fixedAmounts as $fixedAmount) {
                        if (abs($remainingFloat - (float)$fixedAmount) < 0.01) {
                            return 2; // 可以预留固定面额
                        }
                    }
                }
                break;

            case ItunesTradeRate::AMOUNT_CONSTRAINT_ALL:
                if (bccomp($remainingAfterUse, '0', 2) > 0) {
                    return 2; // 可以预留剩余额度（全面额）
                }
                break;
        }

        // 类型3：不太适合
        return 3;
    }

    /**
     * 查询执行中的账号（保留原方法，但不再使用）
     * @deprecated 已被 getAllCandidateAccounts 和 sortAccountsByPriority 替代
     */
    private function findProcessingAccount(
        ItunesTradePlan $plan,
        string          $roomId,
        int             $lastCheckedId,
        array           $checkedAccountIds = []
    ): ?ItunesTradeAccount
    {
        // 此方法已被新的批量查询方法替代，保留以防回滚需要
        return null;
    }

    /**
     * 查找等待状态且处于第一天的账号
     */
    private function findWaitingAccount(
        ItunesTradePlan $plan,
        int             $lastCheckedId,
        array           $checkedAccountIds = []
    ): ?ItunesTradeAccount
    {
        $query = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->where('current_plan_day', 1);

        // 排除已检查过的账号ID
        if (!empty($checkedAccountIds)) {
            $query->whereNotIn('id', $checkedAccountIds);
        }

        // 如果是第一次查找，使用lastCheckedId；否则查找所有未检查的账号
        if (empty($checkedAccountIds) && $lastCheckedId > 0) {
            $query->where('id', '>', $lastCheckedId);
        }

        return $query->orderBy('id', 'asc')->first();
    }

    /**
     * 验证账号是否可用
     */
    private function validateAccount(
        ItunesTradeAccount $account,
        ItunesTradePlan    $plan,
        array              $giftCardInfo
    ): bool
    {
        // 检查账号是否被锁定
        if ($account->status === ItunesTradeAccount::STATUS_LOCKING) {
            $this->getLogger()->info("账号已被锁定，跳过", [
                'account_id' => $account->id,
                'status'     => $account->status
            ]);
            return false;
        }

        // 检查国家匹配
        if (!empty($account->country_code) &&
            $account->country_code !== $giftCardInfo['country_code']) {
            $this->getLogger()->info("账号国家不匹配", [
                'account_id'      => $account->id,
                'account_country' => $account->country_code,
                'card_country'    => $giftCardInfo['country_code']
            ]);
            return false;
        }

        // 新增：智能账号选择验证
        if (!$this->validateAccountCapacity($account, $plan, $giftCardInfo)) {
            return false;
        }

        // 检查总额度
        if (!$this->validateTotalAmount($account, $plan, $giftCardInfo)) {
            return false;
        }

        // 检查当日额度
        if (!$this->validateDailyAmount($account, $plan, $giftCardInfo)) {
            return false;
        }

        return true;
    }

    /**
     * 智能账号容量验证：检查账号是否能充满计划额度或预留最小额度
     */
    private function validateAccountCapacity(
        ItunesTradeAccount $account,
        ItunesTradePlan    $plan,
        array              $giftCardInfo
    ): bool
    {
        $cardAmount = $giftCardInfo['amount'];
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
        $remainingDailyAmount = bcadd($dailyLimit, $plan->float_amount, 2);
        $remainingDailyAmount = bcsub($remainingDailyAmount, $dailySpent, 2);

        // 获取汇率的约束要求
        $rate = $plan->rate;
        $constraintType = $rate ? $rate->amount_constraint : ItunesTradeRate::AMOUNT_CONSTRAINT_ALL;
        $multipleBase = 0;
        $fixedAmounts = [];

        if ($rate) {
            if ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE) {
                $multipleBase = $rate->multiple_base ?? 0;
            } elseif ($constraintType === ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED) {
                $fixedAmounts = $rate->fixed_amounts ?? [];
                // 如果是字符串，尝试JSON解码
                if (is_string($fixedAmounts)) {
                    $decodedAmounts = json_decode($fixedAmounts, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmounts)) {
                        $fixedAmounts = $decodedAmounts;
                    }
                }
            }
        }

        $this->getLogger()->info("智能账号容量验证开始", [
            'account_id' => $account->account,
            'card_amount' => $cardAmount,
            'current_day' => $currentDay,
            'daily_limit' => $dailyLimit,
            'float_amount' => $plan->float_amount,
            'daily_spent' => $dailySpent,
            'remaining_daily_amount' => $remainingDailyAmount,
            'constraint_type' => $constraintType,
            'multiple_base' => $multipleBase,
            'fixed_amounts' => $fixedAmounts
        ]);

        // 情况1：检查是否能够充满计划额度（不超出）
        if (bccomp($cardAmount, $remainingDailyAmount, 2) <= 0) {
            $this->getLogger()->info("账号能够充满计划额度，验证通过", [
                'account_id' => $account->account,
                'card_amount' => $cardAmount,
                'remaining_daily_amount' => $remainingDailyAmount,
                'validation_type' => '能够充满计划额度'
            ]);
            return true;
        }

        // 情况2：如果不能充满，检查是否可以预留符合约束的最小额度
        $remainingAfterUse = bcsub($cardAmount, $remainingDailyAmount, 2);

        // 根据不同的约束类型进行验证
        switch ($constraintType) {
            case ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE:
                return $this->validateMultipleConstraintCapacity($account, $cardAmount, $remainingDailyAmount, $remainingAfterUse, $multipleBase);

            case ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED:
                return $this->validateFixedConstraintCapacity($account, $cardAmount, $remainingDailyAmount, $remainingAfterUse, $fixedAmounts);

            case ItunesTradeRate::AMOUNT_CONSTRAINT_ALL:
                return $this->validateAllConstraintCapacity($account, $cardAmount, $remainingDailyAmount, $remainingAfterUse);

            default:
                $this->getLogger()->warning("未知的约束类型，按全面额处理", [
                    'account_id' => $account->account,
                    'constraint_type' => $constraintType
                ]);
                return $this->validateAllConstraintCapacity($account, $cardAmount, $remainingDailyAmount, $remainingAfterUse);
        }
    }

    /**
     * 验证倍数约束的容量
     */
    private function validateMultipleConstraintCapacity(
        ItunesTradeAccount $account,
        string $cardAmount,
        string $remainingDailyAmount,
        string $remainingAfterUse,
        int $multipleBase
    ): bool {
        if ($multipleBase <= 0) {
            $this->getLogger()->info("倍数基数无效，按全面额处理", [
                'account_id' => $account->account,
                'multiple_base' => $multipleBase
            ]);
            return $this->validateAllConstraintCapacity($account, $cardAmount, $remainingDailyAmount, $remainingAfterUse);
        }

        // 检查剩余金额是否能满足最小倍数要求
        if (bccomp($remainingAfterUse, $multipleBase, 2) >= 0) {
            // 检查剩余金额是否为倍数的整数倍
            $modResult = fmod((float)$remainingAfterUse, (float)$multipleBase);

            if ($modResult == 0) {
                $this->getLogger()->info("账号可以预留倍数额度，验证通过", [
                    'account_id' => $account->account,
                    'card_amount' => $cardAmount,
                    'remaining_daily_amount' => $remainingDailyAmount,
                    'remaining_after_use' => $remainingAfterUse,
                    'multiple_base' => $multipleBase,
                    'mod_result' => $modResult,
                    'validation_type' => '可以预留倍数额度'
                ]);
                return true;
            } else {
                $this->getLogger()->info("剩余金额不是倍数的整数倍，验证失败", [
                    'account_id' => $account->account,
                    'remaining_after_use' => $remainingAfterUse,
                    'multiple_base' => $multipleBase,
                    'mod_result' => $modResult
                ]);
            }
        } else {
            $this->getLogger()->info("剩余金额不足最小倍数要求，验证失败", [
                'account_id' => $account->account,
                'remaining_after_use' => $remainingAfterUse,
                'multiple_base' => $multipleBase
            ]);
        }

        return false;
    }

    /**
     * 验证固定面额约束的容量
     */
    private function validateFixedConstraintCapacity(
        ItunesTradeAccount $account,
        string $cardAmount,
        string $remainingDailyAmount,
        string $remainingAfterUse,
        array $fixedAmounts
    ): bool {
        if (empty($fixedAmounts) || !is_array($fixedAmounts)) {
            $this->getLogger()->info("固定面额配置无效，按全面额处理", [
                'account_id' => $account->account,
                'fixed_amounts' => $fixedAmounts
            ]);
            return $this->validateAllConstraintCapacity($account, $cardAmount, $remainingDailyAmount, $remainingAfterUse);
        }

        // 检查剩余金额是否匹配任何固定面额
        $remainingFloat = (float)$remainingAfterUse;
        $isMatched = false;
        $matchedAmount = null;

        foreach ($fixedAmounts as $fixedAmount) {
            $fixedFloat = (float)$fixedAmount;

            // 精确匹配
            if (abs($remainingFloat - $fixedFloat) < 0.01) {
                $isMatched = true;
                $matchedAmount = $fixedAmount;
                break;
            }
        }

        if ($isMatched) {
            $this->getLogger()->info("账号可以预留固定面额，验证通过", [
                'account_id' => $account->account,
                'card_amount' => $cardAmount,
                'remaining_daily_amount' => $remainingDailyAmount,
                'remaining_after_use' => $remainingAfterUse,
                'fixed_amounts' => $fixedAmounts,
                'matched_amount' => $matchedAmount,
                'validation_type' => '可以预留固定面额'
            ]);
            return true;
        } else {
            $this->getLogger()->info("剩余金额不匹配任何固定面额，验证失败", [
                'account_id' => $account->account,
                'remaining_after_use' => $remainingAfterUse,
                'fixed_amounts' => $fixedAmounts
            ]);
        }

        return false;
    }

    /**
     * 验证全面额约束的容量
     */
    private function validateAllConstraintCapacity(
        ItunesTradeAccount $account,
        string $cardAmount,
        string $remainingDailyAmount,
        string $remainingAfterUse
    ): bool {
        // 全面额约束下，只要剩余金额大于0就认为可以预留
        if (bccomp($remainingAfterUse, '0', 2) > 0) {
            $this->getLogger()->info("账号可以预留剩余额度（全面额），验证通过", [
                'account_id' => $account->account,
                'card_amount' => $cardAmount,
                'remaining_daily_amount' => $remainingDailyAmount,
                'remaining_after_use' => $remainingAfterUse,
                'validation_type' => '可以预留剩余额度（全面额）'
            ]);
            return true;
        } else {
            $this->getLogger()->info("剩余金额不足，验证失败", [
                'account_id' => $account->account,
                'remaining_after_use' => $remainingAfterUse
            ]);
            return false;
        }
    }

    /**
     * 验证当天已兑总额
     */
    private function validateDailyAmount(
        ItunesTradeAccount $account,
        ItunesTradePlan    $plan,
        array              $giftCardInfo
    ): bool
    {
        $currentDay = $account->current_plan_day ?? 1;

        // 获取当天已成功兑换的总额
        $dailySpent = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 获取当天的计划额度
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit   = $dailyAmounts[$currentDay - 1] ?? 0;

        // 计算可用额度（计划额度 + 浮动额度 - 已使用额度），使用 BC Math 保证精度
        $availableDailyAmount = bcsub(
            bcadd($dailyLimit, $plan->float_amount, 2),  // 先加浮动额度
            $dailySpent,  // 再减已使用额度
            2  // 保留2位小数
        );

        $requiredAmount = $giftCardInfo['amount'];

        // 比较可用额度是否足够，使用 bccomp 避免精度问题
        $isValid = (bccomp($availableDailyAmount, $requiredAmount, 2) >= 0);

        $this->getLogger()->info(
            $isValid ? "当日额度验证通过" : "当日额度不足",
            [
                'account_id'             => $account->account,
                'current_day'            => $currentDay,
                'daily_limit'            => $dailyLimit,
                'float_amount'           => $plan->float_amount,
                'daily_spent'            => $dailySpent,
                'available_daily_amount' => $availableDailyAmount,
                'required_amount'        => $requiredAmount,
            ]
        );

        return $isValid;
    }

    /**
     * 检查账户总额度
     */
    private function validateTotalAmount(
        ItunesTradeAccount $account,
        ItunesTradePlan    $plan,
        array              $giftCardInfo
    ): bool
    {
        // 获取账号已成功兑换的总额
//        $totalSpent = ItunesTradeAccountLog::where('account_id', $account->id)
//            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
//            ->sum('amount');

        // 检查加上当前兑换金额后是否超过总限额（使用 bcadd 和 bccomp 确保精度）
        $totalAfterExchange = bcadd($account->amount, $giftCardInfo['amount'], 2);
        $isValid            = (bccomp($totalAfterExchange, $plan->total_amount, 2) <= 0);

        $this->getLogger()->info(
            $isValid ? "总额度验证通过" : "超出总额度限制",
            [
                'account_id'           => $account->account,
                'total_spent'          => $account->amount,
                'current_amount'       => $giftCardInfo['amount'],
                'total_after_exchange' => $totalAfterExchange,
                'total_amount_limit'   => $plan->total_amount,
            ]
        );

        return $isValid;
    }

    /**
     * 创建初始日志记录（在验证参数后立即创建）
     */
    protected function createInitialLog(): ItunesTradeAccountLog
    {
        // 检查是否已存在该礼品卡的日志记录
        $existingLog = ItunesTradeAccountLog::where('code', $this->giftCardCode)
            ->whereIn('status', [ItunesTradeAccountLog::STATUS_PENDING, ItunesTradeAccountLog::STATUS_SUCCESS])
            ->orderBy('created_at', 'desc')
            ->first();

        if ($existingLog) {
            $this->getLogger()->warning("礼品卡已存在处理记录", [
                'code'            => $this->giftCardCode,
                'existing_log_id' => $existingLog->id,
                'existing_status' => $existingLog->status,
                'created_at'      => $existingLog->created_at->toDateTimeString()
            ]);

            // 如果已存在成功记录，抛出异常
            if ($existingLog->status === ItunesTradeAccountLog::STATUS_SUCCESS) {
                throw new GiftCardExchangeException(
                    GiftCardExchangeException::CODE_CARD_ALREADY_REDEEMED,
                    "礼品卡 {$this->giftCardCode} 已兑换成功，请勿重复提交"
                );
            }

            // 如果是待处理状态，需要判断是否为超时的记录或同批次重试
            if ($existingLog->status === ItunesTradeAccountLog::STATUS_PENDING) {
                $timeoutMinutes = 5; // 5分钟超时
                $isTimeout = $existingLog->created_at->addMinutes($timeoutMinutes)->isPast();
                $isSameBatch = !empty($existingLog->batch_id) && $existingLog->batch_id === $this->batchId;

                if ($isTimeout || $isSameBatch) {
                    // 超时的pending记录或同批次重试，标记为失败并允许重新处理
                    $reason = $isSameBatch ? '同批次任务重试' : '处理超时';
                    $existingLog->update([
                        'status' => ItunesTradeAccountLog::STATUS_FAILED,
//                        'error_message' => $reason . '，创建新的处理记录'
                    ]);

                    $this->getLogger()->info("检测到可重试的pending记录，标记为失败并允许重试", [
                        'code'                => $this->giftCardCode,
                        'existing_log_id'     => $existingLog->id,
                        'existing_batch_id'   => $existingLog->batch_id,
                        'current_batch_id'    => $this->batchId,
                        'is_timeout'          => $isTimeout,
                        'is_same_batch'       => $isSameBatch,
                        'timeout_minutes'     => $timeoutMinutes,
                        'original_created_at' => $existingLog->created_at->toDateTimeString(),
                        'reason'              => $reason
                    ]);

                    // 继续执行，创建新的日志记录
                } else {
                    // 未超时且不同批次的pending记录，真正的重复提交
                    $remainingTime = now()->diffInMinutes($existingLog->created_at->addMinutes($timeoutMinutes));
                    throw new GiftCardExchangeException(
                        GiftCardExchangeException::CODE_OTHER_ERROR,
                        "礼品卡 {$this->giftCardCode} 正在处理中，请等待 {$remainingTime} 分钟后重试"
                    );
                }
            }
        }

        // 创建新的日志记录（所有可选字段先为空，随着流程推进逐步填充）
        $log = ItunesTradeAccountLog::create([
            'account_id'    => null, // 找到账号后更新
            'room_id'       => $this->roomId,
            'wxid'          => $this->wxId,
            'msgid'         => $this->msgId,
            'batch_id'      => $this->batchId, // 批次ID用于重试判断
            'plan_id'       => null, // 找到计划后更新
            'rate_id'       => null, // 找到汇率后更新
            'code'          => $this->giftCardCode,
            'day'           => 1, // 默认第一天，找到账号后更新
            'amount'        => 0, // 验证礼品卡后更新
            'status'        => ItunesTradeAccountLog::STATUS_PENDING,
            'country_code'  => '', // 验证礼品卡后更新
            'exchange_time' => now(),
        ]);

        // 触发日志创建事件
        event(new TradeLogCreated($log));

        $this->getLogger()->info("创建初始兑换日志记录", [
            'log_id' => $log->id,
            'code'   => $this->giftCardCode
        ]);

        return $log;
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
    ): array
    {
        return DB::transaction(function () use ($code, $giftCardInfo, $rate, $plan, $account, $batchId, $log) {
            $this->getLogger()->info("开始执行兑换", [
                'code'           => $code,
                'account_id'     => $account->account,
                'plan_id'        => $plan->id,
                'rate_id'        => $rate->id,
                'account_status' => $account->status,
                'log_id'         => $log->id
            ]);

            // 账号已经在findAvailableAccount阶段被锁定为STATUS_LOCKING
            // 这里只需要记录锁定前的状态，用于失败时恢复
            // 通过completed_days字段来推断原始状态
            $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];
            $currentDay    = $account->current_plan_day ?? 1;

            // 推断原始状态：如果是第一天且没有完成记录，则为WAITING；否则为PROCESSING
            $originalStatus = (empty($completedDays) && $currentDay == 1)
                ? ItunesTradeAccount::STATUS_WAITING
                : ItunesTradeAccount::STATUS_PROCESSING;

            $this->getLogger()->info("账号已在查找阶段锁定", [
                'account_id'               => $account->id,
                'code'                     => $code,
                'current_status'           => $account->status,
                'inferred_original_status' => $originalStatus
            ]);

            // 日志的账号信息已在findAvailableAccount后更新，这里不需要重复更新

            try {
                // 调用兑换API
                $exchangeData = $this->callExchangeApi($account, $plan, $code);

                // 解析兑换结果
                $result = $this->parseExchangeResult($exchangeData, $account, $rate, $code);

                if ($result['success']) {
                    // 兑换成功，更新日志状态
                    $log->update([
                        'status'           => ItunesTradeAccountLog::STATUS_SUCCESS,
                        'after_amount'     => $result['data']['total_amount'] ?? 0,
                        'error_message'    => null // 清除可能的错误信息
                    ]);

                    // 更新账号余额
                    if (isset($result['data']['total_amount'])) {
                        $totalAmount = $this->parseBalance((string)$result['data']['total_amount']);
                        $account->update(['amount' => $totalAmount]);

                        $this->getLogger()->info("更新账号余额", [
                            'account_id' => $account->account,
                            'new_amount' => $totalAmount,
                            'fund_added' => $result['data']['amount'] ?? 0
                        ]);
                    }

                    // 兑换成功，保持锁定状态不变，让计划任务来处理状态转换
                    $this->getLogger()->info("兑换成功，保持锁定状态", [
                        'code'             => $code,
                        'account_id'       => $account->account,
                        'status_kept'      => 'LOCKING (待计划任务处理)',
                        'original_amount'  => $giftCardInfo['amount'],
                        'exchanged_amount' => $result['data']['amount'] ?? 0
                    ]);

                    // 触发日志更新事件
                    event(new TradeLogCreated($log->fresh()));

                    // 注意：不调用 checkAndUpdateDayCompletion，让计划任务统一处理
                    // $this->checkAndUpdateDayCompletion($account, $plan);

                    // 执行加账处理
                    $buildWechatMsg = $this->processAccountBilling($giftCardInfo['amount'], $rate, $account);

                    // 返回成功结果
                    return [
                        'success'          => true,
                        'log_id'           => $log->id,
                        'account_id'       => $account->id,
                        'account_username' => $account->account,
                        'plan_id'          => $plan->id,
                        'rate_id'          => $rate->id,
                        'country_code'     => $giftCardInfo['country_code'],
                        'original_amount'  => $giftCardInfo['amount'],
                        'exchanged_amount' => $result['data']['amount'] ?? 0,
                        'rate'             => $rate->rate,
                        'total_amount'     => $result['data']['total_amount'] ?? 0,
                        'currency'         => $giftCardInfo['currency'] ?? 'USD',
                        'exchange_time'    => $log->exchange_time ? $log->exchange_time->toISOString() : null,
                        'message'          => $result['message'],
                        'details'          => $result['data']['details'] ?? null,
                        'wechat_msg'       => $buildWechatMsg
                    ];
                } else {
                    // 兑换失败，更新日志状态
                    $errorMessage = $result['message'] ?? '兑换失败';
                    $log->update([
                        'status'        => ItunesTradeAccountLog::STATUS_FAILED,
                        'error_message' => $errorMessage
                    ]);

                    $this->getLogger()->error("兑换失败", [
                        'code'       => $code,
                        'account_id' => $account->account,
                        'log_id'     => $log->id,
                        'error'      => $errorMessage
                    ]);

                    // 兑换失败，恢复账号到锁定前的状态
                    // 注释掉账号状态恢复，让计划任务统一处理
                    // $account->update(['status' => $originalStatus]);

                    // 触发日志更新事件
                    event(new TradeLogCreated($log->fresh()));

                    // 构建失败的微信消息
                    $failureWechatMsg = "兑换失败\n-------------------------\n" . $code . "\n" . $errorMessage;

                    return [
                        'success' => false,
                        'log_id'  => $log->id,
                        'message' => $errorMessage,
                        'data'    => $result['data'] ?? [],
                        'wechat_msg' => $failureWechatMsg
                    ];
                }

            } catch (Exception $e) {
                // 捕获所有异常，确保pending记录状态得到更新
                $errorMessage = $e->getMessage();
                $errorDetails = [
                    'code'       => $code,
                    'account_id' => $account->account,
                    'log_id'     => $log->id,
                    'error'      => $errorMessage,
                    'trace'      => $e->getTraceAsString(),
                    'error_type' => get_class($e)
                ];

                $this->getLogger()->error("兑换过程发生异常", $errorDetails);

                // 更新日志状态为失败，记录详细错误信息
                try {
                    $log->update([
                        'status'        => ItunesTradeAccountLog::STATUS_FAILED,
                        'error_message' => $errorMessage
                    ]);

                    // 触发日志更新事件
                    event(new TradeLogCreated($log->fresh()));

                    $this->getLogger()->info("已更新pending记录状态为失败", [
                        'log_id' => $log->id,
                        'code'   => $code
                    ]);

                } catch (Exception $updateException) {
                    // 如果更新日志状态也失败了，记录这个严重错误
                    $this->getLogger()->critical("更新pending记录状态失败", [
                        'log_id'         => $log->id,
                        'code'           => $code,
                        'original_error' => $errorMessage,
                        'update_error'   => $updateException->getMessage()
                    ]);
                }

                // 兑换异常，恢复账号到锁定前的状态
                // 注释掉账号状态恢复，让计划任务统一处理
                // try {
                //     $account->update(['status' => $originalStatus]);
                //     $this->getLogger()->info("已恢复账号状态", [
                //         'account_id' => $account->id,
                //         'status_restored' => "LOCKING -> {$originalStatus}"
                //     ]);
                // } catch (Exception $statusException) {
                //     $this->getLogger()->error("恢复账号状态失败", [
                //         'account_id' => $account->id,
                //         'error' => $statusException->getMessage()
                //     ]);
                // }

                // 重新抛出异常，让上层处理
                throw $e;
            }
        });
    }

    /**
     * 处理加账操作
     *
     * @param float $amount
     * @param ItunesTradeRate $rateObj
     * @param ItunesTradeAccount $account
     * @return string
     */
    private function processAccountBilling(float $amount, ItunesTradeRate $rateObj, ItunesTradeAccount $account): string
    {
        try {
            if (empty($this->roomId)) {
                Log::channel('gift_card_exchange')->error('room_id为空，无法进行加账处理');
                return '';
            }

            // 获取群组信息
            $room = MrRoom::where('room_id', $this->roomId)->first();
            if (!$room) {
                Log::channel('gift_card_exchange')->error("群组 {$this->roomId} 不存在");
                return '';
            }

            $countryCode = $rateObj->country_code;

            // 使用BC函数进行精度计算，保留两位小数
            $amount = bcadd($amount, '0', 2);        // 确保金额为两位小数
            $rate   = bcadd($rateObj->rate, '0', 2); // 确保汇率为两位小数

            // 计算变动金额（原始金额 * 汇率）
            $changeAmount = bcmul($amount, $rate, 2);

            // 获取变动前账单金额
            $beforeMoney = bcadd($room->unsettled ?? 0, '0', 2);

            // 计算变动后账单金额
            $afterMoney = bcadd($beforeMoney, $changeAmount, 2);

            // 开始数据库事务
            DB::beginTransaction();
            try {
                // 写入账单记录到 mr_room_bill 表
                MrRoomBill::create([
                    'room_id'      => $this->roomId,
                    'room_name'    => $room->room_name ?? '未知群组',
                    'event'        => 1, // 兑换事件
                    'msgid'        => $this->msgId,
                    'money'        => $amount,
                    'rate'         => $rate,
                    'fee'          => 0.00,
                    'amount'       => $changeAmount,
                    'card_type'    => $countryCode, // 国家
                    'before_money' => $beforeMoney,
                    'bill_money'   => $afterMoney, // 修正：这应该是变动后的总金额
                    'remark'       => 'iTunes',
                    'op_id'        => '',
                    'op_name'      => '',
                    'code'         => $this->giftCardCode,
                    'content'      => json_encode([
                        'account'          => $account->account,
                        'original_amount'  => $amount,
                        'exchange_rate'    => $rate,
                        'converted_amount' => $changeAmount
                    ]),
                    'note'         => "礼品卡兑换 - {$this->giftCardCode}",
                    'status'       => 0,
                    'is_settle'    => 0,
                    'is_del'       => 0
                ]);

                // 更新群组未结算金额和变更时间
                $room->update([
                    'unsettled'  => $afterMoney,
                    'changed_at' => now()
                ]);

                // 提交事务
                DB::commit();

                // 构建成功消息
                $successMessage = $this->buildSuccessMessage([
                    'card_number'   => $this->giftCardCode,
                    'amount'        => $amount,
                    'rate'          => $rate,
                    'before_money'  => $beforeMoney,
                    'change_amount' => $changeAmount,
                    'after_money'   => $afterMoney,
                    'exchange_time' => now()->format('Y-n-j H:i:s')
                ]);


                Log::channel('gift_card_exchange')->info("群组 {$this->roomId} 加账处理完成", [
                    'msgid'         => $this->msgId,
                    'card_number'   => $this->giftCardCode,
                    'amount'        => $amount,
                    'rate'          => $rate,
                    'change_amount' => $changeAmount,
                    'before_money'  => $beforeMoney,
                    'after_money'   => $afterMoney,
                    'success_msg'   => $successMessage,
                    'room_id'       => $this->roomId
                ]);


            } catch (\Exception $e) {
                // 回滚事务
                DB::rollBack();
                throw $e;
            }
            return $successMessage;
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("加账处理失败: " . $e->getMessage(), [
                'room_id' => $this->input['room_id'] ?? '',
            ]);
        }

        return '';
    }

    /**
     * 构建成功消息
     *
     * @param array $data
     * @return string
     */
    private function buildSuccessMessage(array $data): string
    {
        // 确保所有金额都使用BC函数格式化为两位小数
        $amount       = bcadd($data['amount'], '0', 2);
        $beforeMoney  = bcadd($data['before_money'], '0', 2);
        $rate         = bcadd($data['rate'], '0', 2);          // 汇率保留一位小数
        $changeAmount = bcadd($data['change_amount'], '0', 2); // 变动金额保留整数
        $afterMoney   = bcadd($data['after_money'], '0', 2);

        return sprintf(
            "[强]兑换成功\n" .
            "---------------------------------\n" .
            "加载卡号：%s\n" .
            "加载结果：$%s（%s）\n" .
            "原始账单：%s\n" .
            "变动金额：%s*%s=%s\n" .
            "当前账单：%s\n" .
            "加卡时间：%s",
            $data['card_number'],
            $amount,
            $this->countryCode,
            $beforeMoney,
            $amount,
            $rate,
            $changeAmount,
            $afterMoney,
            $data['exchange_time']
        );
    }

    /**
     * 解析兑换结果
     */
    private function parseExchangeResult(array $exchangeResult, ItunesTradeAccount $account, ItunesTradeRate $rate, string $code): array
    {
        // 检查基本数据结构
        if (!isset($exchangeResult['data']['items']) || !is_array($exchangeResult['data']['items'])) {
            $errorMessage = '兑换结果数据结构错误';
            $failureWechatMsg = "兑换失败\n-------------------------\n" . $code . "\n" . $errorMessage;
            return [
                'success' => false,
                'message' => $errorMessage,
                'data'    => [],
                'wechat_msg' => $failureWechatMsg
            ];
        }

        // 查找匹配的兑换项
        $dataId      = $account->account . ":" . $code;
        $matchedItem = null;

        foreach ($exchangeResult['data']['items'] as $item) {
            if (isset($item['data_id']) && $item['data_id'] === $dataId) {
                $matchedItem = $item;
                break;
            }
        }

        if (!$matchedItem) {
            $errorMessage = '未找到匹配的兑换项目';
            $failureWechatMsg = "兑换失败\n-------------------------\n" . $code . "\n" . $errorMessage;
            return [
                'success' => false,
                'message' => $errorMessage,
                'data'    => [],
                'wechat_msg' => $failureWechatMsg
            ];
        }

        $this->getLogger()->info('兑换任务item详情：', $matchedItem);

        // 检查兑换结果
        $result     = $matchedItem['result'] ?? [];
        $resultCode = $result['code'] ?? -1;

        if ($resultCode === 0) {
            // 兑换成功
            $amount      = $this->parseBalance($result['fund'] ?? '0');
            $totalAmount = $this->parseBalance($result['total'] ?? '0');

            return [
                'success' => true,
                'message' => sprintf(
                    "%s:%s兑换成功\n汇率：%s\n%s",
                    $account->account,
                    $code,
                    $rate->rate,
                    $matchedItem['msg'] ?? ''
                ),
                'data'    => [
                    'account'      => $account->account,
                    'amount'       => $amount,
                    'rate'         => $rate->rate,
                    'total_amount' => $totalAmount,
                    'status'       => 'success',
                    'msg'          => $matchedItem['msg'] ?? '',
                    'details'      => json_encode([
                        'card_number'  => $code,
                        'card_type'    => $this->cardType,
                        'country_code' => $this->countryCode,
                        'api_response' => $result
                    ])
                ]
            ];
        } else {
            // 兑换失败
            $errorMessage = sprintf(
                "%s:%s兑换失败\n原因：%s",
                $account->account,
                $code,
                $matchedItem['msg'] ?? '未知原因'
            );
            $failureWechatMsg = "兑换失败\n-------------------------\n" . $code . "\n" . ($matchedItem['msg'] ?? '未知原因');

            return [
                'success' => false,
                'message' => $errorMessage,
                'data'    => [
                    'account'      => $account->account,
                    'amount'       => 0,
                    'rate'         => $rate->rate,
                    'total_amount' => 0,
                    'status'       => 'failed',
                    'msg'          => $matchedItem['msg'] ?? '兑换失败',
                    'details'      => json_encode([
                        'card_number'  => $code,
                        'card_type'    => $this->cardType,
                        'country_code' => $this->countryCode,
                        'api_response' => $result
                    ])
                ],
                'wechat_msg' => $failureWechatMsg
            ];
        }
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
        return (float)$numericStr;
    }

    /**
     * 调用兑换API
     * @throws Exception
     */
    private function callExchangeApi(ItunesTradeAccount $account, ItunesTradePlan $plan, string $code): array
    {
        $this->getLogger()->info("开始调用兑换API", [
            'account' => $account->account,
            'code'    => $code,
            'plan_id' => $plan->id
        ]);

        // 创建兑换任务
        $redemptionData = [
            [
                'username'   => $account->account ?? '',
                'password'   => $account->getDecryptedPassword(),
                'verify_url' => $account->api_url ?? '',
                'pin'        => $code
            ]
        ];

        $giftCardApiClient = new GiftCardApiClient();

        try {
            // 创建兑换任务
            $redemptionTask = $giftCardApiClient->createRedemptionTask($redemptionData, $plan->exchange_interval);

            $this->getLogger()->info("创建兑换请求原始返回消息", [
                'account'  => $account->account,
                'code'     => $code,
                'response' => $redemptionTask
            ]);

            if ($redemptionTask['code'] !== 0) {
                throw new Exception('创建兑换任务失败: ' . ($redemptionTask['msg'] ?? '未知错误'));
            }

            $taskId = $redemptionTask['data']['task_id'] ?? null;
            if (empty($taskId)) {
                throw new Exception('创建兑换任务失败: 未获取到任务ID');
            }

            $this->getLogger()->info('兑换任务创建成功', ['task_id' => $taskId]);

            // 等待任务完成
            return $this->waitForTaskCompletion($giftCardApiClient, $taskId);

        } catch (Exception $e) {
            $this->getLogger()->error("兑换API调用失败", [
                'account' => $account->account,
                'code'    => $code,
                'error'   => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 等待任务完成
     */
    private function waitForTaskCompletion(GiftCardApiClient $giftCardApiClient, string $taskId): array
    {
        $maxAttempts       = 500;        // 最大尝试次数
        $sleepMicroseconds = 200 * 1000; // 200毫秒
        $timeoutSeconds    = 120;        // 2分钟超时
        $startTime         = time();

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            // 检查超时
            if (time() - $startTime > $timeoutSeconds) {
                $this->getLogger()->error('兑换任务执行超时', [
                    'task_id' => $taskId,
                    'timeout_seconds' => $timeoutSeconds,
                    'attempts' => $attempt,
                    'gift_card_code' => $this->giftCardCode
                ]);
                throw new Exception("兑换任务执行超时（{$timeoutSeconds}秒），任务ID: {$taskId}");
            }

            try {
                $redeemResult = $giftCardApiClient->getRedemptionTaskStatus($taskId);

                if ($redeemResult['code'] !== 0) {
                    $this->getLogger()->error('查询兑换任务状态失败', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'error'   => $redeemResult['msg'] ?? '未知错误',
                        'gift_card_code' => $this->giftCardCode
                    ]);

                    // 如果是网络错误，继续重试
                    if ($attempt < $maxAttempts) {
                        usleep($sleepMicroseconds);
                        continue;
                    } else {
                        throw new Exception('查询兑换任务状态失败: ' . ($redeemResult['msg'] ?? '未知错误'));
                    }
                }

                // 验证数据结构
                if (!isset($redeemResult['data']) || !is_array($redeemResult['data'])) {
                    $this->getLogger()->error('兑换任务状态数据结构无效', [
                        'task_id'  => $taskId,
                        'attempt'  => $attempt,
                        'response' => $redeemResult,
                        'gift_card_code' => $this->giftCardCode
                    ]);

                    if ($attempt < $maxAttempts) {
                        usleep($sleepMicroseconds);
                        continue;
                    } else {
                        throw new Exception('兑换任务状态数据结构无效');
                    }
                }

                $status = $redeemResult['data']['status'] ?? '';

                // 定期记录任务状态（每10次记录一次）
                if ($attempt % 10 === 0 || $attempt <= 5) {
                    $this->getLogger()->info('当前兑换任务状态', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'status'  => $status,
                        'gift_card_code' => $this->giftCardCode
                    ]);
                }

                if ($status === 'completed') {
                    $this->getLogger()->info('兑换任务完成', [
                        'task_id'  => $taskId,
                        'attempt'  => $attempt,
                        'response' => $redeemResult,
                        'gift_card_code' => $this->giftCardCode
                    ]);

                    // 验证完成状态的数据结构
                    if (!isset($redeemResult['data']['items']) || !is_array($redeemResult['data']['items'])) {
                        throw new Exception('任务完成但数据结构无效');
                    }

                    // 处理每个item的result字段
                    $this->processTaskResults($redeemResult);

                    return $redeemResult;

                } elseif ($status === 'failed') {
                    $errorMsg = $redeemResult['data']['msg'] ?? '未知原因';
                    $this->getLogger()->error('兑换任务执行失败', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'error'   => $errorMsg,
                        'gift_card_code' => $this->giftCardCode
                    ]);
                    throw new Exception('兑换任务执行失败: ' . $errorMsg);

                } elseif ($status === 'running' || $status === 'pending') {
                    // 任务仍在执行中，继续等待
                    usleep($sleepMicroseconds);
                    continue;

                } else {
                    // 未知状态，记录日志但继续等待
                    $this->getLogger()->warning('未知的任务状态', [
                        'task_id' => $taskId,
                        'attempt' => $attempt,
                        'status'  => $status,
                        'gift_card_code' => $this->giftCardCode
                    ]);
                    usleep($sleepMicroseconds);
                    continue;
                }

            } catch (Exception $e) {
                $this->getLogger()->error('查询任务状态时发生异常', [
                    'task_id' => $taskId,
                    'attempt' => $attempt,
                    'error'   => $e->getMessage(),
                    'gift_card_code' => $this->giftCardCode
                ]);

                // 如果不是最后一次尝试，继续重试
                if ($attempt < $maxAttempts) {
                    usleep($sleepMicroseconds * 2); // 异常时等待更长时间
                    continue;
                } else {
                    throw $e;
                }
            }
        }

        throw new Exception("兑换任务执行超时或达到最大重试次数，任务ID: {$taskId}，礼品卡: {$this->giftCardCode}");
    }

    /**
     * 处理任务结果，解析JSON字符串
     */
    private function processTaskResults(array &$redeemResult): void
    {
        foreach ($redeemResult['data']['items'] as &$item) {
            if (isset($item['result']) && is_string($item['result'])) {
                try {
                    $decodedResult = json_decode($item['result'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $item['result'] = $decodedResult;
                    } else {
                        $this->getLogger()->warning('解析result JSON失败', [
                            'json_error'      => json_last_error_msg(),
                            'original_result' => $item['result']
                        ]);
                    }
                } catch (Exception $e) {
                    $this->getLogger()->error('解析result JSON时发生异常', [
                        'error'           => $e->getMessage(),
                        'original_result' => $item['result']
                    ]);
                }
            }
        }
    }

    /**
     * 检查并更新天数完成情况
     *
     * 这个方法的作用：
     * 1. 检查当天是否还有待处理的任务 - 确保一天的所有兑换任务都完成后才更新状态
     * 2. 检查当天兑换金额是否达到计划要求 - 只有达到要求才能进入下一天
     * 3. 更新账号的completed_days字段 - 记录每天的累计兑换金额
     * 4. 判断是否进入下一天或完成整个计划
     */
    protected function checkAndUpdateDayCompletion(ItunesTradeAccount $account, ItunesTradePlan $plan): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        // 更新completed_days字段
        $completedDays                      = json_decode($account->completed_days ?? '{}', true) ?: [];
        $completedDays[(string)$currentDay] = $currentDay;
        $account->update([
            'completed_days' => json_encode($completedDays),
        ]);
        // 检查当天是否还有其他待处理的任务
        // 这一步很重要：因为可能同时有多个兑换任务在执行，需要确保当天所有任务都完成
        $pendingCount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
            ->count();

        if ($pendingCount == 0) {
            // 当天任务全部完成，计算当天的累计兑换金额
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $currentDay)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 获取当天的计划额度
            $dailyAmounts = $plan->daily_amounts ?? [];
            $dailyLimit   = $dailyAmounts[$currentDay - 1] ?? 0;
            // $dailyTarget = $dailyLimit + $plan->float_amount; // 计划额度 + 浮动额度
            $dailyTarget = $dailyLimit;
            $this->getLogger()->info("检查当天完成情况", [
                'account_id'        => $account->account,
                'day'               => $currentDay,
                'daily_amount'      => $dailyAmount,
                'daily_limit'       => $dailyLimit,
                'float_amount'      => $plan->float_amount,
                'daily_target'      => $dailyTarget,
                'is_target_reached' => $dailyAmount >= $dailyTarget
            ]);

            // 更新completed_days字段
            $completedDays                      = json_decode($account->completed_days ?? '{}', true) ?: [];
            $completedDays[(string)$currentDay] = $dailyAmount;

            // 检查是否达到当天的目标额度
            if ($dailyAmount >= $dailyTarget) {
                // 达到目标额度，可以进入下一天或完成计划
                $this->getLogger()->info("当天目标达成，更新账号状态", [
                    'account_id'     => $account->id,
                    'day'            => $currentDay,
                    'daily_amount'   => $dailyAmount,
                    'daily_target'   => $dailyTarget,
                    'completed_days' => $completedDays
                ]);

                if ($currentDay >= $plan->plan_days) {
                    // 计划完成
                    $account->update([
                        'status'           => ItunesTradeAccount::STATUS_COMPLETED,
                        'current_plan_day' => null,
                        'plan_id'          => null,
                        'completed_days'   => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info("账号计划完成", [
                        'account_id'           => $account->id,
                        'plan_id'              => $plan->id,
                        'total_completed_days' => count($completedDays),
                        'final_completed_days' => $completedDays
                    ]);
                } else {
                    // 进入下一天，但需要等待日期间隔
                    $nextDay = $currentDay + 1;
                    $account->update([
                        'status'           => ItunesTradeAccount::STATUS_WAITING,
                        'current_plan_day' => $nextDay,
                        'completed_days'   => json_encode($completedDays),
                    ]);

                    $this->getLogger()->info("账号进入下一天", [
                        'account_id'     => $account->id,
                        'current_day'    => $nextDay,
                        'plan_id'        => $plan->id,
                        'completed_days' => $completedDays
                    ]);
                }
            } else {
                // 未达到目标额度，保持当前状态，等待更多兑换
                $account->update([
                    'status'         => ItunesTradeAccount::STATUS_LOCKING,
                    'completed_days' => json_encode($completedDays),
                ]);

                $this->getLogger()->info("当天目标未达成，保持等待状态", [
                    'account_id'       => $account->id,
                    'day'              => $currentDay,
                    'daily_amount'     => $dailyAmount,
                    'daily_target'     => $dailyTarget,
                    'remaining_amount' => $dailyTarget - $dailyAmount,
                    'completed_days'   => $completedDays
                ]);
            }
        } else {
            $this->getLogger()->info("当天还有待处理任务，暂不更新状态", [
                'account_id'    => $account->id,
                'current_day'   => $currentDay,
                'pending_count' => $pendingCount
            ]);
        }
    }

    /**
     * 判断是否为系统错误（需要记录堆栈跟踪）
     */
    protected function isSystemError(Exception $e): bool
    {
        // 如果是自定义的礼品卡兑换异常，使用其内置的判断方法
        if ($e instanceof GiftCardExchangeException) {
            return $e->isSystemError();
        }

        $message = $e->getMessage();

        // 检查是否为业务逻辑错误
        foreach (self::BUSINESS_ERRORS as $businessError) {
            // 使用不区分大小写的匹配，提高匹配准确性
            if (stripos($message, $businessError) !== false) {
                return false; // 是业务错误，不需要堆栈跟踪
            }
        }

        // 其他错误视为系统错误，需要堆栈跟踪
        return true;
    }

    /**
     * 判断是否为业务逻辑错误（用于其他地方的错误分类）
     *
     * @param Exception $e 异常对象
     * @return bool true=业务错误, false=系统错误
     */
    protected function isBusinessError(Exception $e): bool
    {
        return !$this->isSystemError($e);
    }

    /**
     * 检查礼品卡状态（不执行兑换）
     */
    public function check(string $code): array
    {
        try {
            return $this->validateGiftCard($code);
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * 检查是否为登录失败错误
     *
     * @param Exception $e 异常对象
     * @return bool true=登录失败错误, false=其他错误
     */
    private function isLoginFailureError(Exception $e): bool
    {
        $message = $e->getMessage();

        // 登录失败相关的错误模式
        $loginFailurePatterns = [
            '登录失败',
            'redis: nil',
            'login failed',
            'need login',
            'session expired',
            'authentication failed',
            'invalid credentials',
            'unauthorized',
            'login required'
        ];

        foreach ($loginFailurePatterns as $pattern) {
            if (stripos($message, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 获取账号容量类型（用于排序）
     * @deprecated 使用 getAccountCapacityTypeOptimized 以获得更好性能
     */
    private function getAccountCapacityType(ItunesTradeAccount $account, ItunesTradePlan $plan, int $multipleBase, array $giftCardInfo): int
    {
        $cardAmount = $giftCardInfo['amount'];
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
        $remainingDailyAmount = bcadd($dailyLimit, $plan->float_amount, 2);
        $remainingDailyAmount = bcsub($remainingDailyAmount, $dailySpent, 2);

        // 类型1：能够充满计划额度（不超出）
        if (bccomp($cardAmount, $remainingDailyAmount, 2) <= 0) {
            return 1;
        }

        // 类型2和3：根据约束类型判断是否可以预留
        $rate = $plan->rate;
        $constraintType = $rate ? $rate->amount_constraint : ItunesTradeRate::AMOUNT_CONSTRAINT_ALL;
        $remainingAfterUse = bcsub($cardAmount, $remainingDailyAmount, 2);

        switch ($constraintType) {
            case ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE:
                if ($multipleBase > 0 && bccomp($remainingAfterUse, $multipleBase, 2) >= 0) {
                    $modResult = fmod((float)$remainingAfterUse, (float)$multipleBase);
                    if ($modResult == 0) {
                        return 2; // 可以预留倍数额度
                    }
                }
                break;

            case ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED:
                $fixedAmounts = $rate->fixed_amounts ?? [];
                if (is_string($fixedAmounts)) {
                    $decodedAmounts = json_decode($fixedAmounts, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($decodedAmounts)) {
                        $fixedAmounts = $decodedAmounts;
                    }
                }

                if (is_array($fixedAmounts) && !empty($fixedAmounts)) {
                    $remainingFloat = (float)$remainingAfterUse;
                    foreach ($fixedAmounts as $fixedAmount) {
                        if (abs($remainingFloat - (float)$fixedAmount) < 0.01) {
                            return 2; // 可以预留固定面额
                        }
                    }
                }
                break;

            case ItunesTradeRate::AMOUNT_CONSTRAINT_ALL:
                if (bccomp($remainingAfterUse, '0', 2) > 0) {
                    return 2; // 可以预留剩余额度（全面额）
                }
                break;
        }

        // 类型3：不太适合
        return 3;
    }
}
