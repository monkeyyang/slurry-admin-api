# 刷新失效登录账号脚本使用说明

## 概述

这个脚本用于自动刷新数据库中状态为"处理中"(`processing`)或"等待中"(`waiting`)且登录状态为"失效"(`invalid`)的iTunes账号的登录状态。

## 功能特性

- ✅ 自动查找需要刷新登录的账号
- ✅ 批量处理，每批最多50个账号
- ✅ 详细的控制台输出和日志记录
- ✅ 错误处理和异常恢复
- ✅ 支持多种运行方式
- ✅ 支持导出账号信息到CSV文件

## 文件说明

1. **`refresh_invalid_login_accounts.php`** - 独立PHP脚本
2. **`app/Console/Commands/RefreshInvalidLoginAccounts.php`** - Laravel Artisan命令

## 使用方法

### 方法一：直接运行PHP脚本

```bash
# 刷新所有失效账号
php refresh_invalid_login_accounts.php

# 刷新指定的单个账号
php refresh_invalid_login_accounts.php example@icloud.com

# 刷新指定的多个账号
php refresh_invalid_login_accounts.php user1@icloud.com user2@icloud.com

# 使用参数格式指定账号
php refresh_invalid_login_accounts.php -a user1@icloud.com -a user2@icloud.com
php refresh_invalid_login_accounts.php --account=user@icloud.com

# 显示帮助信息
php refresh_invalid_login_accounts.php --help
```

### 方法二：使用Artisan命令

```bash
# 刷新所有失效账号
php artisan itunes:refresh-invalid-login

# 刷新指定的单个账号
php artisan itunes:refresh-invalid-login --account=example@icloud.com

# 刷新指定的多个账号
php artisan itunes:refresh-invalid-login --account=user1@icloud.com --account=user2@icloud.com

# 导出账号信息到CSV文件（同时执行刷新）
php artisan itunes:refresh-invalid-login --export=storage/exports/accounts.csv

# 导出账号信息到HTML文件（支持颜色格式）
php artisan itunes:refresh-invalid-login --export-html=storage/exports/accounts.html

# 只导出账号信息，不执行刷新任务
php artisan itunes:refresh-invalid-login --export=storage/exports/accounts.csv --export-only

# 同时导出CSV和HTML格式
php artisan itunes:refresh-invalid-login --export=storage/exports/accounts.csv --export-html=storage/exports/accounts.html --export-only

# 导出指定账号的信息
php artisan itunes:refresh-invalid-login --account=user1@icloud.com --export=storage/exports/specific_accounts.csv --export-only
```

## 脚本执行流程

1. **查询账号**：获取符合条件的账号
   - 如果指定了账号：查询指定的账号中登录状态为 `invalid` 的
   - 如果未指定账号：查询状态为 `processing` 或 `waiting` 且登录状态为 `invalid` 的所有账号

2. **数据验证**：检查账号信息完整性
   - 验证账号名是否存在
   - 验证密码是否可以解密

3. **批量处理**：分批发送登录请求
   - 每批最多50个账号
   - 批次间有2秒延迟避免API压力

4. **导出功能**（可选）：将账号信息导出到文件
   - **CSV格式**：包含账号、密码（解密）、接码地址、金额、状态、登录状态、当前计划天、群聊名称、创建时间
   - **HTML格式**：支持颜色格式，失效状态红色加粗，有效状态绿色加粗，金额美元格式
   - 支持UTF-8编码，确保中文正常显示

5. **创建登录任务**：调用API创建登录任务
   - 使用 `ItunesTradeAccountService::createLoginTask()` 方法
   - 发送POST请求到 `http://47.76.200.188:8080/api/login_poll/new`

## 输出示例

### 刷新所有失效账号

```
==================================================
开始刷新失效登录状态的账号
时间: 2024-12-16 15:30:00
==================================================

找到 3 个账号需要刷新登录状态

需要刷新的账号列表:
--------------------------------------------------------------------------------
ID    Account                        Status       Country    Plan           
--------------------------------------------------------------------------------
123   example1@icloud.com           processing   US         测试计划1        
124   example2@icloud.com           waiting      CA         测试计划2        
125   example3@icloud.com           processing   GB         无计划          
--------------------------------------------------------------------------------

✓ 准备账号: example1@icloud.com
✓ 准备账号: example2@icloud.com
✓ 准备账号: example3@icloud.com

准备发送登录请求...

处理第 1 批，共 3 个账号...
✓ 第 1 批登录任务创建成功
  任务ID: task_1234567890
  包含账号: example1@icloud.com, example2@icloud.com, example3@icloud.com

✓ 登录任务创建完成

==================================================
脚本执行完成
==================================================
```

