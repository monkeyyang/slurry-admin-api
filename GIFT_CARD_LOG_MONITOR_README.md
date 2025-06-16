# 礼品卡日志监控系统

## 概述

这是一个完整的礼品卡日志监控系统，提供多种方式来实时监控和分析礼品卡兑换日志，完全替代原有的 `monitor_gift_card_logs.sh` 脚本。

## 功能特性

- 🔄 **实时监控**: 实时显示新的日志条目
- 📊 **统计分析**: 显示日志级别统计和错误汇总
- 🔍 **搜索功能**: 支持关键词和级别过滤
- 🌈 **彩色输出**: 不同日志级别使用不同颜色显示
- 🔌 **多种接口**: 支持命令行、API和WebSocket
- 📱 **前端集成**: 提供完整的API接口供前端调用

## 安装和配置

### 1. 确保依赖已安装

```bash
# 检查PHP版本 (需要8.0+)
php --version

# 检查Laravel项目
php artisan --version

# 安装Composer依赖 (如果需要)
composer install
```

### 2. 给脚本添加执行权限

```bash
chmod +x monitor_gift_card_logs_new.sh
```

## 使用方法

### 1. 命令行工具

#### 基本用法

```bash
# 显示帮助信息
./monitor_gift_card_logs_new.sh -h

# 显示最近100条日志
./monitor_gift_card_logs_new.sh

# 显示最近50条日志
./monitor_gift_card_logs_new.sh -l 50

# 显示统计信息
./monitor_gift_card_logs_new.sh -s

# 实时监控模式
./monitor_gift_card_logs_new.sh -r
```

#### 高级用法

```bash
# 搜索包含"礼品卡"的日志
./monitor_gift_card_logs_new.sh --search "礼品卡"

# 只显示错误日志
./monitor_gift_card_logs_new.sh --level ERROR

# 搜索错误级别的特定关键词
./monitor_gift_card_logs_new.sh --search "API请求失败" --level ERROR

# 使用API接口获取数据
./monitor_gift_card_logs_new.sh --api -s
```

### 2. Artisan命令

```bash
# 显示统计信息
php artisan giftcard:monitor-logs --stats

# 实时监控
php artisan giftcard:monitor-logs --realtime

# 搜索日志
php artisan giftcard:monitor-logs --search="礼品卡" --level=INFO

# 显示最近50条日志
php artisan giftcard:monitor-logs --lines=50
```

### 3. API接口

#### 获取最新日志
```bash
curl "http://localhost:8000/api/giftcard/logs/latest?lines=20"
```

#### 获取统计信息
```bash
curl "http://localhost:8000/api/giftcard/logs/stats"
```

#### 搜索日志
```bash
curl "http://localhost:8000/api/giftcard/logs/search?keyword=礼品卡&level=ERROR"
```

#### 实时日志流 (Server-Sent Events)
```bash
curl "http://localhost:8000/api/giftcard/logs/stream"
```

### 4. WebSocket实时监控

```bash
# 启动WebSocket服务器
php websocket-server.php 8080

# 或使用启动脚本
./start-websocket.sh 8080
```

## 测试系统

运行测试脚本来验证所有功能：

```bash
php test_log_monitoring.php
```

这个脚本会：
1. 生成测试日志数据
2. 测试日志文件读取
3. 测试Artisan命令
4. 测试API接口

## 日志格式

系统监控的日志格式为Laravel标准格式：

```
[2024-12-16 21:48:17] local.ERROR: API请求失败 - 状态码: 404, 错误信息: 网络超时 {"url":"https://api.example.com"}
```

解析后的格式：
- **时间戳**: 2024-12-16 21:48:17
- **级别**: ERROR (支持 DEBUG, INFO, WARNING, ERROR)
- **消息**: API请求失败 - 状态码: 404, 错误信息: 网络超时
- **上下文**: {"url":"https://api.example.com"}

## 颜色编码

