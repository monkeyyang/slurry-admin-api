# RedeemGiftCardJob 重试机制优化

## 问题描述

根据日志信息：
```
[2025-06-20 23:04:30] local.INFO: 兑换任务完成 {"task_id":"f26482d6-cb53-473b-8a33-b5f3796e0654","attempt":6,"response":{"code":0,"data":{"task_id":"f26482d6-cb53-473b-8a33-b5f3796e0654","status":"completed","items":[{"data_id":"f8d0t5g@icloud.com:X3C2JN94FPMYRMQX","status":"completed","msg":"兑换失败: Tap Continue to request re-enablement.","result":"{\"code\":-14,\"msg\":\"兑换失败: Tap Continue to request re-enablement.\",\"username\":\"f8d0t5g@icloud.com\",\"total\":\"\",\"fund\":\"\",\"available\":\"未知\"}","update_time":"2025-06-20 23:04:29"}],"msg":"任务已完成","update_time":"2025-06-20 23:04:29"},"msg":"执行成功"}}
```

发现以下问题：
1. 兑换API任务返回失败结果："Tap Continue to request re-enablement"
2. 这种错误被当作系统错误进行队列重试
3. **修正**："Tap Continue to request re-enablement"是临时性错误，需要重试

## 解决方案

### 1. 基于类属性的错误分类机制

在 `RedeemGiftCardJob` 中定义了类属性来管理错误类型：

```php
// 业务逻辑错误，不需要堆栈跟踪，直接更新进度为失败，不抛出异常
protected array $businessErrors = [
    '礼品卡无效',
    '该礼品卡已经被兑换',
    '未找到符合条件的汇率',
    '未找到可用的兑换计划',
    '未找到可用的兑换账号',
    'AlreadyRedeemed',
    'Bad card',
    '查卡失败'
];

// 系统错误，需要堆栈跟踪，抛出异常，队列会重试
protected array $systemErrors = [
    '系统错误',
    '网络错误',
    '服务器错误',
    '数据库错误',
    'Tap Continue to request re-enablement'
];
```

### 2. 优化的错误判断逻辑

**isSystemError() 方法：**
```php
protected function isSystemError(Throwable $e): bool
{
    $message = $e->getMessage();

    // 首先检查是否为明确的业务逻辑错误
    foreach ($this->businessErrors as $businessError) {
        if (strpos($message, $businessError) !== false) {
            return false;
        }
    }

    // 然后检查是否为明确的系统错误
    foreach ($this->systemErrors as $systemError) {
        if (strpos($message, $systemError) !== false) {
            return true;
        }
    }

    // 包含"兑换失败:"前缀的错误需要进一步判断
    if (strpos($message, '兑换失败:') === 0) {
        // 检查是否包含需要重试的系统错误关键词
        $retryableErrors = [
            'Tap Continue to request re-enablement',
            '网络',
            '服务器',
            '超时',
            '连接',
            '系统'
        ];
        
        foreach ($retryableErrors as $retryableError) {
            if (strpos($message, $retryableError) !== false) {
                return true; // 需要重试的系统错误
            }
        }
        
        return false; // 其他兑换失败视为业务逻辑错误
    }

    // 其他错误视为系统错误，需要堆栈跟踪
    return true;
}
```

**isBusinessError() 方法：**
```php
protected function isBusinessError(Throwable $e): bool
{
    $message = $e->getMessage();

    // 检查是否为明确的业务逻辑错误
    foreach ($this->businessErrors as $businessError) {
        if (strpos($message, $businessError) !== false) {
            return true;
        }
    }

    // 检查是否为明确的系统错误（需要重试）
    foreach ($this->systemErrors as $systemError) {
        if (strpos($message, $systemError) !== false) {
            return false;
        }
    }

    // 包含"兑换失败:"前缀的错误需要进一步判断
    if (strpos($message, '兑换失败:') === 0) {
        // 检查是否包含需要重试的系统错误关键词
        $retryableErrors = [
            'Tap Continue to request re-enablement',
            '网络',
            '服务器',
            '超时',
            '连接',
            '系统'
        ];
        
        foreach ($retryableErrors as $retryableError) {
            if (strpos($message, $retryableError) !== false) {
                return false; // 需要重试的系统错误
            }
        }
        
        return true; // 其他兑换失败视为业务逻辑错误
    }

    // 其他错误视为系统错误，需要重试
    return false;
}
```

