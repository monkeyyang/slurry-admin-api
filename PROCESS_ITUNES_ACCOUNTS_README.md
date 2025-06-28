# ProcessItunesAccounts 命令使用说明

## 概述

`ProcessItunesAccounts` 是一个用于处理iTunes账号状态转换和登录管理的核心命令。该命令负责维护iTunes账号的生命周期管理，包括自动登录、登出、状态转换以及计划执行等功能。

## 功能特性

- ✅ 自动维护50个零余额登录账号
- ✅ 智能账号状态转换（LOCKING → WAITING → PROCESSING → COMPLETED）
- ✅ 自动登录/登出管理
- ✅ 计划执行和进度跟踪
- ✅ 错误处理和状态恢复
- ✅ 完整的中文日志记录
- ✅ 支持多种执行模式

## 命令参数

### 基本语法
```bash
php artisan itunes:process-accounts [选项]
```

### 可用选项

| 参数 | 说明 | 示例 |
|------|------|------|
| `--logout-only` | 仅执行登出操作 | `php artisan itunes:process-accounts --logout-only` |
| `--login-only` | 仅执行登录操作 | `php artisan itunes:process-accounts --login-only` |
| `--fix-task=TASK_ID` | 通过任务ID修复账号数据 | `php artisan itunes:process-accounts --fix-task=d0974963-a8be-41a2-b6d2-8b75caed3cb5` |

## 使用方法

### 1. 正常执行（推荐）
```bash
# 完整的账号处理流程，每分钟自动运行
php artisan itunes:process-accounts
```

**执行内容：**
- 维护50个零余额且登录有效的账号
- 处理账号状态转换（LOCKING/WAITING → PROCESSING/COMPLETED）
- 自动登录/登出管理
- 计划执行和进度跟踪

### 2. 仅执行登出操作
```bash
# 只处理需要登出的账号
php artisan itunes:process-accounts --logout-only
```

**处理条件：**
- amount = 0（零余额）
- status = processing（处理中状态）
- login_status = valid（登录有效）

**执行逻辑：**
- 按创建时间倒序排列（后导入的先退出）
- 批量调用登出API
- 更新账号登录状态为invalid

### 3. 仅执行登录操作
```bash
# 只处理需要登录的账号
php artisan itunes:process-accounts --login-only
```

**处理条件：**
- status = processing（处理中状态）
- login_status = invalid（登录无效）
- amount > 0（有余额）

**执行逻辑：**
- 按创建时间正序排列（先导入的优先处理）
- 批量创建登录任务
- 等待登录完成并更新账号状态

### 4. 修复任务数据
```bash
# 通过API任务ID修复账号数据
php artisan itunes:process-accounts --fix-task=d0974963-a8be-41a2-b6d2-8b75caed3cb5
```

**执行逻辑：**
- 从API获取任务执行结果
- 解析登录状态和余额信息
- 更新对应账号的数据
- 支持各种货币格式的余额解析

## 账号状态转换流程

### 状态定义
- **PROCESSING**: 处理中，正在执行兑换任务
- **LOCKING**: 锁定中，兑换任务完成，等待状态更新
- **WAITING**: 等待中，等待下次兑换时间
- **COMPLETED**: 已完成，达到计划目标或超时

### 转换流程图
```
[导入账号] → [PROCESSING] → [LOCKING] → [WAITING] → [PROCESSING] → ... → [COMPLETED]
     ↓              ↓             ↓           ↓                         ↓
  [请求登录]    [继续处理]    [请求登出]   [检查时间]              [请求登出]
```

### 详细转换逻辑

#### 1. LOCKING → WAITING
**触发条件：**
- 账号处于LOCKING状态
- 兑换任务完成

**执行操作：**
- 更新completed_days字段
- 检查是否达到计划目标
- 如果未完成：状态变更为WAITING，请求登出
- 如果已完成：状态变更为COMPLETED，请求登出

#### 2. WAITING → PROCESSING
**触发条件：**
- 账号处于WAITING状态
- 满足兑换间隔时间（默认5分钟）
- 满足天数间隔时间（默认24小时）或每日计划未完成