### 刷新指定账号

```
==================================================
开始刷新指定账号的登录状态
指定的账号: example1@icloud.com
时间: 2024-12-16 15:30:00
==================================================

找到 1 个账号需要刷新登录状态

需要刷新的账号列表:
--------------------------------------------------------------------------------
ID    Account                        Status       Country    Plan           
--------------------------------------------------------------------------------
123   example1@icloud.com           processing   US         测试计划1        
--------------------------------------------------------------------------------

✓ 准备账号: example1@icloud.com

准备发送登录请求...

处理第 1 批，共 1 个账号...
✓ 第 1 批登录任务创建成功
  任务ID: task_1234567891
  包含账号: example1@icloud.com

✓ 登录任务创建完成

==================================================
脚本执行完成
==================================================
```

### 导出账号信息

```
==================================================
开始处理失效登录状态的账号
时间: 2024-12-16 15:30:00
==================================================

找到 3 个账号

开始导出账号信息到文件: storage/exports/accounts.csv
✓ 成功导出 3 个账号到 storage/exports/accounts.csv

只导出模式，跳过登录任务创建

==================================================
脚本执行完成
==================================================
```

### 导出HTML格式

```
==================================================
开始处理失效登录状态的账号
时间: 2024-12-16 15:30:00
==================================================

找到 3 个账号

开始导出账号信息到HTML文件: storage/exports/accounts.html
✓ 成功导出 3 个账号到 storage/exports/accounts.html

只导出模式，跳过登录任务创建

==================================================
脚本执行完成
==================================================
```

### HTML文件使用说明

生成的HTML文件支持以下交互功能：

1. **查看长文本内容**：
   - 超过限制长度的文本会自动截断并显示"展开"按钮
   - 点击"展开"按钮查看完整内容
   - 点击"收起"按钮恢复截断显示

2. **复制行数据**：
   - 双击任意表格行可复制整行数据到剪贴板
   - 复制的数据使用制表符分隔，可直接粘贴到Excel等表格软件
   - 复制成功后会显示绿色提示信息，并高亮选中的行

3. **视觉提示**：
   - 鼠标悬停时行背景变色
   - 复制后行会短暂显示蓝色边框高亮
   - 页面右上角显示复制成功提示

### 导出文件格式

#### CSV文件格式

导出的CSV文件包含以下字段：

| 字段名 | 说明 | 示例 |
|--------|------|------|
| 账号 | iTunes账号邮箱 | example@icloud.com |
| 密码 | 解密后的密码 | mypassword123 |
| 接码地址 | API验证URL | https://verify.example.com |
| 金额 | 账号余额（美元格式） | $156.89 |
| 状态 | 账号处理状态 | 处理中 |
| 登录状态 | 登录验证状态 | 失效 |
| 当前计划天 | 执行计划的天数 | 3 |
| 群聊名称 | 绑定的群聊名称 | 测试群组 |
| 创建时间 | 账号创建时间 | 2024-12-15 10:30:00 |

**注意**：
- 无群聊时显示为 `-`
- 金额格式为美元：`$156.89`
- 当前计划天为null时显示为 `1`
- 状态翻译：completed→已完成，processing→处理中，waiting→等待中，locking→锁定中

#### HTML文件格式

HTML导出支持丰富的视觉效果：

- **登录状态颜色**：
  - 🟢 **有效**：绿色加粗显示
  - 🔴 **失效**：红色加粗显示
- **金额格式**：绿色加粗的美元格式（如 **$156.89**）
- **账号邮箱**：蓝色显示
- **群聊名称**：灰色斜体，无群聊显示为 `-`
- **密码字段**：等宽字体，灰色背景
- **接码地址**：紫色显示，过长时省略显示
- **长文本处理**：超长内容自动截断，显示"展开"按钮
- **交互功能**：
  - 点击"展开"按钮查看完整内容，再次点击"收起"恢复
  - 双击任意表格行复制整行数据到剪贴板
  - 复制成功后显示提示信息和行高亮效果
