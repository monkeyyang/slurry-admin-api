# Laravel 队列重试机制详解

## 概述

Laravel 队列系统提供了强大的异常处理和重试机制，`RedeemGiftCardJob` 中实现了智能的错误分类和重试控制。

## 重试机制的核心组件

### 1. 队列任务属性

```php
class RedeemGiftCardJob implements ShouldQueue
{
    public int $tries = 3;                    // 最大尝试次数（包括首次执行）
    public array $backoff = [60, 120, 300];  // 重试间隔（秒）
    public int $timeout = 300;               // 单次执行超时时间
}
```

### 2. 重试控制的关键方法

- `$this->attempts()` - 获取当前尝试次数
- `$this->tries` - 最大尝试次数
- `throw $exception` - 触发重试
- `return` - 直接结束，不重试

## 重试机制工作流程

### 第一次执行（attempts() = 1）
```php
public function handle(GiftCardService $giftCardService, BatchGiftCardService $batchService): void
{
    $this->getLogger()->info("开始处理礼品卡兑换任务", [
        'attempt' => $this->attempts() // 输出: 1
    ]);
    
    try {
        // 执行业务逻辑
        $result = $giftCardService->redeemGiftCard();
        
        // 成功：任务完成，不会重试
        
    } catch (Throwable $e) {
        // 异常处理逻辑
        if ($this->isBusinessError($e)) {
            // 业务错误：直接失败，不重试
            $this->getLogger()->warning("业务逻辑错误，不重试", [
                'attempt' => $this->attempts(), // 输出: 1
                'error' => $e->getMessage()
            ]);
            
            // 发送失败消息，更新进度
            send_msg_to_wechat($this->roomId, "兑换失败\n" . $e->getMessage());
            $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());
            
            return; // 关键：不抛出异常，任务直接结束
        }
        
        // 系统错误：记录日志并重新抛出异常
        $this->getLogger()->error("系统错误，将重试", [
            'attempt' => $this->attempts(),     // 输出: 1
            'max_tries' => $this->tries,        // 输出: 3
            'error' => $e->getMessage()
        ]);
        
        throw $e; // 关键：抛出异常触发重试机制
    }
}
```

### 第二次执行（attempts() = 2）
- Laravel 队列系统检测到异常被抛出
- 检查 `attempts() < tries`（2 < 3）
- 等待 `backoff[1]` = 120 秒
- 重新将任务放入队列执行

### 第三次执行（attempts() = 3）
- 如果再次失败且抛出异常
- 检查 `attempts() < tries`（3 < 3 = false）
- 不再重试，调用 `failed()` 方法

## RedeemGiftCardJob 中的智能重试策略

### 1. 业务错误处理（不重试）

```php
protected array $businessErrors = [
    '礼品卡无效',
    '该礼品卡已经被兑换', 
    '未找到符合条件的汇率',
    '未找到可用的兑换计划',
    '未找到可用的兑换账号',
    'AlreadyRedeemed',
    'Tap Continue to request re-enablement',
    'Bad card',
    '查卡失败',
];

protected function isBusinessError(Throwable $e): bool
{
    $message = $e->getMessage();
    foreach ($this->businessErrors as $businessError) {
        if (strpos($message, $businessError) !== false) {
            return true;
        }
    }
    return false;
}
```

**业务错误的处理逻辑：**
```php
if ($this->isBusinessError($e)) {
    // 1. 记录警告日志（不记录堆栈跟踪）
    $this->getLogger()->warning("检测到业务逻辑错误，不进行重试");
    
    // 2. 发送失败消息
    send_msg_to_wechat($this->roomId, "兑换失败\n" . $e->getMessage());
    
    // 3. 更新批量任务进度
    $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());
    
    // 4. 直接返回，不抛出异常 -> 不触发重试
    return;
}
```

### 2. 系统错误处理（会重试）

```php
protected array $systemErrors = [
    '系统错误',
    '网络错误', 
    '服务器错误',
    '数据库错误',
];
```

**系统错误的处理逻辑：**
```php
// 系统错误处理
$this->getLogger()->error("礼品卡兑换任务失败（系统错误）", [
    'attempt' => $this->attempts(),
    'max_tries' => $this->tries,
    'trace' => $e->getTraceAsString() // 记录完整堆栈跟踪
]);

// 更新进度（标记为可能重试的失败）
$batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());

// 重新抛出异常 -> 触发重试机制
throw $e;
```

## 重试时间间隔控制

### backoff 数组的使用

```php
public array $backoff = [60, 120, 300]; // 秒

// 第1次失败后：等待 60 秒重试
// 第2次失败后：等待 120 秒重试  
// 第3次失败后：等待 300 秒重试（如果还有重试机会）
```

### 动态 backoff 策略

也可以使用方法返回动态间隔：

```php
public function backoff(): array
{
    // 可以根据错误类型返回不同的重试间隔
    return [60, 120, 300];
}
```

## failed() 方法的调用时机

