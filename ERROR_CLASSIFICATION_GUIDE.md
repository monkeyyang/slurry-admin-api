# 错误分类机制说明

## 概述

在礼品卡兑换系统中，我们实现了智能的错误分类机制，将异常分为**业务逻辑错误**和**系统错误**两类，以实现不同的处理策略。

## 为什么需要错误分类？

### 1. **避免无效重试**
- **业务逻辑错误**：如"礼品卡已被兑换"，重试也不会成功
- **系统错误**：如"网络超时"，重试可能会成功

### 2. **优化日志质量**
- **业务逻辑错误**：不记录堆栈跟踪，避免日志污染
- **系统错误**：记录完整堆栈跟踪，便于问题排查

### 3. **提升系统性能**
- 减少不必要的重试次数
- 减少不必要的堆栈跟踪记录

## 错误分类标准

### 业务逻辑错误 (Business Errors)
这些错误是正常业务流程中可能出现的情况，**不需要重试**，**不需要堆栈跟踪**：

```php
private const BUSINESS_ERRORS = [
    '礼品卡无效',
    '该礼品卡已经被兑换',
    '未找到符合条件的汇率',
    '未找到可用的兑换计划',
    '未找到可用的兑换账号',
    '没有找到合适的可执行计划',
    '所有账号已达额度上限',
    'AlreadyRedeemed',
    'Bad card',
    '查卡失败',
    '礼品卡已存在处理记录',
    '正在处理中，请勿重复提交',
    '账号余额不足',
    '超出每日限额',
    '超出总限额',
    '不符合倍数要求'
];
```

### 系统错误 (System Errors)
这些错误通常是临时性的技术问题，**需要重试**，**需要堆栈跟踪**：

```php
private const SYSTEM_ERRORS = [
    '网络错误',
    '连接超时',
    '服务器错误',
    '数据库连接失败',
    '系统繁忙',
    'Connection refused',
    'timeout',
    'Server Error'
];
```

## 实现机制

### 在 GiftCardService 中

```php
/**
 * 判断是否为系统错误（需要记录堆栈跟踪）
 */
protected function isSystemError(Exception $e): bool
{
    // 检查是否为业务逻辑错误
    foreach (self::BUSINESS_ERRORS as $businessError) {
        if (stripos($e->getMessage(), $businessError) !== false) {
            return false; // 是业务错误，不需要堆栈跟踪
        }
    }
    
    return true; // 其他错误视为系统错误
}

// 使用示例
try {
    // 兑换逻辑
} catch (Exception $e) {
    $logData = ['error' => $e->getMessage()];
    
    // 只有系统错误才记录堆栈跟踪
    if ($this->isSystemError($e)) {
        $logData['trace'] = $e->getTraceAsString();
    }
    
    $this->getLogger()->error("兑换失败", $logData);
    throw $e;
}
```

### 在 RedeemGiftCardJob 中

```php
public function handle(): void
{
    try {
        // 任务执行逻辑
    } catch (Throwable $e) {
        if ($this->isBusinessError($e)) {
            // 业务错误：不重试，直接标记为失败
            $this->getLogger()->warning("业务逻辑错误，不进行重试", [
                'error' => $e->getMessage()
            ]);
            
            // 更新进度为失败，不抛出异常
            $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());
            return;
        }
        
        // 系统错误：记录详细信息并重新抛出异常以触发重试
        $this->getLogger()->error("系统错误，将进行重试", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'attempt' => $this->attempts()
        ]);
        
        throw $e; // 触发重试机制
    }
}
```

## 日志示例

### 业务逻辑错误日志
```
[2025-06-23 00:30:48] local.WARNING: 检测到业务逻辑错误，不进行重试 
{
    "job_id": "djZAHuKn86kzJ6lNEJ4J6bntyWGa2c8E",
    "card_code": "XV9T2PXQFCVNDG5G",
    "error": "查卡失败: 该礼品卡已经被兑换-AlreadyRedeemed",
    "attempt": 1
}
```

### 系统错误日志
```
[2025-06-23 00:30:48] local.ERROR: 礼品卡兑换任务失败（系统错误）
{
    "job_id": "djZAHuKn86kzJ6lNEJ4J6bntyWGa2c8E",
    "card_code": "XV9T2PXQFCVNDG5G", 
    "error": "网络连接超时",
    "attempt": 1,
    "max_tries": 3,
    "trace": "Stack trace:\n#0 /path/to/file.php(123): Method->call()\n..."
}
```

## 配置和维护

### 添加新的错误类型

1. **添加业务错误**：
```php
// 在 BUSINESS_ERRORS 常量中添加
private const BUSINESS_ERRORS = [
    // ... 现有错误 ...
    '新的业务错误关键词',
];
```

2. **添加系统错误**：
```php
// 在 SYSTEM_ERRORS 常量中添加
private const SYSTEM_ERRORS = [
    // ... 现有错误 ...
    '新的系统错误关键词',
];
```

### 错误匹配规则

- 使用 `stripos()` 进行**不区分大小写**的模糊匹配
- 只要错误消息中包含关键词就会匹配
- 业务错误优先级高于系统错误

## 监控和分析

### 使用监控脚本查看错误分类

```bash
# 查看业务错误
./monitor_gift_card_logs.sh --search "业务逻辑错误"

# 查看系统错误  
./monitor_gift_card_logs.sh --search "系统错误"

# 查看重试情况
./monitor_gift_card_logs.sh --search "attempt"
```

### 统计信息

```bash
# 查看错误统计
./monitor_gift_card_logs.sh -s
```

## 最佳实践

1. **定期审查错误分类**：根据实际运行情况调整错误分类
2. **监控重试率**：如果系统错误重试率过高，需要排查根本原因
3. **优化错误消息**：确保错误消息包含足够的信息用于分类
4. **及时更新错误列表**：发现新的错误类型时及时添加到相应列表

## 总结

错误分类机制是系统稳定性和可维护性的重要保障：

- ✅ **减少无效重试**：业务错误不重试，节省资源
- ✅ **提高日志质量**：只记录必要的堆栈跟踪
- ✅ **便于问题排查**：清晰区分业务问题和技术问题
- ✅ **提升用户体验**：快速处理业务错误，避免长时间等待

这个机制是**必要且重要**的，建议在所有异常处理场景中使用。 