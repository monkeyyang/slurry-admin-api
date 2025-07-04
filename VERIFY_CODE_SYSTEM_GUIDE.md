# 查码系统功能说明

## 🎯 功能概述

系统实现了完整的异步查码功能，支持多账号并发查码、代理IP轮询、操作日志记录等特性。

## 🔧 核心组件

### 1. 查码Job (ProcessVerifyCodeJob)

**位置**: `app/Jobs/ProcessVerifyCodeJob.php`

**功能特性**:
- ✅ 异步处理查码请求
- ✅ 多账号并发查码
- ✅ 自动重试机制（每5秒一次，1分钟超时）
- ✅ 代理IP支持
- ✅ 完整的操作日志记录
- ✅ 错误处理和异常捕获

### 2. 代理服务 (ProxyService)

**位置**: `app/Services/ProxyService.php`

**功能特性**:
- ✅ 代理IP池管理
- ✅ 轮询使用代理
- ✅ 代理可用性测试
- ✅ 缓存管理

### 3. 查码接口

**路由**: `POST /verify/operation-logs/get-verify-code`

**请求参数**:
```json
{
    "room_id": "群聊ID",
    "msgid": "消息ID（可选）",
    "wxid": "微信ID（可选）",
    "accounts": ["账号1", "账号2", "账号3"]
}
```

**响应格式**:
```json
{
    "code": 0,
    "message": "查码请求已提交，正在后台处理",
    "data": {
        "room_id": "群聊ID",
        "msg_id": "消息ID",
        "accounts_count": 3,
        "accounts": ["账号1", "账号2", "账号3"],
        "status": "processing"
    }
}
```

## 📋 查码流程

### 1. 请求接收
- 接收查码请求
- 参数验证（账号数量限制、长度验证等）
- 记录操作日志

### 2. 任务分发
- 创建查码Job
- 异步分发到队列
- 立即返回处理状态

### 3. 查码执行
- 并发处理多个账号
- 根据账号获取验证码地址
- 使用代理IP发送请求
- 每5秒重试一次，1分钟超时

### 4. 结果处理
- 解析查码响应
- 记录查码结果到操作日志
- 处理成功/失败/超时情况

## 🔍 查码响应格式

### 成功响应
```json
{
    "code": 0,
    "msg": "ok",
    "data": {
        "code": "Apple 账号代码为：203948，请勿与他人共享",
        "code_time": "2025-07-05 02:00:00",
        "expired_date": "2025-09-20 00:00:00"
    }
}
```

### 失败响应
```json
{
    "code": 0,
    "msg": "No verification code",
    "data": {
        "code": "",
        "code_time": "",
        "expired_date": "2025-09-20 00:00:00"
    }
}
```

## ⚙️ 配置说明

### 代理配置 (config/proxy.php)

```php
return [
    'proxy_list' => [
        'http://proxy1.example.com:8080',
        'http://proxy2.example.com:8080',
        'http://username:password@proxy3.example.com:8080',
    ],
    'verify_timeout' => 60,    // 查码超时时间（秒）
    'verify_interval' => 5,    // 查码间隔时间（秒）
    'request_timeout' => 10,   // 单次请求超时时间（秒）
];
```

### 环境变量配置 (.env)

```env
# 队列配置
QUEUE_CONNECTION=database
QUEUE_DRIVER=database

# 代理配置（可选）
PROXY_LIST=http://proxy1.example.com:8080,http://proxy2.example.com:8080
```

## 🚀 部署步骤

### 1. 数据库迁移
```bash
php artisan migrate
```

### 2. 创建队列表
```bash
php artisan queue:table
php artisan migrate
```

### 3. 启动队列服务
```bash
# 开发环境
php artisan queue:work

# 生产环境（使用Supervisor）
# 参考 supervisor-config.conf
```

### 4. 配置代理IP
编辑 `config/proxy.php` 文件，添加实际的代理IP列表。

### 5. 清理缓存
```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

## 📊 操作日志记录

系统会自动记录以下操作：

### 查码请求记录
- 操作类型: `getVerifyCode`
- 目标账号: 请求查码的账号
- 结果: `success`（请求成功）或 `failed`（请求失败）
- 详细信息: 包含消息ID等上下文信息

### 查码结果记录
- 操作类型: `getVerifyCode`
- 目标账号: 查码的账号
- 结果: `success`（查码成功）或 `failed`（查码失败/超时）
- 详细信息: 包含验证码内容或错误信息

## 🔧 使用示例

### 发送查码请求

```bash
curl -X POST http://localhost:8848/dev-api/verify/operation-logs/get-verify-code \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "room_id": "room_123",
    "msgid": "msg_456",
    "wxid": "wx_789",
    "accounts": ["test1@example.com", "test2@example.com"]
  }'
```

### 查看操作日志

```bash
curl -X GET "http://localhost:8848/dev-api/verify/operation-logs?operation_type=getVerifyCode&room_id=room_123"
```

## 🐛 故障排除

### 常见问题

1. **查码任务不执行**
   - 检查队列服务是否启动
   - 检查队列表是否创建
   - 查看队列日志

2. **查码超时**
   - 检查网络连接
   - 验证代理IP是否可用
   - 调整超时配置

3. **账号不存在**
   - 确保账号已添加到数据库
   - 检查账号格式是否正确

4. **验证码地址为空**
   - 确保账号的 `verify_url` 字段已设置
   - 检查加密/解密是否正常

### 日志查看

```bash
# 查看Laravel日志
tail -f storage/logs/laravel.log

# 查看队列日志
tail -f storage/logs/queue.log
```

## 📈 性能优化

### 并发处理
- 多账号同时查码，不等待单个完成
- 使用队列异步处理，避免阻塞

### 代理轮询
- 自动轮询使用代理IP
- 避免单个代理过载

### 缓存优化
- 代理索引缓存
- 减少数据库查询

## 🔒 安全考虑

1. **参数验证**: 严格的输入验证
2. **账号限制**: 最多100个账号
3. **超时控制**: 防止长时间占用资源
4. **操作日志**: 完整的审计记录
5. **错误处理**: 优雅的异常处理 