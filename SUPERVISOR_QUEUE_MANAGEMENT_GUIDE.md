# Supervisor 消息队列管理指南

## 概述

Supervisor是一个进程监控和管理工具，用于在UNIX系统上管理和监控进程。在Laravel项目中，我们使用Supervisor来管理队列工作者进程，确保消息队列的稳定运行。

## 🎯 当前配置概览

您的项目已经配置了以下队列和服务：

### 队列配置
- **礼品卡兑换队列** (`gift_card_exchange`) - 最高优先级，4个进程
- **预报爬虫队列** (`forecast_crawler`) - 高优先级，2个进程
- **账单处理队列** (`bill_processing`) - 中等优先级，2个进程
- **卡密查询队列** (`card_query`) - 中等优先级，2个进程
- **微信消息队列** (`wechat-message`) - 中等优先级，2个进程
- **邮件队列** (`mail`) - 独立处理，1个进程
- **默认队列** (`high,default`) - 低优先级，2个进程

### 其他服务
- **WebSocket服务** - 交易监控服务
- **监控服务** - 礼品卡日志监控、WebSocket监控
- **Laravel调度器** - 定时任务调度

**总计：19个进程，预计内存使用约4.3GB**

## 🚀 快速开始

### 1. 使用管理脚本安装 (推荐)

```bash
# 完整安装 Supervisor (需要root权限)
sudo ./supervisor-manager.sh install
```

### 2. 手动安装

```bash
# Ubuntu/Debian
sudo apt-get update
sudo apt-get install supervisor

# CentOS/RHEL
sudo yum install supervisor

# 启动服务
sudo systemctl enable supervisor
sudo systemctl start supervisor
```

### 3. 部署配置文件

```bash
# 复制配置文件到系统目录
sudo cp supervisor-complete.conf /etc/supervisor/conf.d/slurry-admin-api.conf

# 重新加载配置
sudo supervisorctl reread
sudo supervisorctl update

# 启动所有服务
sudo supervisorctl start all
```

## 📋 管理命令

### 使用管理脚本 (推荐)

```bash
# 查看服务状态
./supervisor-manager.sh status

# 查看特定服务日志
./supervisor-manager.sh logs laravel-gift-card-worker

# 查看微信消息队列日志
./supervisor-manager.sh logs laravel-wechat-message-worker

# 重启所有服务
./supervisor-manager.sh restart

# 重启特定服务
./supervisor-manager.sh restart laravel-gift-card-worker

# 停止所有服务
./supervisor-manager.sh stop

# 更新配置
./supervisor-manager.sh update

# 重新加载配置并重启
./supervisor-manager.sh reload
```

### 直接使用supervisorctl

```bash
# 查看状态
sudo supervisorctl status

# 启动服务
sudo supervisorctl start laravel-gift-card-worker
sudo supervisorctl start all

# 停止服务
sudo supervisorctl stop laravel-gift-card-worker
sudo supervisorctl stop all

# 重启服务
sudo supervisorctl restart laravel-gift-card-worker
sudo supervisorctl restart all

# 查看日志
sudo supervisorctl tail laravel-gift-card-worker
sudo supervisorctl tail -f laravel-gift-card-worker  # 实时日志

# 重新加载配置
sudo supervisorctl reread
sudo supervisorctl update
```

## 📊 监控和维护

### 1. 查看服务状态

```bash
# 查看所有服务状态
./supervisor-manager.sh status

# 输出示例：
# laravel-gift-card-worker:laravel-gift-card-worker_00   RUNNING   pid 1234, uptime 1:23:45
# laravel-gift-card-worker:laravel-gift-card-worker_01   RUNNING   pid 1235, uptime 1:23:45
# laravel-forecast-worker:laravel-forecast-worker_00     RUNNING   pid 1236, uptime 1:23:45
```

### 2. 监控日志

