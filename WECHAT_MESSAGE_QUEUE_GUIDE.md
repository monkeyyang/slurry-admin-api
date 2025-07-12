# 微信消息队列系统使用指南

## 概述

本系统实现了微信消息的异步队列处理和实时监控功能，提供了高可靠性的消息发送机制和完整的监控面板。

## 系统架构

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   应用程序层     │    │   消息队列层     │    │   监控面板层     │
│                 │    │                 │    │                 │
│ • 业务逻辑      │───▶│ • 消息队列      │───▶│ • 实时监控      │
│ • 消息发送      │    │ • 重试机制      │    │ • 统计分析      │
│ • 错误处理      │    │ • 失败恢复      │    │ • 手动操作      │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         ▼                       ▼                       ▼
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   数据存储层     │    │   微信API层     │    │   日志记录层     │
│                 │    │                 │    │                 │
│ • 消息记录      │    │ • 消息发送      │    │ • 操作日志      │
│ • 状态管理      │    │ • 状态回调      │    │ • 错误日志      │
│ • 历史数据      │    │ • 连接管理      │    │ • 性能监控      │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## 功能特性

### 1. 异步消息队列
- ✅ 支持异步和同步两种发送模式
- ✅ 自动重试机制（默认3次）
- ✅ 失败消息的手动重试
- ✅ 消息优先级管理
- ✅ 批量消息发送

### 2. 实时监控面板
- ✅ 实时统计数据展示
- ✅ 消息发送状态监控
- ✅ 队列状态监控
- ✅ 成功率统计
- ✅ 历史数据查询

### 3. 消息管理
- ✅ 消息状态跟踪
- ✅ 消息内容预览
- ✅ 来源标识管理
- ✅ 房间分组管理
- ✅ 消息类型分类

### 4. 错误处理
- ✅ 详细错误日志
- ✅ 自动重试策略
- ✅ 失败消息统计
- ✅ 错误原因分析

## 安装与配置

### 1. 数据库迁移

```bash
# 创建消息日志表
php artisan migrate
```

### 2. 配置文件

在 `config/wechat.php` 中配置相关参数：

```php
return [
    // 微信API配置
    'api_url' => env('WECHAT_API_URL', 'http://106.52.250.202:6666/'),
    
    // 队列配置
    'queue' => [
        'enabled' => env('WECHAT_QUEUE_ENABLED', true),
        'name' => env('WECHAT_QUEUE_NAME', 'wechat-message'),
        'timeout' => env('WECHAT_QUEUE_TIMEOUT', 30),
        'tries' => env('WECHAT_QUEUE_TRIES', 3),
        'backoff' => [10, 30, 60],
    ],
    
    // 监控配置
    'monitor' => [
        'enabled' => env('WECHAT_MONITOR_ENABLED', true),
        'auto_refresh' => env('WECHAT_MONITOR_AUTO_REFRESH', true),
        'refresh_interval' => env('WECHAT_MONITOR_REFRESH_INTERVAL', 5000),
        'page_size' => env('WECHAT_MONITOR_PAGE_SIZE', 20),
    ],
];
```

### 3. 环境变量配置

在 `.env` 文件中添加：

```env
# 微信消息队列配置
WECHAT_QUEUE_ENABLED=true
WECHAT_QUEUE_NAME=wechat-message
WECHAT_QUEUE_TIMEOUT=30
WECHAT_QUEUE_TRIES=3

# 微信监控配置
WECHAT_MONITOR_ENABLED=true
WECHAT_MONITOR_AUTO_REFRESH=true
WECHAT_MONITOR_REFRESH_INTERVAL=5000
WECHAT_MONITOR_PAGE_SIZE=20

# 微信API配置
WECHAT_API_URL=http://106.52.250.202:6666/
WECHAT_DEFAULT_ROOM=45958721463@chatroom
```

### 4. 队列工作器配置

启动队列工作器：

