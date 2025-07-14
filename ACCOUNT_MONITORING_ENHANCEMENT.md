# 账号监控系统简化版

## 概述

本次对 `CheckAvailableAccountsByAmount` 命令进行了简化优化，专注于统计账号金额分布和识别长期未使用的账号。新版本更加简洁明了，便于快速了解账号状态。

## 主要功能

### 1. 金额分布统计

#### 分布区间
- **0余额**：余额为0的账号数量
- **0-600**：余额在0.01到600之间的账号数量
- **600-1200**：余额在600.01到1200之间的账号数量
- **1200-1650**：余额在1200.01到1650之间的账号数量
- **1650+**：余额超过1650的账号数量

#### 统计范围
- 状态为 `processing` 和 `waiting` 的账号
- 包含所有国家的账号

### 2. 长期未使用账号识别

#### 识别条件
- 余额超过1650的账号
- 2小时内没有新的兑换记录
- 显示具体账号信息和最后兑换时间

#### 输出信息
```php
private function getInactiveAccounts($accounts): array
{
    $inactiveAccounts = [];
    $twoHoursAgo = now()->subHours(2);

    // 只检查1650以上的账号
    $highBalanceAccounts = $accounts->where('amount', '>', 1650);

    foreach ($highBalanceAccounts as $account) {
        // 检查最近2小时是否有兑换记录
        $lastActivity = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastActivity || $lastActivity->created_at < $twoHoursAgo) {
            $inactiveAccounts[] = [
                'id' => $account->id,
                'account' => $account->account,
                'balance' => $account->amount,
                'status' => $account->status,
                'last_activity' => $lastActivity ? $lastActivity->created_at->format('Y-m-d H:i:s') : '无记录',
                'hours_inactive' => $lastActivity ? $lastActivity->created_at->diffInHours(now()) : 999
            ];
        }
    }

    // 按未使用时间排序
    usort($inactiveAccounts, function($a, $b) {
        return $b['hours_inactive'] <=> $a['hours_inactive'];
    });

    return $inactiveAccounts;
}
```

## 微信消息格式

### 新的消息格式
```
💰 账号金额分布监控
═══════════════════
📅 检测时间: 2025-01-14 15:30:00
⏱️ 执行耗时: 1250ms

🌍 国家: US
📊 总账号数: 50
💰 金额分布:
  0余额: 15 个
  0-600: 20 个
  600-1200: 10 个
  1200-1650: 3 个
  1650+: 2 个

⚠️  长期未使用账号（1650以上且2小时无兑换）:
  account1@example.com - 余额:1800 - 最后兑换:2025-01-14 13:00:00 - 未使用:2小时
  account2@example.com - 余额:2000 - 最后兑换:2025-01-14 12:30:00 - 未使用:3小时
```

## 执行命令

```bash
# 检查所有国家的账号
php artisan itunes:check-available-accounts

# 检查指定国家的账号
php artisan itunes:check-available-accounts --country=US --country=CA
```

## 输出示例

### 控制台输出
```
🔍 开始检查账号金额分布和长期未使用账号
检查国家: US

📊 检查国家: US
  总账号数: 50
  金额分布:
    0余额: 15 个
    0-600: 20 个
    600-1200: 10 个
    1200-1650: 3 个
    1650+: 2 个
  ⚠️  长期未使用账号（1650以上且2小时无兑换）:
    account1@example.com - 余额:1800 - 最后兑换:2025-01-14 13:00:00 - 未使用:2小时
    account2@example.com - 余额:2000 - 最后兑换:2025-01-14 12:30:00 - 未使用:3小时

✅ 检查完成，耗时: 1250ms
✅ 检测结果已发送到微信群
```

## 监控指标说明

### 1. 金额分布
- **0余额**：可以兑换任意面额的账号
- **0-600**：低余额账号，适合小额兑换
- **600-1200**：中等余额账号
- **1200-1650**：高余额账号
- **1650+**：超高余额账号，需要重点关注使用情况

### 2. 长期未使用账号
- **定义**：余额超过1650且2小时无兑换记录的账号
- **意义**：识别可能存在问题的闲置账号
- **监控价值**：帮助优化账号使用效率，避免资源浪费

## 技术实现

### 1. 性能优化
- 简化查询逻辑，减少数据库访问
- 只对1650以上的账号进行活动检查
- 使用批量查询提升性能

### 2. 数据准确性
- 精确的金额区间划分
- 准确的时间计算（小时级别）
- 考虑账号状态过滤

### 3. 可扩展性
- 支持多国家监控
- 可配置的时间阈值
- 模块化的统计功能

## 使用场景

### 1. 日常监控
- 每30分钟自动执行
- 快速了解账号金额分布
- 识别长期未使用的账号

### 2. 账号健康度管理
- 通过金额分布了解账号池状态
- 识别需要关注的长期未使用账号
- 帮助优化账号使用策略

### 3. 容量规划
- 通过金额分布了解账号容量
- 识别高余额但未使用的账号
- 为账号补充提供数据支持

## 简化优势

### 1. 更清晰的数据展示
- 简化的金额分布区间
- 直观的长期未使用账号识别
- 减少冗余信息

### 2. 更快的执行速度
- 简化查询逻辑
- 减少不必要的计算
- 提升响应速度

### 3. 更容易理解
- 清晰的指标定义
- 直观的数据展示
- 简化的消息格式

## 总结

通过这次简化，账号监控系统现在能够：

1. **简洁明了**：专注于核心指标，减少信息噪音
2. **快速识别**：快速识别长期未使用的高余额账号
3. **直观展示**：通过金额分布快速了解账号池状态
4. **高效执行**：简化的逻辑提升执行效率

这些改进使得账号监控更加实用和高效，能够更好地支持业务运营决策。 