```bash
# 查看实时日志
./supervisor-manager.sh logs laravel-gift-card-worker

# 查看日志文件
tail -f storage/logs/supervisor/gift-card-worker.log
tail -f storage/logs/supervisor/forecast-worker.log
tail -f storage/logs/supervisor/wechat-message-worker.log
tail -f storage/logs/supervisor/default-worker.log
```

### 3. 系统资源监控

```bash
# 查看进程资源使用情况
ps aux | grep "queue:work"

# 查看内存使用情况
free -h

# 监控CPU使用情况
top -p $(pgrep -d, -f "queue:work")
```

## 🔧 配置说明

### 队列优先级配置

```ini
# 礼品卡兑换队列 - 最高优先级
[program:laravel-gift-card-worker]
command=php /www/wwwroot/slurry-admin-api/artisan queue:work redis --queue=gift_card_exchange --tries=3 --timeout=300 --memory=256 --sleep=1
numprocs=4
priority=100

# 预报爬虫队列 - 高优先级
[program:laravel-forecast-worker]
command=php /www/wwwroot/slurry-admin-api/artisan queue:work redis --queue=forecast_crawler --tries=3 --timeout=180 --memory=256 --sleep=2
numprocs=2
priority=200
```

### 重要参数说明

- `--queue`: 指定处理的队列名称
- `--tries`: 任务失败重试次数
- `--timeout`: 任务超时时间（秒）
- `--memory`: 内存限制（MB）
- `--sleep`: 无任务时休眠时间（秒）
- `numprocs`: 进程数量
- `priority`: 优先级（数字越小优先级越高）

## 🛠️ 故障排除

### 1. 服务启动失败

```bash
# 检查配置文件语法
sudo supervisorctl reread

# 查看错误日志
sudo supervisorctl tail laravel-gift-card-worker stderr

# 检查权限
ls -la /www/wwwroot/slurry-admin-api/storage/logs/supervisor/
```

### 2. 队列处理缓慢

```bash
# 增加进程数量 (修改配置文件中的numprocs)
# 减少sleep时间
# 增加内存限制

# 重新加载配置
./supervisor-manager.sh reload
```

### 3. 内存泄漏

```bash
# 查看内存使用情况
ps aux | grep "queue:work" | awk '{print $2, $4, $11}' | sort -k2 -nr

# 重启所有队列工作者
./supervisor-manager.sh restart
```

### 4. 常见错误处理

```bash
# 权限错误
sudo chown -R www:www /www/wwwroot/slurry-admin-api/storage/logs/supervisor/

# Redis连接错误
redis-cli ping

# PHP错误
php -v
which php
```

## 📈 性能优化

### 1. 调整进程数量

根据服务器资源和队列负载调整进程数量：

```bash
# 高负载队列增加进程数
# 低负载队列减少进程数
# 编辑配置文件后重新加载
./supervisor-manager.sh reload
```

### 2. 内存优化

```bash
# 监控内存使用
watch -n 5 'ps aux | grep "queue:work" | awk "{sum+=\$4} END {print \"Total Memory Usage: \" sum \"%\"}"'

# 调整内存限制
# 修改配置文件中的--memory参数
```

### 3. 队列优化

```bash
# 分析队列积压情况
redis-cli -h localhost -p 6379 -n 0 llen queues:gift_card_exchange
redis-cli -h localhost -p 6379 -n 0 llen queues:forecast_crawler
redis-cli -h localhost -p 6379 -n 0 llen queues:wechat-message
redis-cli -h localhost -p 6379 -n 0 llen queues:default

# 根据积压情况调整进程数量和优先级
```

## 🔄 备份和恢复

### 1. 备份配置

```bash
# 备份当前配置
cp /etc/supervisor/conf.d/slurry-admin-api.conf /etc/supervisor/conf.d/slurry-admin-api.conf.bak.$(date +%Y%m%d)

# 备份项目配置文件
cp supervisor-complete.conf supervisor-complete.conf.bak.$(date +%Y%m%d)
```

### 2. 恢复配置

