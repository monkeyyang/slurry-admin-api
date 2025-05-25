# 礼品卡兑换队列系统

## 概述

礼品卡兑换系统现在使用Laravel队列来异步处理兑换请求，提高系统响应速度和处理能力。

## 工作流程

1. **接收请求**: 外部系统发送礼品卡兑换消息到API接口
2. **消息验证**: 验证消息格式是否正确
3. **加入队列**: 将有效的兑换请求加入Laravel队列
4. **异步处理**: 队列工作进程异步处理兑换逻辑
5. **状态查询**: 可通过请求ID查询处理状态

## API接口

### 1. 提交兑换请求

**POST** `/api/gift-card/exchange`

**请求参数:**
```json
{
    "message": "XQPD5D7KJ8TGZT4L /1"
}
```

**响应:**
```json
{
    "code": 0,
    "message": "兑换请求已接收，正在队列中处理",
    "data": {
        "request_id": "exchange_64f8a1b2c3d4e",
        "card_number": "XQPD5D7KJ8TGZT4L",
        "card_type": 1,
        "status": "queued"
    }
}
```

### 2. 查询处理状态

**GET** `/api/gift-card/exchange/status?request_id=exchange_64f8a1b2c3d4e`

**响应:**
```json
{
    "code": 0,
    "message": "ok",
    "data": {
        "request_id": "exchange_64f8a1b2c3d4e",
        "status": "processing",
        "message": "任务正在处理中"
    }
}
```

### 3. 测试队列功能

**POST** `/api/gift-card/test-queue`

**请求参数:**
```json
{
    "message": "TESTCARD123 /1"
}
```

## 消息格式

兑换消息格式：`卡号 /类型`

- **卡号**: 礼品卡号码（字母数字组合）
- **类型**: 卡类型（数字）

示例：
- `XQPD5D7KJ8TGZT4L /1`
- `ABC123DEF456 /2`

## 队列配置

在 `.env` 文件中配置队列相关参数：

```env
# 队列连接类型
GIFT_CARD_QUEUE_CONNECTION=redis

# 队列名称
GIFT_CARD_QUEUE_NAME=gift_card_exchange

# 轮询配置
GIFT_CARD_POLLING_MAX_ATTEMPTS=20
GIFT_CARD_POLLING_INTERVAL=3

# 兑换间隔
GIFT_CARD_REDEMPTION_INTERVAL=6
```

## 启动队列工作进程

使用以下命令启动队列工作进程：

```bash
# 启动默认队列工作进程
php artisan queue:work

# 启动指定连接和队列的工作进程
php artisan queue:work redis --queue=gift_card_exchange

# 后台运行（推荐使用Supervisor管理）
php artisan queue:work redis --queue=gift_card_exchange --daemon
```

## 监控和日志

- 队列任务执行日志记录在Laravel日志中
- 每个任务都有唯一的`request_id`用于追踪
- 任务失败会自动重试（最多3次）
- 某些业务错误（如卡无效）不会重试

## 错误处理

系统会自动处理以下情况：

1. **消息格式错误**: 立即返回错误，不加入队列
2. **礼品卡无效**: 不重试，直接标记为失败
3. **没有合适账户**: 不重试，直接标记为失败
4. **网络错误**: 自动重试，最多3次
5. **系统异常**: 自动重试，最多3次

## 性能优化建议

1. 使用Redis作为队列驱动以获得更好的性能
2. 根据业务量调整队列工作进程数量
3. 使用Supervisor管理队列工作进程
4. 定期清理已完成的队列任务记录 