```bash
# 启动微信消息队列工作器
php artisan queue:work --queue=wechat-message

# 使用 Supervisor 管理队列工作器
# 在 /etc/supervisor/conf.d/wechat-queue.conf 中配置：
[program:wechat-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/artisan queue:work --queue=wechat-message --sleep=3 --tries=3
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/wechat-queue.log
```

## 使用方法

### 1. 基本使用

#### 发送单条消息

```php
use App\Services\WechatMessageService;

$wechatMessageService = app(WechatMessageService::class);

// 使用队列发送（推荐）
$messageId = $wechatMessageService->sendMessage(
    '45958721463@chatroom',  // 群聊ID
    '这是一条测试消息',       // 消息内容
    'text',                  // 消息类型
    'test-source',           // 来源标识
    true,                    // 使用队列
    3                        // 最大重试次数
);

// 同步发送
$result = $wechatMessageService->sendMessage(
    '45958721463@chatroom',
    '这是一条同步消息',
    'text',
    'test-source',
    false  // 不使用队列
);
```

#### 批量发送消息

```php
$messages = [
    ['room_id' => '45958721463@chatroom', 'content' => '批量消息1'],
    ['room_id' => '45958721463@chatroom', 'content' => '批量消息2'],
    ['room_id' => '20229649389@chatroom', 'content' => '批量消息3'],
];

$result = $wechatMessageService->sendBatchMessages(
    $messages,
    'text',
    'batch-source',
    true  // 使用队列
);

echo "成功发送: " . count($result['success']) . " 条";
echo "发送失败: " . count($result['failed']) . " 条";
```

### 2. 使用辅助函数

#### 基本发送

```php
// 使用队列发送（默认）
$messageId = send_msg_to_wechat(
    '45958721463@chatroom',
    '这是一条消息',
    'MT_SEND_TEXTMSG',
    true,  // 使用队列
    'helper-function'  // 来源标识
);

// 同步发送
$result = send_msg_to_wechat(
    '45958721463@chatroom',
    '这是一条同步消息',
    'MT_SEND_TEXTMSG',
    false  // 不使用队列
);
```

### 3. 在现有代码中使用

#### 在 Job 中使用

```php
class YourJob implements ShouldQueue
{
    public function handle()
    {
        // 处理业务逻辑
        
        // 发送成功通知
        send_msg_to_wechat(
            $this->roomId,
            '处理完成',
            'MT_SEND_TEXTMSG',
            true,  // 使用队列
            'your-job'
        );
    }
}
```

#### 在 Service 中使用

```php
class YourService
{
    protected $wechatMessageService;
    
    public function __construct(WechatMessageService $wechatMessageService)
    {
        $this->wechatMessageService = $wechatMessageService;
    }
    
    public function processData()
    {
        // 处理数据
        
        // 发送通知
        $this->wechatMessageService->sendMessage(
            $roomId,
            $message,
            'text',
            'your-service'
        );
    }
}
```

## 监控面板使用

### 1. 访问监控面板

访问 `http://your-domain/wechat/monitor/` 打开监控面板。

### 2. 功能介绍

#### 实时统计
- **总消息数**: 显示系统中的总消息数量
- **待发送**: 显示等待发送的消息数量
- **发送成功**: 显示成功发送的消息数量
- **发送失败**: 显示发送失败的消息数量

#### 队列状态
- **待处理任务**: 显示队列中等待处理的任务数量
- **失败任务**: 显示失败的任务数量

#### 成功率统计
- **总体成功率**: 显示整体的消息发送成功率
- **今日成功率**: 显示今日的消息发送成功率

#### 消息列表
- 支持按群聊、状态、来源、时间等条件筛选
- 显示消息详情和发送状态
- 支持重试失败的消息

### 3. 操作功能

#### 刷新数据
点击"刷新数据"按钮可以手动刷新统计数据。

#### 重试失败消息
对于发送失败的消息，可以点击"重试"按钮进行重新发送。

#### 发送测试消息
可以选择群聊发送测试消息，验证系统功能。

#### 自动刷新
开启自动刷新功能，每5秒自动更新数据。

## 命令行工具

### 1. 测试队列功能

