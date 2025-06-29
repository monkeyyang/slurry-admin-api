<?php

require_once __DIR__ . '/vendor/autoload.php';

// 正确初始化Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';

// 启动应用
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use App\Services\Gift\GiftCardService;
use App\Services\Gift\RedeemService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

echo "开始调试 findAvailableAccount 性能...\n";
echo str_repeat("=", 80) . "\n";

try {
    // 1. 使用真实的礼品卡数据（基于你提供的日志）
    $giftCardCode = 'XMKQH9WHC362QK6H';  // 真实的礼品卡码
    $roomId = '50165570842@chatroom';     // 真实的房间ID
    $msgId = '1111111111';
    $wxId = '2222222';
    
    // 基于真实查卡结果的数据
    $giftCardInfo = [
        'amount' => 200.00,        // 真实面额 $200.00
        'country_code' => 'CA',    // 加拿大卡
        'currency' => 'USD',
        'valid' => true,
        'card_number' => '6247',   // 真实卡号后4位
        'card_type' => 1
    ];
    
    echo "测试数据:\n";
    echo "- 礼品卡码: {$giftCardCode}\n";
    echo "- 房间ID: {$roomId}\n";
    echo "- 消息ID: {$msgId}\n";
    echo "- 微信ID: {$wxId}\n";
    echo "- 礼品卡金额: \${$giftCardInfo['amount']}\n";
    echo "- 国家代码: {$giftCardInfo['country_code']}\n";
    echo "\n";
    
    // 2. 根据日志查找对应的汇率和计划
    echo "查找匹配的汇率...\n";
    
    // 根据日志，应该找到汇率ID=2的倍数要求汇率
    $rate = ItunesTradeRate::where('country_code', 'CA')
        ->where('card_type', 'fast')
        ->where('card_form', 'image')
        ->where('amount_constraint', 'multiple')
        ->where('multiple_base', 50)
        ->where('status', 'active')
        ->first();
    
    if (!$rate) {
        echo "❌ 没有找到匹配的汇率，尝试查找任意可用汇率\n";
        $rate = ItunesTradeRate::where('status', 'active')->first();
        if (!$rate) {
            echo "❌ 没有找到任何可用汇率\n";
            exit(1);
        }
    }
    
    echo "找到汇率:\n";
    echo "- 汇率ID: {$rate->id}\n";
    echo "- 汇率名称: {$rate->name}\n";
    echo "- 汇率值: {$rate->rate}\n";
    echo "- 约束类型: {$rate->amount_constraint}\n";
    if ($rate->amount_constraint === 'multiple') {
        echo "- 倍数基数: {$rate->multiple_base}\n";
        echo "- 最小金额: {$rate->min_amount}\n";
        echo "- 最大金额: {$rate->max_amount}\n";
    }
    echo "\n";
    
    // 根据汇率查找计划
    $plan = ItunesTradePlan::with('rate')->where('rate_id', $rate->id)->where('status', ItunesTradePlan::STATUS_ENABLED)->first();
    
    if (!$plan) {
        echo "❌ 没有找到汇率对应的计划，尝试查找任意可用计划\n";
        $plan = ItunesTradePlan::with('rate')->where('status', ItunesTradePlan::STATUS_ENABLED)->first();
        if (!$plan) {
            echo "❌ 没有找到任何可用计划\n";
            exit(1);
        }
    }
    
    echo "使用的计划:\n";
    echo "- 计划ID: {$plan->id}\n";
    echo "- 计划天数: {$plan->plan_days}\n";
    echo "- 总金额: {$plan->total_amount}\n";
    echo "- 浮动金额: {$plan->float_amount}\n";
    echo "- 汇率ID: {$plan->rate_id}\n";
    echo "- 绑定房间: " . ($plan->bind_room ? '是' : '否') . "\n";
    
    if ($plan->rate) {
        echo "- 汇率: {$plan->rate->rate}\n";
        echo "- 约束类型: {$plan->rate->amount_constraint}\n";
        if ($plan->rate->amount_constraint === 'multiple') {
            echo "- 倍数基数: {$plan->rate->multiple_base}\n";
        }
    }
    echo "\n";
    
    // 3. 查询候选账号数量
    $candidateQuery = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
        ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE);
    
    if ($plan->bind_room && !empty($roomId)) {
        $candidateQuery->where(function ($q) use ($plan, $roomId) {
            $q->where('plan_id', $plan->id)
              ->orWhere('room_id', $roomId)
              ->orWhereNull('plan_id');
        });
    } else {
        $candidateQuery->where(function ($q) use ($plan) {
            $q->where('plan_id', $plan->id)
              ->orWhereNull('plan_id');
        });
    }
    
    $candidateCount = $candidateQuery->count();
    echo "候选账号统计:\n";
    echo "- 符合条件的账号总数: {$candidateCount}\n";
    
    // 显示具体的查询条件
    echo "- 查询条件:\n";
    echo "  └─ 状态: processing\n";
    echo "  └─ 登录状态: valid\n";
    if ($plan->bind_room && !empty($roomId)) {
        echo "  └─ 计划绑定: 当前计划({$plan->id}) OR 当前群聊({$roomId}) OR 未绑定计划\n";
    } else {
        echo "  └─ 计划绑定: 当前计划({$plan->id}) OR 未绑定计划\n";
    }
    
    // 按不同状态分组统计
    $statusStats = ItunesTradeAccount::select('status', DB::raw('count(*) as count'))
        ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
        ->groupBy('status')
        ->get();
    
    echo "- 按状态分布:\n";
    foreach ($statusStats as $stat) {
        echo "  └─ {$stat->status}: {$stat->count} 个\n";
    }
    
    // 按计划绑定情况统计 - 修复SQL错误
    $planStats = collect([
        [
            'plan_status' => '绑定当前计划',
            'count' => ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
                ->where('plan_id', $plan->id)
                ->count()
        ],
        [
            'plan_status' => '未绑定计划', 
            'count' => ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
                ->whereNull('plan_id')
                ->count()
        ],
        [
            'plan_status' => '绑定其他计划',
            'count' => ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
                ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
                ->whereNotNull('plan_id')
                ->where('plan_id', '!=', $plan->id)
                ->count()
        ]
    ])->filter(function($item) { return $item['count'] > 0; });
    
    echo "- 按计划绑定分布:\n";
    foreach ($planStats as $stat) {
        echo "  └─ {$stat['plan_status']}: {$stat['count']} 个\n";
    }
    
    // 分析候选账号的筛选漏斗
    echo "\n候选账号筛选漏斗分析:\n";
    
    // 第1层：基础状态筛选
    $totalProcessing = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)->count();
    $totalWithValidLogin = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
        ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)->count();
    
    echo "  第1层 - 基础状态筛选:\n";
    echo "    └─ processing状态账号: {$totalProcessing} 个\n";
    echo "    └─ + 登录有效: {$totalWithValidLogin} 个 (筛选率: " . round($totalWithValidLogin/$totalProcessing*100, 1) . "%)\n";
    
    // 第2层：计划绑定筛选
    echo "  第2层 - 计划绑定筛选:\n";
    echo "    └─ + 计划绑定条件: {$candidateCount} 个 (筛选率: " . round($candidateCount/$totalWithValidLogin*100, 1) . "%)\n";
    
    // 第3层：国家匹配（如果有的话）
    $countryMatchQuery = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
        ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE);
        
    if ($plan->bind_room && !empty($roomId)) {
        $countryMatchQuery->where(function ($q) use ($plan, $roomId) {
            $q->where('plan_id', $plan->id)
              ->orWhere('room_id', $roomId)
              ->orWhereNull('plan_id');
        });
    } else {
        $countryMatchQuery->where(function ($q) use ($plan) {
            $q->where('plan_id', $plan->id)
              ->orWhereNull('plan_id');
        });
    }
    
    $countryMatchCount = $countryMatchQuery->where(function($q) use ($giftCardInfo) {
        $q->whereNull('country_code')->orWhere('country_code', $giftCardInfo['country_code']);
    })->count();
    
    echo "  第3层 - 国家匹配筛选:\n";
    echo "    └─ + 国家匹配({$giftCardInfo['country_code']}): {$countryMatchCount} 个 (筛选率: " . round($countryMatchCount/$candidateCount*100, 1) . "%)\n";
    
    // 预估第4层：容量验证（这需要复杂计算，我们给个估算）
    $estimatedCapacityMatch = round($countryMatchCount * 0.3); // 假设30%通过容量验证
    echo "  第4层 - 容量验证筛选 (估算):\n";
    echo "    └─ + 容量验证: ~{$estimatedCapacityMatch} 个 (预估筛选率: 30%)\n";
    
    echo "\n⚠️  性能瓶颈分析:\n";
    echo "  - 当前需要对 {$candidateCount} 个账号进行复杂排序\n";
    echo "  - 排序过程中每个账号都需要:\n";
    echo "    └─ 查询每日兑换金额 (数据库查询)\n";
    echo "    └─ 计算容量类型 (复杂业务逻辑)\n";
    echo "    └─ 多层优先级比较 (6个比较维度)\n";
    echo "  - 总计算量: {$candidateCount} × 6层比较 × log({$candidateCount}) ≈ " . round($candidateCount * 6 * log($candidateCount, 2)) . " 次操作\n";
    echo "\n";
    
    // 4. 创建GiftCardService实例并设置参数
    $giftCardService = new GiftCardService(app(\App\Services\GiftCardExchangeService::class));
    $giftCardService->setGiftCardCode($giftCardCode)
        ->setRoomId($roomId)
        ->setCardType('fast')    // 根据日志使用fast类型
        ->setCardForm('image')   // 根据日志使用image形式
        ->setBatchId('test_batch_' . time())
        ->setMsgId($msgId)
        ->setWxId($wxId);
    
    // 5. 使用反射调用私有方法进行性能测试
    $reflection = new ReflectionClass($giftCardService);
    $findAvailableAccountMethod = $reflection->getMethod('findAvailableAccount');
    $findAvailableAccountMethod->setAccessible(true);
    
    echo "开始性能测试...\n";
    echo str_repeat("-", 80) . "\n";
    
    // 执行多次测试
    $testCount = 3;
    $results = [];
    
    for ($i = 1; $i <= $testCount; $i++) {
        echo "第 {$i} 次测试:\n";
        
        $startTime = microtime(true);
        $startMemory = memory_get_usage();
        
        try {
            $account = $findAvailableAccountMethod->invoke(
                $giftCardService, 
                $plan, 
                $roomId, 
                $giftCardInfo
            );
            
            $endTime = microtime(true);
            $endMemory = memory_get_usage();
            
            $executionTime = ($endTime - $startTime) * 1000; // 转换为毫秒
            $memoryUsage = $endMemory - $startMemory;
            
            $results[] = [
                'success' => true,
                'time_ms' => $executionTime,
                'memory_bytes' => $memoryUsage,
                'account_id' => $account ? $account->id : null,
                'account_email' => $account ? $account->account : null
            ];
            
            echo "  ✅ 成功找到账号\n";
            echo "  └─ 账号ID: {$account->id}\n";
            echo "  └─ 账号邮箱: {$account->account}\n";
            echo "  └─ 账号余额: \${$account->amount}\n";
            echo "  └─ 当前计划: " . ($account->plan_id ?: '未绑定') . "\n";
            echo "  └─ 当前天数: " . ($account->current_plan_day ?: '未设置') . "\n";
            echo "  └─ 执行时间: " . number_format($executionTime, 2) . " ms\n";
            echo "  └─ 内存使用: " . number_format($memoryUsage / 1024, 2) . " KB\n";
            
        } catch (Exception $e) {
            $endTime = microtime(true);
            $endMemory = memory_get_usage();
            
            $executionTime = ($endTime - $startTime) * 1000;
            $memoryUsage = $endMemory - $startMemory;
            
            $results[] = [
                'success' => false,
                'time_ms' => $executionTime,
                'memory_bytes' => $memoryUsage,
                'error' => $e->getMessage()
            ];
            
            echo "  ❌ 查找失败\n";
            echo "  └─ 错误信息: {$e->getMessage()}\n";
            echo "  └─ 执行时间: " . number_format($executionTime, 2) . " ms\n";
            echo "  └─ 内存使用: " . number_format($memoryUsage / 1024, 2) . " KB\n";
        }
        
        echo "\n";
        
        // 避免缓存影响，稍微等待
        if ($i < $testCount) {
            usleep(100000); // 100ms
        }
    }
    
    // 6. 性能统计分析
    echo str_repeat("=", 80) . "\n";
    echo "性能统计分析:\n";
    
    $successfulResults = array_filter($results, function($r) { return $r['success']; });
    $failedResults = array_filter($results, function($r) { return !$r['success']; });
    
    echo "- 成功次数: " . count($successfulResults) . " / {$testCount}\n";
    echo "- 失败次数: " . count($failedResults) . " / {$testCount}\n";
    
    if (!empty($successfulResults)) {
        $times = array_column($successfulResults, 'time_ms');
        $memories = array_column($successfulResults, 'memory_bytes');
        
        echo "- 执行时间统计:\n";
        echo "  └─ 平均: " . number_format(array_sum($times) / count($times), 2) . " ms\n";
        echo "  └─ 最小: " . number_format(min($times), 2) . " ms\n";
        echo "  └─ 最大: " . number_format(max($times), 2) . " ms\n";
        
        echo "- 内存使用统计:\n";
        echo "  └─ 平均: " . number_format(array_sum($memories) / count($memories) / 1024, 2) . " KB\n";
        echo "  └─ 最小: " . number_format(min($memories) / 1024, 2) . " KB\n";
        echo "  └─ 最大: " . number_format(max($memories) / 1024, 2) . " KB\n";
        
        // 性能评估
        $avgTime = array_sum($times) / count($times);
        echo "\n性能评估:\n";
        if ($avgTime < 100) {
            echo "✅ 性能优秀 (< 100ms)\n";
        } elseif ($avgTime < 500) {
            echo "⚠️  性能一般 (100-500ms)\n";
        } elseif ($avgTime < 1000) {
            echo "⚠️  性能较慢 (500ms-1s)\n";
        } else {
            echo "❌ 性能很慢 (> 1s)\n";
        }
        
        if ($avgTime > 1000) {
            echo "\n🔧 详细优化建议:\n";
            echo "\n1. 【排序算法优化】(预计提升80-90%)\n";
            echo "   - 当前问题: 791个账号排序耗时2.5秒\n";
            echo "   - 解决方案: 使用预计算排序键值 + PHP原生usort\n";
            echo "   - 预期效果: 2500ms → 100ms\n";
            echo "   - 实施位置: GiftCardService::sortAccountsByPriority方法\n";
            
            echo "\n2. 【数据库查询优化】(预计提升50-70%)\n";
            echo "   - 当前问题: 每次排序都查询每日兑换金额\n";
            echo "   - 解决方案: 批量预查询所有账号的每日数据\n";
            echo "   - 预期效果: 减少数据库查询次数从791次到1次\n";
            echo "   - 实施位置: GiftCardService::batchGetDailySpentAmounts方法\n";
            
            echo "\n3. 【候选账号预过滤】(预计提升30-50%)\n";
            echo "   - 当前问题: 791个候选账号都需要排序验证\n";
            echo "   - 解决方案: 在SQL查询阶段就过滤不符合条件的账号\n";
            echo "   - 预期效果: 候选账号从791个减少到200-300个\n";
            echo "   - 实施位置: GiftCardService::getAllCandidateAccounts方法\n";
            
            echo "\n4. 【数据库索引优化】\n";
            echo "   - 建议添加复合索引:\n";
            echo "     └─ (status, login_status, plan_id)\n";
            echo "     └─ (status, login_status, room_id)\n";
            echo "     └─ (account_id, day, status) for logs表\n";
            
            echo "\n5. 【分层验证优化】\n";
            echo "   - 按容量类型分层: 能充满 → 可预留 → 不适合\n";
            echo "   - 优先验证最有希望的账号\n";
            echo "   - 找到合适账号后立即返回，避免全量排序\n";
        }
    }
    
    // 7. 详细的排序性能测试（如果需要）
    if (!empty($successfulResults) && $candidateCount > 100) {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "排序性能详细测试:\n";
        
        try {
            // 获取候选账号进行排序测试
            $candidateAccounts = $candidateQuery->limit(min($candidateCount, 500))->get(); // 限制到500个避免内存问题
            
            if ($candidateAccounts->count() > 0) {
                echo "准备测试 {$candidateAccounts->count()} 个候选账号的排序性能...\n";
                
                // 直接测试GiftCardService中的排序方法性能
                $giftCardService = new GiftCardService(app(\App\Services\GiftCardExchangeService::class));
                
                // 使用反射获取私有方法进行测试
                $reflection = new ReflectionClass($giftCardService);
                $sortMethod = $reflection->getMethod('sortAccountsByPriority');
                $sortMethod->setAccessible(true);
                
                echo "\n测试当前排序算法性能:\n";
                
                // 测试3次取平均值
                $times = [];
                for ($i = 1; $i <= 3; $i++) {
                    $startTime = microtime(true);
                    
                    $sortedAccounts = $sortMethod->invoke(
                        $giftCardService,
                        $candidateAccounts,
                        $plan,
                        $roomId,
                        $giftCardInfo
                    );
                    
                    $endTime = microtime(true);
                    $executionTime = ($endTime - $startTime) * 1000;
                    $times[] = $executionTime;
                    
                    echo "- 第{$i}次排序: " . number_format($executionTime, 2) . " ms ({$sortedAccounts->count()} 个账号)\n";
                }
                
                $avgTime = array_sum($times) / count($times);
                $minTime = min($times);
                $maxTime = max($times);
                
                echo "\n📊 排序性能统计:\n";
                echo "- 平均时间: " . number_format($avgTime, 2) . " ms\n";
                echo "- 最快时间: " . number_format($minTime, 2) . " ms\n";
                echo "- 最慢时间: " . number_format($maxTime, 2) . " ms\n";
                echo "- 账号数量: {$candidateAccounts->count()}\n";
                echo "- 每个账号平均耗时: " . number_format($avgTime / $candidateAccounts->count(), 3) . " ms\n";
                
                // 性能评估
                echo "\n🔍 性能评估:\n";
                if ($avgTime < 50) {
                    echo "✅ 排序性能优秀 (< 50ms)\n";
                } elseif ($avgTime < 200) {
                    echo "⚠️  排序性能一般 (50-200ms)\n";
                } elseif ($avgTime < 1000) {
                    echo "⚠️  排序性能较慢 (200ms-1s)\n";
                } else {
                    echo "❌ 排序性能很慢 (> 1s)\n";
                    echo "\n💡 优化建议:\n";
                    echo "1. 考虑在数据库查询阶段预过滤账号\n";
                    echo "2. 使用预计算排序键值减少比较复杂度\n";
                    echo "3. 批量查询每日兑换数据避免N+1问题\n";
                    echo "4. 考虑按容量类型分层，优先处理最合适的账号\n";
                }
            }
        } catch (Exception $sortingException) {
            echo "❌ 排序性能测试失败: " . $sortingException->getMessage() . "\n";
            echo "   继续其他测试...\n";
        }
    }
    
    echo "\n" . str_repeat("=", 80) . "\n";
    echo "调试测试完成！\n";
    
} catch (Exception $e) {
    echo "❌ 测试过程中发生错误: " . $e->getMessage() . "\n";
    echo "错误堆栈:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 