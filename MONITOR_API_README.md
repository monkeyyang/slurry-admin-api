# 交易监控API文档

## 概述

本文档描述了礼品卡交易监控系统的后端API接口，与前端TypeScript接口定义完全匹配。

## API接口列表

### 1. 获取日志列表
```
GET /api/trade/monitor/logs
```

**查询参数:**
- `level` (可选): 日志级别 (ERROR, WARNING, INFO, DEBUG)
- `status` (可选): 状态 (success, failed, processing, waiting)
- `accountId` (可选): 账号ID
- `startTime` (可选): 开始时间 (ISO格式)
- `endTime` (可选): 结束时间 (ISO格式)
- `keyword` (可选): 关键词搜索
- `pageNum` (可选): 页码，默认1
- `pageSize` (可选): 每页大小，默认20，最大100

**响应示例:**
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "data": [
      {
        "id": "123",
        "timestamp": "2024-12-16T10:30:00.000Z",
        "level": "INFO",
        "message": "账号 test@example.com 成功兑换礼品卡 ABC123，金额 100，获得 680",
        "accountId": "456",
        "planId": "789",
        "amount": 100,
        "status": "success",
        "errorMessage": null,
        "metadata": {
          "gift_card_code": "ABC123",
          "transaction_id": "TXN_123456",
          "country_code": "US",
          "exchanged_amount": 680,
          "rate_id": 1,
          "batch_id": "batch-uuid",
          "day": 1
        }
      }
    ],
    "total": 150,
    "pageNum": 1,
    "pageSize": 20
  }
}
```

### 2. 获取监控统计数据
```
GET /api/trade/monitor/stats
```

**响应示例:**
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "totalExchanges": 1250,
    "successCount": 1180,
    "failedCount": 70,
    "processingCount": 5,
    "successRate": 94.4,
    "todayExchanges": 45,
    "todaySuccessCount": 42,
    "todayFailedCount": 3
  }
}
```

### 3. 获取实时状态
```
GET /api/trade/monitor/status
```

**响应示例:**
```json
{
  "code": 0,
  "message": "success",
  "data": {
    "isRunning": true,
    "currentTask": {
      "accountId": "123",
      "account": "test@example.com",
      "planId": "456",
      "currentDay": 2,
      "startTime": "2024-12-16T10:25:00.000Z"
    },
    "queueCount": 8,
    "lastUpdateTime": "2024-12-16T10:30:15.000Z"
  }
}
```

### 4. 清空日志
```
DELETE /api/trade/monitor/logs
```

**响应示例:**
```json
{
  "code": 0,
  "message": "日志已清空",
  "data": null
}
```

### 5. 导出日志
```
GET /api/trade/monitor/logs/export
```

**查询参数:** (与获取日志列表相同的筛选参数)

**响应:** CSV文件下载

## WebSocket实时监控

### 连接地址
```
ws://localhost:8080?token=your-auth-token
```

### 消息格式

**接收的消息类型:**

1. **日志更新消息**
```json
{
  "type": "log",
  "data": {
    "id": "123",
    "timestamp": "2024-12-16T10:30:00.000Z",
    "level": "INFO",
    "message": "...",
    "accountId": "456",
    "status": "success",
    "metadata": {...}
  }
}
```

2. **状态更新消息**
```json
{
  "type": "status",
  "data": {
    "isRunning": true,
    "queueCount": 5,
    "currentTask": {...},
    "lastUpdateTime": "2024-12-16T10:30:15.000Z"
  }
}
```

**发送的消息类型:**

1. **心跳检测**
```json
{
  "type": "ping"
}
```

2. **获取状态**
```json
{
  "type": "getStatus"
}
```

## 部署和启动

### 1. 启动WebSocket服务器

**Linux/Mac:**
```bash
chmod +x start-websocket.sh
./start-websocket.sh 8080
```

**Windows:**
```cmd
start-websocket.bat 8080
```

**直接使用PHP:**
```bash
php websocket-server.php 8080
```

### 2. 测试API接口

```bash
php test_monitor_api.php
```

## 前端集成示例

```typescript
import { monitorApi, MonitorWebSocket } from './monitor-api';

// 获取统计数据
const stats = await monitorApi.getStats();

// 获取日志列表
const logs = await monitorApi.getLogs({
  pageNum: 1,
  pageSize: 20,
  level: LogLevel.INFO
});

// 建立WebSocket连接
const ws = new MonitorWebSocket(
  'ws://localhost:8080',
  (logEntry) => {
    console.log('新日志:', logEntry);
  },
  (status) => {
    console.log('状态更新:', status);
  }
);

ws.connect();
```

## 错误处理

所有API接口都遵循统一的错误响应格式:

```json
{
  "code": 500,
  "message": "错误描述",
  "data": null
}
```

常见错误码:
- `401`: 认证失败
- `404`: 资源不存在
- `422`: 参数验证失败
- `500`: 服务器内部错误

## 注意事项

1. **认证**: 所有API接口都需要有效的认证token
2. **权限**: 确保用户有监控数据的访问权限
3. **性能**: 大量日志数据时建议使用分页和筛选
4. **WebSocket**: 确保WebSocket服务器正常运行
5. **Redis**: 实时功能依赖Redis服务

## 配置要求

- PHP 8.0+
- Laravel 10+
- Redis 服务器
- MySQL/PostgreSQL 数据库
- Composer 依赖管理

## 相关文件

- `app/Http/Controllers/Api/TradeMonitorController.php` - 主控制器
- `app/Services/TradeMonitorService.php` - 业务逻辑服务
- `app/Http/Controllers/Api/TradeMonitorWebSocketController.php` - WebSocket控制器
- `app/Events/TradeLogCreated.php` - 日志创建事件
- `app/Listeners/BroadcastTradeLogUpdate.php` - 事件监听器
- `routes/api.php` - 路由配置 