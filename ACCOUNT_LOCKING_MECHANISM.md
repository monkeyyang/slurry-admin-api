# 账号锁定机制说明

## 问题背景

在批量执行礼品卡兑换时，可能会有多个任务同时尝试使用同一个账号进行兑换，这会导致：

1. **并发冲突**：多个任务同时修改同一个账号的状态和余额
2. **数据不一致**：账号的兑换记录和余额可能出现错误
3. **API限制**：同一个iTunes账号不能同时进行多个兑换操作
4. **金额超出**：多个队列同时操作可能导致兑换金额超出计划限制

## 解决方案：多层并发安全机制

### 1. 锁定状态定义

引入新的账号状态：`ItunesTradeAccount::STATUS_LOCKING`

| 状态 | 含义 | 使用场景 |
|------|------|----------|
| `STATUS_WAITING` | 等待中 | 账号空闲，可以被选择 |
| `STATUS_PROCESSING` | 处理中 | 账号正在执行计划 |
| `STATUS_LOCKING` | 锁定中 | 账号正在执行兑换，禁止其他任务使用 |
| `STATUS_COMPLETED` | 已完成 | 账号计划执行完毕 |

### 2. 多层并发安全保护

#### 2.1 第一层：原子锁定机制

```php
// 使用数据库级别的原子操作进行锁定
$lockResult = DB::table('itunes_trade_accounts')
    ->where('id', $account->id)
    ->where('status', $originalStatus) // 确保状态没有被其他任务改变
    ->update([
        'status'     => ItunesTradeAccount::STATUS_LOCKING,
        'plan_id'    => $plan->id,
        'updated_at' => now()
    ]);
```

**特点**：
- 使用数据库原子操作确保只有一个任务能成功锁定账号
- 通过检查原始状态避免状态被其他任务修改

#### 2.2 第二层：事务包装

```php
return DB::transaction(function () use (...) {
    // 验证 -> 锁定 -> 二次验证
    // 整个过程在一个事务中完成
}, 3); // 重试3次应对死锁
```

**特点**：
- 整个验证-锁定过程在数据库事务中执行
- 任何失败都会自动回滚，确保数据一致性
- 支持死锁重试机制

#### 2.3 第三层：锁定后二次验证

```php
// 锁定成功后立即进行二次验证
$freshDailySpentData = $this->batchGetDailySpentAmounts([$account->id], $plan);
if (!$this->validateAccount($account, $plan, $giftCardInfo, $freshDailySpentData)) {
    // 验证失败，事务回滚
    return false;
}
```

**特点**：
- 锁定成功后重新获取最新数据进行验证
- 确保锁定期间没有其他任务修改相关数据
- 验证失败会自动回滚锁定操作

#### 2.4 第四层：执行前最终验证

```php
// 兑换执行前的最终验证
$finalDailySpentData = $this->batchGetDailySpentAmounts([$account->id], $plan);
if (!$this->validateAccount($account, $plan, $giftCardInfo, $finalDailySpentData)) {
    // 最终验证失败，停止兑换
    return ['success' => false, 'message' => '兑换前验证失败'];
}
```

**特点**：
- 在调用兑换API前进行最后一次验证
- 防止锁定后到执行前这段时间内的数据变化
- 确保兑换执行时账号条件仍然符合要求

### 3. 完整的并发安全流程

```
步骤1: 预验证（使用预查询数据）
   ↓
步骤2: 原子锁定（数据库级别）
   ↓
步骤3: 二次验证（最新数据）
   ↓ 
步骤4: 锁定成功确认
   ↓
步骤5: 执行前最终验证
   ↓
步骤6: 调用兑换API
   ↓
步骤7: 处理兑换结果
```

### 4. 性能优化措施

#### 4.1 批量数据预查询

```php
// 一次性获取所有候选账号的每日兑换数据
$dailySpentData = $this->batchGetDailySpentAmounts($accountIds, $plan);
```

**优势**：
- 减少数据库查询次数（从N次降到1次）
- 提高验证效率
- 降低数据库负载

#### 4.2 智能账号排序

```php
// 按容量类型优先级排序：1=能充满，2=可预留，3=不适合
$accounts->sort(function ($a, $b) use (...) {
    $aCapacityType = $this->getAccountCapacityTypeOptimized(...);
    $bCapacityType = $this->getAccountCapacityTypeOptimized(...);
    return $aCapacityType - $bCapacityType;
});
```

**优势**：
- 优先选择最合适的账号
- 减少账号切换次数
- 提高兑换成功率

### 5. 约束类型支持

#### 5.1 倍数约束 (AMOUNT_CONSTRAINT_MULTIPLE)
- 验证剩余金额是否≥倍数基数且为整数倍
- 示例：倍数基数50，剩余100可以预留（100÷50=2倍）

#### 5.2 固定面额约束 (AMOUNT_CONSTRAINT_FIXED)
- 验证剩余金额是否精确匹配固定面额
- 示例：固定面额[50,100,150]，剩余100可以预留

#### 5.3 全面额约束 (AMOUNT_CONSTRAINT_ALL)
- 只要剩余金额>0就可以预留
- 适用于无特殊约束的汇率

### 6. 错误处理和恢复

#### 6.1 锁定失败处理
```php
if ($lockResult <= 0) {
    $this->getLogger()->info("账号原子锁定失败，可能已被其他任务占用");
    return false; // 尝试下一个账号
}
```

#### 6.2 验证失败恢复
```php
if (!$this->validateAccount(...)) {
    // 事务自动回滚，无需手动恢复
    $this->getLogger()->warning("账号锁定后二次验证失败，事务将回滚");
    return false;
}
```

#### 6.3 异常情况处理
```php
} catch (Exception $e) {
    // 更新日志状态，记录错误信息
    $log->update([
        'status' => ItunesTradeAccountLog::STATUS_FAILED,
        'error_message' => $e->getMessage()
    ]);
    // 事务回滚会自动恢复账号状态
}
```

### 7. 日志记录和监控

#### 7.1 详细的操作日志
```php
$this->getLogger()->info("账号原子锁定成功", [
    'account_id' => $account->id,
    'original_status' => $originalStatus,
    'locked_status' => ItunesTradeAccount::STATUS_LOCKING,
    'verification' => 'passed_double_check',
    'transaction' => 'committed'
]);
```

#### 7.2 性能监控日志
```php
$this->getLogger()->info("排序性能优化", [
    'account_count' => count($accountIds),
    'daily_spent_queries' => count($dailySpentData),
    'constraint_type' => $constraintType
]);
```

### 8. 安全保证

1. **防止并发冲突**：多层验证确保同一时间只有一个任务能使用特定账号
2. **数据一致性**：事务机制保证数据的一致性
3. **故障恢复**：失败时能正确恢复账号状态
4. **金额安全**：多次验证防止金额超出限制
5. **性能优化**：批量查询减少数据库负载

### 9. 监控指标

- **锁定成功率**：成功锁定账号的比例
- **二次验证通过率**：锁定后验证的通过率
- **最终验证通过率**：执行前验证的通过率
- **平均处理时间**：从验证到锁定的耗时
- **并发冲突次数**：锁定失败的次数

### 10. 注意事项

1. **锁定时间**：锁定时间应该尽可能短，避免长时间占用账号
2. **事务范围**：整个验证-锁定-验证过程都在同一个事务中
3. **重试机制**：支持死锁重试，但次数要合理
4. **监控告警**：关注并发冲突率和验证失败率
5. **性能平衡**：在安全性和性能之间找到平衡点

这种多层并发安全机制确保了在高并发环境下账号使用的安全性、数据的一致性和系统的稳定性。 