- **响应式设计**：支持各种屏幕尺寸
- **悬停效果**：表格行悬停高亮

## 错误处理

脚本包含完善的错误处理机制：

### 常见错误类型

1. **账号信息缺失**
   ```
   ⚠ 账号 ID:123 缺少账号名，跳过
   ⚠ 账号 ID:124 (example@icloud.com) 缺少密码，跳过
   ```

2. **API调用失败**
   ```
   ✗ 第 1 批登录任务创建失败: 创建登录任务失败: API错误信息
   ```

3. **密码解密失败**
   ```
   ⚠ 处理账号 ID:125 时出错: 解密密码失败
   ```

4. **导出文件错误**
   ```
   导出失败: 无法创建文件: /invalid/path/accounts.csv
   ⚠ 处理账号 example@icloud.com 时出错: 解密密码失败
   ```

### 日志记录

所有错误都会记录到Laravel日志系统中：

```php
Log::error("批量创建登录任务失败", [
    'batch_number' => $batchNum,
    'accounts_in_batch' => ['account1@example.com', 'account2@example.com'],
    'error' => $e->getMessage(),
    'trace' => $e->getTraceAsString()
]);
```

## 数据库查询条件

### 查询所有失效账号（不指定特定账号时）

```sql
SELECT * FROM itunes_trade_accounts 
WHERE status IN ('processing', 'waiting')
AND login_status = 'invalid'
AND deleted_at IS NULL
```

### 查询指定账号（指定特定账号时）

```sql
SELECT * FROM itunes_trade_accounts 
WHERE account IN ('user1@icloud.com', 'user2@icloud.com')
AND login_status = 'invalid'
AND deleted_at IS NULL
```

## API接口说明

### 登录任务创建API

- **URL**: `http://47.76.200.188:8080/api/login_poll/new`
- **方法**: POST
- **数据格式**:
  ```json
  {
    "list": [
      {
        "id": 123,
        "username": "example@icloud.com",
        "password": "encrypted_password",
        "VerifyUrl": "https://verify.url"
      }
    ]
  }
  ```

### 响应格式

```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "task_id": "task_1234567890"
  }
}
```

## 定时执行设置

如果需要定时执行此脚本，可以添加到系统crontab中：

```bash
# 每30分钟刷新所有失效账号
*/30 * * * * cd /path/to/your/project && php refresh_invalid_login_accounts.php >> /var/log/refresh_login.log 2>&1

# 或者使用Artisan命令
*/30 * * * * cd /path/to/your/project && php artisan itunes:refresh-invalid-login >> /var/log/refresh_login.log 2>&1

# 定时刷新特定账号
0 */2 * * * cd /path/to/your/project && php refresh_invalid_login_accounts.php user1@icloud.com user2@icloud.com >> /var/log/refresh_login.log 2>&1
```

## 注意事项

1. **权限要求**：确保脚本有读取数据库和写入日志的权限
2. **网络连接**：需要能访问登录API接口
3. **数据库连接**：确保Laravel数据库配置正确
4. **批处理限制**：每批最多50个账号，避免API超载
5. **延迟设置**：批次间有2秒延迟，可根据需要调整
6. **文件权限**：确保脚本有创建和写入导出文件的权限
7. **导出目录**：建议使用 `storage/exports/` 目录存放导出文件

## 故障排除

### 脚本无法运行

1. 检查PHP版本和扩展
2. 确认composer依赖已安装
3. 检查Laravel环境配置

### API调用失败

1. 检查网络连接
2. 确认API地址是否正确
3. 查看详细错误日志

### 账号查询为空

1. 检查数据库连接
2. 确认查询条件是否正确
3. 验证账号状态和登录状态字段值

### 导出文件问题

1. 检查文件路径是否有效
2. 确认目录存在和写入权限
3. 验证磁盘空间是否充足

## 相关文件

- `app/Models/ItunesTradeAccount.php` - 账号模型
- `app/Services/ItunesTradeAccountService.php` - 账号服务
- `storage/logs/laravel.log` - 错误日志文件 