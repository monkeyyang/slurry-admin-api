# 账号锁定机制说明

## 问题背景

在批量执行礼品卡兑换时，可能会有多个任务同时尝试使用同一个账号进行兑换，这会导致：

1. **并发冲突**：多个任务同时修改同一个账号的状态和余额
2. **数据不一致**：账号的兑换记录和余额可能出现错误
3. **API限制**：同一个iTunes账号不能同时进行多个兑换操作

## 解决方案：账号锁定机制

### 1. 锁定状态定义

引入新的账号状态：`ItunesTradeAccount::STATUS_LOCKING`

| 状态 | 含义 | 使用场景 |
|------|------|----------|
| `STATUS_WAITING` | 等待中 | 账号空闲，可以被选择 |
| `STATUS_PROCESSING` | 处理中 | 账号正在执行计划 |
| `STATUS_LOCKING` | 锁定中 | 账号正在执行兑换，禁止其他任务使用 |
| `STATUS_COMPLETED` | 已完成 | 账号计划执行完毕 |

### 2. 锁定流程

#### 2.1 获取账号时的保护

```php
// 查找账号时自动排除锁定状态
private function findProcessingAccount(...) {
    $query = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
        // 不会查找STATUS_LOCKING状态的账号
        ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE);
}

private function findWaitingAccount(...) {
    return ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_WAITING)
        // 不会查找STATUS_LOCKING状态的账号
        ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE);
}
```

#### 2.2 验证账号时的检查

```php
private function validateAccount(...) {
    // 检查账号是否被锁定
    if ($account->status === ItunesTradeAccount::STATUS_LOCKING) {
        $this->getLogger()->info("账号已被锁定，跳过", [
            'account_id' => $account->id,
            'status' => $account->status
        ]);
        return false;
    }
    // ... 其他验证逻辑
}
```

### 3. 兑换执行流程

#### 3.1 开始兑换：锁定账号

```php
protected function executeRedemption(...) {
    return DB::transaction(function () use (...) {
        // 1. 记录原始状态
        $originalStatus = $account->status;
        
        // 2. 锁定账号
        $account->update([
            'status' => ItunesTradeAccount::STATUS_LOCKING,
            'plan_id' => $plan->id,
        ]);
        
        $this->getLogger()->info("账号已锁定", [
            'account_id' => $account->id,
            'original_status' => $originalStatus,
            'locked_status' => ItunesTradeAccount::STATUS_LOCKING
        ]);
        
        // 3. 执行兑换逻辑...
    });
}
```

#### 3.2 兑换成功：更新为处理中

```php
if ($exchangeData['success']) {
    // 兑换成功，将状态从锁定改为处理中
    $account->update([
        'status' => ItunesTradeAccount::STATUS_PROCESSING,
    ]);
    
    $this->getLogger()->info("兑换成功，账号状态更新", [
        'account_id' => $account->id,
        'status_changed' => 'LOCKING -> PROCESSING',
        // ...
    ]);
}
```

#### 3.3 兑换失败：恢复原状态

```php
} else {
    // 兑换失败，恢复账号到锁定前的状态
    $account->update([
        'status' => $originalStatus,
    ]);
    
    $this->getLogger()->info("兑换失败，恢复账号状态", [
        'account_id' => $account->id,
        'status_restored' => "LOCKING -> {$originalStatus}",
        'error' => $exchangeData['message']
    ]);
}
```

#### 3.4 异常处理：恢复原状态

```php
} catch (Exception $e) {
    // 异常情况，恢复账号到锁定前的状态
    $account->update([
        'status' => $originalStatus,
    ]);
    
    $this->getLogger()->error("兑换过程发生异常，恢复账号状态", [
        'account_id' => $account->id,
        'status_restored' => "LOCKING -> {$originalStatus}",
        'error' => $e->getMessage(),
    ]);
    
    throw $e;
}
```

## 4. 状态转换图

```
                    ┌─────────────┐
                    │   WAITING   │ ◄──────┐
                    └─────┬───────┘        │
                          │                │
                          ▼                │
                    ┌─────────────┐        │
          ┌────────►│   LOCKING   │        │
          │         └─────┬───────┘        │
          │               │                │
          │               ▼                │
          │         ┌─────────────┐        │
          │         │ PROCESSING  │        │
          │         └─────┬───────┘        │
          │               │                │
          │               ▼                │
          │         ┌─────────────┐        │
          │         │  COMPLETED  │        │
          │         └─────────────┘        │
          │                                │
          └─── 失败/异常时恢复 ──────────────┘
```

## 5. 并发安全保证

### 5.1 数据库事务

所有状态变更都在数据库事务中进行：

```php
return DB::transaction(function () use (...) {
    // 锁定账号
    $account->update(['status' => STATUS_LOCKING]);
    
    // 执行兑换
    // ...
    
    // 更新状态
    $account->update(['status' => $newStatus]);
});
```

### 5.2 原子操作

状态变更是原子的，要么全部成功，要么全部回滚。

### 5.3 状态检查

在每个关键步骤都会检查账号状态，确保不会操作已被锁定的账号。

## 6. 日志记录

系统会详细记录锁定过程：

```php
// 锁定时
$this->getLogger()->info("账号已锁定", [
    'account_id' => $account->id,
    'original_status' => $originalStatus,
    'locked_status' => ItunesTradeAccount::STATUS_LOCKING
]);

// 成功时
$this->getLogger()->info("兑换成功，账号状态更新", [
    'account_id' => $account->id,
    'status_changed' => 'LOCKING -> PROCESSING',
]);

// 失败时
$this->getLogger()->info("兑换失败，恢复账号状态", [
    'account_id' => $account->id,
    'status_restored' => "LOCKING -> {$originalStatus}",
    'error' => $exchangeData['message']
]);
```

## 7. 优势

1. **防止并发冲突**：确保同一时间只有一个任务能使用特定账号
2. **数据一致性**：通过事务和状态管理保证数据的一致性
3. **故障恢复**：失败时能正确恢复账号状态
4. **可追踪性**：详细的日志记录便于问题排查
5. **高可用性**：锁定是临时的，不会永久占用资源

## 8. 注意事项

1. **锁定时间**：锁定时间应该尽可能短，避免长时间占用账号
2. **异常处理**：必须确保所有异常情况都能正确恢复账号状态
3. **事务范围**：整个锁定-执行-释放过程都应该在同一个事务中
4. **状态一致性**：状态变更必须与业务逻辑保持一致

这种锁定机制确保了在批量执行环境下账号使用的安全性和数据的一致性。 