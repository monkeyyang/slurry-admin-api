<?php

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use Illuminate\Support\Facades\DB;

// 加载Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== 修复账号天数倒退问题脚本 ===\n";
echo "开始时间: " . date('Y-m-d H:i:s') . "\n\n";

try {
    // 第一步：查找有问题的账号
    echo "第一步：查找有问题的账号...\n";
    
    $problemAccounts = DB::select("
        SELECT 
            a.id,
            a.account,
            a.current_plan_day as current_day,
            MAX(l.day) as max_log_day,
            a.plan_id
        FROM itunes_trade_accounts a
        JOIN itunes_trade_account_logs l ON a.id = l.account_id
        WHERE a.status = 'processing'
            AND l.status = 'success'
            AND a.current_plan_day IS NOT NULL
            AND a.current_plan_day > 0
        GROUP BY a.id, a.account, a.current_plan_day, a.plan_id
        HAVING MAX(l.day) > a.current_plan_day
        ORDER BY a.id
    ");
    
    if (empty($problemAccounts)) {
        echo "✅ 没有找到需要修复的账号！\n";
        exit(0);
    }
    
    echo "找到 " . count($problemAccounts) . " 个需要修复的账号：\n";
    echo "ID\t账号\t\t\t\t当前天数\t日志最大天数\n";
    echo str_repeat("-", 80) . "\n";
    
    foreach ($problemAccounts as $account) {
        echo sprintf("%d\t%-32s\t%d\t\t%d\n", 
            $account->id, 
            substr($account->account, 0, 30), 
            $account->current_day, 
            $account->max_log_day
        );
    }
    
    echo "\n";
    
    // 第二步：询问是否继续修复
    echo "是否继续修复这些账号？(y/N): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (strtolower(trim($line)) !== 'y') {
        echo "❌ 已取消修复操作\n";
        exit(0);
    }
    
    // 第三步：开始修复
    echo "\n第二步：开始修复账号...\n";
    
    $fixedCount = 0;
    $failedCount = 0;
    
    DB::beginTransaction();
    
    try {
        foreach ($problemAccounts as $problemAccount) {
            // 获取账号详细信息
            $account = ItunesTradeAccount::find($problemAccount->id);
            
            if (!$account) {
                echo "❌ 账号 ID {$problemAccount->id} 不存在，跳过\n";
                $failedCount++;
                continue;
            }
            
            // 获取该账号日志中的最大天数
            $maxLogDay = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->max('day');
            
            if (!$maxLogDay) {
                echo "❌ 账号 {$account->account} 没有成功的日志记录，跳过\n";
                $failedCount++;
                continue;
            }
            
            // 检查该天是否已完成计划
            $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
                ->where('day', $maxLogDay)
                ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
                ->sum('amount');
            
            $newCurrentDay = $maxLogDay;
            
            // 如果账号有计划，检查是否应该进入下一天
            if ($account->plan && $account->plan->daily_amounts) {
                $dailyAmounts = $account->plan->daily_amounts;
                $dayLimit = $dailyAmounts[$maxLogDay - 1] ?? 0;
                
                // 如果该天已完成计划，设置为下一天
                if ($dailyAmount >= $dayLimit && $maxLogDay < $account->plan->plan_days) {
                    $newCurrentDay = $maxLogDay + 1;
                    echo "📈 账号 {$account->account}: 第{$maxLogDay}天已完成(${dailyAmount}/${dayLimit})，设置为第{$newCurrentDay}天\n";
                } else {
                    echo "📊 账号 {$account->account}: 第{$maxLogDay}天未完成(${dailyAmount}/${dayLimit})，保持第{$maxLogDay}天\n";
                }
            } else {
                echo "📝 账号 {$account->account}: 无计划或计划配置异常，设置为第{$maxLogDay}天\n";
            }
            
            // 更新账号
            $oldCurrentDay = $account->current_plan_day;
            $account->timestamps = false;
            $account->update(['current_plan_day' => $newCurrentDay]);
            $account->timestamps = true;
            
            echo "✅ 修复成功: {$account->account} ({$oldCurrentDay} -> {$newCurrentDay})\n";
            $fixedCount++;
        }
        
        DB::commit();
        
    } catch (\Exception $e) {
        DB::rollBack();
        throw $e;
    }
    
    echo "\n第三步：修复完成！\n";
    echo "✅ 成功修复: {$fixedCount} 个账号\n";
    echo "❌ 修复失败: {$failedCount} 个账号\n";
    
    // 第四步：验证修复结果
    echo "\n第四步：验证修复结果...\n";
    
    $remainingProblems = DB::select("
        SELECT 
            a.id,
            a.account,
            a.current_plan_day as current_day,
            MAX(l.day) as max_log_day
        FROM itunes_trade_accounts a
        JOIN itunes_trade_account_logs l ON a.id = l.account_id
        WHERE a.status = 'processing'
            AND l.status = 'success'
            AND a.current_plan_day IS NOT NULL
            AND a.current_plan_day > 0
        GROUP BY a.id, a.account, a.current_plan_day
        HAVING MAX(l.day) > a.current_plan_day
        ORDER BY a.id
    ");
    
    if (empty($remainingProblems)) {
        echo "✅ 验证通过：所有账号的天数已正确！\n";
    } else {
        echo "⚠️  仍有 " . count($remainingProblems) . " 个账号存在问题：\n";
        foreach ($remainingProblems as $problem) {
            echo "- {$problem->account}: current_day={$problem->current_day}, max_log_day={$problem->max_log_day}\n";
        }
    }
    
} catch (\Exception $e) {
    echo "❌ 发生错误: " . $e->getMessage() . "\n";
    echo "错误详情: " . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n结束时间: " . date('Y-m-d H:i:s') . "\n";
echo "=== 脚本执行完成 ===\n"; 