<?php

namespace App\Console\Commands;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use App\Models\ItunesTradePlan;
use App\Services\GiftCardApiClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class ProcessItunesAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'itunes:process-accounts {--logout-only : 仅执行登出操作} {--login-only : 仅执行登录操作} {--fix-task= : 通过任务ID修复账号数据}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '处理iTunes账号状态转换和登录管理 - 每分钟运行一次';

    protected GiftCardApiClient $giftCardApiClient;
    private const TARGET_ZERO_AMOUNT_ACCOUNTS = 50; // 目标零余额账号数量

    /**
     * 执行控制台命令
     */
    public function handle(): void
    {
        $date = now();
        $logoutOnly = $this->option('logout-only');
        $loginOnly = $this->option('login-only');
        $fixTask = $this->option('fix-task');

        $this->getLogger()->info("==================================[{$date}]===============================");

        if ($logoutOnly) {
            $this->getLogger()->info("开始执行登出操作...");
        } elseif ($loginOnly) {
            $this->getLogger()->info("开始执行登录操作...");
        } elseif ($fixTask) {
            $this->getLogger()->info("开始执行修复任务，任务ID: {$fixTask}");
        } else {
            $this->getLogger()->info("开始iTunes账号状态转换和登录管理...");
        }

        try {
            $this->giftCardApiClient = app(GiftCardApiClient::class);

            if ($logoutOnly) {
                // 仅执行登出操作
                $this->executeLogoutOnly();
                $this->getLogger()->info('登出操作完成');
                return;
            }

            if ($loginOnly) {
                // 仅执行登录操作
                $this->executeLoginOnly();
                $this->getLogger()->info('登录操作完成');
                return;
            }

            if ($fixTask) {
                // 通过任务ID修复账号数据
                $this->executeFixTask($fixTask);
                $this->getLogger()->info('修复任务操作完成');
                return;
            }

            // 1. 维护零余额账号数量（保持50个）
            $this->maintainZeroAmountAccounts();

            // 2. 处理账号状态转换
            $this->processAccountStatusTransitions();

            $this->getLogger()->info('iTunes账号处理完成');

        } catch (\Exception $e) {
            $this->getLogger()->error('处理过程中发生错误: ' . $e->getMessage());
            $this->getLogger()->error('iTunes账号处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 仅执行登出操作
     */
    private function executeLogoutOnly(): void
    {
        // 查找符合条件的账号：amount=0, status=processing, login_status=valid
        $accounts = ItunesTradeAccount::where('amount', 0)
            ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->orderBy('created_at', 'desc') // 后导入的先退出登录
            ->get();

        $this->getLogger()->info("找到 {$accounts->count()} 个符合登出条件的账号");

        if ($accounts->isEmpty()) {
            $this->getLogger()->info("没有符合登出条件的账号");
            return;
        }

        // 批量登出账号
        $this->batchLogoutAccounts($accounts, '仅登出操作');
    }

    /**
     * 仅执行登录操作
     */
    private function executeLoginOnly(): void
    {
        // 查找符合条件的账号：status=processing, login_status=invalid, amount>0
        $accounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_INVALID)
            ->where('amount', '>', 0)
            ->orderBy('created_at', 'asc') // 先导入的优先处理
            ->get();

        $this->getLogger()->info("找到 {$accounts->count()} 个符合登录条件的账号");

        if ($accounts->isEmpty()) {
            $this->getLogger()->info("没有符合登录条件的账号");
            return;
        }

        // 批量登录账号
        $this->batchLoginAccounts($accounts, $accounts->count());
    }

    /**
     * 通过任务ID执行修复操作
     */
    private function executeFixTask(string $taskId): void
    {
        $this->getLogger()->info("开始修复任务，任务ID: {$taskId}");

        try {
            // 从API获取登录任务状态
            $statusResponse = $this->giftCardApiClient->getLoginTaskStatus($taskId);

            if ($statusResponse['code'] !== 0) {
                $this->getLogger()->error("获取任务状态失败: " . ($statusResponse['msg'] ?? '未知错误'));
                return;
            }

            $taskStatus = $statusResponse['data']['status'] ?? '';
            $items = $statusResponse['data']['items'] ?? [];

            $this->getLogger()->info("任务状态: {$taskStatus}，找到 {" . count($items) . "} 个项目");

            if (empty($items)) {
                $this->getLogger()->warning("任务响应中未找到任何项目");
                return;
            }

            // 处理任务结果中的每个账号
            $processedCount = 0;
            $successCount = 0;
            $failedCount = 0;

            foreach ($items as $item) {
                $this->processFixTaskItem($item, $processedCount, $successCount, $failedCount);
            }

            $this->getLogger()->info("修复任务完成", [
                'task_id' => $taskId,
                'processed_count' => $processedCount,
                'success_count' => $successCount,
                'failed_count' => $failedCount
            ]);

        } catch (\Exception $e) {
            $this->getLogger()->error("修复任务失败: " . $e->getMessage());
        }
    }

    /**
     * 处理单个修复任务项目
     */
    private function processFixTaskItem(array $item, int &$processedCount, int &$successCount, int &$failedCount): void
    {
        $username = $item['data_id'] ?? '';
        $status = $item['status'] ?? '';
        $msg = $item['msg'] ?? '';
        $result = $item['result'] ?? '';

        if (empty($username)) {
            $this->getLogger()->warning("任务项目中用户名为空，跳过");
            return;
        }

        $processedCount++;

        // 查找对应的账号
        $account = ItunesTradeAccount::where('account', $username)->first();
        if (!$account) {
            $this->getLogger()->warning("未找到用户名对应的账号: {$username}");
            $failedCount++;
            return;
        }

        $this->getLogger()->info("正在处理账号修复: {$username}，状态: {$status}，消息: {$msg}");

        if ($status === 'completed') {
            if (strpos($msg, '登录成功') !== false || strpos($msg, 'login successful') !== false) {
                // 登录成功，更新登录状态
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_ACTIVE
                ]);

                $this->getLogger()->info("账号 {$username} 登录状态已更新为有效");

                // 解析并更新余额信息
                if ($result) {
                    try {
                        $resultData = json_decode($result, true);
                        if (isset($resultData['balance']) && !empty($resultData['balance'])) {
                            $balanceString = $resultData['balance'];
                            // 移除货币符号并转换为浮点数
                            // 处理格式如 "$700.00", "¥1000.50", "€500.25", "$1,350.00" 等
                            $balance = (float)preg_replace('/[^\d.-]/', '', $balanceString);
                            $account->update(['amount' => $balance]);
                            $this->getLogger()->info("账号 {$username} 余额已更新: {$balance} (原始: {$balanceString})");
                        }

                        // 如果有国家信息也更新
                        if (isset($resultData['countryCode']) && !empty($resultData['countryCode'])) {
                            // 如果需要，可以在这里添加国家代码更新逻辑
                            $this->getLogger()->info("账号 {$username} 国家: {$resultData['countryCode']} - {$resultData['country']}");
                        }
                    } catch (\Exception $e) {
                        $this->getLogger()->warning("解析账号 {$username} 登录结果失败: " . $e->getMessage());
                    }
                }

                $successCount++;
            } else {
                // 登录失败，更新登录状态为无效
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID
                ]);

                $this->getLogger()->warning("账号 {$username} 登录失败，已更新为无效状态: {$msg}");
                $failedCount++;
            }
        } else {
            $this->getLogger()->warning("账号 {$username} 任务未完成，状态: {$status}");
            $failedCount++;
        }
    }

    /**
     * 维护零余额账号数量
     */
    private function maintainZeroAmountAccounts(): void
    {
        // 统计当前零余额且登录有效的账号数量
        $currentZeroAmountCount = ItunesTradeAccount::where('amount', 0)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->count();

        $this->getLogger()->info("当前零余额且登录有效的账号数量: {$currentZeroAmountCount}");

        if ($currentZeroAmountCount >= self::TARGET_ZERO_AMOUNT_ACCOUNTS) {
            $this->getLogger()->info("目标零余额账号数量已达到 (" . self::TARGET_ZERO_AMOUNT_ACCOUNTS . ")，无需补充");
            return;
        }

        $needCount = self::TARGET_ZERO_AMOUNT_ACCOUNTS - $currentZeroAmountCount;
        $this->getLogger()->info("需要补充 {$needCount} 个零余额登录账号");

        // 查找状态为processing且登录状态为invalid的零余额账号进行登录
        $candidateAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_INVALID)
            ->where('amount', 0)
            ->orderBy('created_at', 'asc') // 先导入的优先
            ->limit($needCount * 2) // 获取更多以防登录失败
            ->get();

        if ($candidateAccounts->isEmpty()) {
            $this->getLogger()->warning("未找到可用于登录的候选账号");
            return;
        }

        $this->getLogger()->info("找到 {$candidateAccounts->count()} 个候选登录账号");

        // 批量登录账号
        $this->batchLoginAccounts($candidateAccounts, $needCount);
    }

    /**
     * 处理账号状态转换
     */
    private function processAccountStatusTransitions(): void
    {
        // 获取需要处理的账号（仅LOCKING和WAITING状态）
        $accounts = ItunesTradeAccount::whereIn('status', [
            ItunesTradeAccount::STATUS_LOCKING,
            ItunesTradeAccount::STATUS_WAITING
        ])
        ->with('plan')
        ->get();

        // 查找有plan_id但计划已删除的账号（仅WAITING和PROCESSING状态）
        $orphanedAccounts = ItunesTradeAccount::whereNotNull('plan_id')
            ->whereDoesntHave('plan')
            ->whereIn('status', [
                ItunesTradeAccount::STATUS_WAITING,
                ItunesTradeAccount::STATUS_PROCESSING
            ])
            ->get();

        // 查找已完成且登录有效需要登出的账号
        $completedAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_COMPLETED)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->get();

        // 合并所有需要处理的账号
        $allAccounts = $accounts->merge($orphanedAccounts)->unique('id');

        $this->getLogger()->info("找到 {$accounts->count()} 个LOCKING/WAITING账号，{$orphanedAccounts->count()} 个孤立账号，{$completedAccounts->count()} 个需要登出的已完成账号");

        // 处理已完成账号的登出
        if ($completedAccounts->isNotEmpty()) {
            $this->batchLogoutAccounts($completedAccounts, '已完成状态登出');
        }

        // 处理状态转换
        foreach ($allAccounts as $account) {
            try {
                $this->processAccount($account);
            } catch (\Exception $e) {
                $this->getLogger()->error("处理账号 {$account->account} 失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 批量登录账号
     */
    private function batchLoginAccounts($accounts, int $targetCount): void
    {
        if ($accounts->isEmpty()) {
            return;
        }

        // 准备登录数据
        $loginData = [];
        foreach ($accounts as $account) {
            $loginData[] = [
                'id' => $account->id,
                'username' => $account->account,
                'password' => $account->getDecryptedPassword(),
                'VerifyUrl' => $account->api_url ?? ''
            ];
        }

        try {
            // 创建登录任务
            $response = $this->giftCardApiClient->createLoginTask($loginData);

            if ($response['code'] !== 0) {
                $this->getLogger()->error("创建登录任务失败: " . ($response['msg'] ?? '未知错误'));
                return;
            }

            $taskId = $response['data']['task_id'] ?? null;
            if (!$taskId) {
                $this->getLogger()->error("创建登录任务失败: 未收到任务ID");
                return;
            }

            $this->getLogger()->info("登录任务创建成功，任务ID: {$taskId}，等待完成...");

            // 等待登录任务完成并更新账号状态
            $this->waitForLoginTaskCompletion($taskId, $accounts, $targetCount);

        } catch (\Exception $e) {
            $this->getLogger()->error("批量登录账号失败: " . $e->getMessage());
        }
    }

    /**
     * 等待登录任务完成
     */
    private function waitForLoginTaskCompletion(string $taskId, $accounts, int $targetCount): void
    {
        $maxAttempts = 60; // 最多等待5分钟（60 * 5秒）
        $sleepSeconds = 5;
        $successCount = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $statusResponse = $this->giftCardApiClient->getLoginTaskStatus($taskId);

                if ($statusResponse['code'] !== 0) {
                    $this->getLogger()->error("查询登录任务状态失败: " . ($statusResponse['msg'] ?? '未知错误'));
                    break;
                }

                $taskStatus = $statusResponse['data']['status'] ?? '';
                $items = $statusResponse['data']['items'] ?? [];

                $this->getLogger()->info("登录任务状态检查（第{$attempt}次）: {$taskStatus}");

                // 处理每个账号的登录结果
                foreach ($items as $item) {
                    if ($item['status'] === 'completed') {
                        $this->processLoginResult($item, $accounts);

                        // 如果登录成功，增加成功计数
                        if (strpos($item['msg'], 'login successful') !== false || strpos($item['msg'], '登录成功') !== false) {
                            $successCount++;
                        }
                    }
                }

                // 如果任务完成或达到目标数量则退出循环
                if ($taskStatus === 'completed' || $successCount >= $targetCount) {
                    $this->getLogger()->info("登录任务完成，成功登录 {$successCount} 个账号");
                    break;
                }

                sleep($sleepSeconds);

            } catch (\Exception $e) {
                $this->getLogger()->error("登录任务状态查询异常: " . $e->getMessage());
                break;
            }
        }

        if ($attempt > $maxAttempts) {
            $this->getLogger()->warning("登录任务等待超时，成功登录 {$successCount} 个账号");
        }
    }

    /**
     * 处理单个账号登录结果
     */
    private function processLoginResult(array $item, $accounts): void
    {
        $username = $item['data_id'] ?? '';
        $status = $item['status'] ?? '';
        $msg = $item['msg'] ?? '';
        $result = $item['result'] ?? '';

        // 查找对应的账号
        $account = $accounts->firstWhere('account', $username);
        if (!$account) {
            $this->getLogger()->warning("未找到用户名对应的账号: {$username}");
            return;
        }

        if ($status === 'completed') {
            if (strpos($msg, 'login successful') !== false || strpos($msg, '登录成功') !== false) {
                // 登录成功，更新登录状态
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_ACTIVE
                ]);

                $this->getLogger()->info("账号 {$username} 登录成功");

                // 从结果中解析余额信息
                if ($result) {
                    try {
                        $resultData = json_decode($result, true);
                        if (isset($resultData['balance'])) {
                            $balanceString = $resultData['balance'];
                            // 移除货币符号并转换为浮点数
                            // 处理格式如 "$700.00", "¥1000.50", "€500.25" 等
                            $balance = (float)preg_replace('/[^\d.-]/', '', $balanceString);
                            $account->update(['amount' => $balance]);
                            $this->getLogger()->info("更新账号 {$username} 余额: {$balance} (原始: {$balanceString})");
                        }
                    } catch (\Exception $e) {
                        $this->getLogger()->warning("解析账号 {$username} 登录结果失败: " . $e->getMessage());
                    }
                }
            } else {
                // 登录失败
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID
                ]);

                $this->getLogger()->warning("账号 {$username} 登录失败: {$msg}");
            }
        }
    }

    /**
     * 批量登出账号
     */
    private function batchLogoutAccounts($accounts, string $reason = ''): void
    {
        if ($accounts->isEmpty()) {
            return;
        }

        // 准备登出数据
        $logoutData = [];
        foreach ($accounts as $account) {
            $logoutData[] = [
                'username' => $account->account
            ];
        }

        try {
            $response = $this->giftCardApiClient->deleteUserLogins($logoutData);

            if ($response['code'] !== 0) {
                $this->getLogger()->error("批量登出失败: " . ($response['msg'] ?? '未知错误'));
                return;
            }

            // 更新账号登录状态
            foreach ($accounts as $account) {
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID
                ]);
            }

            $this->getLogger()->info("成功登出 {$accounts->count()} 个账号" . ($reason ? " ({$reason})" : ''));

        } catch (\Exception $e) {
            $this->getLogger()->error("批量登出失败: " . $e->getMessage());
        }
    }

    /**
     * 获取专用日志记录器实例
     */
    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }

    /**
     * 处理单个账号
     */
    private function processAccount(ItunesTradeAccount $account): void
    {
        // 1. 检查是否有待处理任务，如有则跳过
        if ($this->hasPendingTasks($account)) {
            $this->getLogger()->info("账号 {$account->account} 有待处理任务，跳过");
            return;
        }

        // 2. 处理已删除计划的解绑
        if ($this->handleDeletedPlanUnbinding($account)) {
            return; // 如果处理了计划解绑，则跳过后续处理
        }

        // 3. 根据状态进行处理
        if ($account->status === ItunesTradeAccount::STATUS_LOCKING) {
            $this->processLockingAccount($account);
        } elseif ($account->status === ItunesTradeAccount::STATUS_WAITING) {
            $this->processWaitingAccount($account);
        }
    }

    /**
     * 处理已删除计划的解绑
     */
    private function handleDeletedPlanUnbinding(ItunesTradeAccount $account): bool
    {
        // Check if account has plan_id but plan is deleted
        if ($account->plan_id && !$account->plan) {
            $this->getLogger()->warning("发现账号关联的计划已删除", [
                'account' => $account->account,
                'plan_id' => $account->plan_id,
                'current_plan_day' => $account->current_plan_day,
                'status' => $account->status,
                'issue' => '计划已删除，需要解绑'
            ]);

            // Unbind plan and reset related fields (without updating timestamps)
            $account->timestamps = false;
            $account->update([
                'plan_id' => null,
                'status' => ItunesTradeAccount::STATUS_WAITING,
            ]);
            $account->timestamps = true;

            $this->getLogger()->info("账号 {$account->account} 计划解绑完成", [
                'action' => '清除plan_id',
                'new_status' => ItunesTradeAccount::STATUS_WAITING,
                'reason' => '关联的计划已删除'
            ]);

            return true; // Return true to indicate processing completed
        }

        return false; // Return false to indicate no processing needed
    }

    /**
     * 检查账号是否有待处理任务
     */
    private function hasPendingTasks(ItunesTradeAccount $account): bool
    {
        $pendingCount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_PENDING)
            ->count();

        return $pendingCount > 0;
    }

    /**
     * 处理锁定状态的账号
     */
    private function processLockingAccount(ItunesTradeAccount $account): void
    {
        $this->getLogger()->info("正在处理锁定状态账号: {$account->account}");

        // 1. 未绑定计划的账号，不处理，不发送消息
        if (!$account->plan) {
            $this->getLogger()->debug("账号 {$account->account} 未绑定计划，跳过处理", [
                'account_id' => $account->account,
                'status' => $account->status,
                'plan_id' => $account->plan_id,
                'reason' => '未绑定计划，不处理不发送消息'
            ]);
            return;
        }

        // Get last success log
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        // 2. 如果有执行记录，检查最后一条成功记录的当日天数是否为最后一天
        if ($lastSuccessLog) {
            $lastSuccessDay = $lastSuccessLog->day;
            $planTotalDays = $account->plan->plan_days;
            
            // 如果最后一条成功记录的天数不是最后一天，不处理，不发送消息
            if ($lastSuccessDay < $planTotalDays) {
                $this->getLogger()->debug("账号 {$account->account} 最后成功记录不是最后一天，跳过处理", [
                    'account_id' => $account->account,
                    'last_success_day' => $lastSuccessDay,
                    'plan_total_days' => $planTotalDays,
                    'current_plan_day' => $account->current_plan_day,
                    'reason' => '最后成功记录不是最后一天，不处理不发送消息'
                ]);
                return;
            }
        }

        if (!$lastSuccessLog) {
            $this->getLogger()->info("账号 {$account->account} 没有成功兑换记录，更新状态为PROCESSING");
            $account->timestamps = false;
            $account->update(['status' => 'processing']);
            $account->timestamps = true;

            // 状态变更为处理中时请求登录
            $this->requestAccountLogin($account);
            return;
        }

        // 更新completed_days字段
        $this->updateCompletedDays($account, $lastSuccessLog);

        // 检查账号总金额是否达到计划金额
        if ($this->isAccountCompleted($account)) {
            $this->markAccountCompleted($account);
            return;
        }

        // 检查是否为计划的最后一天
        $currentDay = $account->current_plan_day ?? 1;
        $isLastDay = $currentDay >= $account->plan->plan_days;

        if ($isLastDay) {
            // 最后一天需要检查是否达到总目标
            $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->orderBy('exchange_time', 'desc')
                ->first();

            $currentTotalAmount = $lastSuccessLog ? $lastSuccessLog->after_amount : 0;

            if ($currentTotalAmount >= $account->plan->total_amount) {
                // 已达到目标，标记为完成
                $this->getLogger()->info("账号 {$account->account} 最后一天已达到目标，标记为完成", [
                    'current_day' => $currentDay,
                    'plan_days' => $account->plan->plan_days,
                    'current_total_amount' => $currentTotalAmount,
                    'plan_total_amount' => $account->plan->total_amount,
                    'reason' => 'LOCKING状态最后一天达到目标'
                ]);
                $this->markAccountCompleted($account);
                return;
            } else {
                // 未达到目标，继续处理
                $remainingAmount = $account->plan->total_amount - $currentTotalAmount;
                $this->getLogger()->info("账号 {$account->account} 最后一天未达到目标，继续处理", [
                    'current_day' => $currentDay,
                    'plan_days' => $account->plan->plan_days,
                    'current_total_amount' => $currentTotalAmount,
                    'plan_total_amount' => $account->plan->total_amount,
                    'remaining_amount' => $remainingAmount,
                    'reason' => 'LOCKING状态最后一天继续执行'
                ]);
                // 继续执行后续的WAITING状态逻辑
            }
        }

        // 更改状态为等待（不更新时间戳）
        $account->timestamps = false;
        $account->update(['status' => ItunesTradeAccount::STATUS_WAITING]);
        $account->timestamps = true;

        // 锁定状态变更为等待状态时请求登出
        $this->requestAccountLogout($account, 'locking to waiting');

        $this->getLogger()->info('锁定账号状态变更为等待状态', [
            'account_id' => $account->account,
            'account' => $account->account,
            'status_changed' => 'LOCKING -> WAITING',
            'reason' => '处理完成，变更为等待状态'
        ]);
    }

    /**
     * 处理等待状态的账号
     */
    private function processWaitingAccount(ItunesTradeAccount $account): void
    {
        $this->getLogger()->info("正在处理等待状态账号: {$account->account}");

        // 1. 未绑定计划的账号，不处理，不发送消息
        if (!$account->plan) {
            $this->getLogger()->debug("账号 {$account->account} 未绑定计划，跳过处理", [
                'account_id' => $account->account,
                'status' => $account->status,
                'plan_id' => $account->plan_id,
                'reason' => '未绑定计划，不处理不发送消息'
            ]);
            return;
        }

        // 验证计划配置完整性
        if (!$this->validatePlanConfiguration($account->plan)) {
            $this->getLogger()->error("账号 {$account->account} 计划配置不完整，标记为完成", [
                'plan_id' => $account->plan->id,
                'reason' => '计划配置验证失败'
            ]);
            $this->markAccountCompleted($account);
            return;
        }

        // 获取最后成功日志
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        // 2. 如果有执行记录，检查最后一条成功记录的当日天数是否为最后一天
        if ($lastSuccessLog) {
            $lastSuccessDay = $lastSuccessLog->day;
            $planTotalDays = $account->plan->plan_days;
            
            // 如果最后一条成功记录的天数不是最后一天，不处理，不发送消息
            if ($lastSuccessDay < $planTotalDays) {
                $this->getLogger()->debug("账号 {$account->account} 最后成功记录不是最后一天，跳过处理", [
                    'account_id' => $account->account,
                    'last_success_day' => $lastSuccessDay,
                    'plan_total_days' => $planTotalDays,
                    'current_plan_day' => $account->current_plan_day,
                    'reason' => '最后成功记录不是最后一天，不处理不发送消息'
                ]);
                return;
            }
        }

        if (!$lastSuccessLog) {
            // 没有成功兑换记录的账号，设置为第1天处理状态
            $account->timestamps = false;
            $account->update([
                'status' => ItunesTradeAccount::STATUS_PROCESSING,
                'current_plan_day' => 1
            ]);
            $account->timestamps = true;

            // 状态变更为处理中时请求登录
            $this->requestAccountLogin($account);

            $this->getLogger()->info("账号 {$account->account} 没有成功兑换记录，设置为第1天处理状态", [
                'account_id' => $account->account,
                'old_status' => 'WAITING',
                'new_status' => 'PROCESSING',
                'current_plan_day' => 1,
                'reason' => '没有兑换记录，开始计划执行'
            ]);
            return;
        }

        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $now = now();

        // 计算时间间隔（分钟）
        $intervalMinutes = $lastExchangeTime->diffInMinutes($now);
        $requiredExchangeInterval = max(1, $account->plan->exchange_interval ?? 5); // 最少1分钟

        $this->getLogger()->info("账号 {$account->account} 时间检查: 间隔 {$intervalMinutes} 分钟，要求兑换间隔 {$requiredExchangeInterval} 分钟");

        // 检查是否满足兑换间隔时间
        if ($intervalMinutes < $requiredExchangeInterval) {
            $this->getLogger()->info("账号 {$account->account} 兑换间隔时间不足，保持等待状态");
            return;
        }

        // 兑换间隔已满足，检查天数间隔
        $intervalHours = $lastExchangeTime->diffInHours($now);
        $requiredDayInterval = max(1, $account->plan->day_interval ?? 24); // 最少1小时

        $this->getLogger()->info("账号 {$account->account} 天数检查: 间隔 {$intervalHours} 小时，要求天数间隔 {$requiredDayInterval} 小时");

        // 检查是否超过最大等待时间（防止无限等待）
        // 只有在以下情况才强制完成：
        // 1. 已经是最后一天，或者
        // 2. 已经达到总目标金额，或者
        // 3. 等待时间超过7天（极端情况）
        $maxWaitingHours = 24 * 7; // 最大等待7天
        $isLastDay = $currentDay >= $account->plan->plan_days;
        $hasReachedTarget = $this->isAccountCompleted($account);
        
        if ($intervalHours >= $maxWaitingHours && ($isLastDay || $hasReachedTarget)) {
            $this->getLogger()->warning("账号 {$account->account} 等待时间过长且满足完成条件，强制标记为完成", [
                'interval_hours' => $intervalHours,
                'max_waiting_hours' => $maxWaitingHours,
                'current_day' => $currentDay,
                'plan_days' => $account->plan->plan_days,
                'is_last_day' => $isLastDay,
                'has_reached_target' => $hasReachedTarget,
                'reason' => '超过最大等待时间限制且满足完成条件'
            ]);
            $this->markAccountCompleted($account);
            return;
        } elseif ($intervalHours >= $maxWaitingHours) {
            // 等待时间过长但不满足完成条件，重置为处理状态继续执行
            $this->getLogger()->warning("账号 {$account->account} 等待时间过长但未满足完成条件，重置为处理状态", [
                'interval_hours' => $intervalHours,
                'max_waiting_hours' => $maxWaitingHours,
                'current_day' => $currentDay,
                'plan_days' => $account->plan->plan_days,
                'reason' => '等待时间过长，重置继续执行'
            ]);
            
            // 重置为处理状态，继续执行计划
            $account->timestamps = false;
            $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
            $account->timestamps = true;
            
            // 请求登录
            $this->requestAccountLogin($account);
            return;
        }

        // 检查是否为计划的最后一天
        $isLastDay = $currentDay >= $account->plan->plan_days;

        if ($intervalHours >= $requiredDayInterval) {
            if ($isLastDay) {
                // 最后一天且天数间隔已超过，检查是否达到总目标
                if ($this->isAccountCompleted($account)) {
                    $this->getLogger()->info("账号 {$account->account} 最后一天已达到总目标，标记为完成", [
                        'current_day' => $currentDay,
                        'plan_days' => $account->plan->plan_days,
                        'interval_hours' => $intervalHours,
                        'required_day_interval' => $requiredDayInterval,
                        'reason' => '最后一天达到总目标'
                    ]);
                    $this->markAccountCompleted($account);
                } else {
                    // 最后一天但未达到总目标，检查是否超过48小时
                    if ($intervalHours >= 48) {
                        // 超过48小时，解绑计划让账号可以重新绑定其他计划
                        $this->getLogger()->info("账号 {$account->account} 最后一天超过48小时未达到总目标，解绑计划", [
                            'current_day' => $currentDay,
                            'plan_days' => $account->plan->plan_days,
                            'interval_hours' => $intervalHours,
                            'current_total_amount' => $this->getCurrentTotalAmount($account),
                            'plan_total_amount' => $account->plan->total_amount,
                            'reason' => '最后一天超时解绑，可重新绑定其他计划'
                        ]);
                        $this->unbindAccountPlan($account);
                    } else {
                        // 未超过48小时，继续处理
                        $this->getLogger()->info("账号 {$account->account} 最后一天未达到总目标，继续处理", [
                            'current_day' => $currentDay,
                            'plan_days' => $account->plan->plan_days,
                            'interval_hours' => $intervalHours,
                            'reason' => '最后一天继续执行直到达到目标或超过48小时'
                        ]);
                        $this->checkDailyPlanCompletion($account);
                    }
                }
            } else {
                // 不是最后一天，进入下一天
                $this->advanceToNextDay($account);
            }
        } else {
            // 天数间隔未超过，检查每日计划完成情况
            $this->checkDailyPlanCompletion($account);
        }
    }

    /**
     * 请求账号登录
     */
    private function requestAccountLogin(ItunesTradeAccount $account): void
    {
        // 如果已经登录则跳过
        if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_ACTIVE) {
            $this->getLogger()->info("账号 {$account->account} 已经登录，跳过登录请求");
            return;
        }

        try {
            $loginData = [[
                'id' => $account->id,
                'username' => $account->account,
                'password' => $account->getDecryptedPassword(),
                'VerifyUrl' => $account->api_url ?? ''
            ]];

            $response = $this->giftCardApiClient->createLoginTask($loginData);

            if ($response['code'] === 0) {
                $this->getLogger()->info("成功为账号 {$account->account} 创建登录任务", [
                    'task_id' => $response['data']['task_id'] ?? null
                ]);
            } else {
                $this->getLogger()->error("为账号 {$account->account} 创建登录任务失败: " . ($response['msg'] ?? '未知错误'));
            }

        } catch (\Exception $e) {
            $this->getLogger()->error("账号 {$account->account} 请求登录失败: " . $e->getMessage());
        }
    }

    /**
     * 请求账号登出
     */
    private function requestAccountLogout(ItunesTradeAccount $account, string $reason = ''): void
    {
        // 如果已经登出则跳过
        if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_INVALID) {
            $this->getLogger()->info("账号 {$account->account} 已经登出，跳过登出请求");
            return;
        }

        try {
            $logoutData = [[
                'username' => $account->account
            ]];

            $response = $this->giftCardApiClient->deleteUserLogins($logoutData);

            if ($response['code'] === 0) {
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID
                ]);

                $this->getLogger()->info("账号 {$account->account} 登出成功" . ($reason ? " ({$reason})" : ''));
            } else {
                $this->getLogger()->error("账号 {$account->account} 登出失败: " . ($response['msg'] ?? '未知错误'));
            }

        } catch (\Exception $e) {
            $this->getLogger()->error("账号 {$account->account} 请求登出失败: " . $e->getMessage());
        }
    }

    /**
     * 验证计划配置完整性
     */
    private function validatePlanConfiguration($plan): bool
    {
        // 检查基本配置
        if (empty($plan->plan_days) || $plan->plan_days <= 0) {
            $this->getLogger()->error("计划配置错误: 无效的计划天数", [
                'plan_id' => $plan->id,
                'plan_days' => $plan->plan_days
            ]);
            return false;
        }

        if (empty($plan->total_amount) || $plan->total_amount <= 0) {
            $this->getLogger()->error("计划配置错误: 无效的总金额", [
                'plan_id' => $plan->id,
                'total_amount' => $plan->total_amount
            ]);
            return false;
        }

        // 检查每日金额配置
        $dailyAmounts = $plan->daily_amounts ?? [];
        if (empty($dailyAmounts) || !is_array($dailyAmounts)) {
            $this->getLogger()->error("计划配置错误: 无效的每日金额", [
                'plan_id' => $plan->id,
                'daily_amounts' => $dailyAmounts
            ]);
            return false;
        }

        if (count($dailyAmounts) != $plan->plan_days) {
            $this->getLogger()->error("计划配置错误: 每日金额数量与计划天数不匹配", [
                'plan_id' => $plan->id,
                'daily_amounts_count' => count($dailyAmounts),
                'plan_days' => $plan->plan_days
            ]);
            return false;
        }

        return true;
    }

    /**
     * 更新completed_days字段
     */
    private function updateCompletedDays(ItunesTradeAccount $account, ItunesTradeAccountLog $lastSuccessLog): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $plan = $account->plan;

        if (!$plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，无法更新completed_days");
            return;
        }

        // 获取现有的completed_days数据
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];

        // 根据计划天数更新每天的数据
        for ($day = 1; $day <= $plan->plan_days; $day++) {
            // 计算该天的累计兑换金额
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $day)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 更新该天的数据
            $completedDays[(string)$day] = $dailyAmount;
        }

        // 保存更新的completed_days（不更新时间戳）
        $account->timestamps = false;
        $account->update(['completed_days' => json_encode($completedDays)]);
        $account->timestamps = true;

        $this->getLogger()->info("账号 {$account->account} 所有天数数据已更新", [
            'plan_days' => $plan->plan_days,
            'current_day' => $currentDay,
            'completed_days' => $completedDays
        ]);
    }

    /**
     * 检查账号是否已完成
     */
    private function isAccountCompleted(ItunesTradeAccount $account): bool
    {
        if (!$account->plan) {
            return false;
        }

        // 获取最后一条成功兑换记录的after_amount（兑换后总金额）
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        $currentTotalAmount = $lastSuccessLog ? $lastSuccessLog->after_amount : 0;

        $this->getLogger()->info("账号 {$account->account} 完成检查", [
            'current_total_amount' => $currentTotalAmount,
            'plan_total_amount' => $account->plan->total_amount,
            'account_amount' => $account->amount,
            'is_completed' => $currentTotalAmount >= $account->plan->total_amount
        ]);

        return $currentTotalAmount >= $account->plan->total_amount;
    }

    /**
     * 标记账号为已完成
     */
    private function markAccountCompleted(ItunesTradeAccount $account): void
    {
        if (!$account->plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，无法标记为完成");
            return;
        }

        // 获取现有的completed_days数据
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];

        // 根据计划天数更新每天的数据
        for ($day = 1; $day <= $account->plan->plan_days; $day++) {
            // 计算该天的累计兑换金额
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $day)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 更新该天的数据
            $completedDays[(string)$day] = $dailyAmount;
        }

        // 获取最后一条成功兑换记录的after_amount（当前总金额）
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        $currentTotalAmount = $lastSuccessLog ? $lastSuccessLog->after_amount : 0;

        // 标记为完成状态（不更新时间戳）
        $account->timestamps = false;
        $account->update([
            'status' => ItunesTradeAccount::STATUS_COMPLETED,
            'current_plan_day' => null,
            'plan_id' => null,
            'completed_days' => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        $this->getLogger()->info('账号计划完成', [
            'account_id' => $account->account,
            'account' => $account->account,
            'current_total_amount' => $currentTotalAmount,
            'account_amount' => $account->amount,
            'plan_total_amount' => $account->plan->total_amount ?? 0,
            'plan_days' => $account->plan->plan_days,
            'final_completed_days' => $completedDays
        ]);

        // 为已完成的账号请求登出
        $this->requestAccountLogout($account, 'plan completed');

        // 发送完成通知
        $msg = "[强]兑换目标达成通知\n";
        $msg .= "---------------\n";
        $msg .= $account->account."[".$currentTotalAmount."]";

       send_msg_to_wechat('44769140035@chatroom', $msg);
    }

    /**
     * 进入下一天
     */
    private function advanceToNextDay(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $nextDay = $currentDay + 1;

        if (!$account->plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，无法进入下一天");
            return;
        }

        // 检查是否已达到或超过计划的最后一天
        if ($currentDay >= $account->plan->plan_days) {
            $this->getLogger()->warning("账号 {$account->account} 已达到或超过计划最后一天，标记为完成", [
                'current_day' => $currentDay,
                'plan_days' => $account->plan->plan_days,
                'reason' => '已达到计划天数限制'
            ]);
            $this->markAccountCompleted($account);
            return;
        }

        // 获取现有的completed_days数据
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];

        // 根据计划天数更新每天的数据
        for ($day = 1; $day <= $account->plan->plan_days; $day++) {
            // 计算该天的累计兑换金额
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $day)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');

            // 更新该天的数据
            $completedDays[(string)$day] = $dailyAmount;
        }

        // 进入下一天（不更新时间戳）
        $account->timestamps = false;
        $account->update([
            'current_plan_day' => $nextDay,
            'status' => ItunesTradeAccount::STATUS_PROCESSING,
            'completed_days' => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        // 状态变更为处理中时请求登录
        $this->requestAccountLogin($account);

        $this->getLogger()->info('账号进入下一天', [
            'account_id' => $account->account,
            'account' => $account->account,
            'current_day' => $nextDay,
            'plan_days' => $account->plan->plan_days,
            'status_changed' => 'WAITING -> PROCESSING',
            'reason' => '天数间隔已超过，进入下一天',
            'completed_days' => $completedDays
        ]);
    }

    /**
     * 获取账号当前总金额
     */
    private function getCurrentTotalAmount(ItunesTradeAccount $account): float
    {
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        return $lastSuccessLog ? $lastSuccessLog->after_amount : 0;
    }

    /**
     * 解绑账号计划
     */
    private function unbindAccountPlan(ItunesTradeAccount $account): void
    {
        // 获取现有的completed_days数据
        $completedDays = json_decode($account->completed_days ?? '{}', true) ?: [];

        // 根据计划天数更新每天的数据
        if ($account->plan) {
            for ($day = 1; $day <= $account->plan->plan_days; $day++) {
                // 计算该天的累计兑换金额
                $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                    ->where('day', $day)
                    ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                    ->sum('amount');

                // 更新该天的数据
                $completedDays[(string)$day] = $dailyAmount;
            }
        }

        // 解绑计划并设置为等待状态（不更新时间戳）
        $account->timestamps = false;
        $account->update([
            'plan_id' => null,
            'current_plan_day' => null,
            'status' => ItunesTradeAccount::STATUS_WAITING,
            'completed_days' => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        // 请求登出账号
        $this->requestAccountLogout($account, 'plan unbound');

        $this->getLogger()->info('账号计划解绑完成', [
            'account_id' => $account->account,
            'account' => $account->account,
            'old_status' => 'WAITING',
            'new_status' => 'WAITING',
            'plan_id_cleared' => true,
            'current_plan_day_cleared' => true,
            'reason' => '最后一天超时未完成，解绑计划以便重新绑定',
            'final_completed_days' => $completedDays
        ]);
    }

    /**
     * 检查每日计划完成情况
     */
    private function checkDailyPlanCompletion(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $plan = $account->plan;

        // 计算当前天的累计兑换金额
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 获取当前天的计划金额
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;

        $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天计划检查: 已兑换 {$dailyAmount}，目标 {$dailyLimit}");

        // 处理配置异常：如果每日目标为0或负数，视为当天完成
        if ($dailyLimit <= 0) {
            $this->getLogger()->warning("账号 {$account->account} 第{$currentDay}天目标金额配置异常 ({$dailyLimit})，视为当天完成", [
                'current_day' => $currentDay,
                'daily_limit' => $dailyLimit,
                'plan_id' => $plan->id
            ]);

            // 检查是否为最后一天
            if ($currentDay >= $plan->plan_days) {
                $this->getLogger()->info("账号 {$account->account} 最后一天配置异常但视为完成，标记账号为完成");
                $this->markAccountCompleted($account);
            } else {
                // 检查总金额是否达到目标
                if ($this->isAccountCompleted($account)) {
                    $this->getLogger()->info("账号 {$account->account} 总金额已达到目标，标记为完成");
                    $this->markAccountCompleted($account);
                } else {
                    $this->getLogger()->info("账号 {$account->account} 每日配置异常，保持等待状态");
                }
            }
            return;
        }

        if ($dailyAmount >= $dailyLimit) {
            // 每日计划完成，检查是否为最后一天
            if ($currentDay >= $plan->plan_days) {
                // 最后一天计划完成，标记账号为完成
                $this->getLogger()->info("账号 {$account->account} 最后一天计划完成，标记为完成", [
                    'current_day' => $currentDay,
                    'plan_days' => $plan->plan_days,
                    'daily_amount' => $dailyAmount,
                    'daily_limit' => $dailyLimit,
                    'reason' => '最后一天计划完成'
                ]);
                $this->markAccountCompleted($account);
            } else {
                // 不是最后一天，检查总金额是否达到目标
                if ($this->isAccountCompleted($account)) {
                    $this->getLogger()->info("账号 {$account->account} 总金额已达到目标，标记为完成", [
                        'current_day' => $currentDay,
                        'total_amount' => $account->amount,
                        'plan_total_amount' => $plan->total_amount,
                        'reason' => '总金额目标已达到'
                    ]);
                    $this->markAccountCompleted($account);
                } else {
                    $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天计划完成，等待下一天", [
                        'current_day' => $currentDay,
                        'plan_days' => $plan->plan_days,
                        'daily_amount' => $dailyAmount,
                        'daily_limit' => $dailyLimit,
                        'status' => '保持等待状态直到满足天数间隔'
                    ]);
                }
            }
        } else {
            // 计划未完成，检查账号是否有足够余额继续兑换
            $remainingDaily = $dailyLimit - $dailyAmount;

            $this->getLogger()->info("账号 {$account->account} 每日计划未完成检查", [
                'current_day' => $currentDay,
                'daily_amount' => $dailyAmount,
                'daily_limit' => $dailyLimit,
                'remaining_daily' => $remainingDaily
            ]);

            // 余额充足，更改状态为处理中（不更新时间戳）
            $account->timestamps = false;
            $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
            $account->timestamps = true;

            // 状态变更为处理中时请求登录
            $this->requestAccountLogin($account);

            $this->getLogger()->info('等待账号状态变更为处理中', [
                'account_id' => $account->account,
                'account' => $account->account,
                'current_day' => $currentDay,
                'status_changed' => 'WAITING -> PROCESSING',
                'reason' => '每日计划未完成，变更为处理状态'
            ]);
        }
    }
}