```php
public function failed(Throwable $exception): void
{
    // 只有在以下情况才会调用：
    // 1. 达到最大重试次数 (attempts >= tries)
    // 2. 任务超时 (execution time > timeout)
    // 3. 手动调用 $this->fail()
    
    // 业务错误已在 handle() 中处理，这里不重复处理
    if ($this->isBusinessError($exception)) {
        $this->getLogger()->info("业务逻辑错误已在handle方法中处理");
        return;
    }
    
    // 系统错误的最终失败处理
    $this->getLogger()->error("礼品卡兑换任务最终失败", [
        'attempts' => $this->attempts(),
        'max_tries' => $this->tries,
        'error' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString()
    ]);
    
    // 发送最终失败消息
    send_msg_to_wechat($this->roomId, "兑换失败\n" . $exception->getMessage());
    
    // 确保更新批量任务进度
    $batchService = app(BatchGiftCardService::class);
    $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $exception->getMessage());
}
```

## 实际执行示例

### 场景1：业务错误（礼品卡无效）

```
第1次执行 (attempts=1):
├── 验证礼品卡 -> 抛出异常："礼品卡无效"
├── isBusinessError() -> 返回 true
├── 记录警告日志，发送微信消息
├── return（不抛出异常）
└── 任务结束，不重试
```

### 场景2：系统错误（网络超时）

```
第1次执行 (attempts=1):
├── 调用API -> 抛出异常："网络错误"
├── isBusinessError() -> 返回 false
├── 记录错误日志
├── throw $e（抛出异常）
└── 队列系统安排重试

等待 60 秒...

第2次执行 (attempts=2):
├── 调用API -> 抛出异常："网络错误"
├── 记录错误日志
├── throw $e
└── 队列系统安排重试

等待 120 秒...

第3次执行 (attempts=3):
├── 调用API -> 抛出异常："网络错误"
├── 记录错误日志
├── throw $e
├── attempts >= tries (3)
└── 调用 failed() 方法，任务最终失败
```

### 场景3：第2次重试成功

```
第1次执行 (attempts=1):
├── 网络错误 -> throw $e
└── 安排重试

第2次执行 (attempts=2):
├── 执行成功
├── 发送成功消息
└── 任务完成
```

## 队列系统的内部实现

Laravel 队列 Worker 的处理逻辑（简化版）：

```php
// Laravel 内部处理逻辑（伪代码）
class QueueWorker 
{
    public function process($job)
    {
        try {
            // 执行任务的 handle() 方法
            $job->handle();
            
            // 成功：删除任务
            $job->delete();
            
        } catch (Exception $e) {
            // 失败：检查重试次数
            if ($job->attempts() < $job->tries) {
                // 重新入队，延迟执行
                $delay = $job->backoff[$job->attempts() - 1] ?? 0;
                $job->release($delay);
            } else {
                // 达到最大重试次数
                $job->failed($e);
                $job->delete();
            }
        }
    }
}
```

## 监控和调试

### 1. 日志记录

```php
// 在每次尝试时记录关键信息
$this->getLogger()->info("任务执行信息", [
    'attempt' => $this->attempts(),        // 当前尝试次数
    'max_tries' => $this->tries,          // 最大尝试次数
    'remaining_tries' => $this->tries - $this->attempts(), // 剩余尝试次数
]);
```

### 2. 队列监控命令

```bash
# 查看队列状态
php artisan queue:work --verbose

# 查看失败的任务
php artisan queue:failed

# 重试失败的任务
php artisan queue:retry all
```

## 最佳实践

### 1. 合理设置重试参数

```php
// 根据业务场景调整
public int $tries = 3;              // 不要设置过高，避免资源浪费
public array $backoff = [60, 120, 300]; // 指数退避，给系统恢复时间
public int $timeout = 300;          // 合理的超时时间
```

### 2. 精确的错误分类

```php
// 明确区分业务错误和系统错误
protected array $businessErrors = [
    // 这些错误重试也不会成功
    '礼品卡无效',
    '该礼品卡已经被兑换',
    // ...
];

protected array $systemErrors = [
    // 这些错误可能是临时的，重试可能成功
    '网络错误',
    '服务器错误', 
    // ...
];
```

### 3. 详细的日志记录

```php
// 记录足够的上下文信息
$this->getLogger()->error("任务失败", [
    'job_id' => $this->job->getJobId(),
    'attempt' => $this->attempts(),
    'max_tries' => $this->tries,
    'card_code' => $this->giftCardCode,
    'error_type' => $this->isBusinessError($e) ? 'business' : 'system',
    'error' => $e->getMessage(),
    'trace' => $this->isSystemError($e) ? $e->getTraceAsString() : null
]);
```

## 总结

Laravel 队列的重试机制是自动的，关键在于：

1. **抛出异常** = 触发重试
2. **不抛出异常** = 直接结束
3. **tries 属性** = 控制最大重试次数
4. **backoff 属性** = 控制重试间隔
5. **智能错误分类** = 避免无意义的重试

`RedeemGiftCardJob` 通过智能的错误分类和处理，确保只有可能成功的系统错误才会重试，而业务逻辑错误则直接失败，避免了资源浪费和无效重试。 