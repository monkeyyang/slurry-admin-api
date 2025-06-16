# WebSocket连接故障排除指南

## 🚨 问题描述

前端出现以下错误：
```
WebSocket connection to 'ws://localhost:8848/ws/monitor?token=null' failed: 
WebSocket is closed before the connection is established.
```

## 🔍 问题分析

1. **端口不匹配**: 前端尝试连接8848端口，但WebSocket服务器可能运行在其他端口
2. **Token为null**: 认证token为空导致连接被拒绝
3. **服务器未启动**: WebSocket服务器可能没有运行
4. **网络连接问题**: 防火墙或网络配置阻止连接

## 🛠️ 解决方案

### 1. 快速诊断

运行诊断脚本来检查所有可能的问题：

```bash
# 给脚本添加执行权限
chmod +x websocket-diagnose.sh

# 运行诊断
./websocket-diagnose.sh
```

### 2. 启动WebSocket服务器

使用管理脚本启动WebSocket服务器：

```bash
# 给脚本添加执行权限
chmod +x websocket-manager.sh

# 启动服务
./websocket-manager.sh start

# 检查状态
./websocket-manager.sh status

# 查看日志
./websocket-manager.sh logs
```

### 3. 手动启动（如果管理脚本不工作）

```bash
# 直接启动在8848端口
chmod +x start-websocket-8848.sh
./start-websocket-8848.sh
```

或者手动启动：

```bash
# 后台启动
nohup php websocket-server.php 8848 > storage/logs/websocket.log 2>&1 &

# 查看进程
ps aux | grep websocket-server
```

### 4. 验证服务器运行

```bash
# 检查端口是否开放
lsof -i :8848

# 测试TCP连接
telnet localhost 8848

# 或使用nc
nc -zv localhost 8848
```

### 5. 前端配置检查

确保前端WebSocket连接配置正确：

```javascript
// 正确的连接地址
const wsUrl = 'ws://localhost:8848/ws/monitor';

// 如果需要token，确保token不为null
const token = 'your-valid-token'; // 不要使用null
const wsUrlWithToken = `ws://localhost:8848/ws/monitor?token=${token}`;
```

## 📋 常见问题和解决方法

### 问题1: 端口被占用

```bash
# 查找占用进程
lsof -i :8848

# 杀死占用进程
kill -9 <PID>

# 重新启动服务
./websocket-manager.sh start
```

### 问题2: PHP依赖缺失

```bash
# 安装依赖
composer install

# 检查Ratchet是否安装
ls vendor/ratchet/
```

### 问题3: 权限问题

```bash
# 给所有脚本添加执行权限
chmod +x *.sh

# 确保日志目录可写
mkdir -p storage/logs
chmod 755 storage/logs
```

### 问题4: Token验证失败

当前配置已经允许空token用于开发环境。如果需要严格的token验证：

1. 修改 `app/Http/Controllers/Api/TradeMonitorWebSocketController.php`
2. 在 `validateToken` 方法中实现真正的token验证逻辑
3. 确保前端传递有效的token

## 🔧 管理命令

### WebSocket服务管理

```bash
# 启动服务
./websocket-manager.sh start

# 停止服务
./websocket-manager.sh stop

# 重启服务
./websocket-manager.sh restart

# 查看状态
./websocket-manager.sh status

# 查看实时日志
./websocket-manager.sh logs
```

### 诊断工具

```bash
# 运行完整诊断
./websocket-diagnose.sh

# 查看礼品卡日志
./monitor_gift_card_logs.sh

# 查看礼品卡日志统计
./monitor_gift_card_logs.sh --stats
```

## 📊 监控和日志

### 日志文件位置

- WebSocket服务日志: `storage/logs/websocket-YYYYMMDD.log`
- 礼品卡兑换日志: `storage/logs/gift_card_exchange-YYYY-MM-DD.log`
- Laravel应用日志: `storage/logs/laravel.log`

### 实时监控

```bash
# 监控WebSocket日志
tail -f storage/logs/websocket-$(date +%Y%m%d).log

# 监控礼品卡日志
./monitor_gift_card_logs.sh

# 监控所有日志
tail -f storage/logs/*.log
```

## 🌐 网络配置

### 防火墙设置

如果使用防火墙，确保8848端口开放：

```bash
# Ubuntu/Debian
sudo ufw allow 8848

# CentOS/RHEL
sudo firewall-cmd --permanent --add-port=8848/tcp
sudo firewall-cmd --reload
```

### Nginx代理（可选）

如果使用Nginx，可以配置WebSocket代理：

```nginx
location /ws/ {
    proxy_pass http://localhost:8848;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

## 🚀 生产环境部署

### 使用Supervisor管理

创建Supervisor配置文件 `/etc/supervisor/conf.d/websocket.conf`:

```ini
[program:websocket-server]
command=php /path/to/your/project/websocket-server.php 8848
directory=/path/to/your/project
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/websocket-supervisor.log
```

启动Supervisor：

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start websocket-server
```

## 📞 获取帮助

如果问题仍然存在：

1. 运行 `./websocket-diagnose.sh` 获取详细诊断信息
2. 检查 `storage/logs/` 目录下的所有日志文件
3. 确保所有依赖都已正确安装
4. 验证网络连接和防火墙设置

## 🎯 快速解决步骤

1. **运行诊断**: `./websocket-diagnose.sh`
2. **启动服务**: `./websocket-manager.sh start`
3. **检查状态**: `./websocket-manager.sh status`
4. **查看日志**: `./websocket-manager.sh logs`
5. **测试连接**: 在浏览器开发者工具中测试WebSocket连接

如果以上步骤都完成但问题仍然存在，请检查前端代码中的WebSocket连接配置和token传递逻辑。 