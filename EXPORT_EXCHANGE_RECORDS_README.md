# 兑换记录导出工具

这个工具提供了两个脚本来导出兑换成功的记录，包含用户所需的关键字段：

- 兑换码
- 国家
- 金额
- 账号余款
- 账号
- 错误信息
- 执行状态
- 时间
- 群聊
- 计划
- 汇率

## 脚本说明

### 1. `export_successful_exchanges.php` - 快速导出工具

这是一个简化的脚本，专门用于快速导出兑换成功记录，只包含用户需要的关键字段。

#### 特点：
- 从iTunes交易日志表获取成功记录
- 输出中文字段名，便于阅读
- 支持基本的过滤条件
- 默认输出CSV格式

#### 用法：
```bash
# 基本用法，导出所有成功记录
php export_successful_exchanges.php

# 限制导出数量
php export_successful_exchanges.php --limit=1000

# 按国家过滤
php export_successful_exchanges.php --country=US --output=us_success.csv

# 按时间范围过滤
php export_successful_exchanges.php --start-date=2024-01-01 --end-date=2024-12-31

# 显示帮助
php export_successful_exchanges.php --help
```

### 2. `export_exchange_records.php` - 完整导出工具

这是一个功能完整的脚本，支持多种导出格式和详细的过滤条件。

#### 特点：
- 支持多种数据表选择
- 支持多种输出格式（CSV、JSON、Excel）
- 丰富的过滤条件
- 详细的数据字段
- 支持多状态导出

#### 用法：
```bash
# 导出iTunes交易日志的成功记录到CSV
php export_exchange_records.php --table=itunes_trade_account_logs --output=csv --file=success_records.csv

# 导出所有表的记录到Excel
php export_exchange_records.php --table=all --output=xlsx --file=all_records.xlsx --start-date=2024-01-01

# 导出礼品卡兑换记录到JSON
php export_exchange_records.php --table=gift_card_exchange_records --output=json --country=US --limit=1000

# 显示帮助
php export_exchange_records.php --help
```

## 支持的选项

### 快速导出工具选项：
- `--output=FILE`: 输出文件名（默认：successful_exchanges.csv）
- `--limit=NUM`: 限制导出数量
- `--country=CODE`: 国家代码过滤（如：US, CA, GB）
- `--start-date=DATE`: 开始日期（YYYY-MM-DD）
- `--end-date=DATE`: 结束日期（YYYY-MM-DD）
- `--help`: 显示帮助信息

### 完整导出工具选项：
- `--table=TABLE`: 数据表类型（itunes_trade_account_logs|gift_card_exchange_records|all）
- `--output=FORMAT`: 输出格式（csv|json|xlsx）
- `--file=FILE`: 输出文件名
- `--status=STATUS`: 状态过滤（success|failed|pending）
- `--start-date=DATE`: 开始日期（YYYY-MM-DD）
- `--end-date=DATE`: 结束日期（YYYY-MM-DD）
- `--country=CODE`: 国家代码过滤
- `--account=ACCOUNT`: 账号过滤
- `--plan-id=ID`: 计划ID过滤
- `--rate-id=ID`: 汇率ID过滤
- `--limit=NUM`: 限制导出数量
- `--help`: 显示帮助信息

## 数据来源

### 快速导出工具数据来源

**iTunes交易账户日志表 (itunes_trade_account_logs)**
- 包含iTunes账户的兑换记录
- 有详细的账号余额信息
- 包含群聊和微信相关信息

### 完整导出工具数据来源

**支持两个数据表：**
1. **iTunes交易账户日志表 (itunes_trade_account_logs)** - 主要数据源
2. **礼品卡兑换记录表 (gift_card_exchange_records)** - 如果系统中存在此表

## 输出字段说明

### 快速导出工具输出字段：
| 字段 | 说明 |
|------|------|
| 兑换码 | 礼品卡码或兑换码 |
| 国家 | 国家名称 |
| 金额 | 兑换金额 |
| 账号余款 | 账号剩余余额 |
| 账号 | iTunes账号 |
| 错误信息 | 错误信息（如果有） |
| 执行状态 | 兑换状态（成功） |
| 时间 | 兑换时间 |
| 群聊 | 群聊名称 |
| 计划 | 计划名称 |
| 汇率 | 汇率值 |
| 数据来源 | 数据来源表（固定为iTunes交易日志） |

### 完整导出工具输出字段：
包含更多详细字段，如ID、批次ID、微信ID、消息ID等。

## 示例用法

### 导出今天的成功记录：
```bash
php export_successful_exchanges.php --start-date=2024-07-13 --end-date=2024-07-13
```

### 导出美国区的前1000条成功记录：
```bash
php export_successful_exchanges.php --country=US --limit=1000 --output=us_success.csv
```

### 导出所有表的记录到Excel：
```bash
php export_exchange_records.php --table=all --output=xlsx --file=all_exchange_records.xlsx
```

### 导出失败的记录：
```bash
php export_exchange_records.php --status=failed --output=csv --file=failed_records.csv
```

## 注意事项

1. **Excel兼容性**：CSV文件包含UTF-8 BOM，确保Excel正确显示中文字符。

2. **Excel格式**：如果需要导出Excel格式，需要安装PhpSpreadsheet包：
   ```bash
   composer require phpoffice/phpspreadsheet
   ```

3. **数据库连接**：确保Laravel应用能够正常连接到数据库。

4. **权限**：确保脚本有权限写入输出文件。

5. **内存限制**：对于大量数据，可能需要调整PHP内存限制。

## 故障排除

### 常见问题：

1. **找不到记录**：
   - 检查数据库中是否有符合条件的记录
   - 确认状态过滤条件是否正确

2. **无法写入文件**：
   - 检查文件路径和权限
   - 确保目录存在

3. **内存不足**：
   - 使用--limit参数限制导出数量
   - 分批导出数据

4. **编码问题**：
   - CSV文件包含UTF-8 BOM
   - 使用支持UTF-8的文本编辑器打开

## 扩展功能

如果需要添加其他功能，可以：
1. 修改格式化函数添加新字段
2. 添加新的过滤条件
3. 支持其他输出格式
4. 添加数据统计功能 