<?php
/**
 * 智能账号选择逻辑测试脚本
 * 
 * 测试不同场景下的账号选择逻辑
 */

// 模拟测试数据
class TestData {
    
    /**
     * 测试场景1：能充满计划额度
     */
    public static function testScenario1() {
        echo "=== 测试场景1：能充满计划额度 ===\n";
        
        $cardAmount = 500;           // 礼品卡金额
        $dailyLimit = 1000;          // 每日计划额度
        $dailySpent = 300;           // 已使用额度
        $floatAmount = 0;            // 浮动额度
        $multipleBase = 50;          // 倍数要求
        
        $remainingDailyAmount = $dailyLimit + $floatAmount - $dailySpent; // 700
        
        echo "礼品卡金额: {$cardAmount}\n";
        echo "每日计划额度: {$dailyLimit}\n";
        echo "已使用额度: {$dailySpent}\n";
        echo "剩余需要额度: {$remainingDailyAmount}\n";
        echo "倍数要求: {$multipleBase}\n";
        
        // 判断逻辑
        if ($cardAmount <= $remainingDailyAmount) {
            echo "✓ 结果: 能够充满计划额度，选择该账号\n";
            echo "使用后剩余额度: " . ($remainingDailyAmount - $cardAmount) . "\n";
            return 1; // 优先级1
        }
        
        echo "✗ 结果: 不能充满计划额度\n";
        return 3;
    }
    
    /**
     * 测试场景2：可以预留倍数额度
     */
    public static function testScenario2() {
        echo "\n=== 测试场景2：可以预留倍数额度 ===\n";
        
        $cardAmount = 500;           // 礼品卡金额
        $dailyLimit = 1000;          // 每日计划额度
        $dailySpent = 600;           // 已使用额度
        $floatAmount = 0;            // 浮动额度
        $multipleBase = 50;          // 倍数要求
        
        $remainingDailyAmount = $dailyLimit + $floatAmount - $dailySpent; // 400
        
        echo "礼品卡金额: {$cardAmount}\n";
        echo "每日计划额度: {$dailyLimit}\n";
        echo "已使用额度: {$dailySpent}\n";
        echo "剩余需要额度: {$remainingDailyAmount}\n";
        echo "倍数要求: {$multipleBase}\n";
        
        // 判断逻辑
        if ($cardAmount <= $remainingDailyAmount) {
            echo "✓ 结果: 能够充满计划额度\n";
            return 1;
        }
        
        if ($multipleBase > 0) {
            $remainingAfterUse = $cardAmount - $remainingDailyAmount; // 100
            echo "使用后剩余金额: {$remainingAfterUse}\n";
            
            if ($remainingAfterUse >= $multipleBase) {
                $modResult = $remainingAfterUse % $multipleBase;
                echo "倍数验证: {$remainingAfterUse} % {$multipleBase} = {$modResult}\n";
                
                if ($modResult == 0) {
                    echo "✓ 结果: 可以预留倍数额度，选择该账号\n";
                    echo "预留倍数: " . ($remainingAfterUse / $multipleBase) . " 倍\n";
                    return 2; // 优先级2
                } else {
                    echo "✗ 结果: 剩余金额不是倍数的整数倍\n";
                }
            } else {
                echo "✗ 结果: 剩余金额不足最小倍数要求\n";
            }
        }
        
        return 3;
    }
    
    /**
     * 测试场景3：不合适需要换账号
     */
    public static function testScenario3() {
        echo "\n=== 测试场景3：不合适需要换账号 ===\n";
        
        $cardAmount = 500;           // 礼品卡金额
        $dailyLimit = 1000;          // 每日计划额度
        $dailySpent = 800;           // 已使用额度
        $floatAmount = 0;            // 浮动额度
        $multipleBase = 50;          // 倍数要求
        
        $remainingDailyAmount = $dailyLimit + $floatAmount - $dailySpent; // 200
        
        echo "礼品卡金额: {$cardAmount}\n";
        echo "每日计划额度: {$dailyLimit}\n";
        echo "已使用额度: {$dailySpent}\n";
        echo "剩余需要额度: {$remainingDailyAmount}\n";
        echo "倍数要求: {$multipleBase}\n";
        
        // 判断逻辑
        if ($cardAmount <= $remainingDailyAmount) {
            echo "✓ 结果: 能够充满计划额度\n";
            return 1;
        }
        
        if ($multipleBase > 0) {
            $remainingAfterUse = $cardAmount - $remainingDailyAmount; // 300
            echo "使用后剩余金额: {$remainingAfterUse}\n";
            
            if ($remainingAfterUse >= $multipleBase) {
                $modResult = $remainingAfterUse % $multipleBase;
                echo "倍数验证: {$remainingAfterUse} % {$multipleBase} = {$modResult}\n";
                
                if ($modResult == 0) {
                    echo "✓ 结果: 可以预留倍数额度\n";
                    return 2;
                } else {
                    echo "✗ 结果: 剩余金额不是倍数的整数倍，需要换账号\n";
                }
            } else {
                echo "✗ 结果: 剩余金额不足最小倍数要求\n";
            }
        }
        
        echo "最终结果: 该账号不合适，需要换其他账号\n";
        return 3;
    }
    
