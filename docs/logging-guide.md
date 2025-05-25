# Laravel 日志使用指南

## 自定义日志通道

系统已配置了多个专门的日志通道，用于不同模块的日志记录：

### 可用的日志通道

1. **gift_card_exchange** - 礼品卡兑换日志
   - 文件：`storage/logs/gift_card_exchange-YYYY-MM-DD.log`
   - 保留天数：30天

2. **forecast_crawler** - 预报爬虫日志
   - 文件：`storage/logs/forecast_crawler-YYYY-MM-DD.log`
   - 保留天数：14天

3. **bill_processing** - 账单处理日志
   - 文件：`storage/logs/bill_processing-YYYY-MM-DD.log`
   - 保留天数：14天

4. **queue_jobs** - 队列任务日志
   - 文件：`storage/logs/queue_jobs-YYYY-MM-DD.log`
   - 保留天数：7天

5. **wechat** - 微信相关日志
   - 文件：`storage/logs/wechat-YYYY-MM-DD.log`
   - 保留天数：14天

## 使用方法

### 基本用法

```php
use Illuminate\Support\Facades\Log;

// 使用指定通道记录日志
Log::channel('gift_card_exchange')->info('礼品卡兑换开始', [
    'card_number' => 'ABC123',
    'request_id' => 'req_123'
]);

Log::channel('forecast_crawler')->error('爬虫失败', [
    'forecast_id' => 123,
    'error' => $exception->getMessage()
]);
```

### 在队列任务中使用

```php
// 在 ProcessGiftCardExchangeJob 中
Log::channel('gift_card_exchange')->info("开始处理礼品卡兑换", [
    'request_id' => $this->requestId,
    'message' => $this->message,
    'attempt' => $this->attempts()
]);

// 在 ProcessForecastCrawlerJob 中
Log::channel('forecast_crawler')->info('处理预报IDs: ' . implode(',', $this->forecastIds));

// 在 ProcessBillJob 中
Log::channel('bill_processing')->info("开始处理账单 {$this->billId}");
```

### 在服务类中使用

```php
// 在 GiftCardExchangeService 中
Log::channel('gift_card_exchange')->info('验证礼品卡', [
    'card_number' => $cardNumber,
    'validation_result' => $result
]);

// 在 ForecastCrawlerService 中
Log::channel('forecast_crawler')->debug('爬虫请求详情', [
    'url' => $url,
    'params' => $params
]);
```

### 日志级别

支持的日志级别（按严重程度排序）：

```php
Log::channel('channel_name')->emergency($message);  // 系统不可用
Log::channel('channel_name')->alert($message);      // 必须立即采取行动
Log::channel('channel_name')->critical($message);   // 严重错误
Log::channel('channel_name')->error($message);      // 运行时错误
Log::channel('channel_name')->warning($message);    // 警告
Log::channel('channel_name')->notice($message);     // 正常但重要的事件
Log::channel('channel_name')->info($message);       // 信息性消息
Log::channel('channel_name')->debug($message);      // 调试信息
```

## 日志格式

### 推荐的日志格式

```php
// 成功操作
Log::channel('gift_card_exchange')->info('操作描述', [
    'request_id' => $requestId,
    'user_id' => $userId,
    'data' => $relevantData,
    'duration' => $executionTime
]);

// 错误操作
Log::channel('gift_card_exchange')->error('错误描述', [
    'request_id' => $requestId,
    'error_code' => $errorCode,
    'error_message' => $exception->getMessage(),
    'stack_trace' => $exception->getTraceAsString(),
    'context' => $additionalContext
]);
```

## 日志查看命令

### 实时查看日志

```bash
# 查看礼品卡兑换日志
tail -f storage/logs/gift_card_exchange-$(date +%Y-%m-%d).log

# 查看预报爬虫日志
tail -f storage/logs/forecast_crawler-$(date +%Y-%m-%d).log

# 查看账单处理日志
tail -f storage/logs/bill_processing-$(date +%Y-%m-%d).log
```

### 搜索日志

```bash
# 搜索特定请求ID的日志
grep "request_id.*req_123" storage/logs/gift_card_exchange-*.log

# 搜索错误日志
grep "ERROR" storage/logs/gift_card_exchange-*.log

# 搜索最近的错误
grep "ERROR" storage/logs/gift_card_exchange-$(date +%Y-%m-%d).log | tail -10
```

## 日志轮转和清理

### 自动清理

Laravel 会根据配置自动清理过期的日志文件：

- `gift_card_exchange`: 保留30天
- `forecast_crawler`: 保留14天
- `bill_processing`: 保留14天
- `queue_jobs`: 保留7天

### 手动清理

```bash
# 清理30天前的日志
find storage/logs -name "*.log" -mtime +30 -delete

# 清理特定通道的旧日志
find storage/logs -name "gift_card_exchange-*.log" -mtime +30 -delete
```

## 性能考虑

### 最佳实践

1. **避免在循环中记录大量日志**
2. **使用适当的日志级别**
3. **避免记录敏感信息**（如密码、API密钥）
4. **使用结构化数据**（数组格式）而不是字符串拼接

### 示例

```php
// ✅ 好的做法
Log::channel('gift_card_exchange')->info('卡片验证完成', [
    'card_number' => substr($cardNumber, 0, 4) . '****', // 隐藏敏感信息
    'is_valid' => $isValid,
    'validation_time' => $validationTime
]);

// ❌ 避免的做法
Log::channel('gift_card_exchange')->info("卡片 {$cardNumber} 验证结果: {$isValid}");
```

## 监控和告警

### 错误日志监控

可以设置脚本监控错误日志：

```bash
#!/bin/bash
# 检查最近5分钟的错误日志
ERROR_COUNT=$(grep "ERROR" storage/logs/gift_card_exchange-$(date +%Y-%m-%d).log | grep "$(date -d '5 minutes ago' '+%Y-%m-%d %H:%M')" | wc -l)

if [ $ERROR_COUNT -gt 10 ]; then
    echo "礼品卡兑换错误过多: $ERROR_COUNT 个错误"
    # 发送告警通知
fi
``` 