**执行操作：**
- 检查是否为计划最后一天
- 如果是最后一天且超时：标记为COMPLETED
- 如果不是最后一天且满足天数间隔：进入下一天
- 如果每日计划未完成：继续当天处理

#### 3. 任何状态 → COMPLETED
**触发条件：**
- 账号兑换总金额达到计划目标（基于最后一条成功兑换记录的after_amount）
- 等待时间超过最大限制（48小时）
- 计划已删除或配置异常
- 计划最后一天且已达到目标金额

## 零余额账号维护

### 维护策略
- **目标数量**: 50个账号
- **条件**: amount = 0 且 login_status = valid
- **补充机制**: 从processing且login_status为invalid的零余额账号池中登录补充

### 补充流程
1. 统计当前零余额且登录有效的账号数量
2. 如果少于50个，计算需要补充的数量
3. 查找候选账号（processing + invalid + amount=0）
4. 按创建时间升序排列（先导入的优先）
5. 批量创建登录任务
6. 等待登录完成并更新状态

## 登录/登出管理

### 登录管理
**自动登录场景：**
- 状态变更为PROCESSING时
- 进入下一天时
- 维护零余额账号时

**登录流程：**
1. 检查是否已经登录（跳过重复登录）
2. 准备登录数据（用户名、密码、验证URL）
3. 调用API创建登录任务
4. 等待任务完成并更新账号状态

### 登出管理
**自动登出场景：**
- LOCKING状态变更为WAITING时
- 账号标记为COMPLETED时
- 执行--logout-only参数时

**登出流程：**
1. 检查是否已经登出（跳过重复登出）
2. 调用API删除用户登录
3. 更新账号登录状态为invalid

### 登出优先级
- **后导入的账号优先退出登录**
- **先导入的账号保持登录状态**
- 确保系统始终有足够的可用账号

## API接口集成

### 登录任务接口
```http
POST /api/login_poll/new
Content-Type: application/json

{
  "data": [
    {
      "id": 123,
      "username": "user@icloud.com",
      "password": "decrypted_password",
      "VerifyUrl": "https://api.example.com/verify"
    }
  ]
}
```

### 查询任务状态接口
```http
GET /api/login_poll/status?task_id=d0974963-a8be-41a2-b6d2-8b75caed3cb5
```

### 删除用户登录接口
```http
POST /api/del_users
Content-Type: application/json

{
  "data": [
    {"username": "user@icloud.com"}
  ]
}
```

## 余额解析功能

### 支持的货币格式
- `$700.00` → `700.00`
- `¥1000.50` → `1000.50`
- `€500.25` → `500.25`
- `$1,350.00` → `1350.00`

### 解析逻辑
使用正则表达式 `/[^\d.-]/` 移除所有非数字、非小数点、非负号的字符，然后转换为浮点数。

## 错误处理机制

### 异常恢复
- **API调用失败**: 记录错误日志，跳过当前操作
- **账号数据异常**: 清理无效关联，重置状态
- **计划配置错误**: 标记账号为完成，避免无限循环

### 日志记录
- **详细的中文日志**: 所有操作都有完整的中文日志记录
- **错误追踪**: 包含错误信息和堆栈跟踪
- **操作统计**: 处理数量、成功数量、失败数量

### 防护机制
- **重复操作检查**: 避免重复登录/登出
- **超时保护**: 防止账号无限等待
- **状态一致性**: 确保账号状态与实际情况一致
- **准确的金额计算**: 使用兑换记录的after_amount而非账号余额

## 日志输出示例

