<?php

require_once __DIR__ . '/vendor/autoload.php';

// 正确初始化Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';

// 启动应用
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeAccountLog;
use Illuminate\Support\Facades\DB;

echo "极简高效账号查找逻辑测试...\n";
echo str_repeat("=", 80) . "\n";

/**
 * 极简版账号查找逻辑
 * 核心思想：
 * 1. 最少的数据库查询
 * 2. 最简单的验证逻辑
 * 3. 最快的早期退出
 */
function findAvailableAccountFast($plan, $roomId, $giftCardInfo) {
    $startTime = microtime(true);

    echo "开始极简账号查找...\n";
    echo "- 计划ID: {$plan->id}\n";
    echo "- 礼品卡金额: \${$giftCardInfo['amount']}\n";
    echo "- 计划总额度: \${$plan->total_amount}\n";
    echo "- 计划浮动额度: \${$plan->float_amount}\n";
    echo "\n";

    // 第1步：获取当天的计划额度
    $dailyAmounts = $plan->daily_amounts ?? [];
    $dailyLimit = $dailyAmounts[0] ?? 0; // 假设是第1天
    $dailyTarget = $dailyLimit + $plan->float_amount;

    echo "每日目标计算:\n";
    echo "- 第1天基础额度: \${$dailyLimit}\n";
    echo "- 浮动额度: \${$plan->float_amount}\n";
    echo "- 第1天总目标: \${$dailyTarget}\n";
    echo "\n";

    // 第2步：使用最优化的SQL查询，一次性获取最合适的账号
    echo "执行优化SQL查询...\n";
    $queryStartTime = microtime(true);

    $sql = "
        SELECT a.*,
               COALESCE(SUM(l.amount), 0) as daily_spent
        FROM itunes_trade_accounts a
        LEFT JOIN itunes_trade_account_logs l ON (
            a.id = l.account_id
            AND l.day = 1
            AND l.status = 'success'
        )
        WHERE a.status = 'processing'
          AND a.login_status = 'valid'
          AND a.amount > 0
          AND a.amount < {$plan->total_amount}
          AND (
              (a.plan_id = {$plan->id}) OR
              (a.room_id = '{$roomId}') OR
              (a.plan_id IS NULL)
          )
        GROUP BY a.id
        HAVING (a.amount + {$giftCardInfo['amount']}) <= {$plan->total_amount}
           AND (COALESCE(SUM(l.amount), 0) + {$giftCardInfo['amount']}) <= {$dailyTarget}
        ORDER BY
            CASE
                WHEN a.plan_id = {$plan->id} AND a.room_id = '{$roomId}' THEN 1
                WHEN a.plan_id = {$plan->id} THEN 2
                WHEN a.room_id = '{$roomId}' THEN 3
                WHEN a.plan_id IS NULL THEN 4
                ELSE 5
            END,
            a.amount DESC,
            a.id ASC
        LIMIT 1
    ";

    $result = DB::select($sql);
    $queryEndTime = microtime(true);
    $queryTime = ($queryEndTime - $queryStartTime) * 1000;

    echo "SQL查询完成: " . number_format($queryTime, 2) . " ms\n";

    if (empty($result)) {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;
        echo "❌ 未找到合适的账号\n";
        echo "总耗时: " . number_format($totalTime, 2) . " ms\n";
        return null;
    }

    $accountData = $result[0];
    echo "找到候选账号:\n";
    echo "- 账号ID: {$accountData->id}\n";
    echo "- 账号邮箱: {$accountData->account}\n";
    echo "- 账号余额: \${$accountData->amount}\n";
    echo "- 当日已兑换: \${$accountData->daily_spent}\n";
    echo "- 计划ID: " . ($accountData->plan_id ?: '未绑定') . "\n";
    echo "- 房间ID: " . ($accountData->room_id ?: '未绑定') . "\n";
    echo "\n";

    // 第3步：验证账号（最简单的验证）
    echo "验证账号条件...\n";
    $validationStartTime = microtime(true);

    // 验证1：总额度检查
    $totalAfterExchange = $accountData->amount + $giftCardInfo['amount'];
    $totalValid = $totalAfterExchange <= $plan->total_amount;
    echo "- 总额度检查: " . ($totalValid ? '✅ 通过' : '❌ 失败') . " ({$totalAfterExchange} <= {$plan->total_amount})\n";

    // 验证2：当日额度检查
    $dailyAfterExchange = $accountData->daily_spent + $giftCardInfo['amount'];
    $dailyValid = $dailyAfterExchange <= $dailyTarget;
    echo "- 当日额度检查: " . ($dailyValid ? '✅ 通过' : '❌ 失败') . " ({$dailyAfterExchange} <= {$dailyTarget})\n";

    // 验证3：约束检查（根据汇率约束）
    $constraintValid = true;
    if ($plan->rate) {
        $rate = $plan->rate;
        if ($rate->amount_constraint === 'multiple' && $rate->multiple_base > 0) {
            $constraintValid = ($giftCardInfo['amount'] % $rate->multiple_base == 0) &&
                              ($giftCardInfo['amount'] >= ($rate->min_amount ?? 0));
            echo "- 倍数约束检查: " . ($constraintValid ? '✅ 通过' : '❌ 失败') . " (倍数: {$rate->multiple_base})\n";
        } elseif ($rate->amount_constraint === 'fixed') {
            $fixedAmounts = $rate->fixed_amounts ?? [];
            if (is_string($fixedAmounts)) {
                $fixedAmounts = json_decode($fixedAmounts, true) ?: [];
            }
            $constraintValid = in_array($giftCardInfo['amount'], $fixedAmounts);
            echo "- 固定面额检查: " . ($constraintValid ? '✅ 通过' : '❌ 失败') . "\n";
        } else {
            echo "- 约束检查: ✅ 全面额，无限制\n";
        }
    }

    $validationEndTime = microtime(true);
    $validationTime = ($validationEndTime - $validationStartTime) * 1000;
    echo "验证完成: " . number_format($validationTime, 2) . " ms\n";

    if (!$totalValid || !$dailyValid || !$constraintValid) {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;
        echo "❌ 账号验证失败\n";
        echo "总耗时: " . number_format($totalTime, 2) . " ms\n";
        return null;
    }

    // 第4步：尝试锁定账号
    echo "尝试锁定账号...\n";
    $lockStartTime = microtime(true);

    $lockResult = DB::table('itunes_trade_accounts')
        ->where('id', $accountData->id)
        ->where('status', 'processing')
        ->update([
            'status' => 'locking',
            'plan_id' => $plan->id,
            'room_id' => $roomId,
            'current_plan_day' => 1,
            'updated_at' => now()
        ]);

    $lockEndTime = microtime(true);
    $lockTime = ($lockEndTime - $lockStartTime) * 1000;
    echo "锁定操作: " . number_format($lockTime, 2) . " ms\n";

    if ($lockResult > 0) {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        echo "✅ 账号锁定成功\n";
        echo "总耗时: " . number_format($totalTime, 2) . " ms\n";
        echo "\n";
        echo "时间分解:\n";
        echo "- SQL查询: " . number_format($queryTime, 2) . " ms (" . round($queryTime/$totalTime*100, 1) . "%)\n";
        echo "- 验证逻辑: " . number_format($validationTime, 2) . " ms (" . round($validationTime/$totalTime*100, 1) . "%)\n";
        echo "- 锁定操作: " . number_format($lockTime, 2) . " ms (" . round($lockTime/$totalTime*100, 1) . "%)\n";
        echo "- 其他开销: " . number_format($totalTime - $queryTime - $validationTime - $lockTime, 2) . " ms\n";

        // 返回账号对象
        return ItunesTradeAccount::find($accountData->id);
    } else {
        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;
        echo "❌ 账号锁定失败（可能被其他进程占用）\n";
        echo "总耗时: " . number_format($totalTime, 2) . " ms\n";
        return null;
    }
}

