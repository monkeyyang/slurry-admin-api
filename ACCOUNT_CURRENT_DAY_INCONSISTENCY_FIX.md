# 账号当前计划天数不一致问题修复

## 问题描述

在iTunes交易账号管理中，发现部分账号的`current_plan_day`字段与实际执行进度不一致。具体表现为：

- 账号已经成功执行了第1天和第2天的兑换任务
- 但是`current_plan_day`仍然显示为1
- 账号状态可能一直处于`processing`或`locking`状态
- 导致账号无法正确进入下一阶段或完成状态

## 问题根因分析

1. **状态更新机制缺陷**：在某些异常情况下，账号的`current_plan_day`没有随着兑换进度正确更新
2. **并发处理问题**：多个兑换任务同时执行时，可能导致状态更新冲突
3. **异常中断**：系统异常或重启可能导致状态更新中断
4. **历史数据问题**：早期版本的逻辑缺陷导致的历史遗留问题

## 解决方案

### 新增检查功能

在`FixAccountDataInconsistency`命令中新增第5个检查项：**检查current_plan_day与实际执行进度不一致的账号**

### 检查逻辑

1. **查找目标账号**：
   - 有当前计划天数（`current_plan_day`不为空）
   - 状态为`processing`或`locking`
   - 包括有计划和无计划（历史原因解绑）的账号

2. **分析执行进度**：
   - 获取所有成功的兑换记录
   - 统计已完成的天数
   - 获取最后一次成功兑换的时间

3. **计算正确的当前天数**：
   - **有计划账号**：根据计划配置和时间间隔判断
   - **无计划账号**：使用特殊逻辑（基于金额和最大3天限制）

### 修复策略

#### 情况1：账号应该完成
```
有计划账号：已完成天数 >= 计划总天数
无计划账号：已完成天数 >= 3天
修复：标记为完成状态
```

#### 情况2：需要更新当前天数
```
条件：current_plan_day与计算出的正确天数不一致
修复：
- 更新current_plan_day到正确值
- 有计划账号：根据时间间隔设置状态（WAITING或PROCESSING）
- 无计划账号：始终设置为PROCESSING状态
- 重新计算并更新completed_days字段
```

### 无计划账号特殊逻辑

对于历史原因解绑计划的账号，使用以下规则：

1. **最大天数限制**：3天
2. **金额判断标准**：每天600为阈值
3. **状态设置**：始终为PROCESSING（便于继续兑换）

#### 天数计算规则：
```php
// 如果最后一天累计金额 >= 600，进入下一天
if ($lastDayAmount >= 600) {
    $nextDay = $lastCompletedDay + 1;
    if ($nextDay > 3) {
        return 4; // 表示应该完成
    }
    return $nextDay;
} else {
    // 金额不够600，继续当前天
    return $lastCompletedDay;
}
```

### 核心算法

#### 计算正确当前天数的逻辑：
```php
private function calculateCorrectCurrentDay($account, $completedDays, $lastSuccessLog): int
{
    if (empty($completedDays)) {
        return 1; // 没有完成记录，应该是第1天
    }

    $planDays = $account->plan->plan_days;
    $lastCompletedDay = max($completedDays);

    // 如果所有天数都已完成，账号应该被标记为完成
    if (count($completedDays) >= $planDays) {
        return $planDays + 1; // 表示应该完成
    }

    // 检查是否应该进入下一天
    if ($lastSuccessLog) {
        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $dayInterval = $account->plan->day_interval ?? 24; // 默认24小时间隔
        $hoursFromLastExchange = $lastExchangeTime->diffInHours(now());

        if ($hoursFromLastExchange >= $dayInterval) {
            // 已经超过间隔时间，应该进入下一天
            return $lastCompletedDay + 1;
        } else {
            // 还在间隔时间内，应该等待
            return $lastCompletedDay;
        }
    }

    return $lastCompletedDay + 1;
}
```

## 使用方法

### 检查模式（推荐先运行）
```bash
php artisan fix:account-data-inconsistency --dry-run
```

### 修复模式
```bash
php artisan fix:account-data-inconsistency
```

## 修复示例

### 修复前
```
账号: test@example.com
当前计划天数: 1
状态: processing
已完成天数: [1, 2]
最后兑换时间: 2025/6/20 21:32:57
```

### 修复后
```
账号: test@example.com
当前计划天数: 3 (或标记为完成)
状态: waiting/processing (根据时间间隔)
已完成天数: [1, 2]
completed_days字段: {"1": 700, "2": 700}
```

## 修复场景

### 场景1：已完成所有天数但未标记完成
- **检测**：completed_days包含所有计划天数
- **修复**：标记账号为完成状态，清除计划关联

### 场景2：部分完成但current_plan_day错误
- **检测**：current_plan_day与最新完成天数不匹配
- **修复**：更新到正确的天数，设置合适的状态

### 场景3：时间间隔判断
- **检测**：根据最后兑换时间和计划间隔判断状态
- **修复**：设置为WAITING（未到时间）或PROCESSING（可以执行）

## 日志记录

修复过程会记录详细日志：
```json
{
    "account_id": 123,
    "account": "test@example.com",
    "old_current_plan_day": 1,
    "new_current_plan_day": 3,
    "new_status": "waiting",
    "completed_days": [1, 2],
    "updated_completed_days": {"1": 700, "2": 700}
}
```

## 预防措施

1. **定期运行检查**：建议每天运行一次数据一致性检查
2. **监控异常**：关注账号状态更新的异常日志
3. **完善状态机**：改进账号状态转换的原子性操作
4. **增加验证**：在关键操作后验证数据一致性

## 兼容性说明

- **安全性**：修复操作基于实际兑换记录，不会丢失数据
- **可逆性**：所有修复操作都有详细日志，可以追溯
- **幂等性**：重复运行不会产生副作用

## 总结

此功能解决了账号当前计划天数与实际执行进度不一致的问题，确保：

1. **数据一致性**：账号状态与实际执行进度保持同步
2. **业务连续性**：修复后账号可以正常进入下一阶段
3. **系统稳定性**：减少因状态不一致导致的业务异常
4. **可观测性**：提供详细的检查和修复日志

通过这个修复功能，可以有效解决您截图中显示的问题，让账号状态正确反映实际的执行进度。 