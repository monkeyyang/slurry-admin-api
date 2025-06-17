# recordFailure 方法优化建议

## 当前代码分析

```php
protected function recordFailure(Throwable $e, BatchGiftCardService $batchService): void
{
    $errorMessage = sprintf(
        "尝试 %d/%d 失败: %s",
        $this->attempts(),
        $this->tries,
        $e->getMessage()
    );

    // 尝试获取礼品卡基本信息（如果可能的话）
    $cardInfo = [];
    try {
        // 这里可以尝试调用GiftCardService的check方法来获取基本信息
        // 但要避免再次抛出异常
    } catch (\Exception $ex) {
        // 忽略获取卡信息时的异常
    }

    $batchService->recordError($this->batchId, $this->giftCardCode, $errorMessage, $cardInfo);
}
```

## 优化后的完整实现

```php
use App\Exceptions\GiftCardExchangeException;
use App\Services\Gift\GiftCardService;
use Illuminate\Support\Facades\Log;

/**
 * 记录失败信息
 */
protected function recordFailure(Throwable $e, BatchGiftCardService $batchService): void
{
    $attempts = $this->attempts();
    $maxTries = $this->tries;
    
    // 1. 构建详细的错误消息
    $errorMessage = $this->buildErrorMessage($e, $attempts, $maxTries);
    
    // 2. 获取礼品卡信息（安全方式）
    $cardInfo = $this->getCardInfoSafely();
    
    // 3. 构建错误上下文
    $errorContext = $this->buildErrorContext($e, $attempts, $maxTries);
    
    // 4. 记录到批次服务
    $batchService->recordError(
        $this->batchId, 
        $this->giftCardCode, 
        $errorMessage, 
        $cardInfo,
        $errorContext  // 新增：错误上下文
    );
    
    // 5. 记录到日志系统
    $this->logFailure($e, $errorMessage, $cardInfo, $errorContext);
}

/**
 * 构建错误消息
 */
private function buildErrorMessage(Throwable $e, int $attempts, int $maxTries): string
{
    $baseMessage = sprintf("尝试 %d/%d 失败", $attempts, $maxTries);
    
    // 如果是自定义异常，包含错误代码
    if ($e instanceof GiftCardExchangeException) {
        return sprintf(
            "%s [Code: %d]: %s",
            $baseMessage,
            $e->getErrorCode(),
            $e->getMessage()
        );
    }
    
    return sprintf("%s: %s", $baseMessage, $e->getMessage());
}

/**
 * 安全获取礼品卡信息
 */
private function getCardInfoSafely(): array
{
    try {
        // 使用GiftCardService的check方法获取基本信息
        $giftCardService = app(GiftCardService::class);
        $result = $giftCardService->check($this->giftCardCode);
        
        if ($result['valid']) {
            return [
                'country_code' => $result['country_code'] ?? null,
                'amount' => $result['amount'] ?? null,
                'currency' => $result['currency'] ?? null,
                'card_type' => $this->cardType ?? null,
                'card_form' => $this->cardForm ?? null,
            ];
        }
        
        return ['error' => $result['error'] ?? 'Unknown validation error'];
        
    } catch (\Exception $ex) {
        // 记录获取卡信息失败的原因，但不影响主流程
        Log::channel('gift_card_exchange')->warning('获取礼品卡信息失败', [
            'code' => $this->giftCardCode,
            'error' => $ex->getMessage()
        ]);
        
        return ['error' => 'Failed to get card info: ' . $ex->getMessage()];
    }
}

/**
 * 构建错误上下文
 */
private function buildErrorContext(Throwable $e, int $attempts, int $maxTries): array
{
    $context = [
        'job_id' => $this->job->getJobId() ?? null,
        'queue' => $this->job->getQueue() ?? null,
        'attempts' => $attempts,
        'max_tries' => $maxTries,
        'room_id' => $this->roomId ?? null,
        'batch_id' => $this->batchId,
        'card_type' => $this->cardType ?? null,
        'card_form' => $this->cardForm ?? null,
        'exception_class' => get_class($e),
        'timestamp' => now()->toISOString(),
    ];
    
    // 如果是自定义异常，添加额外信息
    if ($e instanceof GiftCardExchangeException) {
        $context['error_code'] = $e->getErrorCode();
        $context['exception_context'] = $e->getContext();
        $context['is_business_error'] = $e->isBusinessError();
    }
    
    return $context;
}

/**
 * 记录失败日志
 */
private function logFailure(
    Throwable $e, 
    string $errorMessage, 
    array $cardInfo, 
    array $errorContext
): void {
    $logLevel = $this->determineLogLevel($e);
    $logData = [
        'message' => $errorMessage,
        'card_code' => $this->giftCardCode,
        'card_info' => $cardInfo,
        'context' => $errorContext,
    ];
    
    // 只有系统错误才记录堆栈跟踪
    if ($this->shouldIncludeStackTrace($e)) {
        $logData['stack_trace'] = $e->getTraceAsString();
    }
    
    Log::channel('gift_card_exchange')->log($logLevel, '礼品卡兑换任务失败', $logData);
}

/**
 * 确定日志级别
 */
private function determineLogLevel(Throwable $e): string
{
    if ($e instanceof GiftCardExchangeException && $e->isBusinessError()) {
        return 'warning';  // 业务错误使用warning级别
    }
    
    return 'error';  // 系统错误使用error级别
}

/**
 * 判断是否应该包含堆栈跟踪
 */
private function shouldIncludeStackTrace(Throwable $e): bool
{
    if ($e instanceof GiftCardExchangeException) {
        return $e->isSystemError();
    }
    
    // 对于其他异常，检查是否为业务逻辑错误
    $businessErrors = [
        '礼品卡无效',
        '未找到符合条件的汇率',
        '未找到可用的兑换计划',
        '未找到可用的兑换账号',
    ];
    
    foreach ($businessErrors as $businessError) {
        if (strpos($e->getMessage(), $businessError) !== false) {
            return false;
        }
    }
    
    return true;
}
```

## 优化要点

### 1. **错误消息增强**
- 包含错误代码（如果是自定义异常）
- 更清晰的格式化输出
- 支持不同类型异常的差异化处理

### 2. **礼品卡信息获取完善**
- 实际调用 `GiftCardService::check()` 方法
- 返回结构化的卡信息
- 安全的异常处理，记录获取失败原因

### 3. **错误上下文丰富**
- 队列任务相关信息
- 自定义异常的额外上下文
- 时间戳和环境信息

### 4. **分级日志记录**
- 业务错误使用 warning 级别
- 系统错误使用 error 级别
- 有选择性地包含堆栈跟踪

### 5. **批次服务扩展**
可能需要扩展 `BatchGiftCardService::recordError` 方法：

```php
public function recordError(
    string $batchId, 
    string $giftCardCode, 
    string $errorMessage, 
    array $cardInfo = [],
    array $errorContext = []
): void {
    // 记录到数据库
    // 更新批次统计
    // 触发告警（如果需要）
}
```

## 使用场景

这个优化后的方法适用于：

1. **队列任务失败处理**
2. **批量处理错误收集**
3. **实时监控和告警**
4. **错误分析和统计**
5. **问题排查和调试**

通过这些优化，可以获得更详细、更有用的错误信息，便于问题定位和系统监控。 