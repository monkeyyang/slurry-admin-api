# 数据库字段映射说明

## itunes_trade_account_logs 表字段说明

### 关键字段定义

| 字段名 | 类型 | 说明 | 示例 |
|--------|------|------|------|
| `code` | varchar | **礼品卡密码/卡密** | "XPX9M3XRQWFPHF8Z" |
| `amount` | decimal | **兑换金额**（礼品卡面额） | 100.00 |
| `status` | varchar | 兑换状态 | "pending", "success", "failed" |
| `account_id` | int | 关联的iTunes账号ID | 16 |
| `plan_id` | int | 关联的交易计划ID | 5 |
| `rate_id` | int | 关联的汇率ID | 3 |
| `day` | int | 计划执行天数 | 1, 2, 3 |
| `country_code` | varchar | 国家代码 | "US", "CA", "GB" |
| `exchange_time` | timestamp | 兑换时间 | "2024-12-16 00:57:51" |
| `error_message` | text | 错误信息（失败时） | "兑换失败: 礼品卡已被使用" |

### ⚠️ 重要说明

1. **没有 `exchanged_amount` 字段**
   - 兑换后的实际金额信息存储在API返回结果中
   - 数据库中的 `amount` 字段存储的是礼品卡原始面额
   
2. **没有 `gift_card_code` 字段**
   - 礼品卡密码存储在 `code` 字段中
   
3. **字段使用规范**
   ```php
   // ✅ 正确用法
   $log->code              // 获取礼品卡密码
   $log->amount            // 获取兑换金额（面额）
   
   // ❌ 错误用法
   $log->gift_card_code    // 字段不存在
   $log->exchanged_amount  // 字段不存在
   ```

## itunes_trade_accounts 表字段说明

### 关键字段定义

| 字段名 | 类型 | 说明 | 示例 |
|--------|------|------|------|
| `account` | varchar | iTunes账号用户名 | "example@icloud.com" |
| `status` | varchar | 账号状态 | "waiting", "processing", "completed" |
| `current_plan_day` | int | 当前计划执行天数 | 1, 2, 3 |
| `plan_id` | int | 当前绑定的计划ID | 5 |
| `completed_days` | json | 已完成天数记录 | {"1": 200, "2": 300} |
| `room_id` | varchar | 绑定的群聊ID | "room_12345" |
| `country_code` | varchar | 账号国家 | "US", "CA" |
| `login_status` | varchar | 登录状态 | "active", "inactive" |

### completed_days 字段格式

```json
{
  "1": 200.00,    // 第1天累计兑换金额
  "2": 350.50,    // 第2天累计兑换金额
  "3": 180.25     // 第3天累计兑换金额
}
```

## 常见查询示例

### 1. 获取账号当天已兑换金额
```php
$dailyAmount = ItunesTradeAccountLog::where('account_id', $accountId)
    ->where('day', $currentDay)
    ->where('status', 'success')
    ->sum('amount');  // 使用 amount 字段
```

### 2. 获取账号总兑换金额
```php
$totalAmount = ItunesTradeAccountLog::where('account_id', $accountId)
    ->where('status', 'success')
    ->sum('amount');  // 使用 amount 字段
```

### 3. 查找特定礼品卡的兑换记录
```php
$log = ItunesTradeAccountLog::where('code', $giftCardCode)->first();
// 使用 code 字段，不是 gift_card_code
```

### 4. 更新兑换状态
```php
$log->update([
    'status' => 'success',
    'error_message' => null,
]);
// 不要尝试更新 exchanged_amount 字段
```

## 代码中的正确用法

### ✅ 正确的字段引用

```php
// 创建兑换日志
ItunesTradeAccountLog::create([
    'account_id' => $account->id,
    'plan_id' => $plan->id,
    'code' => $giftCardCode,        // 正确：使用 code
    'amount' => $giftCardAmount,    // 正确：使用 amount
    'status' => 'pending',
    // ...
]);

// 查询统计
$sum = ItunesTradeAccountLog::where('account_id', $id)
    ->where('status', 'success')
    ->sum('amount');                // 正确：使用 amount

// 获取卡密
$cardCode = $log->code;             // 正确：使用 code
```

### ❌ 错误的字段引用

```php
// 错误的字段名
$log->gift_card_code;               // 字段不存在
$log->exchanged_amount;             // 字段不存在

// 错误的查询
->sum('exchanged_amount');          // 字段不存在

// 错误的更新
$log->update([
    'exchanged_amount' => $amount   // 字段不存在
]);
```

## 数据流程说明

1. **兑换开始**：创建日志记录，`amount` = 礼品卡面额，`status` = "pending"
2. **兑换成功**：更新 `status` = "success"，实际兑换金额在API返回中
3. **兑换失败**：更新 `status` = "failed"，`error_message` = 错误信息
4. **统计计算**：使用 `amount` 字段（礼品卡面额）进行累计

## 注意事项

1. **字段命名一致性**：确保代码中使用的字段名与数据库表结构一致
2. **金额含义**：`amount` 存储的是礼品卡面额，不是兑换后的实际金额
3. **状态管理**：通过 `status` 字段判断兑换是否成功
4. **错误处理**：失败时在 `error_message` 中记录详细错误信息

遵循这些规范可以避免字段名错误导致的SQL异常。 