```bash
# 恢复配置文件
cp /etc/supervisor/conf.d/slurry-admin-api.conf.bak.20241216 /etc/supervisor/conf.d/slurry-admin-api.conf

# 重新加载
./supervisor-manager.sh reload
```

## 📝 日志管理

### 1. 日志文件位置

```
storage/logs/supervisor/
├── gift-card-worker.log      # 礼品卡队列日志
├── forecast-worker.log       # 预报爬虫队列日志
├── bill-worker.log          # 账单处理队列日志
├── card-query-worker.log    # 卡密查询队列日志
├── wechat-message-worker.log # 微信消息队列日志
├── mail-worker.log          # 邮件队列日志
├── default-worker.log       # 默认队列日志
├── websocket-trade-monitor.log  # WebSocket监控日志
└── scheduler.log            # 调度器日志
```

### 2. 日志轮转

配置已自动设置日志轮转：
- 单文件最大100MB
- 保留5个备份文件
- 自动压缩旧日志

### 3. 日志分析

```bash
# 查看错误日志
grep -i "error\|exception\|failed" storage/logs/supervisor/*.log

# 统计任务处理情况
grep -c "Processing" storage/logs/supervisor/gift-card-worker.log

# 查看最近的错误
tail -100 storage/logs/supervisor/gift-card-worker.log | grep -i "error"
```

## 🚨 监控告警

### 1. 进程监控脚本

```bash
#!/bin/bash
# 检查Supervisor服务是否正常运行
check_supervisor_status() {
    if ! pgrep -f "supervisord" > /dev/null; then
        echo "ALERT: Supervisor is not running!"
        # 发送告警邮件或微信消息
    fi
    
    # 检查队列工作者状态
    failed_workers=$(supervisorctl status | grep -c "FATAL\|STOPPED")
    if [ $failed_workers -gt 0 ]; then
        echo "ALERT: $failed_workers workers are not running!"
        # 发送告警
    fi
}

# 添加到crontab中每分钟检查
# * * * * * /path/to/check_supervisor_status.sh
```

### 2. 队列积压监控

```bash
# 检查队列积压情况
check_queue_backlog() {
    gift_card_queue=$(redis-cli -h localhost -p 6379 -n 0 llen queues:gift_card_exchange)
    if [ $gift_card_queue -gt 100 ]; then
        echo "ALERT: Gift card queue backlog: $gift_card_queue"
    fi
    
    wechat_queue=$(redis-cli -h localhost -p 6379 -n 0 llen queues:wechat-message)
    if [ $wechat_queue -gt 50 ]; then
        echo "ALERT: Wechat message queue backlog: $wechat_queue"
    fi
}
```

## 💡 最佳实践

1. **定期检查服务状态**
   ```bash
   # 每天检查一次
   ./supervisor-manager.sh status
   ```

2. **监控资源使用**
   ```bash
   # 监控内存和CPU使用情况
   htop
   ```

3. **定期清理日志**
   ```bash
   # 清理30天前的日志
   find storage/logs/supervisor/ -name "*.log.*" -mtime +30 -delete
   ```

4. **备份重要配置**
   ```bash
   # 定期备份配置文件
   cp supervisor-complete.conf supervisor-complete.conf.bak.$(date +%Y%m%d)
   ```

5. **测试配置更改**
   ```bash
   # 在生产环境应用前，先在测试环境验证配置
   sudo supervisorctl reread
   ```

## 📞 支持和帮助

如果遇到问题，请按以下步骤排查：

1. 查看服务状态：`./supervisor-manager.sh status`
2. 检查错误日志：`./supervisor-manager.sh logs <service_name>`
3. 验证配置文件：`sudo supervisorctl reread`
4. 检查系统资源：`free -h`, `df -h`
5. 重启服务：`./supervisor-manager.sh restart`

---

**注意事项：**
- 修改配置文件后必须重新加载配置
- 生产环境变更前请先在测试环境验证
- 定期监控系统资源使用情况
- 保持日志文件的定期清理和备份 