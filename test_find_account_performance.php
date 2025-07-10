<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\Gift\FindAccountService;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FindAccountService 性能测试脚本
 *
 * 测试参数：
 * - 国家：CA
 * - 金额：200
 * - 计划ID：1
 * - 房间ID：50165570842@chatroom
 */

echo "========================================\n";
echo "FindAccountService 性能测试开始\n";
echo "========================================\n";

// 测试参数
$testParams = [
    'country' => 'CA',
    'amount' => 200,
    'plan_id' => 1,
    'room_id' => '50165570842@chatroom',
    'current_day' => 1
];


echo "📋 测试参数：\n";
echo "  - 国家：{$testParams['country']}\n";
echo "  - 金额：{$testParams['amount']}\n";
echo "  - 计划ID：{$testParams['plan_id']}\n";
echo "  - 房间ID：{$testParams['room_id']}\n";
echo "  - 当前天数：{$testParams['current_day']}\n";
echo "\n";

try {
    // 初始化Laravel应用
    if (file_exists(__DIR__ . '/bootstrap/app.php')) {
        $app = require_once __DIR__ . '/bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    }

    // 创建服务实例
    $findAccountService = new FindAccountService();

    // 1. 获取计划信息
    echo "📋 获取计划信息...\n";
    $plan = ItunesTradePlan::with('rate')->find($testParams['plan_id']);

    if (!$plan) {
        echo "❌ 错误：找不到计划ID {$testParams['plan_id']}\n";
        exit(1);
    }

    echo "  - 计划名称：{$plan->name}\n";
    echo "  - 计划国家：{$plan->country}\n";
    echo "  - 总额度：{$plan->total_amount}\n";
    echo "  - 计划天数：{$plan->plan_days}\n";
    echo "  - 绑定群聊：" . ($plan->bind_room ? '是' : '否') . "\n";

    if ($plan->rate) {
        echo "  - 汇率约束：{$plan->rate->amount_constraint}\n";
        if ($plan->rate->amount_constraint === 'multiple') {
            echo "    * 倍数基数：{$plan->rate->multiple_base}\n";
            echo "    * 最小金额：{$plan->rate->min_amount}\n";
            echo "    * 最大金额：{$plan->rate->max_amount}\n";
        }
    }
    echo "\n";

    // 2. 准备礼品卡信息
    $giftCardInfo = [
        'amount' => $testParams['amount'],
        'country_code' => $testParams['country'],
        'room_id' => $testParams['room_id']
    ];

    // 3. 获取基础统计信息（纯数据库统计，不含筛选逻辑）
    echo "📊 基础账号统计（{$testParams['country']}国家）：\n";
    $basicStats = $findAccountService->getSelectionStatistics($testParams['country'], $plan);

    echo "  📈 账号状态分布：\n";
    foreach ($basicStats['status_distribution'] as $status => $count) {
        echo "    - {$status}：{$count} 个\n";
    }

    echo "  🔐 登录状态分布：\n";
    foreach ($basicStats['login_status_distribution'] as $loginStatus => $count) {
        echo "    - {$loginStatus}：{$count} 个\n";
    }

    echo "  🎯 可用账号概况：\n";
    echo "    - 可处理状态：{$basicStats['total_processing']} 个\n";
    echo "    - 有效登录：{$basicStats['total_active_login']} 个\n";

    if (isset($basicStats['plan_statistics'])) {
        $planStats = $basicStats['plan_statistics'];
        echo "  📋 计划绑定分布：\n";
        echo "    - 绑定到计划#{$plan->id}：{$planStats['bound_to_plan']} 个\n";
        echo "    - 未绑定计划：{$planStats['unbound']} 个\n";
        echo "  💰 余额分布：\n";
        echo "    - 零余额账号：{$planStats['zero_amount']} 个\n";
        echo "    - 有余额账号：{$planStats['positive_amount']} 个\n";
        echo "    - 平均余额：{$planStats['avg_amount']}\n";
        echo "    - 最大余额：{$planStats['max_amount']}\n";
    }

    echo "  ⚠️  注意：以上为基础统计，未执行业务筛选逻辑\n";
    echo "\n";

    // 4. 执行5层交集筛选性能分析（真正的业务逻辑测试）
    echo "🚀 5层交集筛选性能分析：\n";
    echo "  🔍 正在执行完整的筛选流程（礼品卡金额:{$testParams['amount']}）...\n";
    $performanceStats = $findAccountService->getFilteringPerformanceStats(
        $plan,
        $testParams['room_id'],
        $giftCardInfo,
        $testParams['current_day']
    );

    echo "  📈 各层筛选结果：\n";
    $stageNames = [
        'base_qualification' => '第1层-基础条件',
        'constraint_qualification' => '第2层-约束条件',
        'room_binding_qualification' => '第3层-群聊绑定',
        'capacity_qualification' => '第4层-容量检查',
        'daily_plan_qualification' => '第5层-每日计划'
    ];

    foreach ($performanceStats['layers'] as $stage => $layerStats) {
        $stageName = $stageNames[$stage] ?? $stage;
        echo "    {$stageName}：{$layerStats['qualified_count']} 个账号，耗时 {$layerStats['execution_time_ms']}ms\n";
    }

    echo "\n  🎯 最终结果：\n";
    echo "    - 最终合格账号：{$performanceStats['final_qualified_count']} 个\n";
    echo "    - 总耗时：{$performanceStats['total_time_ms']}ms\n";

    $performanceLevel = $performanceStats['total_time_ms'] < 30 ? 'S级🏆' :
                      ($performanceStats['total_time_ms'] < 100 ? 'A级🥇' : 'B级🥈');
    echo "    - 性能等级：{$performanceLevel}\n";
    echo "\n";

    // 5. 实际查找最优账号（多次测试）
    echo "🎯 实际账号查找测试：\n";
    echo "  ⚠️  注意：此测试可能会锁定账号，影响后续并发测试\n";
    $testRounds = 2; // 减少测试轮数，避免过多状态污染
    $totalTime = 0;
    $successCount = 0;
    $foundAccounts = [];

    echo "  执行 {$testRounds} 轮账号查找测试...\n";

    for ($i = 1; $i <= $testRounds; $i++) {
        $startTime = microtime(true);

        try {
            $account = $findAccountService->findOptimalAccount(
                $plan,
                $testParams['room_id'],
                $giftCardInfo,
                $testParams['current_day'],
                true  // 启用测试模式，不锁定账号
            );

            $endTime = microtime(true);
            $executeTime = ($endTime - $startTime) * 1000;
            $totalTime += $executeTime;

            if ($account) {
                $successCount++;
                $foundAccounts[] = [
                    'round' => $i,
                    'id' => $account->id,
                    'email' => $account->account,
                    'balance' => $account->amount,
                    'plan_id' => $account->plan_id,
                    'room_id' => $account->room_id,
                    'current_day' => $account->current_plan_day ?? 1,
                    'status' => $account->status,
                    'time' => round($executeTime, 2)
                ];
                echo "    第{$i}轮：✅ 找到账号 #{$account->id} ({$account->account})，余额:{$account->amount}，耗时 " . round($executeTime, 2) . "ms [测试模式-未锁定]\n";
            } else {
                echo "    第{$i}轮：❌ 未找到账号，耗时 " . round($executeTime, 2) . "ms\n";

                // 第6层失败分析：如果是第1轮，详细分析第6层失败原因
                if ($i == 1) {
                    echo "      🔍 分析第6层失败原因...\n";
                    analyzeLayer6Failure($findAccountService, $plan, $testParams, $giftCardInfo);
                }
            }

            // 模拟间隔，避免过快重复
            usleep(10000); // 10ms

        } catch (Exception $e) {
            echo "    第{$i}轮：💥 异常 - {$e->getMessage()}\n";
        }
    }

    // 统计结果
    echo "\n  📊 多轮测试统计：\n";
    echo "    - 成功率：{$successCount}/{$testRounds} (" . round($successCount/$testRounds*100, 1) . "%)\n";

    if ($testRounds > 0) {
        echo "    - 平均耗时：" . round($totalTime/$testRounds, 2) . "ms\n";
    }

    if (!empty($foundAccounts)) {
        $times = array_column($foundAccounts, 'time');
        echo "    - 最优耗时：" . round(min($times), 2) . "ms\n";
        echo "    - 最差耗时：" . round(max($times), 2) . "ms\n";

        echo "\n  🎯 找到的账号详细信息：\n";
        echo "  " . str_repeat("=", 60) . "\n";
        foreach (array_unique(array_column($foundAccounts, 'id')) as $accountId) {
            $accountInfo = array_values(array_filter($foundAccounts, fn($a) => $a['id'] == $accountId))[0];
            echo "  📋 账号 #{$accountInfo['id']}\n";
            echo "      - 邮箱：{$accountInfo['email']}\n";
            echo "      - 当前余额：{$accountInfo['balance']}\n";
            echo "      - 绑定计划：" . ($accountInfo['plan_id'] ? "#{$accountInfo['plan_id']}" : '未绑定') . "\n";
            echo "      - 绑定房间：" . ($accountInfo['room_id'] ? $accountInfo['room_id'] : '未绑定') . "\n";
            echo "      - 当前天数：{$accountInfo['current_day']}\n";
            echo "      - 账号状态：{$accountInfo['status']}\n";

            // 显示该账号被找到的轮次
            $rounds = array_column(array_filter($foundAccounts, fn($a) => $a['id'] == $accountId), 'round');
            echo "      - 被选中轮次：" . implode(', ', $rounds) . "\n";
            echo "  " . str_repeat("-", 60) . "\n";
        }
    }
    echo "\n";
    exit;
    // 6. 第6层锁定机制测试（只测试1次锁定）
    echo "🔒 第6层锁定机制测试：\n";
    echo "  ⚠️  此测试将真正锁定1个账号用于验证锁定机制\n";

    try {
        $lockTestStart = microtime(true);
        $lockedAccount = $findAccountService->findOptimalAccount(
            $plan,
            $testParams['room_id'],
            $giftCardInfo,
            $testParams['current_day'],
            false  // 生产模式，执行真正的锁定
        );
        $lockTestTime = (microtime(true) - $lockTestStart) * 1000;

        if ($lockedAccount) {
            echo "  ✅ 锁定测试成功：账号#{$lockedAccount->id} ({$lockedAccount->account})\n";
            echo "    - 账号状态：{$lockedAccount->status}\n";
            echo "    - 绑定计划：{$lockedAccount->plan_id}\n";
            echo "    - 绑定房间：{$lockedAccount->room_id}\n";
            echo "    - 当前天数：{$lockedAccount->current_plan_day}\n";
            echo "    - 锁定耗时：" . round($lockTestTime, 2) . "ms\n";
        } else {
            echo "  ❌ 锁定测试失败：未找到可锁定的账号\n";
            echo "    - 测试耗时：" . round($lockTestTime, 2) . "ms\n";
        }
    } catch (Exception $e) {
        echo "  💥 锁定测试异常：{$e->getMessage()}\n";
    }
    echo "\n";

    // 7. 改进的并发性能测试（基于候选账号）
    echo "⚡ 改进的并发性能测试：\n";
    echo "  🎯 基于前5层筛选的 {$performanceStats['final_qualified_count']} 个候选账号进行测试...\n";
    echo "  ℹ️  注意：现在实际查找测试使用测试模式，不会锁定账号，所以数据应该保持一致\n";

    $concurrentRequests = min(10, $performanceStats['final_qualified_count']); // 最多10个请求，不超过候选账号数

    if ($concurrentRequests == 0) {
        echo "  ❌ 没有候选账号可供并发测试\n\n";
    } else {
        echo "  📊 执行 {$concurrentRequests} 个并发请求（每个使用不同账号）...\n";

        // 获取候选账号ID列表（重新执行前5层筛选）
        $baseAccountIds = [];
        $constraintAccountIds = [];
        $roomBindingAccountIds = [];
        $capacityAccountIds = [];
        $candidateAccountIds = [];

        try {
            // 直接使用之前性能分析的结果，避免重复计算
            if (isset($performanceStats['layers']['daily_plan_qualification']['qualified_count']) &&
                $performanceStats['layers']['daily_plan_qualification']['qualified_count'] > 0) {

                // 重新执行筛选获取候选账号列表（使用相同参数）
                $reflection = new ReflectionClass($findAccountService);

                echo "  🔄 重新执行5层筛选获取候选账号...\n";

                $getBaseMethod = $reflection->getMethod('getBaseQualifiedAccountIds');
                $getBaseMethod->setAccessible(true);
                $baseAccountIds = $getBaseMethod->invoke($findAccountService, $plan, $giftCardInfo['amount'], $giftCardInfo['country']);
                echo "    第1层：" . count($baseAccountIds) . " 个账号\n";

                $getConstraintMethod = $reflection->getMethod('getConstraintQualifiedAccountIds');
                $getConstraintMethod->setAccessible(true);
                $constraintAccountIds = $getConstraintMethod->invoke($findAccountService, $baseAccountIds, $plan, $giftCardInfo['amount']);
                echo "    第2层：" . count($constraintAccountIds) . " 个账号\n";

                $getRoomBindingMethod = $reflection->getMethod('getRoomBindingQualifiedAccountIds');
                $getRoomBindingMethod->setAccessible(true);
                $roomBindingAccountIds = $getRoomBindingMethod->invoke($findAccountService, $constraintAccountIds, $plan, $giftCardInfo);
                echo "    第3层：" . count($roomBindingAccountIds) . " 个账号\n";

                $getCapacityMethod = $reflection->getMethod('getCapacityQualifiedAccountIds');
                $getCapacityMethod->setAccessible(true);
                $capacityAccountIds = $getCapacityMethod->invoke($findAccountService, $roomBindingAccountIds, $plan, $giftCardInfo['amount']);
                echo "    第4层：" . count($capacityAccountIds) . " 个账号\n";

                $getDailyPlanMethod = $reflection->getMethod('getDailyPlanQualifiedAccountIds');
                $getDailyPlanMethod->setAccessible(true);
                $candidateAccountIds = $getDailyPlanMethod->invoke($findAccountService, $capacityAccountIds, $plan, $giftCardInfo['amount'], $testParams['current_day']);
                echo "    第5层：" . count($candidateAccountIds) . " 个账号\n";

                echo "  ✅ 重新获取 " . count($candidateAccountIds) . " 个候选账号ID\n";

                // 如果第5层结果异常，显示详细调试信息
                if (count($capacityAccountIds) > 0 && count($candidateAccountIds) == 0) {
                    echo "  🚨 第5层异常：输入" . count($capacityAccountIds) . "个账号，输出0个账号\n";
                    echo "  🔍 查看最近的第5层验证日志...\n";
                    showLayer5DebugLogs();
                }
            } else {
                echo "  ⚠️  性能分析没有合格账号，跳过重新获取\n";
                $candidateAccountIds = [];
            }

        } catch (Exception $e) {
            echo "  ❌ 获取候选账号失败：{$e->getMessage()}\n";
            $candidateAccountIds = [];
        }

        if (empty($candidateAccountIds)) {
            echo "  ❌ 没有可用的候选账号进行并发测试\n\n";
        } else {
            $startTime = microtime(true);
            $results = [];

            // 从候选账号中随机选择不同的账号进行测试
            $shuffledAccountIds = $candidateAccountIds;
            shuffle($shuffledAccountIds);
            $selectedAccountIds = array_slice($shuffledAccountIds, 0, $concurrentRequests);

            echo "  🎲 随机选择的测试账号ID：" . implode(', ', $selectedAccountIds) . "\n";

            for ($i = 1; $i <= $concurrentRequests; $i++) {
                $requestStart = microtime(true);
                $testAccountId = $selectedAccountIds[$i - 1]; // 使用指定的账号ID

                try {
                    // 每个请求使用稍微不同的金额，但主要是测试不同账号
                    $testGiftCardInfo = $giftCardInfo;
                    $testGiftCardInfo['amount'] = $giftCardInfo['amount'] + ($i % 3) * 50; // 200, 250, 300循环

                    // 直接尝试锁定指定账号（模拟并发竞争）
                    $reflection = new ReflectionClass($findAccountService);
                    $attemptLockMethod = $reflection->getMethod('attemptLockAccount');
                    $attemptLockMethod->setAccessible(true);

                    $account = $attemptLockMethod->invoke(
                        $findAccountService,
                        $testAccountId,
                        $plan,
                        $testParams['room_id'],
                        $testParams['current_day']
                    );

                    $requestEnd = microtime(true);
                    $requestTime = ($requestEnd - $requestStart) * 1000;

                    $results[] = [
                        'request_id' => $i,
                        'target_account_id' => $testAccountId,
                        'amount' => $testGiftCardInfo['amount'],
                        'success' => $account !== null,
                        'account_id' => $account ? $account->id : null,
                        'account_email' => $account ? $account->account : null,
                        'account_balance' => $account ? $account->amount : null,
                        'account_status' => $account ? $account->status : null,
                        'time' => round($requestTime, 2)
                    ];

                } catch (Exception $e) {
                    $requestEnd = microtime(true);
                    $requestTime = ($requestEnd - $requestStart) * 1000;

                    $results[] = [
                        'request_id' => $i,
                        'target_account_id' => $testAccountId,
                        'amount' => $testGiftCardInfo['amount'],
                        'success' => false,
                        'error' => $e->getMessage(),
                        'time' => round($requestTime, 2)
                    ];
                }
            }

            $totalTime = (microtime(true) - $startTime) * 1000;
            $successfulRequests = array_filter($results, fn($r) => $r['success']);
            $averageTime = count($results) > 0 ? array_sum(array_column($results, 'time')) / count($results) : 0;

            echo "\n  📊 改进的并发测试结果：\n";
            echo "    - 总请求数：{$concurrentRequests}\n";
            echo "    - 成功请求：" . count($successfulRequests) . "\n";
            echo "    - 成功率：" . round(count($successfulRequests)/$concurrentRequests*100, 1) . "%\n";
            echo "    - 总耗时：" . round($totalTime, 2) . "ms\n";
            echo "    - 平均单请求耗时：" . round($averageTime, 2) . "ms\n";

            if ($averageTime > 0) {
                echo "    - 理论QPS：" . round(1000 / $averageTime, 1) . " 请求/秒\n";
            }

            // 显示详细结果
            echo "\n  🔍 并发请求详细结果：\n";
            echo "  " . str_repeat("=", 70) . "\n";
            foreach ($results as $result) {
                $status = $result['success'] ? '✅' : '❌';
                echo "  📋 请求 #{$result['request_id']} (目标账号#{$result['target_account_id']}, 金额:{$result['amount']}) {$status} 耗时:{$result['time']}ms\n";

                if ($result['success']) {
                    echo "      🎯 锁定账号: #{$result['account_id']} ({$result['account_email']})\n";
                    echo "      💰 账号余额: {$result['account_balance']}\n";
                    echo "      📊 账号状态: {$result['account_status']}\n";
                } else {
                    $error = isset($result['error']) ? $result['error'] : '锁定失败';
                    echo "      ❌ 失败原因: {$error}\n";
                }
                echo "  " . str_repeat("-", 70) . "\n";
            }
        }
    }

} catch (Exception $e) {
    echo "❌ 测试过程中发生异常：{$e->getMessage()}\n";
    echo "堆栈跟踪：\n{$e->getTraceAsString()}\n";
}

/**
 * 显示第5层筛选的详细调试日志
 */
function showLayer5DebugLogs() {
    try {
        // 查找最新的gift_card_exchange.log文件
        $logPath = storage_path('logs');
        $logFiles = glob($logPath . '/gift_card_exchange*.log');

        if (empty($logFiles)) {
            echo "    ❌ 未找到gift_card_exchange日志文件\n";
            return;
        }

        // 获取最新的日志文件
        $latestLogFile = max($logFiles);

        if (!file_exists($latestLogFile)) {
            echo "    ❌ 日志文件不存在：{$latestLogFile}\n";
            return;
        }

        // 读取最近的日志内容
        $logContent = file_get_contents($latestLogFile);
        $logLines = explode("\n", $logContent);

        // 查找第5层相关的最新日志
        $layer5Logs = [];
        $currentTime = date('Y-m-d H:i');

        foreach (array_reverse($logLines) as $line) {
            if (empty(trim($line))) continue;

            // 查找包含第5层验证信息的日志行
            if (strpos($line, '第5层') !== false ||
                strpos($line, 'getDailyPlanQualifiedAccountIds') !== false ||
                strpos($line, 'validateDailyPlanLimitOptimized') !== false) {

                // 只显示最近10分钟内的日志
                if (strpos($line, $currentTime) !== false || strpos($line, date('Y-m-d H:i', strtotime('-1 minute'))) !== false) {
                    $layer5Logs[] = $line;
                }
            }

            // 限制显示条数
            if (count($layer5Logs) >= 20) {
                break;
            }
        }

        if (empty($layer5Logs)) {
            echo "    ⚠️  未找到最近的第5层验证日志\n";
        } else {
            echo "    📋 最近的第5层验证日志：\n";
            foreach (array_reverse($layer5Logs) as $log) {
                // 简化日志显示，只显示关键信息
                if (preg_match('/\{.*\}/', $log, $matches)) {
                    $jsonData = json_decode($matches[0], true);
                    if ($jsonData) {
                        if (isset($jsonData['account_id'])) {
                            echo "      账号#{$jsonData['account_id']}: ";
                            if (isset($jsonData['result'])) {
                                echo ($jsonData['result'] ? '✅通过' : '❌失败');
                                if (!$jsonData['result'] && isset($jsonData['failure_reason'])) {
                                    echo " - {$jsonData['failure_reason']}";
                                }
                            }
                            echo "\n";
                        } elseif (isset($jsonData['total_accounts'])) {
                            echo "      总计：{$jsonData['total_accounts']}个账号，通过{$jsonData['qualified_accounts']}个，成功率{$jsonData['qualification_rate']}\n";
                        }
                    }
                }
            }
        }

    } catch (Exception $e) {
        echo "    ❌ 读取第5层日志异常：{$e->getMessage()}\n";
    }
}

/**
 * 分析第6层（排序和锁定）失败的原因
 */
function analyzeLayer6Failure($findAccountService, $plan, $testParams, $giftCardInfo) {
    try {
        $reflection = new ReflectionClass($findAccountService);

        // 重新执行前5层筛选获取候选账号
        $getBaseMethod = $reflection->getMethod('getBaseQualifiedAccountIds');
        $getBaseMethod->setAccessible(true);
        $baseAccountIds = $getBaseMethod->invoke($findAccountService, $plan, $giftCardInfo['amount'], $giftCardInfo['country']);

        $getConstraintMethod = $reflection->getMethod('getConstraintQualifiedAccountIds');
        $getConstraintMethod->setAccessible(true);
        $constraintAccountIds = $getConstraintMethod->invoke($findAccountService, $baseAccountIds, $plan, $giftCardInfo['amount']);

        $getRoomBindingMethod = $reflection->getMethod('getRoomBindingQualifiedAccountIds');
        $getRoomBindingMethod->setAccessible(true);
        $roomBindingAccountIds = $getRoomBindingMethod->invoke($findAccountService, $constraintAccountIds, $plan, $giftCardInfo);

        $getCapacityMethod = $reflection->getMethod('getCapacityQualifiedAccountIds');
        $getCapacityMethod->setAccessible(true);
        $capacityAccountIds = $getCapacityMethod->invoke($findAccountService, $roomBindingAccountIds, $plan, $giftCardInfo['amount']);

        $getDailyPlanMethod = $reflection->getMethod('getDailyPlanQualifiedAccountIds');
        $getDailyPlanMethod->setAccessible(true);
        $finalAccountIds = $getDailyPlanMethod->invoke($findAccountService, $capacityAccountIds, $plan, $giftCardInfo['amount'], $testParams['current_day']);

        if (empty($finalAccountIds)) {
            echo "        ❌ 前5层筛选结果为空，分析数据不一致问题...\n";

            // 对比性能分析和实际查找的差异
            echo "        🔍 数据一致性对比分析：\n";
            echo "          性能分析时间：" . date('Y-m-d H:i:s', strtotime('-1 minute')) . "\n";
            echo "          当前查找时间：" . date('Y-m-d H:i:s') . "\n";

            // 重新检查每一层的详细情况
            echo "        📊 逐层对比分析：\n";

            // 检查第1层的详细情况
            echo "          第1层详细检查：\n";
            $baseCountSql = "
                SELECT COUNT(*) as count
                FROM itunes_trade_accounts a
                WHERE a.status = 'processing'
                  AND a.login_status = 'valid'
                  AND a.country_code = ?
                  AND a.amount >= 0
                  AND (a.amount + ?) <= ?
                  AND a.deleted_at IS NULL
            ";
            $baseCount = DB::select($baseCountSql, [$giftCardInfo['country'], $giftCardInfo['amount'], $plan->total_amount]);
            echo "            - 基础条件符合：" . ($baseCount[0]->count ?? 0) . " 个账号\n";

            // 检查第4层容量筛选的详细情况
            echo "          第4层容量检查详细：\n";
            if (!empty($capacityAccountIds)) {
                echo "            - 容量筛选通过：" . count($capacityAccountIds) . " 个账号\n";

                // 检查第5层的查询条件
                echo "          第5层每日计划检查：\n";
                $sampleAccountIds = array_slice($capacityAccountIds, 0, 5);
                foreach ($sampleAccountIds as $sampleId) {
                    $accountInfo = DB::select("
                        SELECT a.id, a.plan_id, a.current_plan_day, a.status, a.login_status
                        FROM itunes_trade_accounts a
                        WHERE a.id = ?
                          AND a.deleted_at IS NULL
                    ", [$sampleId]);

                    if (!empty($accountInfo)) {
                        $account = $accountInfo[0];
                        echo "            - 账号#{$account->id}: plan_id={$account->plan_id}, day={$account->current_plan_day}, status={$account->status}\n";
                    }
                }
            } else {
                echo "            - 容量筛选未通过任何账号\n";
            }

            return;
        }

        echo "        📊 前5层筛选通过账号：" . count($finalAccountIds) . " 个\n";
        echo "        🎯 分析前10个候选账号的第6层处理...\n";

        // 分析前10个账号的第6层处理
        $testAccountIds = array_slice($finalAccountIds, 0, 10);

        // 获取排序优先级
        $sortMethod = $reflection->getMethod('sortAccountsByPriority');
        $sortMethod->setAccessible(true);
        $sortedAccountIds = $sortMethod->invoke($findAccountService, $testAccountIds, $plan, $testParams['room_id'], $giftCardInfo['amount']);

        echo "        📈 账号优先级排序完成，前5个账号ID：" . implode(', ', array_slice($sortedAccountIds, 0, 5)) . "\n";

        // 测试锁定前3个最优账号
        $attemptLockMethod = $reflection->getMethod('attemptLockAccount');
        $attemptLockMethod->setAccessible(true);

        $lockResults = [];
        for ($i = 0; $i < min(3, count($sortedAccountIds)); $i++) {
            $accountId = $sortedAccountIds[$i];

            echo "        🔒 尝试锁定账号#{$accountId}...\n";

            $account = $attemptLockMethod->invoke(
                $findAccountService,
                $accountId,
                $plan,
                $testParams['room_id'],
                $testParams['current_day']
            );

            $lockResults[] = [
                'account_id' => $accountId,
                'success' => $account !== null,
                'account' => $account
            ];

            if ($account) {
                echo "        ✅ 账号#{$accountId} 锁定成功\n";
                break; // 成功锁定一个账号就停止
            } else {
                echo "        ❌ 账号#{$accountId} 锁定失败\n";
            }
        }

        // 分析锁定失败的原因
        $successCount = count(array_filter($lockResults, fn($r) => $r['success']));

        if ($successCount == 0) {
            echo "        🔍 所有账号锁定失败，可能原因：\n";
            echo "          1. 账号状态已变更（不再是'processing'）\n";
            echo "          2. 并发锁定冲突\n";
            echo "          3. 数据库事务问题\n";

            // 检查第一个账号的当前状态
            if (!empty($sortedAccountIds)) {
                $firstAccountId = $sortedAccountIds[0];
                $currentStatus = DB::table('itunes_trade_accounts')
                    ->where('id', $firstAccountId)
                    ->value('status');

                echo "          📋 第一个账号#{$firstAccountId}当前状态：{$currentStatus}\n";

                if ($currentStatus !== 'processing') {
                    echo "          🎯 发现问题：账号状态不是'processing'，可能已被其他进程锁定\n";
                }
            }
        } else {
            echo "        🎉 找到了可以锁定的账号！问题可能在于兜底逻辑\n";
        }

    } catch (Exception $e) {
        echo "        ❌ 第6层分析异常：{$e->getMessage()}\n";
    }
}

echo "\n========================================\n";
echo "性能测试完成\n";
echo "========================================\n";
