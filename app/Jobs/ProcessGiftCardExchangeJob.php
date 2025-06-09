<?php

namespace App\Jobs;

use App\Services\GiftCardExchangeService;
use App\Models\MrRoom;
use App\Models\MrRoomBill;
use App\Models\ChargePlan;
use App\Models\ChargePlanItem;
use App\Models\ChargePlanLog;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Throwable;
use Carbon\Carbon;

class ProcessGiftCardExchangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $message;
    protected string $requestId;
    protected array $input;

    /**
     * 队列连接
     *
     * @var string
     */
    public $connection;

    /**
     * 队列名称
     *
     * @var string
     */
    public $queue;

    /**
     * 任务最大尝试次数
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param array $input 兑换消息
     * @param string|null $requestId 请求ID，用于追踪
     */
    public function __construct(array $input, string $requestId = null)
    {
         Log::channel('gift_card_exchange')->info("礼品卡兑换队列任务数据", [
            'request_id' => $requestId,
            'result' => $input
        ]);
        $this->input = $input;
        $this->message = $input['msg'];
        $this->requestId = $requestId ?: uniqid('exchange_', true);

        // 设置队列连接和队列名称 - 高优先级
        $this->connection = 'redis';
        $this->queue = 'gift_card_exchange';
    }

    /**
     * Execute the job.
     *
     * @param GiftCardExchangeService $giftCardExchangeService
     * @return void
     * @throws Exception
     */
    public function handle(GiftCardExchangeService $giftCardExchangeService): void
    {
        try {
            Log::channel('gift_card_exchange')->info('---------------------开始处理兑换--------------------');

            Log::channel('gift_card_exchange')->info("开始处理礼品卡兑换队列任务", [
                'request_id' => $this->requestId,
                'message' => json_encode($this->input),
                'attempt' => $this->attempts()
            ]);

            // 先设置群组相关信息，在处理兑换消息之前
            $giftCardExchangeService->setRoomId($this->input['room_id']);
            $giftCardExchangeService->setWxId($this->input['wxid']);
            $giftCardExchangeService->setMsgid($this->input['msgid']);

            // 处理兑换消息
            $result = $giftCardExchangeService->processExchangeMessage($this->message);

            // 立即调试结果结构
            Log::channel('gift_card_exchange')->info("调试：processExchangeMessage返回结果", [
                'request_id' => $this->requestId,
                'result_type' => gettype($result),
                'result_is_array' => is_array($result),
                'result_keys' =>array_keys($result),
                'success_exists' => isset($result['success']),
                'success_value' => $result['success'] ?? 'not_set',
                'data_exists' => isset($result['data']),
                'data_type' => isset($result['data']) ? gettype($result['data']) : 'not_set',
                'data_is_array' => isset($result['data']) && is_array($result['data']),
                'data_keys' => (isset($result['data']) && is_array($result['data'])) ? array_keys($result['data']) : 'not_array'
            ]);

            // 如果data存在，记录其内容
            if (isset($result['data']) && is_array($result['data'])) {
                Log::channel('gift_card_exchange')->info("调试：data数组内容", [
                    'request_id' => $this->requestId,
                    'data_content' => $result['data'],
                    'status_in_data' => $result['data']['status'] ?? 'not_set',
                    'amount_in_data' => $result['data']['amount'] ?? 'not_set'
                ]);
            }

            if ($result['success']) {
                Log::channel('gift_card_exchange')->info("礼品卡兑换队列任务处理成功", [
                    'request_id' => $this->requestId,
                    'result' => $result
                ]);

                // 检查兑换是否真正成功（不仅仅是没有异常）
                $exchangeData = $result['data'] ?? [];
                $exchangeStatus = $exchangeData['data']['status'] ?? '';
                $amount = floatval($exchangeData['data']['amount'] ?? 0);

                // 添加调试日志
                Log::channel('gift_card_exchange')->info("调试：提取的兑换数据", [
                    'request_id' => $this->requestId,
                    'exchangeData' => $exchangeData,
                    'extractedStatus' => $exchangeStatus,
                    'extractedAmount' => $amount,
                    'statusCheck' => $exchangeStatus === 'success',
                    'amountCheck' => $amount > 0
                ]);

                if ($exchangeStatus === 'success' && $amount > 0) {
                    // 兑换真正成功且有金额，执行加账处理
                    $this->processAccountBilling($exchangeData['data']);

                    // 处理计划状态管理
                    $this->managePlanProgress($exchangeData['data']);

                    Log::channel('gift_card_exchange')->info("兑换成功，已执行加账处理", [
                        'request_id' => $this->requestId,
                        'amount' => $amount
                    ]);
                } else {
                    // 兑换失败或金额为0，不执行加账
                    Log::channel('gift_card_exchange')->warning("兑换未成功或金额为0，跳过加账处理", [
                        'request_id' => $this->requestId,
                        'status' => $exchangeStatus,
                        'amount' => $amount,
                        'message' => $exchangeData['data']['msg'] ?? ''
                    ]);

                    // 发送失败消息到微信群
                    $failMessage = sprintf(
                        "❌兑换失败\n" .
                        "--------------------------------------\n" .
                        "卡号：%s\n" .
                        "失败原因：%s",
                        $this->extractCardNumber(),
                        $exchangeData['data']['msg'] ?? '未知原因'
                    );
//                    send_msg_to_wechat($this->input['room_id'], $failMessage);
                }
            } else {
                Log::channel('gift_card_exchange')->error("礼品卡兑换队列任务处理失败1", [
                    'request_id' => $this->requestId,
                    'error' => $result['message']
                ]);
//                send_msg_to_wechat($this->input['room_id'], $result['message']);
                // 如果是业务逻辑错误（如卡无效、没有合适账户等），不重试
                if ($this->shouldNotRetry($result['message'])) {
                    $this->fail(new Exception($result['message']));
                    return;
                }

                throw new Exception($result['message']);
            }
        } catch (Exception $e) {
            Log::channel('gift_card_exchange')->error("礼品卡兑换队列任务执行异常2", [
                'request_id' => $this->requestId,
                'message' => $this->message,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            throw $e;
        }
    }

    /**
     * 判断是否不应该重试
     *
     * @param string $errorMessage
     * @return bool
     */
    protected function shouldNotRetry(string $errorMessage): bool
    {
        $noRetryErrors = [
            '消息格式无效',
            '礼品卡无效',
            '没有找到合适的可执行计划',
            '所有账号已达额度上限',
            '当前卡密面额',
            '小出设定的最小面额',
            '超出设定的最大面额',
            '不符合设定的倍数要求'
        ];

        foreach ($noRetryErrors as $error) {
            if (str_contains($errorMessage, $error)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::channel('gift_card_exchange')->error("礼品卡兑换队列任务最终失败", [
            'request_id' => $this->requestId,
            'message' => $this->message,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }

    /**
     * 处理加账操作
     *
     * @param array $data 兑换结果数据
     * @return void
     */
    private function processAccountBilling(array $data): void
    {
        try {
            if (empty($this->input['room_id'])) {
                Log::channel('gift_card_exchange')->error('room_id为空，无法进行加账处理');
                return;
            }

            $roomId = $this->input['room_id'];

            // 获取群组信息
            $room = MrRoom::where('room_id', $roomId)->first();
            if (!$room) {
                Log::channel('gift_card_exchange')->error("群组 {$roomId} 不存在");
                return;
            }

            // 从兑换结果中提取必要信息
            $account = $data['account'] ?? '';
            $cardNumber = $this->extractCardNumber();
            $amount = $data['amount'] ?? 0; // 原始金额
            $rate = $data['rate'] ?? 0; // 汇率
            $totalAmount = $data['total_amount'] ?? 0; // 账户总额

            // 从details中提取国家代码和货币信息
            $details = json_decode($data['details'] ?? '{}', true);
            $countryCode = $details['country_code'] ?? 'USD'; // 默认USD
            $currencyMap = [
                'US' => 'USD',
                'CA' => 'CAD',
                'GB' => 'GBP',
                'AU' => 'AUD',
                'DE' => 'EUR',
                'FR' => 'EUR',
                'IT' => 'EUR',
                'ES' => 'EUR',
                'JP' => 'JPY',
                'KR' => 'KRW',
                'HK' => 'HKD',
                'SG' => 'SGD'
            ];
            $currency = $currencyMap[$countryCode] ?? $countryCode;

            // 使用BC函数进行精度计算，保留两位小数
            $amount = bcadd($amount, '0', 2); // 确保金额为两位小数
            $rate = bcadd($rate, '0', 2); // 确保汇率为两位小数

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
                    'room_id' => $roomId,
                    'room_name' => $room->room_name ?? '未知群组',
                    'event' => 1, // 兑换事件
                    'msgid' => $this->input['msgid'] ?? '',
                    'money' => $amount,
                    'rate' => $rate,
                    'fee' => 0.00,
                    'amount' => $changeAmount,
                    'card_type' => 'image', // 默认图片类型
                    'before_money' => $beforeMoney,
                    'bill_money' => $afterMoney, // 修正：这应该是变动后的总金额
                    'remark' => $cardNumber,
                    'op_id' => $this->input['wxid'] ?? '',
                    'op_name' => '',
                    'code' => $cardNumber,
                    'content' => json_encode([
                        'account' => $account,
                        'original_amount' => $amount,
                        'exchange_rate' => $rate,
                        'converted_amount' => $changeAmount,
                        'total_balance' => $totalAmount
                    ]),
                    'note' => "礼品卡兑换 - {$cardNumber}",
                    'status' => 0,
                    'is_settle' => 0,
                    'is_del' => 0
                ]);

                // 更新群组未结算金额和变更时间
                $room->update([
                    'unsettled' => $afterMoney,
                    'changed_at' => now()
                ]);

                // 提交事务
                DB::commit();

                // 构建成功消息
                $successMessage = $this->buildSuccessMessage([
                    'card_number' => $cardNumber,
                    'amount' => $amount,
                    'rate' => $rate,
                    'before_money' => $beforeMoney,
                    'change_amount' => $changeAmount,
                    'after_money' => $afterMoney,
                    'currency' => $currency,
                    'exchange_time' => now()->format('Y-n-j H:i:s')
                ]);

                // 发送微信消息
                // send_msg_to_wechat($roomId, $successMessage);

                Log::channel('gift_card_exchange')->info("群组 {$roomId} 加账处理完成", [
                    'card_number' => $cardNumber,
                    'amount' => $amount,
                    'rate' => $rate,
                    'change_amount' => $changeAmount,
                    'before_money' => $beforeMoney,
                    'after_money' => $afterMoney,
                    'success_msg' => $successMessage,
                    'room_id' => $roomId
                ]);

            } catch (\Exception $e) {
                // 回滚事务
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("加账处理失败: " . $e->getMessage(), [
                'request_id' => $this->requestId,
                'room_id' => $this->input['room_id'] ?? '',
                'data' => $data
            ]);
        }
    }

    /**
     * 从消息中提取卡号
     *
     * @return string
     */
    private function extractCardNumber(): string
    {
        if (preg_match('/^([A-Z0-9]+)\s*\/\s*\d+$/i', $this->message, $matches)) {
            return $matches[1];
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
        $amount = bcadd($data['amount'], '0', 2);
        $beforeMoney = bcadd($data['before_money'], '0', 2);
        $rate = bcadd($data['rate'], '0', 1); // 汇率保留一位小数
        $changeAmount = bcadd($data['change_amount'], '0', 0); // 变动金额保留整数
        $afterMoney = bcadd($data['after_money'], '0', 2);
        $currency = $data['currency'] ?? 'USD'; // 动态货币代码

        return sprintf(
            "[强]兑换成功\n" .
            "--------------------------------------\n" .
            "加载卡号：%s\n" .
            "加载结果：$%s（%s）\n" .
            "原始账单：%s\n" .
            "变动金额：%s$%s*%s=%s\n" .
            "当前账单：%s\n" .
            "加卡时间：%s",
            $data['card_number'],
            $amount,
            $currency,
            $beforeMoney,
            $currency,
            $amount,
            $rate,
            $changeAmount,
            $afterMoney,
            $data['exchange_time']
        );
    }

    /**
     * 处理计划状态管理
     *
     * @param array $data 兑换结果数据
     * @return void
     */
    private function managePlanProgress(array $data): void
    {
        try {
            // 从兑换结果中获取账号信息
            $account = $data['account'] ?? '';
            $amount = floatval($data['amount'] ?? 0);

            if (empty($account) || $amount <= 0) {
                Log::channel('gift_card_exchange')->warning("无效的账号或金额，跳过计划状态管理", [
                    'account' => $account,
                    'amount' => $amount
                ]);
                return;
            }

            // 查找相关的处理中计划
            $plans = ChargePlan::where('account', $account)
                ->where('status', 'processing')
                ->get();

            if ($plans->isEmpty()) {
                Log::channel('gift_card_exchange')->info("账号 {$account} 没有找到处理中的计划");
                return;
            }

            foreach ($plans as $plan) {
                $this->updatePlanProgress($plan, $amount);
            }

        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("计划状态管理失败: " . $e->getMessage(), [
                'request_id' => $this->requestId,
                'data' => $data
            ]);
        }
    }

    /**
     * 更新单个计划的进度
     *
     * @param ChargePlan $plan
     * @param float $amount
     * @return void
     */
    private function updatePlanProgress(ChargePlan $plan, float $amount): void
    {
        try {
            DB::beginTransaction();

            // 初始化当前天数（如果为空则设为1）
            if (empty($plan->current_day)) {
                $plan->current_day = 1;
                $plan->save();
            }

            $currentDay = $plan->current_day;

            // 获取当前天的计划项
            $currentDayItem = $plan->items()
                ->where('day', $currentDay)
                ->first();

            if (!$currentDayItem) {
                Log::channel('gift_card_exchange')->warning("计划 {$plan->id} 第 {$currentDay} 天没有对应的计划项");
                DB::rollBack();
                return;
            }

            // 如果当前天的状态还是pending，设为processing
            if ($currentDayItem->status === 'pending') {
                $currentDayItem->status = 'processing';
                $currentDayItem->save();

                Log::channel('gift_card_exchange')->info("计划 {$plan->id} 第 {$currentDay} 天状态变更为进行中");
            }

            // 更新计划已充值金额
            $plan->charged_amount = ($plan->charged_amount ?? 0) + $amount;
            $plan->save();

            // 创建日志记录
            ChargePlanLog::create([
                'plan_id' => $plan->id,
                'item_id' => $currentDayItem->id,
                'day' => $currentDay,
                'time' => now()->format('H:i:s'),
                'action' => 'gift_card_exchange',
                'amount' => $amount,
                'status' => 'success',
                'details' => "礼品卡兑换成功，金额: {$amount}"
            ]);

            // 获取当前天已执行的总金额（从当前天开始时间统计，包含本次兑换）
            $currentDayStartTime = $this->getCurrentDayStartTime($plan, $currentDay);
            $dailyExecutedAmount = ChargePlanLog::where('plan_id', $plan->id)
                ->where('day', $currentDay)
                ->where('status', 'success')
                ->where('action', 'gift_card_exchange')
                ->where('created_at', '>=', $currentDayStartTime)
                ->sum('amount');

            Log::channel('gift_card_exchange')->info("计划 {$plan->id} 第 {$currentDay} 天已执行金额: {$dailyExecutedAmount}，目标金额: {$currentDayItem->amount}，最大金额: {$currentDayItem->max_amount}");

            // 更新当日计划的基本数据
            $currentDayItem->executed_amount = ($currentDayItem->executed_amount ?? 0) + $amount;
            $currentDayItem->executed_at = now();
            
            // 根据是否达到当日计划设置状态和结果
            // 判断条件：达到目标金额或超出最大金额都算完成
            $isCompleted = ($dailyExecutedAmount >= $currentDayItem->amount) || 
                          ($dailyExecutedAmount >= $currentDayItem->max_amount);
            
            Log::channel('gift_card_exchange')->info("计划 {$plan->id} 第 {$currentDay} 天完成判断", [
                'dailyExecutedAmount' => $dailyExecutedAmount,
                'targetAmount' => $currentDayItem->amount,
                'maxAmount' => $currentDayItem->max_amount,
                'reachedTarget' => $dailyExecutedAmount >= $currentDayItem->amount,
                'exceededMax' => $dailyExecutedAmount >= $currentDayItem->max_amount,
                'isCompleted' => $isCompleted
            ]);
            
            if ($isCompleted) {
                // 当日计划已完成
                $currentDayItem->status = 'completed';
                if ($dailyExecutedAmount >= $currentDayItem->max_amount) {
                    $currentDayItem->result = "当日计划已完成（超出最大金额），累计金额: {$dailyExecutedAmount}";
                } else {
                    $currentDayItem->result = "当日计划已完成，累计金额: {$dailyExecutedAmount}";
                }
                
                Log::channel('gift_card_exchange')->info("计划 {$plan->id} 第 {$currentDay} 天计划已达成，累计: {$dailyExecutedAmount}");
                
                // 保存当日计划更新
                $currentDayItem->save();

                // 检查是否完成整个计划
                if ($plan->charged_amount >= $plan->total_amount) {
                    // 整个计划已完成
                    $plan->status = 'completed';
                    $plan->save();

                    // 将其他未执行的天数标记为已取消
                    $this->cancelRemainingDays($plan, $currentDay);

                    Log::channel('gift_card_exchange')->info("计划 {$plan->id} 整个计划已完成");
                } else {
                    // 当日计划完成但整个计划未完成，检查是否可以进入下一天
                    $this->checkNextDayAvailability($plan, $currentDay);
                }
            } else {
                // 当日计划进行中
                $currentDayItem->status = 'processing';
                $currentDayItem->result = "当日计划进行中，累计金额: {$dailyExecutedAmount}/{$currentDayItem->amount}";
                
                // 保存当日计划更新
                $currentDayItem->save();
                
                Log::channel('gift_card_exchange')->info("计划 {$plan->id} 第 {$currentDay} 天计划进行中，累计: {$dailyExecutedAmount}/{$currentDayItem->amount}");
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            Log::channel('gift_card_exchange')->error("更新计划 {$plan->id} 进度失败: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 检查下一天的可用性
     *
     * @param ChargePlan $plan
     * @param int $currentDay
     * @return void
     */
    private function checkNextDayAvailability(ChargePlan $plan, int $currentDay): void
    {
        try {
            $nextDay = $currentDay + 1;

            // 检查是否还有下一天的计划
            $nextDayItem = $plan->items()
                ->where('day', $nextDay)
                ->first();

            if (!$nextDayItem) {
                Log::channel('gift_card_exchange')->info("计划 {$plan->id} 没有第 {$nextDay} 天的计划项");
                return;
            }

            // 获取当前天完成的时间（即最后一次成功执行的时间）
            $currentDayCompletionTime = ChargePlanLog::where('plan_id', $plan->id)
                ->where('day', $currentDay)
                ->where('status', 'success')
                ->where('action', 'gift_card_exchange')
                ->latest('created_at')
                ->value('created_at');

            if ($currentDayCompletionTime) {
                $completionTime = Carbon::parse($currentDayCompletionTime);
                $now = Carbon::now();
                $hoursElapsed = $now->diffInHours($completionTime);

                Log::channel('gift_card_exchange')->info("计划 {$plan->id} 第 {$currentDay} 天完成时间检查", [
                    'completionTime' => $completionTime->format('Y-m-d H:i:s'),
                    'currentTime' => $now->format('Y-m-d H:i:s'),
                    'hoursElapsed' => $hoursElapsed,
                    'canProceedToNextDay' => $hoursElapsed >= 24
                ]);

                // 检查是否已过去24小时，如果是，立即进入下一天
                if ($hoursElapsed >= 24) {
                    $plan->current_day = $nextDay;
                    $plan->save();

                    Log::channel('gift_card_exchange')->info("计划 {$plan->id} 24小时已过，立即进入第 {$nextDay} 天");
                } else {
                    $remainingHours = 24 - $hoursElapsed;
                    Log::channel('gift_card_exchange')->info("计划 {$plan->id} 第 {$currentDay} 天已完成，但24小时未满，还需等待 {$remainingHours} 小时");
                }
            } else {
                Log::channel('gift_card_exchange')->warning("计划 {$plan->id} 找不到第 {$currentDay} 天的完成时间记录");
            }

        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("检查计划 {$plan->id} 下一天可用性失败: " . $e->getMessage());
        }
    }

    /**
     * 取消剩余天数的计划项
     *
     * @param ChargePlan $plan
     * @param int $completedDay
     * @return void
     */
    private function cancelRemainingDays(ChargePlan $plan, int $completedDay): void
    {
        try {
            // 将所有大于当前完成天数且状态为pending的计划项标记为failed（已取消）
            $remainingItems = $plan->items()
                ->where('day', '>', $completedDay)
                ->where('status', 'pending')
                ->get();

            foreach ($remainingItems as $item) {
                $item->status = 'failed';
                $item->result = '计划已提前完成，此项目被取消';
                $item->save();

                // 创建取消日志
                ChargePlanLog::create([
                    'plan_id' => $plan->id,
                    'item_id' => $item->id,
                    'day' => $item->day,
                    'time' => now()->format('H:i:s'),
                    'action' => 'cancel',
                    'amount' => 0,
                    'status' => 'success',
                    'details' => '计划已提前完成，此项目被自动取消'
                ]);
            }

            Log::channel('gift_card_exchange')->info("计划 {$plan->id} 已取消剩余 " . $remainingItems->count() . " 个计划项");

        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("取消计划 {$plan->id} 剩余天数失败: " . $e->getMessage());
        }
    }

    /**
     * 获取当前天的开始时间
     *
     * @param ChargePlan $plan
     * @param int $currentDay
     * @return Carbon
     */
    private function getCurrentDayStartTime(ChargePlan $plan, int $currentDay): Carbon
    {
        try {
            if ($currentDay == 1) {
                // 第一天从计划开始时间算起
                return Carbon::parse($plan->start_time ?? now());
            } else {
                // 其他天从上一天完成后24小时算起
                $previousDay = $currentDay - 1;

                // 获取上一天最后一次成功执行的时间
                $lastExecutionTime = ChargePlanLog::where('plan_id', $plan->id)
                    ->where('day', $previousDay)
                    ->where('status', 'success')
                    ->where('action', 'gift_card_exchange')
                    ->latest('created_at')
                    ->value('created_at');

                if ($lastExecutionTime) {
                    return Carbon::parse($lastExecutionTime)->addHours(24);
                } else {
                    // 如果没有找到上一天的执行记录，使用计划开始时间 + (当前天-1) * 24小时
                    return Carbon::parse($plan->start_time ?? now())->addDays($currentDay - 1);
                }
            }
        } catch (\Exception $e) {
            Log::channel('gift_card_exchange')->error("获取计划 {$plan->id} 第 {$currentDay} 天开始时间失败: " . $e->getMessage());
            // 默认返回当前时间
            return Carbon::now();
        }
    }
}
