# ProcessItunesAccountsV2 使用指南

## 概述

`ProcessItunesAccountsV2` 是重构后的iTunes账号状态管理命令，专注于核心职责，避免了原版的复杂性和死循环问题。

## 设计原则

### 1. 职责明确
- **主要处理**: 只处理 `LOCKING` 和 `WAITING` 状态的账号
- **辅助处理**: 清理异常状态（孤立账号、已完成账号等）
- **不处理**: `PROCESSING` 状态的账号（让它们专心执行兑换任务）

### 2. 四步处理流程
```
第1步：维护零余额账号数量
  ├── 统计当前零余额且登录有效的账号数量
  ├── 如果不足50个 -> 从零余额登录失效账号中补充
  └── 批量创建登录任务

第2步：清理异常状态
  ├── 孤立账号（计划已删除） -> 解绑并设为WAITING
  ├── 已完成但仍登录的账号 -> 登出
  └── 数据不一致问题 -> 回退到正确天数

第3步：处理LOCKING状态
  ├── 无计划账号 -> PROCESSING
  ├── 已达到总目标 -> COMPLETED
  ├── 当日计划完成 -> WAITING + 登出
  └── 当日计划未完成 -> PROCESSING

第4步：处理WAITING状态
  ├── 已达到总目标 -> COMPLETED
  ├── 无计划有余额账号 -> PROCESSING + 登录（解决一直无效问题）
  ├── 无计划零余额账号 -> 保持WAITING
  ├── 新账号（无兑换记录） -> PROCESSING + 登录
  ├── 时间间隔不足 -> 继续等待
  ├── 当日计划未完成 -> PROCESSING + 登录
  ├── 最后一天超时 -> 解绑计划
  └── 可以进入下一天 -> PROCESSING + 登录
```

## 命令使用

### 基本用法
```bash
# 正常执行
php artisan itunes:process-accounts-v2

# 预览模式（不实际执行，只显示操作）
php artisan itunes:process-accounts-v2 --dry-run
```

### 与原版对比

| 方面 | 原版 ProcessItunesAccounts | 新版 ProcessItunesAccountsV2 |
|------|---------------------------|------------------------------|
| 处理范围 | 所有状态 | 只处理 LOCKING + WAITING |
| PROCESSING处理 | 频繁干扰 | 基本不处理 |
| 复杂度 | 高（1400+行） | 低（650行） |
| 死循环风险 | 高 | 低 |
| 日志可读性 | 一般 | 好（带emoji） |
| 预览功能 | 无 | 有（--dry-run） |

## 核心改进

### 1. 避免死循环
- 不再对 `PROCESSING` 状态进行过度检查
- 只处理明确的数据不一致问题
- 清晰的状态转换路径

### 2. 更好的可观测性
```bash
# 预览模式示例输出
🔍 DRY RUN 模式：只显示操作，不实际执行
=== 第1步：维护零余额账号数量 ===
💰 需要补充 15 个零余额登录账号
🔍 DRY RUN: 将为 30 个账号创建登录任务

=== 第2步：处理异常状态账号 ===
🔧 孤立账号: user@example.com
🔒 已完成账号需登出: completed@example.com
⚠️  数据不一致: problem@example.com -> 回退到第2天

=== 第3步：处理LOCKING状态账号 ===
📝 无计划账号: noplan@example.com -> PROCESSING
✅ 当日计划完成: daily_done@example.com (第3天) -> WAITING
⏳ 当日计划未完成: ongoing@example.com (第1天) -> PROCESSING

=== 第4步：处理WAITING状态账号 ===
💸 无计划有余额账号: rich_orphan@example.com -> PROCESSING (可用于兑换)
🚀 新账号开始: newbie@example.com -> PROCESSING (第1天)
⏳ 继续当日计划: continue@example.com -> PROCESSING (第2天)
📅 进入下一天: nextday@example.com -> PROCESSING (第4天)
⏰ 最后一天超时: timeout@example.com -> 解绑计划
```

### 3. 防御性编程
- 大量的空值检查
- 异常处理包装
- 状态验证

### 4. 新增功能解决的关键问题

#### 问题1：零余额账号维护缺失
**原问题**：系统需要维护50个零余额且登录有效的账号用于测试
**解决方案**：
- 自动统计当前符合条件的账号数量
- 从零余额但登录失效的账号中补充
- 批量创建登录任务，异步处理

#### 问题2：无计划WAITING账号永远无效
**原问题**：有余额但无计划的WAITING账号会一直保持WAITING状态，无法被使用
**解决方案**：
- 检查无计划账号的余额
- 有余额账号 → 转为PROCESSING状态 + 请求登录
- 零余额账号 → 保持WAITING状态（用于被绑定计划）

## 部署建议

### 1. 逐步替换
```bash
# 第1步：并行运行观察（预览模式）
php artisan itunes:process-accounts-v2 --dry-run

# 第2步：短时间测试
# 暂停原命令，运行新命令30分钟

# 第3步：完全替换
# 更新crontab或调度器
```

### 2. 监控要点
- 检查账号状态转换是否正常
- 观察是否还有死循环现象
- 验证完成通知是否正常发送
- 确认登录/登出操作是否有效

### 3. 配置文件
```bash
# 确保日志配置正确
config/logging.php -> kernel_process_accounts通道

# 确保队列配置正确（用于登录/登出任务）
config/queue.php
```

## 故障排除

### 1. 常见问题
```bash
# 检查模型关系是否正确
php artisan tinker
>>> App\Models\ItunesTradeAccount::with('plan')->first()

# 检查GiftCardApiClient是否可用
>>> app(App\Services\GiftCardApiClient::class)

# 检查日志输出
tail -f storage/logs/laravel.log | grep process_accounts
```

### 2. 调试技巧
```bash
# 使用预览模式快速诊断
php artisan itunes:process-accounts-v2 --dry-run

# 检查特定账号状态
php artisan tinker
>>> App\Models\ItunesTradeAccount::where('account', 'user@example.com')->first()
```

## 注意事项

1. **不要同时运行两个版本**：会造成冲突
2. **定期检查预览输出**：了解系统状态
3. **关注异常日志**：及时发现问题
4. **备份重要数据**：在完全切换前

## 后续优化

1. 添加更多状态统计
2. 支持更灵活的时间配置
3. 添加更多的预检验证
4. 考虑拆分为更小的独立命令 