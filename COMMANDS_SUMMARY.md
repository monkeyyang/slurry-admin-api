# 命令总结文档

## 概述

本文档汇总了Slurry Admin API项目中所有重要的Artisan命令，特别是与iTunes账号管理、礼品卡处理、队列管理相关的核心命令。

## iTunes账号管理命令

### 1. ProcessItunesAccounts - 核心账号处理命令
```bash
# 基本用法
php artisan itunes:process-accounts

# 可选参数
php artisan itunes:process-accounts --logout-only      # 仅执行登出操作
php artisan itunes:process-accounts --login-only       # 仅执行登录操作  
php artisan itunes:process-accounts --fix-task=TASK_ID # 修复任务数据
```

**功能说明：**
- 自动维护50个零余额登录账号
- 处理账号状态转换（LOCKING → WAITING → PROCESSING → COMPLETED）
- 自动登录/登出管理
- 计划执行和进度跟踪

**定时执行：** 每分钟自动运行（已配置在Kernel.php中）

**详细文档：** [PROCESS_ITUNES_ACCOUNTS_README.md](PROCESS_ITUNES_ACCOUNTS_README.md)

### 2. RefreshInvalidLoginAccounts - 刷新失效登录账号
```bash
# 刷新所有失效账号
php artisan itunes:refresh-invalid-login

# 刷新指定账号
php artisan itunes:refresh-invalid-login --account=user@icloud.com

# 导出账号信息
php artisan itunes:refresh-invalid-login --export=storage/exports/accounts.csv
```

**功能说明：**
- 批量刷新失效登录状态的账号
- 支持指定特定账号刷新
- 支持导出账号信息到CSV/HTML格式

**详细文档：** [REFRESH_LOGIN_ACCOUNTS_README.md](REFRESH_LOGIN_ACCOUNTS_README.md)

## 礼品卡处理命令

### 3. CleanupPendingRecords - 清理pending记录
```bash
# 清理超时的pending记录
php artisan cleanup:pending-records

# 预览模式（不实际执行）
php artisan cleanup:pending-records --preview
```

**功能说明：**
- 清理状态为"检查兑换代码"的超时记录
- 智能判断：检查重复兑换、批次任务状态等
- 兜底机制确保pending记录最终都有结果

**定时执行：** 每10分钟自动运行（已配置在Kernel.php中）

## 定时任务配置

在 `app/Console/Kernel.php` 中已配置的定时任务：

```php
protected function schedule(Schedule $schedule): void
{
    // 每分钟处理iTunes账号状态转换和登录管理
    $schedule->command('itunes:process-accounts')->everyMinute()
             ->name('process_itunes_accounts')
             ->withoutOverlapping();

    // 每10分钟清理超时的pending记录
    $schedule->command('cleanup:pending-records --timeout=10')->everyTenMinutes()
             ->name('cleanup_pending_records')
             ->withoutOverlapping();

    // 每分钟执行卡密查询队列
    $schedule->job(new ProcessCardQueryJob())->everyMinute()
             ->name('card_query_job')
             ->withoutOverlapping();
}
```

## 相关文档

- [ProcessItunesAccounts命令详细说明](PROCESS_ITUNES_ACCOUNTS_README.md)
- [刷新失效登录账号说明](REFRESH_LOGIN_ACCOUNTS_README.md)
- [礼品卡兑换记录修复分析](PENDING_EXCHANGE_RECORDS_ANALYSIS.md)
- [项目总体文档](PROJECT_DOCUMENTATION.md)

---

*最后更新时间: 2024年12月16日* 