- 🔴 **ERROR**: 红色 - 系统错误和异常
- 🟡 **WARNING**: 黄色 - 警告信息
- 🟢 **INFO**: 绿色 - 一般信息
- 🔵 **DEBUG**: 青色 - 调试信息

## API响应格式

所有API接口都返回统一格式：

```json
{
  "code": 0,
  "message": "success",
  "data": {
    // 具体数据
  }
}
```

### 日志条目格式

```json
{
  "id": "unique_id",
  "timestamp": "2024-12-16 21:48:17",
  "level": "ERROR",
  "message": "API请求失败",
  "context": {
    "url": "https://api.example.com"
  },
  "color": "error"
}
```

### 统计信息格式

```json
{
  "total": 150,
  "levels": {
    "ERROR": 5,
    "WARNING": 10,
    "INFO": 120,
    "DEBUG": 15
  },
  "recent_errors": [
    {
      "timestamp": "2024-12-16 21:48:17",
      "message": "API请求失败"
    }
  ],
  "last_update": "2024-12-16 21:50:00"
}
```

## 前端集成

### JavaScript示例 (使用Server-Sent Events)

```javascript
// 连接实时日志流
const eventSource = new EventSource('/api/giftcard/logs/stream');

eventSource.onmessage = function(event) {
    const data = JSON.parse(event.data);
    
    if (data.type === 'log') {
        displayLogEntry(data.data);
    }
};

function displayLogEntry(log) {
    const logElement = document.createElement('div');
    logElement.className = `log-entry log-${log.color}`;
    logElement.innerHTML = `
        <span class="timestamp">${log.timestamp}</span>
        <span class="level">${log.level}</span>
        <span class="message">${log.message}</span>
    `;
    document.getElementById('log-container').appendChild(logElement);
}
```

### TypeScript示例 (使用WebSocket)

```typescript
const ws = new WebSocket('ws://localhost:8080?token=your-token');

ws.onmessage = (event) => {
    const data = JSON.parse(event.data);
    
    if (data.type === 'gift_card_log') {
        console.log('新日志:', data.data);
    }
};
```

## 故障排除

### 常见问题

1. **日志文件不存在**
   ```bash
   # 检查日志目录权限
   ls -la storage/logs/
   
   # 手动创建日志文件
   touch storage/logs/gift_card_exchange-$(date +%Y-%m-%d).log
   ```

2. **Artisan命令不可用**
   ```bash
   # 检查命令是否注册
   php artisan list | grep giftcard
   
   # 清除缓存
   php artisan config:clear
   php artisan cache:clear
   ```

3. **API接口返回404**
   ```bash
   # 检查路由
   php artisan route:list | grep giftcard
   
   # 确保Laravel服务器运行
   php artisan serve
   ```

4. **WebSocket连接失败**
   ```bash
   # 检查端口是否被占用
   netstat -an | grep 8080
   
   # 重启WebSocket服务器
   php websocket-server.php 8080
   ```

## 性能优化

1. **日志文件轮转**: 系统自动按日期创建日志文件
2. **内存限制**: 读取日志时限制行数，避免内存溢出
3. **缓存机制**: API接口可以添加适当的缓存
4. **异步处理**: WebSocket使用异步方式处理消息

## 扩展功能

1. **日志归档**: 可以添加自动归档旧日志文件的功能
2. **告警系统**: 可以在检测到错误时发送邮件或短信通知
3. **图表展示**: 可以添加日志趋势图表
4. **导出功能**: 支持导出日志为CSV或Excel格式

## 相关文件

- `monitor_gift_card_logs_new.sh` - 主监控脚本
- `app/Console/Commands/MonitorGiftCardLogs.php` - Artisan命令
- `app/Services/GiftCardLogMonitorService.php` - 核心服务类
- `app/Http/Controllers/Api/GiftCardLogMonitorController.php` - API控制器
- `test_log_monitoring.php` - 测试脚本
- `websocket-server.php` - WebSocket服务器
- `start-websocket.sh` - WebSocket启动脚本 