### 正常执行日志
```
[2024-12-16 15:30:00] INFO: ===================================[2024-12-16 15:30:00]===============================
[2024-12-16 15:30:00] INFO: 开始iTunes账号状态转换和登录管理...
[2024-12-16 15:30:00] INFO: 当前零余额且登录有效的账号数量: 45
[2024-12-16 15:30:00] INFO: 需要补充 5 个零余额登录账号
[2024-12-16 15:30:00] INFO: 找到 8 个候选登录账号
[2024-12-16 15:30:00] INFO: 登录任务创建成功，任务ID: task_1234567890，等待完成...
[2024-12-16 15:30:05] INFO: 登录任务状态检查（第1次）: running
[2024-12-16 15:30:10] INFO: 账号 user1@icloud.com 登录成功
[2024-12-16 15:30:10] INFO: 更新账号 user1@icloud.com 余额: 0 (原始: $0.00)
[2024-12-16 15:30:15] INFO: 登录任务完成，成功登录 5 个账号
[2024-12-16 15:30:15] INFO: 找到 10 个LOCKING/WAITING账号，2 个孤立账号，3 个需要登出的已完成账号
[2024-12-16 15:30:15] INFO: 成功登出 3 个账号 (已完成状态登出)
[2024-12-16 15:30:15] INFO: 正在处理锁定状态账号: user2@icloud.com
[2024-12-16 15:30:15] INFO: 账号 user2@icloud.com 所有天数数据已更新
[2024-12-16 15:30:15] INFO: 账号 user2@icloud.com 完成检查 current_total_amount: 1700.00, plan_total_amount: 2000.00, is_completed: false
[2024-12-16 15:30:15] INFO: 锁定账号状态变更为等待状态
[2024-12-16 15:30:15] INFO: iTunes账号处理完成
```

### 仅登出操作日志
```
[2024-12-16 15:30:00] INFO: 开始执行登出操作...
[2024-12-16 15:30:00] INFO: 找到 5 个符合登出条件的账号
[2024-12-16 15:30:00] INFO: 成功登出 5 个账号 (仅登出操作)
[2024-12-16 15:30:00] INFO: 登出操作完成
```

### 修复任务日志
```
[2024-12-16 15:30:00] INFO: 开始执行修复任务，任务ID: d0974963-a8be-41a2-b6d2-8b75caed3cb5
[2024-12-16 15:30:00] INFO: 任务状态: completed，找到 3 个项目
[2024-12-16 15:30:00] INFO: 正在处理账号修复: user1@icloud.com，状态: completed，消息: 登录成功
[2024-12-16 15:30:00] INFO: 账号 user1@icloud.com 登录状态已更新为有效
[2024-12-16 15:30:00] INFO: 账号 user1@icloud.com 余额已更新: 1350.00 (原始: $1,350.00)
[2024-12-16 15:30:00] INFO: 修复任务完成 processed_count: 3, success_count: 2, failed_count: 1
[2024-12-16 15:30:00] INFO: 修复任务操作完成
```

### 最后一天继续执行日志
```
[2025-06-29 00:59:02] INFO: 正在处理锁定状态账号: vanceO2664@icloud.com
[2025-06-29 00:59:02] INFO: 账号 vanceO2664@icloud.com 所有天数数据已更新
[2025-06-29 00:59:02] INFO: 账号 vanceO2664@icloud.com 完成检查 current_total_amount: 1600.00, plan_total_amount: 1850.00, is_completed: false
[2025-06-29 00:59:02] INFO: 账号 vanceO2664@icloud.com 最后一天未达到目标，继续处理 remaining_amount: 250.00
[2025-06-29 00:59:02] INFO: 账号 vanceO2664@icloud.com 登出成功 (locking to waiting)
[2025-06-29 00:59:02] INFO: 锁定账号状态变更为等待状态
```

## 定时任务配置

### Cron设置
```bash
# 每分钟执行一次
* * * * * cd /path/to/project && php artisan itunes:process-accounts >> /dev/null 2>&1
```

### Laravel调度器
```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('itunes:process-accounts')
             ->everyMinute()
             ->withoutOverlapping()
             ->runInBackground();
}
```

## 性能优化建议

### 1. 批量处理
- 登录任务批量创建，减少API调用次数
- 登出操作批量执行，提高效率

### 2. 智能跳过
- 已登录账号跳过重复登录请求
- 已登出账号跳过重复登出请求
- 无需处理的账号直接跳过

### 3. 异步处理
- 登录任务创建后异步等待完成
- 状态更新操作独立执行

### 4. 缓存优化
- 账号查询结果缓存
- API响应结果缓存

