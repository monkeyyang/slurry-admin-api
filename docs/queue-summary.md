# Laravel 队列系统配置总结

## 队列任务概览

系统目前配置了5个队列任务，按优先级分配：

### 1. 高优先级队列
- **礼品卡兑换** (`gift_card_exchange`)
  - 任务类：`ProcessGiftCardExchangeJob`
  - 进程数：4个
  - 超时时间：300秒
  - 检查间隔：1秒
  - 日志文件：`gift_card_exchange-YYYY-MM-DD.log`

### 2. 中优先级队列
- **预报爬虫** (`forecast_crawler`)
  - 任务类：`ProcessForecastCrawlerJob`
  - 进程数：3个
  - 超时时间：180秒
  - 检查间隔：3秒
  - 日志文件：`forecast_crawler-YYYY-MM-DD.log`

- **账单处理** (`bill_processing`)
  - 任务类：`ProcessBillJob`
  - 进程数：3个
  - 超时时间：60秒
  - 检查间隔：3秒
  - 日志文件：`bill_processing-YYYY-MM-DD.log`

- **卡密查询** (`card_query`)
  - 任务类：`ProcessCardQueryJob`
  - 进程数：2个
  - 超时时间：300秒
  - 检查间隔：3秒
  - 日志文件：`card_query-YYYY-MM-DD.log`

### 3. 低优先级队列
- **其他任务** (`high`, `default`)
  - 进程数：2个
  - 超时时间：300秒
  - 检查间隔：5秒
  - 日志文件：`worker-default.log`

## 资源配置

### 8核16G服务器配置
- **总进程数**：14个
- **预计CPU使用率**：70-80%
- **预计内存使用**：约2GB（队列进程）
- **剩余资源**：给MySQL、Redis、Nginx等

### 进程分配策略
```
高优先级：4个进程 (28.6%)
中优先级：8个进程 (57.1%) 
低优先级：2个进程 (14.3%)
```

## 监控告警阈值

| 队列名称 | 积压告警阈值 | 说明 |
|---------|-------------|------|
| gift_card_exchange | > 100个任务 | 用户体验敏感，需快速处理 |
| forecast_crawler | > 50个任务 | 网络请求较多，适中处理 |
| bill_processing | > 50个任务 | 数据库操作，适中处理 |
| card_query | > 30个任务 | 定时任务，相对较少 |
| high/default | > 20个任务 | 后台任务，低优先级 |

## 日志管理

### 日志文件保留策略
- **礼品卡兑换**：30天（业务重要）
- **预报爬虫**：14天
- **账单处理**：14天
- **卡密查询**：14天
- **队列任务**：7天
- **微信相关**：14天

### 日志查看命令
```bash
# 实时查看各队列日志
tail -f storage/logs/gift_card_exchange-$(date +%Y-%m-%d).log
tail -f storage/logs/forecast_crawler-$(date +%Y-%m-%d).log
tail -f storage/logs/bill_processing-$(date +%Y-%m-%d).log
tail -f storage/logs/card_query-$(date +%Y-%m-%d).log

# 查看队列积压情况
redis-cli LLEN queues:gift_card_exchange
redis-cli LLEN queues:forecast_crawler
redis-cli LLEN queues:bill_processing
redis-cli LLEN queues:card_query
```

## Supervisor 配置

### 配置文件位置
- 主配置：`/etc/supervisor/conf.d/laravel-worker.conf`
- 优化配置：`supervisor-8core-16gb.conf`

### 管理命令
```bash
# 查看所有队列进程状态
supervisorctl status laravel-workers:*

# 重启所有队列进程
supervisorctl restart laravel-workers:*

# 重新加载配置
supervisorctl reread
supervisorctl update

# 查看进程日志
supervisorctl tail laravel-worker-gift-card:laravel-worker-gift-card_00
```

## 性能优化建议

### 1. 动态调整策略
- **高峰期**：增加礼品卡兑换进程到6个
- **低峰期**：减少各队列进程数节省资源
- **监控驱动**：根据队列积压情况自动调整

### 2. 扩容路径
- **业务增长50%**：增加到18个进程
- **业务翻倍**：升级到16核32G服务器
- **大规模**：部署多台队列服务器

### 3. 故障处理
- **进程死锁**：自动重启机制
- **内存泄漏**：定期重启进程
- **Redis连接**：连接池和重连机制

## 部署清单

### 1. 文件权限检查
```bash
# 确保日志目录权限正确
chown -R www:www storage/logs/
chmod -R 755 storage/logs/

# 确保队列进程可写入日志
ls -la storage/logs/
```

### 2. Redis 配置检查
```bash
# 检查Redis连接
redis-cli ping

# 检查队列键
redis-cli keys "queues:*"
```

### 3. 监控脚本部署
```bash
# 设置监控脚本权限
chmod +x scripts/queue-monitor.sh

# 添加到定时任务
echo "*/5 * * * * /www/wwwroot/slurry-admin-api/scripts/queue-monitor.sh" | crontab -
```

## 常见问题解决

### 1. 日志权限问题
```bash
# 问题：Permission denied
# 解决：修改文件所有者
chown www:www storage/logs/*.log
```

### 2. 队列积压严重
```bash
# 问题：队列任务堆积
# 解决：临时增加进程数
supervisorctl start laravel-worker-gift-card:laravel-worker-gift-card_04
```

### 3. 内存使用过高
```bash
# 问题：队列进程内存泄漏
# 解决：重启进程
supervisorctl restart laravel-workers:*
```

## 联系方式

如有问题，请查看：
- 日志文件：`storage/logs/`
- 监控脚本：`scripts/queue-monitor.sh`
- 配置文档：`docs/queue-performance-tuning.md`
- 日志指南：`docs/logging-guide.md` 