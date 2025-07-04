# 加密功能和操作记录功能说明

## 🔐 加密功能

### 概述
系统已实现对称加密功能，用于保护账号密码和验证码地址等敏感信息。

### 加密服务 (EncryptionService)

**位置**: `app/Services/EncryptionService.php`

**主要方法**:
- `encrypt($data)` - 加密单个数据
- `decrypt($encryptedData)` - 解密单个数据
- `encryptArray($data, $sensitiveFields)` - 批量加密数组中的敏感字段
- `decryptArray($data, $sensitiveFields)` - 批量解密数组中的敏感字段

### 模型自动加密

**ItunesAccountVerify 模型**:
- 保存时自动加密 `password` 和 `verify_url` 字段
- 读取时自动解密这些字段
- 使用 Laravel 的 `Crypt` 门面进行加密

### 加密字段
- `password` - 账号密码
- `verify_url` - 验证码获取地址

## 📝 操作记录功能

### 概述
系统已实现完整的操作记录功能，记录用户的各种操作行为。

### 操作记录表结构

```sql
CREATE TABLE `operation_logs` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `uid` int(10) unsigned NULL COMMENT '用户ID',
    `room_id` varchar(200) NULL COMMENT '来源群聊ID',
    `wxid` varchar(200) NULL COMMENT '来源微信ID',
    `operation_type` varchar(50) NOT NULL COMMENT '操作类型',
    `target_account` varchar(200) NULL COMMENT '目标账号',
    `result` enum('success', 'failed', 'password_error') NOT NULL COMMENT '操作结果',
    `details` text NULL COMMENT '详细信息',
    `user_agent` text NULL COMMENT '用户代理',
    `ip_address` varchar(45) NULL COMMENT 'IP地址',
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `operation_logs_uid_index` (`uid`),
    KEY `operation_logs_room_id_index` (`room_id`),
    KEY `operation_logs_wxid_index` (`wxid`),
    KEY `operation_logs_operation_type_index` (`operation_type`),
    KEY `operation_logs_target_account_index` (`target_account`),
    KEY `operation_logs_result_index` (`result`),
    KEY `operation_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='操作记录表';
```

### 支持的操作类型

| 操作类型 | 说明 |
|---------|------|
| `search` | 搜索 |
| `delete` | 删除 |
| `copy` | 复制 |
| `getVerifyCode` | 获取验证码 |
| `edit` | 编辑 |
| `create` | 创建 |
| `import` | 导入 |
| `export` | 导出 |
| `password_verify` | 密码验证 |
| `page_view` | 页面浏览 |

### 操作结果类型

| 结果类型 | 说明 |
|---------|------|
| `success` | 成功 |
| `failed` | 失败 |
| `password_error` | 密码错误 |

### API 接口

#### 操作记录接口
- `POST /verify/operation-logs` - 创建操作记录
- `GET /verify/operation-logs` - 获取操作记录列表
- `GET /verify/operation-logs/statistics` - 获取操作统计
- `GET /verify/operation-logs/{id}` - 获取操作记录详情
- `DELETE /verify/operation-logs/{id}` - 删除操作记录
- `DELETE /verify/operation-logs/batch` - 批量删除操作记录

#### 查询参数
- `operation_type` - 操作类型
- `target_account` - 目标账号
- `result` - 操作结果
- `uid` - 用户ID
- `room_id` - 群聊ID
- `wxid` - 微信ID
- `startTime` - 开始时间
- `endTime` - 结束时间
- `pageNum` - 页码
- `pageSize` - 每页数量

### 自动记录的操作

系统会自动记录以下操作：

1. **创建账号** (`create`)
2. **更新账号** (`edit`)
3. **删除账号** (`delete`)
4. **复制账号密码** (`copy`)
5. **获取验证码** (`getVerifyCode`)

## 🚀 部署步骤

### 1. 数据库迁移

```bash
# 运行迁移
php artisan migrate

# 或者直接执行SQL
mysql -u username -p database_name < database/migrations/add_room_id_wxid_to_operation_logs.sql
```

### 2. 清理缓存

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

### 3. 测试加密功能

```bash
php test_encryption.php
```

## 🔧 使用示例

### 创建操作记录

```php
use App\Models\OperationLog;

OperationLog::create([
    'uid' => auth()->id(),
    'room_id' => 'room_123',
    'wxid' => 'wx_456',
    'operation_type' => 'create',
    'target_account' => 'test@example.com',
    'result' => 'success',
    'details' => '创建验证码账号',
    'ip_address' => request()->ip(),
    'user_agent' => request()->header('User-Agent'),
]);
```

### 加密敏感数据

```php
use App\Services\EncryptionService;

$encryptedPassword = EncryptionService::encrypt('mypassword123');
$decryptedPassword = EncryptionService::decrypt($encryptedPassword);
```

## 📊 统计功能

操作记录统计接口返回：
- `totalOperations` - 总操作数
- `successOperations` - 成功操作数
- `failedOperations` - 失败操作数
- `operationsByType` - 按操作类型统计

## 🔒 安全说明

1. 加密使用 Laravel 的 `Crypt` 门面，基于 AES-256-CBC 算法
2. 加密密钥存储在 `.env` 文件的 `APP_KEY` 中
3. 敏感字段在数据库中以加密形式存储
4. 操作记录包含 IP 地址和用户代理信息，便于安全审计 