try {
    // 1. 测试数据
    $giftCardInfo = [
        'amount' => 500.00,
        'country_code' => 'CA',
        'currency' => 'USD',
        'valid' => true
    ];

    $roomId = '50165570842@chatroom';

    // 2. 获取计划
    $plan = ItunesTradePlan::with('rate')->where('status', ItunesTradePlan::STATUS_ENABLED)->first();
    if (!$plan) {
        echo "❌ 没有找到可用计划\n";
        exit(1);
    }

    echo "测试配置:\n";
    echo "- 计划ID: {$plan->id}\n";
    echo "- 房间ID: {$roomId}\n";
    echo "- 礼品卡金额: \${$giftCardInfo['amount']}\n";
    echo "- 国家代码: {$giftCardInfo['country_code']}\n";
    echo "\n";

    // 3. 执行多次测试
    echo str_repeat("=", 80) . "\n";
    echo "开始极简账号查找性能测试 (5次测试):\n";
    echo str_repeat("-", 60) . "\n";

    $results = [];
    $testCount = 5;

    for ($i = 1; $i <= $testCount; $i++) {
        echo "第 {$i} 次测试:\n";
        echo str_repeat("-", 40) . "\n";

        $testStartTime = microtime(true);

        try {
            $account = findAvailableAccountFast($plan, $roomId, $giftCardInfo);

            $testEndTime = microtime(true);
            $testTime = ($testEndTime - $testStartTime) * 1000;

            if ($account) {
                $results[] = [
                    'success' => true,
                    'time_ms' => $testTime,
                    'account_id' => $account->id,
                    'account_email' => $account->account
                ];

                echo "🎉 第{$i}次测试成功: " . number_format($testTime, 2) . " ms\n";

                // 恢复账号状态以便下次测试
                DB::table('itunes_trade_accounts')
                    ->where('id', $account->id)
                    ->update(['status' => 'processing']);

            } else {
                $results[] = [
                    'success' => false,
                    'time_ms' => $testTime,
                    'error' => '未找到合适账号'
                ];
                echo "❌ 第{$i}次测试失败: " . number_format($testTime, 2) . " ms\n";
            }

        } catch (Exception $e) {
            $testEndTime = microtime(true);
            $testTime = ($testEndTime - $testStartTime) * 1000;

            $results[] = [
                'success' => false,
                'time_ms' => $testTime,
                'error' => $e->getMessage()
            ];

            echo "❌ 第{$i}次测试异常: " . $e->getMessage() . " (" . number_format($testTime, 2) . " ms)\n";
        }

        echo "\n";

        // 短暂等待避免并发问题
        if ($i < $testCount) {
            usleep(200000); // 200ms
        }
    }

    // 4. 性能统计
    echo str_repeat("=", 80) . "\n";
    echo "极简账号查找性能统计:\n";

    $successfulResults = array_filter($results, function($r) { return $r['success']; });
    $failedResults = array_filter($results, function($r) { return !$r['success']; });

    echo "- 成功次数: " . count($successfulResults) . " / {$testCount}\n";
    echo "- 失败次数: " . count($failedResults) . " / {$testCount}\n";

    if (!empty($successfulResults)) {
        $times = array_column($successfulResults, 'time_ms');

        $avgTime = array_sum($times) / count($times);
        $minTime = min($times);
        $maxTime = max($times);

        echo "\n⚡ 执行时间统计:\n";
        echo "- 平均时间: " . number_format($avgTime, 2) . " ms\n";
        echo "- 最快时间: " . number_format($minTime, 2) . " ms\n";
        echo "- 最慢时间: " . number_format($maxTime, 2) . " ms\n";
        echo "- 时间范围: " . number_format($maxTime - $minTime, 2) . " ms\n";

        // 与原版本对比
        $originalAvgTime = 2898.99; // 之前测试的结果
        if ($avgTime < $originalAvgTime) {
            $improvement = round(($originalAvgTime - $avgTime) / $originalAvgTime * 100, 1);
            $timeSaved = round($originalAvgTime - $avgTime, 2);

            echo "\n🚀 性能提升对比:\n";
            echo "- 原版本平均时间: " . number_format($originalAvgTime, 2) . " ms\n";
            echo "- 极简版平均时间: " . number_format($avgTime, 2) . " ms\n";
            echo "- 性能提升: {$improvement}%\n";
            echo "- 时间节省: {$timeSaved} ms\n";

            if ($improvement > 90) {
                echo "🎉 极致优化成功！\n";
            } elseif ($improvement > 80) {
                echo "🔥 优化效果显著！\n";
            } elseif ($improvement > 50) {
                echo "👍 优化效果良好！\n";
            } else {
                echo "✨ 有一定优化效果\n";
            }
        }

        // 性能等级评估
        echo "\n📊 性能等级评估:\n";
        if ($avgTime < 10) {
            echo "🏆 性能等级: S+ (极致优化)\n";
        } elseif ($avgTime < 50) {
            echo "🥇 性能等级: S (优秀)\n";
        } elseif ($avgTime < 100) {
            echo "🥈 性能等级: A (良好)\n";
        } elseif ($avgTime < 500) {
            echo "🥉 性能等级: B (一般)\n";
        } else {
            echo "📉 性能等级: C (需要优化)\n";
        }
    }

    if (!empty($failedResults)) {
        echo "\n❌ 失败原因统计:\n";
        $errorCounts = [];
        foreach ($failedResults as $result) {
            $error = $result['error'];
            $errorCounts[$error] = ($errorCounts[$error] ?? 0) + 1;
        }

        foreach ($errorCounts as $error => $count) {
            echo "- {$error}: {$count} 次\n";
        }
    }

    echo "\n" . str_repeat("=", 80) . "\n";
    echo "极简账号查找测试完成！\n";

} catch (Exception $e) {
    echo "❌ 测试过程中发生错误: " . $e->getMessage() . "\n";
    echo "错误堆栈:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