    /**
     * 测试场景4：包含浮动额度的情况
     */
    public static function testScenario4() {
        echo "\n=== 测试场景4：包含浮动额度的情况 ===\n";
        
        $cardAmount = 550;           // 礼品卡金额
        $dailyLimit = 1000;          // 每日计划额度
        $dailySpent = 500;           // 已使用额度
        $floatAmount = 100;          // 浮动额度
        $multipleBase = 50;          // 倍数要求
        
        $remainingDailyAmount = $dailyLimit + $floatAmount - $dailySpent; // 600
        
        echo "礼品卡金额: {$cardAmount}\n";
        echo "每日计划额度: {$dailyLimit}\n";
        echo "浮动额度: {$floatAmount}\n";
        echo "已使用额度: {$dailySpent}\n";
        echo "剩余需要额度（含浮动）: {$remainingDailyAmount}\n";
        echo "倍数要求: {$multipleBase}\n";
        
        // 判断逻辑
        if ($cardAmount <= $remainingDailyAmount) {
            echo "✓ 结果: 能够充满计划额度（包含浮动额度），选择该账号\n";
            echo "使用后剩余额度: " . ($remainingDailyAmount - $cardAmount) . "\n";
            return 1;
        }
        
        // 其他逻辑...
        return 3;
    }
    
    /**
     * 测试场景5：固定面额约束 - 可以预留
     */
    public static function testScenario5() {
        echo "\n=== 测试场景5：固定面额约束 - 可以预留 ===\n";
        
        $cardAmount = 200;           // 礼品卡金额
        $dailyLimit = 1000;          // 每日计划额度
        $dailySpent = 850;           // 已使用额度
        $floatAmount = 0;            // 浮动额度
        $fixedAmounts = [50, 100, 150]; // 固定面额要求
        
        $remainingDailyAmount = $dailyLimit + $floatAmount - $dailySpent; // 150
        
        echo "礼品卡金额: {$cardAmount}\n";
        echo "每日计划额度: {$dailyLimit}\n";
        echo "已使用额度: {$dailySpent}\n";
        echo "剩余需要额度: {$remainingDailyAmount}\n";
        echo "固定面额要求: " . implode(', ', $fixedAmounts) . "\n";
        
        // 判断逻辑
        if ($cardAmount <= $remainingDailyAmount) {
            echo "✓ 结果: 能够充满计划额度\n";
            return 1;
        }
        
        // 检查固定面额约束
        $remainingAfterUse = $cardAmount - $remainingDailyAmount; // 50
        echo "使用后剩余金额: {$remainingAfterUse}\n";
        
        $isMatched = false;
        $matchedAmount = null;
        
        foreach ($fixedAmounts as $fixedAmount) {
            if (abs($remainingAfterUse - $fixedAmount) < 0.01) {
                $isMatched = true;
                $matchedAmount = $fixedAmount;
                break;
            }
        }
        
        if ($isMatched) {
            echo "✓ 结果: 可以预留固定面额，选择该账号\n";
            echo "匹配的固定面额: {$matchedAmount}\n";
            return 2; // 优先级2
        } else {
            echo "✗ 结果: 剩余金额不匹配任何固定面额\n";
        }
        
        return 3;
    }
    
