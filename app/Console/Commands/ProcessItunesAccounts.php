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
    protected $signature = 'itunes:process-accounts {--logout-only : 仅执行登出操作} {--login-only : 仅执行登录操作} {--fix-task= : 通过任务ID修复账号数据} {--login-account= : 仅登录指定账号}';

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
        $date         = now();
        $logoutOnly   = $this->option('logout-only');
        $loginOnly    = $this->option('login-only');
        $fixTask      = $this->option('fix-task');
        $loginAccount = $this->option('login-account');

        $this->getLogger()->info("==================================[{$date}]===============================");

        if ($logoutOnly) {
            $this->getLogger()->info("开始执行登出操作...");
        } elseif ($loginOnly) {
            $this->getLogger()->info("开始执行登录操作...");
        } elseif ($fixTask) {
            $this->getLogger()->info("开始执行修复任务，任务ID: {$fixTask}");
        } elseif ($loginAccount) {
            $this->getLogger()->info("开始执行指定账号登录操作，账号: {$loginAccount}");
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

            if ($loginAccount) {
                // 仅登录指定账号
                $this->executeLoginSpecificAccount($loginAccount);
                $this->getLogger()->info('指定账号登录操作完成');
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
        $accounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_COMPLETED)
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
     * 仅登录指定账号
     */
    private function executeLoginSpecificAccount(string $accountEmail): void
    {
        $this->getLogger()->info("开始登录指定账号: {$accountEmail}");

        // 查找指定账号
        $account = ItunesTradeAccount::where('account', $accountEmail)->first();

        if (!$account) {
            $this->getLogger()->error("未找到账号: {$accountEmail}");
            return;
        }

        $this->getLogger()->info("找到指定账号", [
            'account_id'           => $account->id,
            'account_email'        => $account->account,
            'current_status'       => $account->status,
            'current_login_status' => $account->login_status,
            'amount'               => $account->amount,
            'country_code'         => $account->country_code
        ]);

        // 检查账号是否已经登录
        if ($account->login_status === ItunesTradeAccount::STATUS_LOGIN_ACTIVE) {
            $this->getLogger()->info("账号 {$accountEmail} 已经登录，无需重复登录");
            return;
        }

        // 创建单个账号的登录任务
        $loginData = [[
                          'id'        => $account->id,
                          'username'  => $account->account,
                          'password'  => $account->getDecryptedPassword(),
                          'VerifyUrl' => $account->api_url ?? ''
                      ]];

        try {
            $this->getLogger()->info("为指定账号创建登录任务", [
                'account'      => $accountEmail,
                'has_password' => !empty($account->getDecryptedPassword()),
                'has_api_url'  => !empty($account->api_url)
            ]);

            $response = $this->giftCardApiClient->createLoginTask($loginData);

            $this->getLogger()->info("指定账号登录API响应", [
                'account'       => $accountEmail,
                'response_code' => $response['code'] ?? 'unknown',
                'response_msg'  => $response['msg'] ?? 'no message',
                'response_data' => $response['data'] ?? null
            ]);

            if ($response['code'] !== 0) {
                $this->getLogger()->error("指定账号登录任务创建失败", [
                    'account'    => $accountEmail,
                    'error_code' => $response['code'] ?? 'unknown',
                    'error_msg'  => $response['msg'] ?? '未知错误'
                ]);
                return;
            }

            $taskId = $response['data']['task_id'] ?? null;
            if (!$taskId) {
                $this->getLogger()->error("指定账号登录任务创建失败: 未收到任务ID", [
                    'account'  => $accountEmail,
                    'response' => $response
                ]);
                return;
            }

            $this->getLogger()->info("指定账号登录任务创建成功", [
                'account' => $accountEmail,
                'task_id' => $taskId
            ]);

            // 等待登录任务完成
            $this->waitForSpecificAccountLoginCompletion($taskId, $account);

        } catch (\Exception $e) {
            $this->getLogger()->error("指定账号登录异常: " . $e->getMessage(), [
                'account'        => $accountEmail,
                'exception_type' => get_class($e),
                'trace'          => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 等待指定账号登录任务完成
     */
    private function waitForSpecificAccountLoginCompletion(string $taskId, ItunesTradeAccount $account): void
    {
        $maxAttempts  = 60; // 最多等待5分钟（60 * 5秒）
        $sleepSeconds = 5;

        $this->getLogger()->info("等待指定账号登录任务完成", [
            'account'       => $account->account,
            'task_id'       => $taskId,
            'max_wait_time' => $maxAttempts * $sleepSeconds . 's'
        ]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $statusResponse = $this->giftCardApiClient->getLoginTaskStatus($taskId);

                $this->getLogger()->info("指定账号登录任务状态查询（第{$attempt}次）", [
                    'account'       => $account->account,
                    'task_id'       => $taskId,
                    'attempt'       => $attempt,
                    'response_code' => $statusResponse['code'] ?? 'unknown'
                ]);

                if ($statusResponse['code'] !== 0) {
                    $this->getLogger()->error("查询指定账号登录任务状态失败", [
                        'account'   => $account->account,
                        'task_id'   => $taskId,
                        'error_msg' => $statusResponse['msg'] ?? '未知错误'
                    ]);
                    break;
                }

                $taskStatus = $statusResponse['data']['status'] ?? '';
                $items      = $statusResponse['data']['items'] ?? [];

                $this->getLogger()->info("指定账号登录任务进度", [
                    'account'     => $account->account,
                    'task_id'     => $taskId,
                    'task_status' => $taskStatus,
                    'items_count' => count($items)
                ]);

                // 查找对应账号的结果
                foreach ($items as $item) {
                    if ($item['data_id'] === $account->account) {
                        $this->processSpecificAccountLoginResult($item, $account);

                        if ($taskStatus === 'completed') {
                            $this->getLogger()->info("指定账号登录任务完成", [
                                'account'        => $account->account,
                                'task_id'        => $taskId,
                                'total_attempts' => $attempt
                            ]);
                            return;
                        }
                        break;
                    }
                }

                if ($taskStatus === 'completed') {
                    $this->getLogger()->info("指定账号登录任务完成", [
                        'account'        => $account->account,
                        'task_id'        => $taskId,
                        'total_attempts' => $attempt
                    ]);
                    break;
                }

                if ($attempt < $maxAttempts) {
                    sleep($sleepSeconds);
                }

            } catch (\Exception $e) {
                $this->getLogger()->error("指定账号登录任务状态查询异常: " . $e->getMessage(), [
                    'account' => $account->account,
                    'task_id' => $taskId,
                    'attempt' => $attempt
                ]);
                break;
            }
        }

        if ($attempt > $maxAttempts) {
            $this->getLogger()->warning("指定账号登录任务等待超时", [
                'account'         => $account->account,
                'task_id'         => $taskId,
                'total_wait_time' => $maxAttempts * $sleepSeconds . 's'
            ]);
        }
    }

    /**
     * 处理指定账号登录结果
     */
    private function processSpecificAccountLoginResult(array $item, ItunesTradeAccount $account): void
    {
        $status = $item['status'] ?? '';
        $msg    = $item['msg'] ?? '';
        $result = $item['result'] ?? '';

        $this->getLogger()->info("处理指定账号登录结果", [
            'account'         => $account->account,
            'task_status'     => $status,
            'task_msg'        => $msg,
            'has_result_data' => !empty($result)
        ]);

        if ($status === 'completed') {
            if (strpos($msg, 'login successful') !== false || strpos($msg, '登录成功') !== false) {
                // 登录成功，更新登录状态
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_ACTIVE
                ]);

                $this->getLogger()->info("指定账号登录成功", [
                    'account'     => $account->account,
                    'success_msg' => $msg
                ]);

                // 解析并更新余额信息
                if ($result) {
                    try {
                        $resultData = json_decode($result, true);
                        if (isset($resultData['balance']) && !empty($resultData['balance'])) {
                            $balanceString = $resultData['balance'];
                            $balance       = (float)preg_replace('/[^\d.-]/', '', $balanceString);
                            $oldBalance    = $account->amount;
                            $account->update(['amount' => $balance]);

                            $this->getLogger()->info("指定账号余额更新", [
                                'account'        => $account->account,
                                'old_balance'    => $oldBalance,
                                'new_balance'    => $balance,
                                'balance_string' => $balanceString
                            ]);
                        }

                        if (isset($resultData['countryCode'])) {
                            $this->getLogger()->info("指定账号国家信息", [
                                'account'      => $account->account,
                                'country_code' => $resultData['countryCode'],
                                'country_name' => $resultData['country'] ?? 'unknown'
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->getLogger()->warning("解析指定账号登录结果失败: " . $e->getMessage(), [
                            'account'    => $account->account,
                            'raw_result' => $result
                        ]);
                    }
                }
            } else {
                // 登录失败
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID
                ]);

                $this->getLogger()->warning("指定账号登录失败", [
                    'account'     => $account->account,
                    'failure_msg' => $msg,
                    'result'      => $result
                ]);
            }
        } else {
            $this->getLogger()->info("指定账号登录任务进行中", [
                'account'        => $account->account,
                'current_status' => $status,
                'current_msg'    => $msg
            ]);
        }
    }

    /**
     * 仅执行登录操作
     */
    private function executeLoginOnly(): void
    {
        // 查找符合条件的账号：status=processing, login_status=invalid, amount>0
        $accounts = ItunesTradeAccount::whereIn('status', [ItunesTradeAccount::STATUS_PROCESSING, ItunesTradeAccount::STATUS_WAITING])
//            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_INVALID)
            ->where('amount', '>=', 0)
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
            $items      = $statusResponse['data']['items'] ?? [];

            $this->getLogger()->info("任务状态: {$taskStatus}，找到 {" . count($items) . "} 个项目");

            if (empty($items)) {
                $this->getLogger()->warning("任务响应中未找到任何项目");
                return;
            }

            // 处理任务结果中的每个账号
            $processedCount = 0;
            $successCount   = 0;
            $failedCount    = 0;

            foreach ($items as $item) {
                $this->processFixTaskItem($item, $processedCount, $successCount, $failedCount);
            }

            $this->getLogger()->info("修复任务完成", [
                'task_id'         => $taskId,
                'processed_count' => $processedCount,
                'success_count'   => $successCount,
                'failed_count'    => $failedCount
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
        $status   = $item['status'] ?? '';
        $msg      = $item['msg'] ?? '';
        $result   = $item['result'] ?? '';

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
        // 获取当前零余额且登录有效的账号
        $currentZeroAmountAccounts = ItunesTradeAccount::where('amount', 0)
            ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->orderBy('created_at', 'desc')
            ->get();

        $currentZeroAmountCount = $currentZeroAmountAccounts->count();

        $this->getLogger()->info("📊 维护零余额账号 - 当前状态统计", [
            'current_count' => $currentZeroAmountCount,
            'target_count'  => self::TARGET_ZERO_AMOUNT_ACCOUNTS,
            'account_list'  => $currentZeroAmountAccounts->pluck('account')->toArray()
        ]);

        // 显示当前零余额账号明细
        if ($currentZeroAmountCount > 0) {
            $this->getLogger()->info("✅ 当前零余额登录账号明细 ({$currentZeroAmountCount}个)");
            foreach ($currentZeroAmountAccounts as $index => $account) {
                $this->getLogger()->info("   " . ($index + 1) . ". {$account->account} (ID: {$account->id}, 国家: {$account->country_code})");
            }
        } else {
            $this->getLogger()->warning("⚠️  当前没有零余额且登录有效的账号");
        }

        if ($currentZeroAmountCount >= self::TARGET_ZERO_AMOUNT_ACCOUNTS) {
            $this->getLogger()->info("🎯 目标零余额账号数量已达到 (" . self::TARGET_ZERO_AMOUNT_ACCOUNTS . ")，无需补充");
            return;
        }

        $needCount = self::TARGET_ZERO_AMOUNT_ACCOUNTS - $currentZeroAmountCount;
        $this->getLogger()->info("💰 需要补充 {$needCount} 个零余额登录账号");

        // 查找状态为processing且登录状态为invalid的零余额账号进行登录
        $candidateAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_INVALID)
            ->where('amount', 0)
            ->orderBy('created_at', 'asc') // 先导入的优先
            ->limit($needCount * 2)        // 获取更多以防登录失败
            ->get();

        if ($candidateAccounts->isEmpty()) {
            $this->getLogger()->warning("❌ 未找到可用于登录的候选账号", [
                'search_criteria' => [
                    'status'       => 'PROCESSING',
                    'login_status' => 'INVALID',
                    'amount'       => 0
                ],
                'suggestion'      => '可能需要导入更多零余额账号或检查现有账号状态'
            ]);
            return;
        }

        $this->getLogger()->info("🔍 找到候选登录账号", [
            'candidate_count'    => $candidateAccounts->count(),
            'target_login_count' => $needCount,
            'account_list'       => $candidateAccounts->pluck('account')->toArray()
        ]);

        // 显示候选账号明细
        $this->getLogger()->info("📋 候选登录账号明细 ({$candidateAccounts->count()}个)：");
        foreach ($candidateAccounts as $index => $account) {
            $createdDays = now()->diffInDays($account->created_at);
            $this->getLogger()->info("   " . ($index + 1) . ". {$account->account} (ID: {$account->id}, 国家: {$account->country_code}, 导入: {$createdDays}天前)");
        }

        // 批量登录账号
        $this->getLogger()->info("🚀 开始为候选账号创建登录任务...");
        $this->batchLoginAccounts($candidateAccounts, $needCount);
    }

    /**
     * 处理账号状态转换
     */
    private function processAccountStatusTransitions(): void
    {
        // 获取需要处理的账号（LOCKING和WAITING状态）
        $accounts = ItunesTradeAccount::whereIn('status', [
            ItunesTradeAccount::STATUS_LOCKING,
            ItunesTradeAccount::STATUS_WAITING
        ])
            ->with('plan')
            ->get();

        // 获取需要检查的PROCESSING状态账号（可能已完成当日计划需要转为WAITING）
        $processingAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->whereNotNull('plan_id')
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

        $this->getLogger()->info("找到 {$accounts->count()} 个LOCKING/WAITING账号，{$processingAccounts->count()} 个PROCESSING账号，{$orphanedAccounts->count()} 个孤立账号，{$completedAccounts->count()} 个需要登出的已完成账号");

        // 处理已完成账号的登出
        if ($completedAccounts->isNotEmpty()) {
            $this->batchLogoutAccounts($completedAccounts, '已完成状态登出');
        }

        // 处理PROCESSING状态的账号
        foreach ($processingAccounts as $account) {
            try {
                $this->processProcessingAccount($account);
            } catch (\Exception $e) {
                $this->getLogger()->error("处理PROCESSING账号 {$account->account} 失败: " . $e->getMessage());
            }
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
            $this->getLogger()->info("📋 批量登录：无账号需要处理");
            return;
        }

        $this->getLogger()->info("🚀 开始批量登录账号", [
            'total_accounts'       => $accounts->count(),
            'target_success_count' => $targetCount,
            'account_list'         => $accounts->pluck('account')->toArray()
        ]);

        // 准备登录数据
        $loginData = [];
        foreach ($accounts as $account) {
            $loginData[] = [
                'id'        => $account->id,
                'username'  => $account->account,
                'password'  => $account->getDecryptedPassword(),
                'VerifyUrl' => $account->api_url ?? ''
            ];

            $this->getLogger()->debug("📝 准备账号登录数据", [
                'account_id'           => $account->id,
                'account'              => $account->account,
                'has_password'         => !empty($account->getDecryptedPassword()),
                'has_api_url'          => !empty($account->api_url),
                'current_status'       => $account->status,
                'current_login_status' => $account->login_status,
                'amount'               => $account->amount
            ]);
        }

        try {
            $this->getLogger()->info("📡 发起批量登录API请求", [
                'accounts_count' => count($loginData),
                'target_count'   => $targetCount
            ]);

            // 创建登录任务
            $response = $this->giftCardApiClient->createLoginTask($loginData);

            // 详细记录API响应
            $this->getLogger()->info("📊 批量登录API响应", [
                'response_code'  => $response['code'] ?? 'unknown',
                'response_msg'   => $response['msg'] ?? 'no message',
                'response_data'  => $response['data'] ?? null,
                'accounts_count' => count($loginData),
                'full_response'  => $response
            ]);

            if ($response['code'] !== 0) {
                $this->getLogger()->error("❌ 创建批量登录任务失败", [
                    'error_code'        => $response['code'] ?? 'unknown',
                    'error_msg'         => $response['msg'] ?? '未知错误',
                    'accounts_affected' => $accounts->pluck('account')->toArray()
                ]);
                return;
            }

            $taskId = $response['data']['task_id'] ?? null;
            if (!$taskId) {
                $this->getLogger()->error("❌ 创建批量登录任务失败: 未收到任务ID", [
                    'response'          => $response,
                    'accounts_affected' => $accounts->pluck('account')->toArray()
                ]);
                return;
            }

            $this->getLogger()->info("✅ 批量登录任务创建成功", [
                'task_id'              => $taskId,
                'accounts_count'       => $accounts->count(),
                'target_success_count' => $targetCount,
                'next_step'            => '等待任务完成并处理结果'
            ]);

            // 等待登录任务完成并更新账号状态
            $this->waitForLoginTaskCompletion($taskId, $accounts, $targetCount);

        } catch (\Exception $e) {
            $this->getLogger()->error("❌ 批量登录账号异常: " . $e->getMessage(), [
                'accounts_count'    => $accounts->count(),
                'target_count'      => $targetCount,
                'accounts_affected' => $accounts->pluck('account')->toArray(),
                'exception_type'    => get_class($e),
                'trace'             => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 等待登录任务完成
     */
    private function waitForLoginTaskCompletion(string $taskId, $accounts, int $targetCount): void
    {
        $maxAttempts  = 60; // 最多等待5分钟（60 * 5秒）
        $sleepSeconds = 5;
        $successCount = 0;
        $failedCount  = 0;
        $pendingCount = 0;

        $this->getLogger()->info("🕐 开始等待批量登录任务完成", [
            'task_id'              => $taskId,
            'target_accounts'      => $accounts->count(),
            'target_success_count' => $targetCount,
            'max_wait_time'        => $maxAttempts * $sleepSeconds . 's',
            'check_interval'       => $sleepSeconds . 's'
        ]);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $statusResponse = $this->giftCardApiClient->getLoginTaskStatus($taskId);

                $this->getLogger()->info("📊 批量登录任务状态查询（第{$attempt}次）", [
                    'task_id'       => $taskId,
                    'attempt'       => $attempt,
                    'max_attempts'  => $maxAttempts,
                    'response_code' => $statusResponse['code'] ?? 'unknown',
                    'response_msg'  => $statusResponse['msg'] ?? 'no message',
                    'elapsed_time'  => ($attempt - 1) * $sleepSeconds . 's'
                ]);

                if ($statusResponse['code'] !== 0) {
                    $this->getLogger()->error("❌ 查询批量登录任务状态失败", [
                        'task_id'   => $taskId,
                        'error_msg' => $statusResponse['msg'] ?? '未知错误',
                        'attempt'   => $attempt
                    ]);
                    break;
                }

                $taskStatus = $statusResponse['data']['status'] ?? '';
                $items      = $statusResponse['data']['items'] ?? [];

                $this->getLogger()->info("📈 批量登录任务进度", [
                    'task_id'               => $taskId,
                    'task_status'           => $taskStatus,
                    'total_items'           => count($items),
                    'current_success_count' => $successCount,
                    'current_failed_count'  => $failedCount,
                    'target_count'          => $targetCount,
                    'attempt'               => $attempt
                ]);

                // 重置计数器，重新统计
                $tempSuccessCount = 0;
                $tempFailedCount  = 0;
                $tempPendingCount = 0;

                // 处理每个账号的登录结果
                foreach ($items as $item) {
                    $itemStatus = $item['status'] ?? '';
                    $itemMsg    = $item['msg'] ?? '';

                    if ($itemStatus === 'completed') {
                        $this->processLoginResult($item, $accounts);

                        // 统计成功和失败
                        if (strpos($itemMsg, 'login successful') !== false || strpos($itemMsg, '登录成功') !== false) {
                            $tempSuccessCount++;
                        } else {
                            $tempFailedCount++;
                        }
                    } else {
                        $tempPendingCount++;
                    }
                }

                $successCount = $tempSuccessCount;
                $failedCount  = $tempFailedCount;
                $pendingCount = $tempPendingCount;

                $this->getLogger()->info("📊 批量登录统计更新", [
                    'task_id'         => $taskId,
                    'success_count'   => $successCount,
                    'failed_count'    => $failedCount,
                    'pending_count'   => $pendingCount,
                    'total_processed' => $successCount + $failedCount,
                    'target_reached'  => $successCount >= $targetCount
                ]);

                // 如果任务完成或达到目标数量则退出循环
                if ($taskStatus === 'completed' || $successCount >= $targetCount) {
                    $this->getLogger()->info("✅ 批量登录任务完成", [
                        'task_id'         => $taskId,
                        'final_status'    => $taskStatus,
                        'success_count'   => $successCount,
                        'failed_count'    => $failedCount,
                        'total_attempts'  => $attempt,
                        'total_wait_time' => ($attempt - 1) * $sleepSeconds . 's',
                        'target_achieved' => $successCount >= $targetCount
                    ]);
                    break;
                }

                if ($attempt < $maxAttempts) {
                    $this->getLogger()->debug("⏳ 等待下次检查", [
                        'task_id'            => $taskId,
                        'next_check_in'      => $sleepSeconds . 's',
                        'remaining_attempts' => $maxAttempts - $attempt
                    ]);
                    sleep($sleepSeconds);
                }

            } catch (\Exception $e) {
                $this->getLogger()->error("❌ 批量登录任务状态查询异常: " . $e->getMessage(), [
                    'task_id'        => $taskId,
                    'attempt'        => $attempt,
                    'exception_type' => get_class($e),
                    'trace'          => $e->getTraceAsString()
                ]);
                break;
            }
        }

        if ($attempt > $maxAttempts) {
            $this->getLogger()->warning("⏰ 批量登录任务等待超时", [
                'task_id'             => $taskId,
                'final_success_count' => $successCount,
                'final_failed_count'  => $failedCount,
                'final_pending_count' => $pendingCount,
                'target_count'        => $targetCount,
                'total_wait_time'     => $maxAttempts * $sleepSeconds . 's',
                'note'                => '部分任务可能仍在进行中'
            ]);
        }
    }

    /**
     * 处理单个账号登录结果
     */
    private function processLoginResult(array $item, $accounts): void
    {
        $username = $item['data_id'] ?? '';
        $status   = $item['status'] ?? '';
        $msg      = $item['msg'] ?? '';
        $result   = $item['result'] ?? '';

        // 查找对应的账号
        $account = $accounts->firstWhere('account', $username);
        if (!$account) {
            $this->getLogger()->warning("未找到用户名对应的账号: {$username}");
            return;
        }

        $this->getLogger()->info("📋 处理批量登录结果", [
            'account'              => $username,
            'account_id'           => $account->id,
            'task_status'          => $status,
            'task_msg'             => $msg,
            'has_result_data'      => !empty($result),
            'current_login_status' => $account->login_status,
            'current_amount'       => $account->amount
        ]);

        if ($status === 'completed') {
            if (strpos($msg, 'login successful') !== false || strpos($msg, '登录成功') !== false) {
                // 登录成功，更新登录状态
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_ACTIVE
                ]);

                $this->getLogger()->info("✅ 批量登录成功", [
                    'account'          => $username,
                    'success_msg'      => $msg,
                    'old_login_status' => 'invalid',
                    'new_login_status' => 'active'
                ]);

                // 从结果中解析余额信息
                if ($result) {
                    try {
                        $resultData = json_decode($result, true);

                        $this->getLogger()->info("💰 批量登录获取余额数据", [
                            'account'     => $username,
                            'result_data' => $resultData,
                            'raw_result'  => $result
                        ]);

                        if (isset($resultData['balance'])) {
                            $balanceString = $resultData['balance'];
                            // 移除货币符号并转换为浮点数
                            // 处理格式如 "$700.00", "¥1000.50", "€500.25" 等
                            $balance    = (float)preg_replace('/[^\d.-]/', '', $balanceString);
                            $oldBalance = $account->amount; // 在更新前保存旧余额
                            $account->update(['amount' => $balance]);

                            $this->getLogger()->info("💵 批量登录更新余额", [
                                'account'        => $username,
                                'old_balance'    => $oldBalance,
                                'new_balance'    => $balance,
                                'balance_string' => $balanceString,
                                'parsing_method' => 'regex currency removal'
                            ]);
                        }

                        if (isset($resultData['countryCode'])) {
                            $this->getLogger()->info("🌍 批量登录获取国家信息", [
                                'account'      => $username,
                                'country_code' => $resultData['countryCode'],
                                'country_name' => $resultData['country'] ?? 'unknown'
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->getLogger()->warning("❌ 批量登录解析结果失败: " . $e->getMessage(), [
                            'account'        => $username,
                            'raw_result'     => $result,
                            'exception_type' => get_class($e)
                        ]);
                    }
                }
            } else {
                // 登录失败
                $account->update([
                    'login_status' => ItunesTradeAccount::STATUS_LOGIN_INVALID
                ]);

                $this->getLogger()->warning("❌ 批量登录失败", [
                    'account'              => $username,
                    'failure_msg'          => $msg,
                    'result'               => $result,
                    'login_status_updated' => 'invalid'
                ]);
            }
        } else {
            $this->getLogger()->info("⏳ 批量登录任务未完成", [
                'account'        => $username,
                'current_status' => $status,
                'current_msg'    => $msg,
                'note'           => '任务仍在进行中'
            ]);
        }
    }

    /**d
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
     * 处理PROCESSING状态的账号
     */
    private function processProcessingAccount(ItunesTradeAccount $account): void
    {
        $this->getLogger()->info("正在处理PROCESSING状态账号: {$account->account}");

        // 1. 检查是否有待处理任务，如有则跳过
        if ($this->hasPendingTasks($account)) {
            $this->getLogger()->info("账号 {$account->account} 有待处理任务，跳过");
            return;
        }

        // 2. 检查是否已达到总目标金额
        if ($this->isAccountCompleted($account)) {
            $this->getLogger()->info("账号 {$account->account} 已达到总目标金额，标记为完成");
            $this->markAccountCompleted($account);
            return;
        }

        // 3. 检查是否完成当日计划
        if ($account->plan) {
            $currentDay           = $account->current_plan_day ?? 1;
            $isDailyPlanCompleted = $this->isDailyPlanCompleted($account, $currentDay);

            if ($isDailyPlanCompleted) {
                // 已完成当日计划，状态改为waiting，请求登出
                $this->getLogger()->info("账号 {$account->account} 完成当日计划，状态改为WAITING并请求登出", [
                    'account_id'    => $account->id,
                    'account_email' => $account->account,
                    'current_day'   => $currentDay,
                    'reason'        => '完成当日计划额度'
                ]);

                $account->timestamps = false;
                $account->update(['status' => ItunesTradeAccount::STATUS_WAITING]);
                $account->timestamps = true;

                // 请求登出
//                $this->requestAccountLogout($account, 'daily plan completed');
            } else {
                // 当日计划未完成，只检查严重的天数不一致情况（前一天未完成但被错误推进）
                if ($currentDay > 1 && $account->login_status === ItunesTradeAccount::STATUS_LOGIN_INVALID) {
                    // 检查当前天是否有任何兑换记录
                    $currentDayExchangeCount = ItunesTradeAccountLog::where('account_id', $account->id)
                        ->where('day', $currentDay)
                        ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                        ->count();

                    // 只有在当前天没有兑换记录的情况下才检查前一天是否未完成
                    if ($currentDayExchangeCount == 0) {
                        $previousDay            = $currentDay - 1;
                        $isPreviousDayCompleted = $this->isDailyPlanCompleted($account, $previousDay);

                        // 只处理严重情况：前一天未完成但被错误推进到当前天
                        if (!$isPreviousDayCompleted) {
                            $this->getLogger()->warning("账号 {$account->account} 严重的天数不一致：前一天未完成但被错误推进到当前天，回退到前一天", [
                                'account_id'                 => $account->id,
                                'account_email'              => $account->account,
                                'previous_day'               => $previousDay,
                                'current_day'                => $currentDay,
                                'current_day_exchange_count' => $currentDayExchangeCount,
                                'login_status'               => $account->login_status,
                                'reason'                     => '前一天未完成但被错误推进，需要回退修复'
                            ]);

                            $account->timestamps = false;
                            $account->update([
                                'current_plan_day' => $previousDay,
                                'status'           => ItunesTradeAccount::STATUS_PROCESSING
                            ]);
                            $account->timestamps = true;

                            // 请求登录继续完成前一天的计划
                            $this->requestAccountLogin($account);
                            return;
                        }
                        // 如果前一天已完成，说明正常进入当前天，不做任何状态改变
                    }
                }

                $this->getLogger()->debug("账号 {$account->account} 当日计划未完成，保持PROCESSING状态", [
                    'current_day'  => $currentDay,
                    'login_status' => $account->login_status
                ]);
            }
        } else {
            $this->getLogger()->debug("账号 {$account->account} 未绑定计划，保持PROCESSING状态");
        }
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
                'account'          => $account->account,
                'plan_id'          => $account->plan_id,
                'current_plan_day' => $account->current_plan_day,
                'status'           => $account->status,
                'issue'            => '计划已删除，需要解绑'
            ]);

            // Unbind plan and reset related fields (without updating timestamps)
            $account->timestamps = false;
            $account->update([
                'plan_id' => null,
                'status'  => ItunesTradeAccount::STATUS_WAITING,
            ]);
            $account->timestamps = true;

            $this->getLogger()->info("账号 {$account->account} 计划解绑完成", [
                'action'     => '清除plan_id',
                'new_status' => ItunesTradeAccount::STATUS_WAITING,
                'reason'     => '关联的计划已删除'
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

        // 1. 未绑定计划的账号，更新账户状态为processing，不发送消息
        if (!$account->plan) {
            $this->getLogger()->debug("账号 {$account->account} 未绑定计划，更新状态为PROCESSING", [
                'account_id'    => $account->id,
                'account_email' => $account->account,
                'status'        => $account->status,
                'plan_id'       => $account->plan_id,
                'reason'        => '未绑定计划，更新为processing状态'
            ]);

            $account->timestamps = false;
            $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
            $account->timestamps = true;

            return;
        }

        // 2. 检查账号总金额是否达到计划金额，达到计划总额要发送通知，且请求登出
        if ($this->isAccountCompleted($account)) {
            $this->getLogger()->info("账号 {$account->account} 达到计划总额，发送通知并请求登出", [
                'account_id'        => $account->id,
                'account_email'     => $account->account,
                'current_amount'    => $account->amount,
                'plan_total_amount' => $account->plan->total_amount,
                'reason'            => '达到计划总额度'
            ]);

            $this->markAccountCompleted($account);
            return;
        }

        // 3. 判断是否完成当日计划（当日兑换总额 > 计划当日额度要求）
        $currentDay           = $account->current_plan_day ?? 1;
        $isDailyPlanCompleted = $this->isDailyPlanCompleted($account, $currentDay);

        if ($isDailyPlanCompleted) {
            // 已完成当日计划，状态改为waiting，请求登出
            $this->getLogger()->info("账号 {$account->account} 完成当日计划，状态改为WAITING并请求登出", [
                'account_id'    => $account->id,
                'account_email' => $account->account,
                'current_day'   => $currentDay,
                'reason'        => '完成当日计划额度'
            ]);

            $account->timestamps = false;
            $account->update(['status' => ItunesTradeAccount::STATUS_WAITING]);
            $account->timestamps = true;

            // 请求登出（暂不登出）
//            $this->requestAccountLogout($account, 'daily plan completed');

        } else {
            // 未完成当日计划，状态改为processing
            $this->getLogger()->info("账号 {$account->account} 未完成当日计划，状态改为PROCESSING", [
                'account_id'    => $account->id,
                'account_email' => $account->account,
                'current_day'   => $currentDay,
                'reason'        => '未完成当日计划额度'
            ]);

            $account->timestamps = false;
            $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
            $account->timestamps = true;
        }
    }

    /**
     * 处理等待状态的账号
     */
    private function processWaitingAccount(ItunesTradeAccount $account): void
    {
        $this->getLogger()->info("正在处理等待状态账号: {$account->account}");


        // 查看最后一条日志是否已达到计划总额
        if ($this->isAccountCompleted($account)) {
            $this->getLogger()->warning("账号 {$account->account} 满足完成条件，标记为完成", [
                'reason' => '完成计划额度'
            ]);
            $this->markAccountCompleted($account);
            return;
        }

        // 获取最后成功日志
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        // 2. 检查当前天是否已完成计划
        if ($lastSuccessLog) {
            $currentDay           = $account->current_plan_day ?? 1;
            $isDailyPlanCompleted = $this->isDailyPlanCompleted($account, $currentDay);

            // 如果当前天的计划未完成，改为processing状态继续执行
            if (!$isDailyPlanCompleted) {
                $this->getLogger()->info("账号 {$account->account} 当前天计划未完成，改为PROCESSING状态", [
                    'account_id'    => $account->id,
                    'account_email' => $account->account,
                    'current_day'   => $currentDay,
                    'reason'        => '当前天计划未完成，需要继续执行'
                ]);

                $account->timestamps = false;
                $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
                $account->timestamps = true;

                // 状态变更为处理中时请求登录
                $this->requestAccountLogin($account);
                return;
            }

            // 如果当前天计划已完成，继续检查是否可以进入下一天
            $this->getLogger()->info("账号 {$account->account} 当前天计划已完成，检查是否可以进入下一天", [
                'current_day' => $currentDay,
                'plan_days'   => $account->plan->plan_days
            ]);
        }

        if (!$lastSuccessLog) {
            // 没有成功兑换记录的账号，只有在current_plan_day为空或0时才设置为第1天
            $currentDay = $account->current_plan_day;
            if (empty($currentDay) || $currentDay <= 0) {
                $currentDay = 1;
            }

            $account->timestamps = false;
            $account->update([
                'status'           => ItunesTradeAccount::STATUS_PROCESSING,
                'current_plan_day' => $currentDay
            ]);
            $account->timestamps = true;

            // 状态变更为处理中时请求登录
            $this->requestAccountLogin($account);

            $this->getLogger()->info("账号 {$account->account} 没有成功兑换记录，设置为处理状态", [
                'account_id'       => $account->account,
                'old_status'       => 'WAITING',
                'new_status'       => 'PROCESSING',
                'current_plan_day' => $currentDay,
                'reason'           => '没有兑换记录，保持当前天数继续执行'
            ]);
            return;
        }

        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $now              = now();

        // 计算时间间隔（分钟）
        $intervalMinutes          = $lastExchangeTime->diffInMinutes($now);
        $requiredExchangeInterval = max(1, $account->plan->exchange_interval ?? 5); // 最少1分钟

        $this->getLogger()->info("账号 {$account->account} 时间检查: 间隔 {$intervalMinutes} 分钟，要求兑换间隔 {$requiredExchangeInterval} 分钟");

        // 检查是否满足兑换间隔时间
        if ($intervalMinutes < $requiredExchangeInterval) {
            $this->getLogger()->info("账号 {$account->account} 兑换间隔时间不足，保持等待状态");
            return;
        }

        // 兑换间隔已满足，检查天数间隔
        $intervalHours       = $lastExchangeTime->diffInHours($now);
        $requiredDayInterval = max(1, $account->plan->day_interval ?? 24); // 最少1小时

        $this->getLogger()->info("账号 {$account->account} 天数检查: 间隔 {$intervalHours} 小时，要求天数间隔 {$requiredDayInterval} 小时");

        // 检查是否超过最大等待时间（防止无限等待）
        // 只有在以下情况才强制完成：
        // 1. 已经是最后一天，或者
        // 2. 已经达到总目标金额，或者
        // 3. 等待时间超过7天（极端情况）
        $maxWaitingHours = 24 * 7;                                         // 最大等待7天
        $currentDay      = $account->current_plan_day;

        // 检查是否为计划的最后一天
        $isLastDay = $currentDay >= $account->plan->plan_days;

        if ($intervalHours >= $requiredDayInterval) {
            if ($isLastDay) {
                // 最后一天但未达到总目标，检查是否超过48小时
                if ($intervalHours >= 48) {
                    // 超过48小时，解绑计划让账号可以重新绑定其他计划
                    $this->getLogger()->info("账号 {$account->account} 最后一天超过48小时未达到总目标，解绑计划", [
                        'current_day'          => $currentDay,
                        'plan_days'            => $account->plan->plan_days,
                        'interval_hours'       => $intervalHours,
                        'current_total_amount' => $this->getCurrentTotalAmount($account),
                        'plan_total_amount'    => $account->plan->total_amount,
                        'reason'               => '最后一天超时解绑，可重新绑定其他计划'
                    ]);
                    $this->unbindAccountPlan($account);
                }
            } else {
                // 不是最后一天，进入下一天
                $this->advanceToNextDay($account);
            }
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
            $this->getLogger()->info("🚀 开始为账号 {$account->account} 创建登录任务", [
                'account_id'           => $account->id,
                'account_email'        => $account->account,
                'current_login_status' => $account->login_status,
                'amount'               => $account->amount,
                'status'               => $account->status,
                'current_plan_day'     => $account->current_plan_day
            ]);

            $loginData = [[
                              'id'        => $account->id,
                              'username'  => $account->account,
                              'password'  => $account->getDecryptedPassword(),
                              'VerifyUrl' => $account->api_url ?? ''
                          ]];

            $response = $this->giftCardApiClient->createLoginTask($loginData);

            // 详细记录API响应
            $this->getLogger()->info("📡 登录任务API响应详情", [
                'account'       => $account->account,
                'response_code' => $response['code'] ?? 'unknown',
                'response_msg'  => $response['msg'] ?? 'no message',
                'response_data' => $response['data'] ?? null,
                'full_response' => $response
            ]);

            if ($response['code'] === 0) {
                $taskId = $response['data']['task_id'] ?? null;
                $this->getLogger()->info("✅ 账号 {$account->account} 登录任务创建成功", [
                    'task_id'    => $taskId,
                    'account_id' => $account->id,
                    'next_step'  => '任务已提交，等待后续处理结果'
                ]);

                // 尝试快速检查任务状态（不阻塞太久）
                if ($taskId) {
                    $this->quickCheckLoginTaskStatus($taskId, $account);
                }
            } else {
                $this->getLogger()->error("❌ 账号 {$account->account} 登录任务创建失败", [
                    'error_code' => $response['code'] ?? 'unknown',
                    'error_msg'  => $response['msg'] ?? '未知错误',
                    'account_id' => $account->id
                ]);
            }

        } catch (\Exception $e) {
            $this->getLogger()->error("❌ 账号 {$account->account} 请求登录异常: " . $e->getMessage(), [
                'account_id'     => $account->id,
                'exception_type' => get_class($e),
                'trace'          => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 快速检查登录任务状态
     */
    private function quickCheckLoginTaskStatus(string $taskId, ItunesTradeAccount $account): void
    {
        try {
            $this->getLogger()->info("🔍 快速检查登录任务状态", [
                'task_id' => $taskId,
                'account' => $account->account
            ]);

            // 只做一次快速检查，不阻塞太久
            $statusResponse = $this->giftCardApiClient->getLoginTaskStatus($taskId);

            $this->getLogger()->info("📊 登录任务快速状态查询结果", [
                'task_id'       => $taskId,
                'account'       => $account->account,
                'response_code' => $statusResponse['code'] ?? 'unknown',
                'response_msg'  => $statusResponse['msg'] ?? 'no message',
                'task_status'   => $statusResponse['data']['status'] ?? 'unknown',
                'items_count'   => count($statusResponse['data']['items'] ?? [])
            ]);

            if ($statusResponse['code'] === 0) {
                $taskStatus = $statusResponse['data']['status'] ?? '';
                $items      = $statusResponse['data']['items'] ?? [];

                if ($taskStatus === 'completed' && !empty($items)) {
                    $this->getLogger()->info("🎯 登录任务已完成，处理结果", [
                        'task_id'     => $taskId,
                        'account'     => $account->account,
                        'items_count' => count($items)
                    ]);

                    // 查找对应账号的结果
                    foreach ($items as $item) {
                        if ($item['data_id'] === $account->account) {
                            $this->logDetailedLoginResult($item, $account);
                            break;
                        }
                    }
                } else {
                    $this->getLogger()->info("⏳ 登录任务进行中", [
                        'task_id'     => $taskId,
                        'account'     => $account->account,
                        'task_status' => $taskStatus,
                        'note'        => '任务将在后续轮次中继续检查'
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->getLogger()->warning("⚠️ 快速检查登录任务状态异常: " . $e->getMessage(), [
                'task_id' => $taskId,
                'account' => $account->account,
                'note'    => '将在后续处理中继续尝试'
            ]);
        }
    }

    /**
     * 记录详细的登录结果
     */
    private function logDetailedLoginResult(array $item, ItunesTradeAccount $account): void
    {
        $status = $item['status'] ?? '';
        $msg    = $item['msg'] ?? '';
        $result = $item['result'] ?? '';

        $this->getLogger()->info("📋 登录任务详细结果", [
            'account'         => $account->account,
            'task_status'     => $status,
            'task_msg'        => $msg,
            'has_result_data' => !empty($result),
            'full_item'       => $item
        ]);

        if ($status === 'completed') {
            if (strpos($msg, 'successful') !== false || strpos($msg, '成功') !== false) {
                $this->getLogger()->info("✅ 账号登录成功回调", [
                    'account'     => $account->account,
                    'success_msg' => $msg
                ]);

                // 解析结果数据
                if ($result) {
                    try {
                        $resultData = json_decode($result, true);
                        $this->getLogger()->info("💰 登录成功获取余额信息", [
                            'account'     => $account->account,
                            'result_data' => $resultData,
                            'raw_result'  => $result
                        ]);

                        if (isset($resultData['balance'])) {
                            $balanceString = $resultData['balance'];
                            $balance       = (float)preg_replace('/[^\d.-]/', '', $balanceString);

                            $this->getLogger()->info("💵 账号余额解析", [
                                'account'        => $account->account,
                                'balance_string' => $balanceString,
                                'parsed_balance' => $balance,
                                'current_amount' => $account->amount
                            ]);
                        }

                        if (isset($resultData['countryCode'])) {
                            $this->getLogger()->info("🌍 账号国家信息", [
                                'account'      => $account->account,
                                'country_code' => $resultData['countryCode'],
                                'country_name' => $resultData['country'] ?? 'unknown'
                            ]);
                        }
                    } catch (\Exception $e) {
                        $this->getLogger()->warning("❌ 解析登录结果数据失败: " . $e->getMessage(), [
                            'account'    => $account->account,
                            'raw_result' => $result
                        ]);
                    }
                }
            } else {
                $this->getLogger()->warning("❌ 账号登录失败回调", [
                    'account'     => $account->account,
                    'failure_msg' => $msg,
                    'result'      => $result
                ]);
            }
        } else {
            $this->getLogger()->info("⏳ 登录任务状态更新", [
                'account'        => $account->account,
                'current_status' => $status,
                'current_msg'    => $msg
            ]);
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
                'plan_id'   => $plan->id,
                'plan_days' => $plan->plan_days
            ]);
            return false;
        }

        if (empty($plan->total_amount) || $plan->total_amount <= 0) {
            $this->getLogger()->error("计划配置错误: 无效的总金额", [
                'plan_id'      => $plan->id,
                'total_amount' => $plan->total_amount
            ]);
            return false;
        }

        // 检查每日金额配置
        $dailyAmounts = $plan->daily_amounts ?? [];
        if (empty($dailyAmounts) || !is_array($dailyAmounts)) {
            $this->getLogger()->error("计划配置错误: 无效的每日金额", [
                'plan_id'       => $plan->id,
                'daily_amounts' => $dailyAmounts
            ]);
            return false;
        }

        if (count($dailyAmounts) != $plan->plan_days) {
            $this->getLogger()->error("计划配置错误: 每日金额数量与计划天数不匹配", [
                'plan_id'             => $plan->id,
                'daily_amounts_count' => count($dailyAmounts),
                'plan_days'           => $plan->plan_days
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
        $plan       = $account->plan;

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
            'plan_days'      => $plan->plan_days,
            'current_day'    => $currentDay,
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
            'plan_total_amount'    => $account->plan->total_amount,
            'account_amount'       => $account->amount,
            'is_completed'         => $currentTotalAmount >= $account->plan->total_amount
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
            'status'           => ItunesTradeAccount::STATUS_COMPLETED,
            'current_plan_day' => null,
            'plan_id'          => null,
            'completed_days'   => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        $this->getLogger()->info('账号计划完成', [
            'account_id'           => $account->account,
            'account'              => $account->account,
            'current_total_amount' => $currentTotalAmount,
            'account_amount'       => $account->amount,
            'plan_total_amount'    => $account->plan->total_amount ?? 0,
            'plan_days'            => $account->plan->plan_days,
            'final_completed_days' => $completedDays
        ]);

        // 为已完成的账号请求登出
        $this->requestAccountLogout($account, 'plan completed');

        // 发送完成通知
        $msg = "[强]兑换目标达成通知\n";
        $msg .= "---------------------------------\n";
        $msg .= $account->account . "\n";
        $msg .= "国家：{$account->country_code}   账户余款：{$currentTotalAmount}";

        send_msg_to_wechat('45958721463@chatroom', $msg);
    }

    /**
     * 进入下一天
     */
    private function advanceToNextDay(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $nextDay    = $currentDay + 1;

        if (!$account->plan) {
            $this->getLogger()->warning("账号 {$account->account} 没有关联的计划，无法进入下一天");
            return;
        }

        // 检查是否已达到或超过计划的最后一天
//        if ($currentDay >= $account->plan->plan_days) {
//            $this->getLogger()->warning("账号 {$account->account} 已达到或超过计划最后一天，标记为完成", [
//                'current_day' => $currentDay,
//                'plan_days' => $account->plan->plan_days,
//                'reason' => '已达到计划天数限制'
//            ]);
//            $this->markAccountCompleted($account);
//            return;
//        }

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
            'status'           => ItunesTradeAccount::STATUS_PROCESSING,
            'completed_days'   => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        // 状态变更为处理中时请求登录
        $this->requestAccountLogin($account);

        $this->getLogger()->info('账号进入下一天', [
            'account_id'     => $account->account,
            'account'        => $account->account,
            'current_day'    => $nextDay,
            'plan_days'      => $account->plan->plan_days,
            'status_changed' => 'WAITING -> PROCESSING',
            'reason'         => '天数间隔已超过，进入下一天',
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
            'plan_id'          => null,
            'current_plan_day' => null,
            'status'           => ItunesTradeAccount::STATUS_WAITING,
            'completed_days'   => json_encode($completedDays),
        ]);
        $account->timestamps = true;

        // 请求登出账号
//        $this->requestAccountLogout($account, 'plan unbound');

        $this->getLogger()->info('账号计划解绑完成', [
            'account_id'               => $account->account,
            'account'                  => $account->account,
            'old_status'               => 'WAITING',
            'new_status'               => 'WAITING',
            'plan_id_cleared'          => true,
            'current_plan_day_cleared' => true,
            'reason'                   => '最后一天超时未完成，解绑计划以便重新绑定',
            'final_completed_days'     => $completedDays
        ]);
    }

    /**
     * 检查每日计划完成情况
     */
    private function checkDailyPlanCompletion(ItunesTradeAccount $account): void
    {
        $currentDay = $account->current_plan_day ?? 1;
        $plan       = $account->plan;

        // 计算当前天的累计兑换金额
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 获取当前天的计划金额
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit   = $dailyAmounts[$currentDay - 1] ?? 0;

        $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天计划检查: 已兑换 {$dailyAmount}，目标 {$dailyLimit}");

        // 处理配置异常：如果每日目标为0或负数，视为当天完成
        if ($dailyLimit <= 0) {
            $this->getLogger()->warning("账号 {$account->account} 第{$currentDay}天目标金额配置异常 ({$dailyLimit})，视为当天完成", [
                'current_day' => $currentDay,
                'daily_limit' => $dailyLimit,
                'plan_id'     => $plan->id
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
                    'current_day'  => $currentDay,
                    'plan_days'    => $plan->plan_days,
                    'daily_amount' => $dailyAmount,
                    'daily_limit'  => $dailyLimit,
                    'reason'       => '最后一天计划完成'
                ]);
                $this->markAccountCompleted($account);
            } else {
                // 不是最后一天，检查总金额是否达到目标
                if ($this->isAccountCompleted($account)) {
                    $this->getLogger()->info("账号 {$account->account} 总金额已达到目标，标记为完成", [
                        'current_day'       => $currentDay,
                        'total_amount'      => $account->amount,
                        'plan_total_amount' => $plan->total_amount,
                        'reason'            => '总金额目标已达到'
                    ]);
                    $this->markAccountCompleted($account);
                } else {
                    $this->getLogger()->info("账号 {$account->account} 第{$currentDay}天计划完成，等待下一天", [
                        'current_day'  => $currentDay,
                        'plan_days'    => $plan->plan_days,
                        'daily_amount' => $dailyAmount,
                        'daily_limit'  => $dailyLimit,
                        'status'       => '保持等待状态直到满足天数间隔'
                    ]);
                }
            }
        } else {
            // 计划未完成，检查账号是否有足够余额继续兑换
            $remainingDaily = $dailyLimit - $dailyAmount;

            $this->getLogger()->info("账号 {$account->account} 每日计划未完成检查", [
                'current_day'     => $currentDay,
                'daily_amount'    => $dailyAmount,
                'daily_limit'     => $dailyLimit,
                'remaining_daily' => $remainingDaily
            ]);

            // 余额充足，更改状态为处理中（不更新时间戳）
            $account->timestamps = false;
            $account->update(['status' => ItunesTradeAccount::STATUS_PROCESSING]);
            $account->timestamps = true;

            // 状态变更为处理中时请求登录
            $this->requestAccountLogin($account);

            $this->getLogger()->info('等待账号状态变更为处理中', [
                'account_id'     => $account->account,
                'account'        => $account->account,
                'current_day'    => $currentDay,
                'status_changed' => 'WAITING -> PROCESSING',
                'reason'         => '每日计划未完成，变更为处理状态'
            ]);
        }
    }

    /**
     * 检查当日计划是否完成
     */
    private function isDailyPlanCompleted(ItunesTradeAccount $account, int $currentDay): bool
    {
        $plan = $account->plan;

        if (!$plan) {
            return false;
        }

        // 计算当前天的累计兑换金额
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        // 获取当前天的计划金额
        $dailyAmounts = $plan->daily_amounts ?? [];
        $dailyLimit   = $dailyAmounts[$currentDay - 1] ?? 0;

        $isCompleted = $dailyAmount >= $dailyLimit;

        $this->getLogger()->debug("检查当日计划完成情况", [
            'account_id'    => $account->id,
            'account_email' => $account->account,
            'current_day'   => $currentDay,
            'daily_amount'  => $dailyAmount,
            'daily_limit'   => $dailyLimit,
            'is_completed'  => $isCompleted
        ]);

        return $isCompleted;
    }
}
