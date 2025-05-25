# Laravel 队列配置说明

## 队列优先级设计

系统采用单个 Supervisor 程序处理多个队列，按以下优先级顺序执行：

### 队列优先级（从高到低）

1. **gift_card_exchange** - 礼品卡兑换队列
   - 最高优先级
   - 处理礼品卡兑换请求
   - 超时时间：300秒
   - 重试次数：3次

2. **forecast_crawler** - 预报爬虫队列
   - 高优先级
   - 处理预报数据爬取
   - 超时时间：180秒
   - 重试次数：3次

3. **bill_processing** - 账单处理队列
   - 中等优先级
   - 处理微信账单记录
   - 超时时间：60秒
   - 重试次数：3次

4. **high** - 高优先级通用队列
   - 处理紧急任务
   - 超时时间：120秒

5. **default** - 默认队列
   - 最低优先级
   - 处理普通任务
   - 超时时间：120秒

## Supervisor 配置

### 统一队列配置 (推荐)

```ini
[program:laravel-unified-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /www/wwwroot/slurry-admin-api/artisan queue:work redis --queue=gift_card_exchange,forecast_crawler,bill_processing,high,default --tries=3 --timeout=300 --memory=256
autostart=true
autorestart=true
user=www
numprocs=4
redirect_stderr=true
stdout_logfile=/www/wwwroot/slurry-admin-api/storage/logs/unified-worker.log
stopwaitsecs=60
```

### 配置说明

- `--queue=gift_card_exchange,forecast_crawler,bill_processing,high,default`：按优先级顺序处理队列
- `numprocs=4`：启动4个工作进程
- `--timeout=300`：最大超时时间300秒（适应最长任务）
- `--memory=256`：内存限制256MB

## 队列管理命令

### 使用管理脚本

```bash
# 启动队列工作进程
./scripts/queue-manager.sh start

# 停止队列工作进程
./scripts/queue-manager.sh stop

# 重启队列工作进程
./scripts/queue-manager.sh restart

# 查看队列状态
./scripts/queue-manager.sh status

# 实时监控队列
./scripts/queue-manager.sh monitor
```

### 手动命令

```bash
# 启动队列工作进程
php artisan queue:work redis --queue=gift_card_exchange,forecast_crawler,bill_processing,high,default

# 处理单个任务
php artisan queue:work redis --queue=gift_card_exchange --once

# 清空指定队列
php artisan queue:clear redis --queue=gift_card_exchange
```

## 队列任务分发

### 礼品卡兑换
```php
ProcessGiftCardExchangeJob::dispatch($message, $requestId);
```

### 预报爬虫
```php
ProcessForecastCrawlerJob::dispatch($forecastIds);
```

### 账单处理
```php
ProcessBillJob::dispatch($billId);
```

## 监控和调试

### 检查队列长度
```bash
php artisan tinker --execute="
use Illuminate\Support\Facades\Redis;
echo 'gift_card_exchange: ' . Redis::llen('queues:gift_card_exchange') . PHP_EOL;
echo 'forecast_crawler: ' . Redis::llen('queues:forecast_crawler') . PHP_EOL;
echo 'bill_processing: ' . Redis::llen('queues:bill_processing') . PHP_EOL;
"
```

### 查看失败任务
```bash
php artisan queue:failed
```

### 重试失败任务
```bash
# 重试所有失败任务
php artisan queue:retry all

# 重试指定任务
php artisan queue:retry 5
```

## 性能优化建议

1. **进程数量**：根据服务器CPU核心数调整 `numprocs`
2. **内存限制**：根据任务复杂度调整 `--memory` 参数
3. **超时时间**：根据最长任务执行时间调整 `--timeout`
4. **队列分离**：如果某个队列任务量特别大，可以考虑独立配置

## 故障排除

### 常见问题

1. **队列不执行**
   - 检查 Supervisor 进程状态
   - 检查 Redis 连接
   - 查看日志文件

2. **任务执行失败**
   - 查看 `storage/logs/unified-worker.log`
   - 检查失败任务：`php artisan queue:failed`
   - 查看具体错误信息

3. **内存泄漏**
   - 调整 `--memory` 参数
   - 检查任务代码是否有内存泄漏
   - 定期重启工作进程 