    /**
     * 测试场景6：固定面额约束 - 不匹配
     */
    public static function testScenario6() {
        echo "\n=== 测试场景6：固定面额约束 - 不匹配 ===\n";
        
        $cardAmount = 200;           // 礼品卡金额
        $dailyLimit = 1000;          // 每日计划额度
        $dailySpent = 825;           // 已使用额度
        $floatAmount = 0;            // 浮动额度
        $fixedAmounts = [50, 100, 150]; // 固定面额要求
        
        $remainingDailyAmount = $dailyLimit + $floatAmount - $dailySpent; // 175
        
        echo "礼品卡金额: {$cardAmount}\n";
        echo "每日计划额度: {$dailyLimit}\n";
        echo "已使用额度: {$dailySpent}\n";
        echo "剩余需要额度: {$remainingDailyAmount}\n";
        echo "固定面额要求: " . implode(', ', $fixedAmounts) . "\n";
        
        // 判断逻辑
        if ($cardAmount <= $remainingDailyAmount) {
            echo "✓ 结果: 能够充满计划额度\n";
            return 1;
        }
        
        // 检查固定面额约束
        $remainingAfterUse = $cardAmount - $remainingDailyAmount; // 25
        echo "使用后剩余金额: {$remainingAfterUse}\n";
        
        $isMatched = false;
        
        foreach ($fixedAmounts as $fixedAmount) {
            if (abs($remainingAfterUse - $fixedAmount) < 0.01) {
                $isMatched = true;
                break;
            }
        }
        
        if ($isMatched) {
            echo "✓ 结果: 可以预留固定面额\n";
            return 2;
        } else {
            echo "✗ 结果: 剩余金额不匹配任何固定面额，需要换账号\n";
            echo "剩余金额 {$remainingAfterUse} 不在固定面额列表中\n";
        }
        
        echo "最终结果: 该账号不合适，需要换其他账号\n";
        return 3;
    }
    
    /**
     * 测试场景7：全面额约束
     */
    public static function testScenario7() {
        echo "\n=== 测试场景7：全面额约束 ===\n";
        
        $cardAmount = 300;           // 礼品卡金额
        $dailyLimit = 1000;          // 每日计划额度
        $dailySpent = 800;           // 已使用额度
        $floatAmount = 50;           // 浮动额度
        
        $remainingDailyAmount = $dailyLimit + $floatAmount - $dailySpent; // 250
        
        echo "礼品卡金额: {$cardAmount}\n";
        echo "每日计划额度: {$dailyLimit}\n";
        echo "浮动额度: {$floatAmount}\n";
        echo "已使用额度: {$dailySpent}\n";
        echo "剩余需要额度（含浮动）: {$remainingDailyAmount}\n";
        echo "约束类型: 全面额（无特殊要求）\n";
        
        // 判断逻辑
        if ($cardAmount <= $remainingDailyAmount) {
            echo "✓ 结果: 能够充满计划额度，选择该账号\n";
            echo "使用后剩余额度: " . ($remainingDailyAmount - $cardAmount) . "\n";
            return 1;
        }
        
        // 全面额约束下，只要剩余金额大于0就可以预留
        $remainingAfterUse = $cardAmount - $remainingDailyAmount; // 50
        echo "使用后剩余金额: {$remainingAfterUse}\n";
        
        if ($remainingAfterUse > 0) {
            echo "✓ 结果: 可以预留剩余额度（全面额），选择该账号\n";
            echo "预留金额: {$remainingAfterUse}（可用于任何面额的后续卡片）\n";
            return 2;
        } else {
            echo "✗ 结果: 剩余金额不足\n";
        }
        
        return 3;
    }
}

// 运行测试
echo "智能账号选择逻辑测试\n";
echo "====================\n";

$priority1 = TestData::testScenario1();
$priority2 = TestData::testScenario2();
$priority3 = TestData::testScenario3();
$priority4 = TestData::testScenario4();
$priority5 = TestData::testScenario5();
$priority6 = TestData::testScenario6();
$priority7 = TestData::testScenario7();

echo "\n=== 排序优先级总结 ===\n";
echo "场景1优先级: {$priority1} (能充满计划额度)\n";
echo "场景2优先级: {$priority2} (可以预留倍数额度)\n";
echo "场景3优先级: {$priority3} (不合适需要换账号)\n";
echo "场景4优先级: {$priority4} (包含浮动额度)\n";
echo "场景5优先级: {$priority5} (固定面额约束 - 可以预留)\n";
echo "场景6优先级: {$priority6} (固定面额约束 - 不匹配)\n";
echo "场景7优先级: {$priority7} (全面额约束)\n";

echo "\n优先级说明:\n";
echo "1 = 最高优先级（能充满计划额度）\n";
echo "2 = 中等优先级（可以预留倍数额度）\n";
echo "3 = 最低优先级（不合适，通常跳过）\n";

echo "\n测试完成！\n";
?> 