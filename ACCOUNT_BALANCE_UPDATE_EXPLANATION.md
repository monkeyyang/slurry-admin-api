# 账号余额更新机制说明

## 问题背景

在礼品卡兑换系统中，iTunes账号可能已经有一定的余额，当兑换新的礼品卡时，我们需要准确记录账号的最新总余额，而不是只记录本次兑换的金额。

## API 返回数据结构

兑换成功后，API返回的数据包含两个重要字段：

```json
{
  "result": {
    "code": 0,
    "msg": "兑换成功,加载金额:$5.00,ID总金额:$5.00",
    "username": "i44f086@icloud.com",
    "total": "$5.00",     // ID总金额 - 账号当前总余额
    "fund": "$5.00",      // 本次加载金额 - 本次兑换的礼品卡金额
    "available": "Good card"
  }
}
```

### 字段含义

| 字段 | 含义 | 示例 | 用途 |
|------|------|------|------|
| `fund` | 本次加载金额 | "$5.00" | 记录本次兑换的礼品卡面额 |
| `total` | ID总金额 | "$15.00" | 账号当前的总余额（包含原有余额+新增金额） |

## 更新策略

### 之前的问题

```php
// ❌ 错误做法：只记录本次兑换金额
$account->update([
    'amount' => $exchangeData['data']['amount']  // 只有本次兑换的$5
]);
```

这种做法的问题：
- 如果账号原本有 $10 余额
- 兑换 $5 礼品卡后，总余额应该是 $15
- 但只记录 $5，丢失了原有的 $10

### 正确的做法

```php
// ✅ 正确做法：使用API返回的总金额
$totalAmount = $exchangeData['data']['total_amount'] ?? 0;  // $15
$account->update([
    'amount' => $totalAmount  // 更新为完整的总余额
]);
```

## 代码实现

### 1. 解析API返回结果

在 `parseExchangeResult` 方法中：

```php
if ($resultCode === 0) {
    // 兑换成功
    $amount = $this->parseBalance($result['fund'] ?? '0');        // 本次加载：$5.00
    $totalAmount = $this->parseBalance($result['total'] ?? '0');  // ID总金额：$15.00
    
    return [
        'success' => true,
        'data' => [
            'amount' => $amount,           // 本次兑换金额
            'total_amount' => $totalAmount, // 账号总余额
            // ...
        ]
    ];
}
```

### 2. 更新账号余额

在 `executeRedemption` 方法中：

```php
if ($exchangeData['success']) {
    // 更新账号余额为API返回的总金额
    $totalAmount = $exchangeData['data']['total_amount'] ?? 0;
    if ($totalAmount > 0) {
        $account->update([
            'amount' => $totalAmount,  // 更新为ID总金额
        ]);
        
        $this->getLogger()->info("更新账号余额", [
            'account_id' => $account->id,
            'account' => $account->account,
            'new_amount' => $totalAmount,      // 新的总余额
            'fund_added' => $exchangeData['data']['amount'] ?? 0  // 本次添加金额
        ]);
    }
}
```

## 实际场景示例

### 场景1：空账号兑换

```
账号原余额: $0.00
兑换礼品卡: $10.00
API返回:
  - fund: "$10.00"  (本次加载)
  - total: "$10.00" (总余额)
更新结果: account.amount = $10.00 ✅
```

### 场景2：有余额账号兑换

```
账号原余额: $25.00
兑换礼品卡: $15.00  
API返回:
  - fund: "$15.00"  (本次加载)
  - total: "$40.00" (总余额)
更新结果: account.amount = $40.00 ✅
```

### 场景3：多次兑换

```
第一次兑换:
  原余额: $0.00 → 兑换$5.00 → 总余额: $5.00

第二次兑换:
  原余额: $5.00 → 兑换$10.00 → 总余额: $15.00
  
第三次兑换:
  原余额: $15.00 → 兑换$20.00 → 总余额: $35.00
```

## 日志记录

更新余额时会记录详细日志：

```php
$this->getLogger()->info("更新账号余额", [
    'account_id' => $account->id,
    'account' => 'i44f086@icloud.com',
    'new_amount' => 15.00,     // 更新后的总余额
    'fund_added' => 5.00       // 本次添加的金额
]);
```

## 数据一致性保证

### 1. 事务保护

所有余额更新都在数据库事务中进行：

```php
return DB::transaction(function () use (...) {
    // 更新日志状态
    $log->update([...]);
    
    // 更新账号余额
    $account->update([
        'amount' => $totalAmount
    ]);
    
    // 其他操作...
});
```

### 2. 错误处理

如果API返回的总金额为0或无效，不会更新账号余额：

```php
$totalAmount = $exchangeData['data']['total_amount'] ?? 0;
if ($totalAmount > 0) {  // 只有有效金额才更新
    $account->update(['amount' => $totalAmount]);
}
```

## 优势

1. **数据准确性**：始终反映账号的真实总余额
2. **防止丢失**：不会因为只记录增量而丢失原有余额
3. **审计友好**：日志中同时记录增量和总额
4. **业务逻辑简化**：不需要手动计算累加，直接使用API返回值

## 注意事项

1. **API依赖**：依赖兑换API正确返回 `total` 字段
2. **解析准确性**：`parseBalance` 方法需要正确解析货币格式
3. **并发安全**：通过数据库事务保证并发更新的安全性
4. **错误恢复**：如果更新失败，需要通过事务回滚保证数据一致性

这种设计确保了账号余额的准确性和系统的可靠性。 