### 3. 错误分类优先级

1. **明确的业务逻辑错误**：优先匹配 `$businessErrors` 数组
2. **明确的系统错误**：优先匹配 `$systemErrors` 数组
3. **"兑换失败:"前缀错误**：根据内容进一步判断
   - 包含系统错误关键词（网络、服务器、超时等）→ 系统错误（需要重试）
   - 其他内容 → 业务逻辑错误（不重试）
4. **其他错误**：视为系统错误

### 4. 微信消息发送逻辑

**修改前的问题：**
- 每次异常都立即发送微信消息，包括重试过程中的异常
- 导致用户收到多条重复的失败消息

**修改后的逻辑：**
- **业务逻辑错误**：立即发送失败消息（因为不会重试）
- **系统错误重试中**：不发送消息（等待最终结果）
- **最后一次重试失败**：不发送消息（由failed方法处理）
- **任务最终失败**：发送失败消息

### 5. 微信消息发送时机

1. **兑换成功**：立即发送成功消息
2. **业务逻辑错误**：立即发送失败消息（不重试）
3. **系统错误重试中**：不发送消息
4. **最后一次重试失败**：不发送消息（由failed方法处理）
5. **任务最终失败**：发送失败消息

**避免重复消息的机制：**
- 业务逻辑错误：只在 `handle()` 方法中发送一次
- 系统错误：只在 `failed()` 方法中发送一次
- 重试过程中：不发送任何消息

## 优化效果

1. **避免重复消息**：微信消息只在任务真正失败时发送，避免重试过程中的重复消息
2. **提高系统效率**：减少无效的消息发送，节省系统资源
3. **更好的错误分类**：基于类属性的明确错误分类机制
4. **正确处理临时性错误**："Tap Continue to request re-enablement"等临时性错误会进行重试
5. **易于维护**：错误类型通过类属性管理，便于添加和修改

## 错误类型分类

### 业务逻辑错误（不重试，立即发送消息）
- 礼品卡无效
- 该礼品卡已经被兑换
- 未找到符合条件的汇率
- 未找到可用的兑换计划
- 未找到可用的兑换账号
- AlreadyRedeemed
- Bad card
- 查卡失败
- **兑换失败: [其他业务原因]**（如礼品卡已过期、金额不匹配等）

### 系统错误（需要重试，最终失败时发送消息）
- 系统错误
- 网络错误
- 服务器错误
- 数据库错误
- **兑换失败: Tap Continue to request re-enablement**（临时性错误，需要重试）
- **兑换失败: [网络/服务器/超时/连接/系统相关错误]**（API层面的临时错误，需要重试）

### "兑换失败"错误的细分处理

当错误消息以"兑换失败:"开头时，系统会根据错误内容进行进一步判断：

**需要重试的系统错误：**
- 兑换失败: Tap Continue to request re-enablement
- 兑换失败: 网络连接超时
- 兑换失败: 服务器响应错误
- 兑换失败: 系统维护中
- 兑换失败: 连接失败

**不需要重试的业务逻辑错误：**
- 兑换失败: 礼品卡已过期
- 兑换失败: 金额不匹配
- 兑换失败: 国家不支持
- 兑换失败: 其他业务相关错误

## 注意事项

1. 修改后需要重启队列工作进程以应用新的逻辑
2. 建议监控日志，确保错误分类正确
3. 如需添加新的错误类型，直接修改类属性数组即可
4. 微信消息发送逻辑已优化，避免重复消息
5. **"兑换失败:"前缀的错误会根据内容进行智能判断，API层面的临时错误会重试**
6. 如需添加新的可重试错误关键词，可以修改 `$retryableErrors` 数组 