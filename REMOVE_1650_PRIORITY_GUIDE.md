# 移除1650优先临时条件指南

## 概述

当前在 `FindAccountService` 中添加了1650优先选择的临时筛选层。这个文档说明如何在不影响其他功能的情况下移除这个临时条件。

## 当前实现

### 1. 新增的筛选层
- **方法**: `get1650PriorityAccountIds()`
- **位置**: `app/Services/Gift/FindAccountService.php`
- **功能**: 优先选择兑换后额度为1650的账号

### 2. 集成点
在主筛选流程中，1650优先筛选被插入为第4.5层：
```php
// 4. 容量检查筛选（充满/预留逻辑）
$capacityAccountIds = $this->getCapacityQualifiedAccountIds($roomBindingAccountIds, $plan, $giftCardAmount);

// 4.5. 临时筛选层：1650优先选择
$priority1650AccountIds = $this->get1650PriorityAccountIds($capacityAccountIds, $giftCardAmount);

// 5. 每日计划筛选
$dailyPlanAccountIds = $this->getDailyPlanQualifiedAccountIds($priority1650AccountIds, $plan, $giftCardAmount, $currentDay);
```

## 移除步骤

### 步骤1: 删除1650优先筛选层调用
在 `findOptimalAccount` 方法中，删除以下代码块：

```php
// 删除这段代码
// 4.5. 临时筛选层：1650优先选择
$priority1650AccountIds = $this->get1650PriorityAccountIds($capacityAccountIds, $giftCardAmount);

if (empty($priority1650AccountIds)) {
    $this->logNoAccountFound($plan, $roomId, $giftCardAmount, $startTime, '1650_priority_qualification');
    return null;
}

$this->getLogger()->debug("1650优先筛选完成", [
    'qualified_count' => count($priority1650AccountIds),
    'stage'           => '1650_priority_qualification'
]);
```

### 步骤2: 恢复原有的筛选流程
将每日计划筛选的参数改回原来的变量：

```php
// 修改这行
$dailyPlanAccountIds = $this->getDailyPlanQualifiedAccountIds($priority1650AccountIds, $plan, $giftCardAmount, $currentDay);

// 改为
$dailyPlanAccountIds = $this->getDailyPlanQualifiedAccountIds($capacityAccountIds, $plan, $giftCardAmount, $currentDay);
```

### 步骤3: 删除1650优先筛选方法（可选）
如果确定不再需要，可以删除 `get1650PriorityAccountIds` 方法。

### 步骤4: 删除1700排斥条件（可选）
在 `validateAccountCapacity` 方法中，删除以下代码块：

```php
// 删除这段代码
// 临时条件：排斥金额+面额为1700的账号
if (abs($afterExchangeAmount - 1700) < 0.01) {
    $this->getLogger()->debug("排斥账号：金额+面额为1700", [
        'account_id' => $accountData->id,
        'current_balance' => $currentBalance,
        'gift_card_amount' => $giftCardAmount,
        'after_exchange_amount' => $afterExchangeAmount
    ]);
    return false;
}
```

## 验证移除

### 1. 功能测试
- 确保账号筛选功能正常工作
- 验证容量检查逻辑正确执行
- 确认每日计划筛选正常

### 2. 日志检查
- 确认不再出现 "1650优先筛选" 相关的日志
- 验证筛选流程日志正确显示各阶段

### 3. 性能测试
- 确认筛选性能没有明显下降
- 验证数据库查询正常

## 回滚方案

如果需要恢复1650优先逻辑，可以：

1. 从版本控制系统恢复相关代码
2. 或者重新添加 `get1650PriorityAccountIds` 方法
3. 在主筛选流程中重新插入1650优先筛选层

## 注意事项

1. **测试**: 移除前请确保在测试环境中验证
2. **日志**: 移除后检查日志，确保没有错误
3. **监控**: 移除后监控账号筛选的准确性和性能
4. **文档**: 更新相关文档，移除对1650优先逻辑的说明

## 总结

通过这种解耦的设计，1650优先逻辑可以很容易地移除，不会影响原有的筛选流程。只需要删除几个代码块，系统就能恢复到原来的状态。 