# Laravel 队列性能调优指南

## 8核16G服务器配置建议

### 推荐配置

#### 总进程数：14个
- **礼品卡兑换队列**：4个进程（高优先级）
- **预报爬虫队列**：3个进程（中优先级）
- **账单处理队列**：3个进程（中优先级）
- **卡密查询队列**：2个进程（中优先级）
- **其他任务队列**：2个进程（低优先级）

#### 资源分配
- **CPU使用率**：约60-75%（保留25-40%给系统）
- **内存使用**：约1.5-2GB（队列进程）
- **剩余资源**：给数据库、Redis、Web服务器等

### 配置参数说明

#### 1. 进程数量 (numprocs)
```ini
# 高优先级任务 - 需要快速响应
numprocs=4  # 礼品卡兑换

# 中优先级任务 - 平衡处理速度和资源
numprocs=3  # 预报爬虫、账单处理

# 低优先级任务 - 后台处理
numprocs=2  # 其他任务
```

#### 2. 超时时间 (timeout)
```bash
--timeout=300  # 礼品卡兑换（5分钟）
--timeout=180  # 预报爬虫（3分钟）
--timeout=60   # 账单处理（1分钟）
```

#### 3. 内存限制 (memory)
```bash
--memory=256   # 每个进程最大256MB
```

#### 4. 睡眠时间 (sleep)
```bash
--sleep=1   # 高优先级（1秒检查一次）
--sleep=3   # 中优先级（3秒检查一次）
--sleep=5   # 低优先级（5秒检查一次）
```

### 监控指标

#### 1. 队列积压监控
```bash
# 检查各队列长度
redis-cli LLEN queues:gift_card_exchange
redis-cli LLEN queues:forecast_crawler
redis-cli LLEN queues:bill_processing
redis-cli LLEN queues:card_query
```

**告警阈值：**
- 礼品卡兑换：> 100个任务
- 预报爬虫：> 50个任务
- 账单处理：> 50个任务
- 卡密查询：> 30个任务

#### 2. 系统资源监控
```bash
# CPU负载
uptime

# 内存使用
free -h

# 队列进程内存
ps aux | grep "queue:work" | awk '{sum+=$6} END {print sum/1024 " MB"}'
```

#### 3. 处理速度监控
```bash
# 每分钟处理任务数
tail -f storage/logs/worker-*.log | grep "处理完成"
```

### 性能调优策略

#### 1. 根据业务量调整

**低峰期（夜间）：**
```ini
# 减少进程数，节省资源
礼品卡兑换：2个进程
预报爬虫：2个进程
账单处理：2个进程
卡密查询：1个进程
其他任务：1个进程
```

**高峰期（白天）：**
```ini
# 增加进程数，提高处理能力
礼品卡兑换：6个进程
预报爬虫：4个进程
账单处理：4个进程
卡密查询：3个进程
其他任务：2个进程
```

#### 2. 动态调整脚本

```bash
#!/bin/bash
# 根据队列积压情况动态调整进程数

GIFT_CARD_QUEUE=$(redis-cli LLEN queues:gift_card_exchange)
CARD_QUERY_QUEUE=$(redis-cli LLEN queues:card_query)

if [ "$GIFT_CARD_QUEUE" -gt 200 ]; then
    # 增加礼品卡兑换进程
    supervisorctl start laravel-worker-gift-card:*
elif [ "$GIFT_CARD_QUEUE" -lt 10 ]; then
    # 减少礼品卡兑换进程
    supervisorctl stop laravel-worker-gift-card:laravel-worker-gift-card_03
fi

if [ "$CARD_QUERY_QUEUE" -gt 50 ]; then
    # 增加卡密查询进程
    supervisorctl start laravel-worker-card-query:*
fi
```

### 故障排查

#### 1. 进程死锁
```bash
# 检查僵死进程
ps aux | grep "queue:work" | grep -v grep

# 重启所有队列进程
supervisorctl restart laravel-workers:*
```

#### 2. 内存泄漏
```bash
# 定期重启进程防止内存泄漏
# 在supervisor配置中添加：
stopwaitsecs=60
```

#### 3. Redis连接问题
```bash
# 检查Redis连接
redis-cli ping

# 检查Redis内存使用
redis-cli info memory
```

### 最佳实践

#### 1. 进程管理
- 使用Supervisor管理队列进程
- 设置自动重启机制
- 配置日志轮转

#### 2. 监控告警
- 设置队列积压告警
- 监控系统资源使用
- 记录处理时间统计

#### 3. 容量规划
- 根据业务增长预估队列负载
- 定期评估服务器配置
- 准备扩容方案

### 扩容建议

#### 垂直扩容（升级硬件）
- **16核32G**：可支持25-30个队列进程
- **32核64G**：可支持50-60个队列进程

#### 水平扩容（增加服务器）
- 使用Redis集群
- 部署多台队列处理服务器
- 实现负载均衡

### 测试验证

#### 1. 压力测试
```bash
# 批量添加测试任务
for i in {1..1000}; do
    php artisan queue:test-gift-card
done

# 测试卡密查询队列
php artisan queue:dispatch ProcessCardQueryJob
```

#### 2. 性能基准
```bash
# 记录处理1000个任务的时间
time php artisan queue:process-batch 1000
```

#### 3. 稳定性测试
```bash
# 长时间运行测试
nohup bash -c 'for i in {1..10000}; do php artisan queue:test-all; sleep 1; done' &