## 故障排除

### 常见问题

#### 1. 登录任务创建失败
**可能原因：**
- API服务不可用
- 账号密码解密失败
- 网络连接问题

**解决方案：**
- 检查API服务状态
- 验证账号数据完整性
- 检查网络连接

#### 2. 账号状态异常
**可能原因：**
- 计划配置错误
- 数据库数据不一致
- 业务逻辑异常

**解决方案：**
- 使用--fix-task修复账号数据
- 检查计划配置完整性
- 清理孤立数据

#### 3. 余额解析错误
**可能原因：**
- API返回格式变化
- 货币符号不支持
- 数据格式异常

**解决方案：**
- 检查API返回数据格式
- 更新货币解析逻辑
- 添加新的货币格式支持

#### 4. 最后一天账号被错误登出
**问题现象：**
- 账号在计划最后一天处于LOCKING状态
- 账号被登出但未标记为完成
- 日志显示"锁定账号状态变更为等待状态"

**可能原因：**
- 账号完成检查逻辑基于账号余额而非兑换记录
- 缺少最后一天的特殊处理逻辑

**解决方案：**
- 已在v2.1.0中修复
- 最后一天会检查是否达到目标金额
- 只有达到目标才标记为完成，否则继续执行
- 账号完成检查基于实际兑换记录总额

### 调试技巧

#### 1. 查看详细日志
```bash
# 查看实时日志
tail -f storage/logs/laravel.log | grep "ProcessItunesAccounts"

# 查看专用日志
tail -f storage/logs/kernel_process_accounts.log
```

#### 2. 单独测试功能
```bash
# 测试登出功能
php artisan itunes:process-accounts --logout-only

# 测试登录功能
php artisan itunes:process-accounts --login-only

# 测试修复功能
php artisan itunes:process-accounts --fix-task=TASK_ID
```

#### 3. 数据库查询验证
```sql
-- 查看账号状态分布
SELECT status, login_status, COUNT(*) as count 
FROM itunes_trade_accounts 
GROUP BY status, login_status;

-- 查看零余额账号
SELECT * FROM itunes_trade_accounts 
WHERE amount = 0 AND login_status = 'valid';

-- 查看需要处理的账号
SELECT * FROM itunes_trade_accounts 
WHERE status IN ('locking', 'waiting') 
ORDER BY updated_at DESC;
```

## 最佳实践

### 1. 监控建议
- 设置日志监控告警
- 定期检查账号状态分布
- 监控API调用成功率

### 2. 维护建议
- 定期清理无效账号数据
- 更新计划配置和汇率信息
- 备份重要的账号数据

### 3. 安全建议
- 保护账号密码安全
- 限制API访问权限
- 定期更新访问密钥

## 版本历史

### v2.1.0 (2025-06-29)
- ✅ 修复LOCKING状态最后一天账号被错误登出的问题
- ✅ 改进账号完成检查逻辑，使用最后一条成功兑换记录的after_amount
- ✅ 优化完成通知，显示实际兑换总金额而非账号余额
- ✅ 修复最后一天处理逻辑，只有达到目标才完成，否则继续执行

### v2.0.0 (2024-12-16)
- ✅ 完整的中文化日志输出
- ✅ 新增修复任务功能（--fix-task）
- ✅ 新增仅登录功能（--login-only）
- ✅ 修复强制完成逻辑错误
- ✅ 增强余额解析功能
- ✅ 优化登录/登出管理

### v1.0.0 (2024-12-01)
- ✅ 基础账号状态转换功能
- ✅ 零余额账号维护
- ✅ 登录/登出管理
- ✅ 仅登出操作功能

## 相关文档

- [礼品卡兑换记录修复文档](PENDING_EXCHANGE_RECORDS_ANALYSIS.md)
- [刷新失效登录账号文档](REFRESH_LOGIN_ACCOUNTS_README.md)
- [项目总体文档](PROJECT_DOCUMENTATION.md)
- [iTunes交易增强功能文档](ITUNES_TRADE_ENHANCEMENTS_SUMMARY.md) 