```bash
# 基本测试
php artisan wechat:test-queue

# 指定参数测试
php artisan wechat:test-queue --room=45958721463@chatroom --count=10

# 使用同步模式测试
php artisan wechat:test-queue --sync
```

### 2. 查看队列状态

```bash
# 查看队列任务
php artisan queue:work --queue=wechat-message

# 查看失败任务
php artisan queue:failed

# 重试失败任务
php artisan queue:retry all
```

### 3. 监控队列

```bash
# 实时监控队列状态
php artisan queue:monitor wechat-message

# 查看队列统计
php artisan queue:stats
```

## API 接口

### 1. 获取统计数据

```http
GET /api/wechat/monitor/stats
```

### 2. 获取消息列表

```http
GET /api/wechat/monitor/messages?page=1&page_size=20&room_id=xxx&status=1
```

### 3. 重试消息

```http
POST /api/wechat/monitor/retry
Content-Type: application/json

{
    "message_id": 123
}
```

### 4. 发送测试消息

```http
POST /api/wechat/monitor/test-message
Content-Type: application/json

{
    "room_id": "45958721463@chatroom",
    "content": "测试消息",
    "use_queue": true
}
```

## 故障排除

### 1. 队列不工作

**问题**: 消息一直显示"待发送"状态

**解决方案**:
1. 检查队列工作器是否运行: `ps aux | grep queue:work`
2. 启动队列工作器: `php artisan queue:work --queue=wechat-message`
3. 检查 Redis 连接是否正常
4. 查看队列错误日志: `storage/logs/laravel.log`

### 2. 消息发送失败

**问题**: 消息显示"发送失败"状态

**解决方案**:
1. 检查微信API连接是否正常
2. 查看错误日志了解具体原因
3. 检查群聊ID是否正确
4. 验证微信机器人是否在线

### 3. 监控面板无法访问

**问题**: 监控面板显示错误或无法加载

**解决方案**:
1. 检查路由是否正确配置
2. 确认控制器文件是否存在
3. 检查数据库连接是否正常
4. 查看 Web 服务器错误日志

### 4. 数据库错误

**问题**: 出现数据库相关错误

**解决方案**:
1. 运行数据库迁移: `php artisan migrate`
2. 检查数据库连接配置
3. 确认数据表是否存在
4. 检查数据库权限

## 性能优化

### 1. 队列性能

```bash
# 增加队列工作器进程数
php artisan queue:work --queue=wechat-message --processes=4

# 调整队列超时时间
php artisan queue:work --queue=wechat-message --timeout=60
```

### 2. 数据库优化

```sql
-- 为经常查询的字段添加索引
CREATE INDEX idx_wechat_message_logs_room_created ON wechat_message_logs(room_id, created_at);
CREATE INDEX idx_wechat_message_logs_status_created ON wechat_message_logs(status, created_at);
```

### 3. 缓存配置

```php
// 在 config/cache.php 中配置 Redis 缓存
'redis' => [
    'client' => 'predis',
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'database' => 0,
    ],
],
```

## 最佳实践

### 1. 消息发送

- 优先使用队列模式，提高系统性能
- 为不同功能设置不同的来源标识，便于追踪
- 合理设置重试次数，避免无限重试
- 对重要消息使用同步模式确保及时发送

### 2. 监控管理

- 定期查看监控面板了解系统状态
- 及时处理失败的消息
- 定期清理历史数据，保持系统性能
- 设置告警机制，及时发现问题

### 3. 错误处理

- 详细记录错误日志，便于问题排查
- 对不同类型的错误采用不同的处理策略
- 建立错误通知机制，及时响应问题
- 定期分析错误原因，优化系统稳定性

### 4. 系统维护

- 定期备份消息数据
- 监控系统资源使用情况
- 定期更新系统组件
- 建立完善的测试流程

## 总结

微信消息队列系统提供了高可靠性的消息发送机制和完整的监控功能，通过合理配置和使用，可以大大提高系统的稳定性和可维护性。建议在生产环境中使用队列模式，并定期监控系统状态，及时处理异常情况。 