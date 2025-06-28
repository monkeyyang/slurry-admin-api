# Slurry Admin API 项目文档

## 项目概述

**Slurry Admin API** 是一个基于 Laravel 10 框架开发的综合性管理系统，主要用于礼品卡交易、iTunes账号管理、仓库库存管理以及微信机器人集成的后端API服务。

### 基本信息
- **项目名称**: dotreen/slurry
- **版本**: 1.0.0
- **框架**: Laravel 10.10
- **PHP版本**: >= 8.0
- **许可证**: MIT

## 技术架构

### 核心技术栈
- **后端框架**: Laravel 10
- **数据库**: MySQL (支持多数据库连接)
- **队列系统**: Redis Queue
- **WebSocket**: React/Socket + Ratchet/Pawl
- **认证系统**: Laravel Sanctum
- **验证码**: Gregwar/Captcha
- **HTTP客户端**: Guzzle HTTP

### 主要依赖包
```json
{
  "laravel/framework": "^10.10",
  "laravel/sanctum": "^3.3",
  "guzzlehttp/guzzle": "^7.2",
  "react/socket": "^1.0",
  "ratchet/pawl": "^0.4",
  "gregwar/captcha": "^1.2",
  "ramsey/uuid": "^4.0"
}
```

## 核心功能模块

### 1. 用户认证与权限管理
- **管理员用户管理**: 用户CRUD、状态管理、密码重置
- **角色权限系统**: 角色分配、权限控制
- **菜单管理**: 动态菜单树、权限绑定
- **邀请码系统**: 注册邀请码生成与管理

### 2. 礼品卡交易系统
#### 核心功能
- **批量礼品卡兑换**: 支持大批量礼品卡处理
- **交易监控**: 实时交易状态监控、日志记录
- **账号管理**: Apple账号批量查询、状态管理
- **汇率管理**: 多国家汇率配置、实时更新

#### 子模块
- **GiftCardExchangeService**: 礼品卡兑换核心服务
- **GiftCardApiClient**: 第三方API客户端
- **TradeMonitorService**: 交易监控服务
- **CardQueryService**: 卡密查询服务

### 3. iTunes交易管理
#### 账号管理
- **账号导入**: 批量导入iTunes账号
- **状态监控**: 登录状态、处理状态实时监控
- **计划绑定**: 账号与交易计划关联管理
- **登录刷新**: 自动刷新失效登录状态

#### 交易计划
- **计划管理**: 交易计划CRUD、状态控制
- **汇率配置**: 多国家、多类型汇率设置
- **执行日志**: 详细的交易执行记录
- **模板系统**: 计划模板创建与应用

#### 关键服务
- **ItunesTradeAccountService**: 账号管理核心服务
- **ItunesTradePlanService**: 交易计划服务
- **ItunesTradeRateService**: 汇率管理服务
- **ItunesTradeExecutionLogService**: 执行日志服务

### 4. 仓库管理系统
#### 库存管理
- **货品管理**: 商品信息、别名管理
- **入库管理**: 批量入库、状态跟踪
- **库存查询**: 实时库存状态、批量操作
- **预报管理**: 货物预报、匹配确认

#### 仓库配置
- **仓库设置**: 多仓库配置、状态管理
- **预报队列**: 自动化预报处理队列
- **结算系统**: 入库记录结算、状态重置

### 5. 微信机器人集成
#### 消息处理
- **Webhook接口**: 微信消息接收处理
- **命令系统**: 丰富的文本命令支持
- **群聊管理**: 微信群聊绑定、账单记录
- **实时通信**: WebSocket实时消息推送

#### 命令支持
```
- 账单查询: bill, 账单
- 卡密操作: 进卡, 出卡
- 状态查询: query, 查询
- 管理功能: 开启, 关闭, 清空
- 高级功能: 整合, 召回, 代码查询
```

### 6. 监控与日志系统
#### 实时监控
- **交易监控**: 实时交易状态、统计数据
- **日志监控**: 礼品卡操作日志、错误追踪
- **WebSocket监控**: 实时连接状态、消息流
- **队列监控**: 任务队列状态、性能指标

#### 日志管理
- **分类日志**: 按模块分类的详细日志
- **实时流**: Server-Sent Events日志流
- **日志导出**: 支持日志数据导出
- **统计分析**: 日志统计与分析

## API接口文档

### 认证相关
```
POST /api/login              # 用户登录
POST /api/register           # 用户注册
GET  /api/captchaImage       # 获取验证码
GET  /api/getConfig          # 获取系统配置
```

### 管理员管理
```
GET  /api/admin/user/list           # 获取用户列表
POST /api/admin/user/create         # 创建用户
POST /api/admin/user/update         # 更新用户
POST /api/admin/user/updateStatus   # 更新用户状态
POST /api/admin/user/resetPassword  # 重置密码
POST /api/admin/user/delete         # 删除用户
```

### 礼品卡管理
```
POST /api/gift-card/set-query-rule    # 设置查询规则
POST /api/gift-card/batch-query       # 批量查询卡密
POST /api/gift-card/exchange          # 处理兑换
GET  /api/gift-card/exchange/status   # 查询兑换状态
POST /api/gift-card/validate          # 验证礼品卡
GET  /api/gift-card/records           # 获取兑换记录
```

### iTunes交易管理
```
# 汇率管理
GET    /api/trade/itunes/rates              # 获取汇率列表
POST   /api/trade/itunes/rates              # 创建汇率
PUT    /api/trade/itunes/rates/{id}         # 更新汇率
DELETE /api/trade/itunes/rates/{id}         # 删除汇率

# 计划管理  
GET    /api/trade/itunes/plans              # 获取计划列表
POST   /api/trade/itunes/plans              # 创建计划
PUT    /api/trade/itunes/plans/{id}         # 更新计划
DELETE /api/trade/itunes/plans/{id}         # 删除计划

# 账号管理
GET    /api/trade/itunes/accounts           # 获取账号列表
POST   /api/trade/itunes/accounts/batch-import  # 批量导入账号
PUT    /api/trade/itunes/accounts/{id}/status   # 更新账号状态
POST   /api/trade/itunes/accounts/{id}/bind-plan    # 绑定计划
PUT    /api/trade/itunes/accounts/{id}/login-status # 更新登录状态

# 执行日志
GET    /api/trade/itunes/execution-logs     # 获取执行日志
GET    /api/trade/itunes/execution-logs-statistics  # 获取统计信息
```

### 仓库管理
```
# 货品管理
GET    /api/warehouse/goods/list            # 获取货品列表
POST   /api/warehouse/goods/create          # 创建货品
PUT    /api/warehouse/goods/update/{id}     # 更新货品
DELETE /api/warehouse/goods/delete/{id}     # 删除货品

# 库存管理
GET    /api/warehouse/stock/list            # 获取库存列表
POST   /api/warehouse/stock/import          # 批量导入库存
POST   /api/warehouse/stock/match           # 匹配预报
POST   /api/warehouse/stock/confirm/{id}    # 确认入库

# 预报管理
GET    /api/forecast/list                   # 获取预报列表
POST   /api/forecast/add                    # 添加预报
POST   /api/forecast/cancel/{id}            # 取消预报
```

### 监控接口
```
# 交易监控
GET /api/trade/monitor/logs                 # 获取监控日志
GET /api/trade/monitor/stats                # 获取统计数据
GET /api/trade/monitor/status               # 获取实时状态

# 礼品卡日志监控
GET /api/giftcard/logs/latest               # 获取最新日志
GET /api/giftcard/logs/stats                # 获取日志统计
GET /api/giftcard/logs/search               # 搜索日志
GET /api/giftcard/logs/stream               # 实时日志流
```

### 微信机器人
```
POST /api/api/wechat/webhook                # 微信消息webhook
GET  /api/api/wechat/test                   # 测试接口
```

## 控制台命令

### 核心命令
```bash
# iTunes账号管理
php artisan itunes:refresh-invalid-login   # 刷新失效登录账号
php artisan itunes:process-accounts        # 处理iTunes账号
php artisan itunes:execute-plans           # 执行交易计划
php artisan itunes:check-plan-progress     # 检查计划进度

# 礼品卡处理
php artisan gift-card:process-exchange     # 处理礼品卡兑换
php artisan gift-card:query-cards          # 查询礼品卡
php artisan gift-card:monitor-logs         # 监控日志

# 队列管理
php artisan queue:work                     # 启动队列工作进程
php artisan forecast:process-crawler       # 处理预报爬虫队列
php artisan card:run-query-job             # 运行卡密查询任务

# 监控命令
php artisan monitor:gift-card-logs         # 监控礼品卡日志
php artisan monitor:start-account          # 启动账号监控
```

### 命令参数示例
```bash
# 刷新指定账号登录状态
php artisan itunes:refresh-invalid-login --account=user@example.com

# 导出账号信息
php artisan itunes:refresh-invalid-login --export=accounts.csv --export-only
php artisan itunes:refresh-invalid-login --export-html=accounts.html --export-only

# 限制处理数量
php artisan itunes:refresh-invalid-login --limit=100
```

## 队列系统

### 队列配置
- **默认队列**: Redis
- **队列驱动**: `redis`
- **连接池**: 支持多连接配置
- **失败重试**: 自动重试机制

### 主要队列任务
```php
# 礼品卡相关
ProcessGiftCardExchangeJob::class     # 礼品卡兑换任务
RedeemGiftCardJob::class              # 礼品卡兑换任务
ProcessCardQueryJob::class            # 卡密查询任务

# 账号相关  
ProcessAppleIdLoginJob::class         # Apple ID登录任务
ProcessBillJob::class                 # 账单处理任务

# 预报相关
ProcessForecastCrawlerJob::class      # 预报爬虫任务
```

### Supervisor配置
```ini
# 多核心配置 (supervisor-8core-16gb.conf)
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php artisan queue:work redis --sleep=3 --tries=3
directory=/path/to/project
autostart=true
autorestart=true
numprocs=8
```

## WebSocket服务

### 服务配置
- **端口**: 8848 (生产环境)
- **协议**: WebSocket
- **库**: React/Socket + Ratchet/Pawl

### 启动脚本
```bash
# 开发环境
./start-websocket.sh

# 生产环境  
./start-websocket-production.sh

# 指定端口
./start-websocket-8848.sh
```

### 功能支持
- **实时消息推送**: 交易状态实时更新
- **日志流**: 实时日志推送
- **监控数据**: 实时监控数据推送
- **连接管理**: 客户端连接状态管理

## 部署指南

### 环境要求
- **PHP**: >= 8.0
- **MySQL**: >= 5.7
- **Redis**: >= 6.0
- **Nginx**: >= 1.18
- **Supervisor**: 进程管理

### 部署步骤

#### 1. 克隆项目
```bash
git clone <repository-url>
cd slurry-admin-api
```

#### 2. 安装依赖
```bash
composer install --no-dev --optimize-autoloader
npm install && npm run build
```

#### 3. 环境配置
```bash
cp .env.example .env
php artisan key:generate
```

#### 4. 数据库配置
```bash
# 配置数据库连接
php artisan migrate
php artisan db:seed
```

#### 5. 队列配置
```bash
# 配置Supervisor
sudo cp supervisor-complete.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

#### 6. WebSocket配置
```bash
# 配置Nginx代理
sudo cp nginx-websocket.conf /etc/nginx/sites-available/
sudo ln -s /etc/nginx/sites-available/nginx-websocket.conf /etc/nginx/sites-enabled/

# 启动WebSocket服务
./start-websocket-production.sh
```

#### 7. 权限设置
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 生产环境优化
```bash
# 缓存配置
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 优化自动加载
composer dump-autoload --optimize

# 队列监控
php artisan queue:restart
```

## 监控与维护

### 日志文件位置
```
storage/logs/laravel.log          # 应用主日志
storage/logs/gift-card.log        # 礼品卡操作日志  
storage/logs/trade-monitor.log    # 交易监控日志
storage/logs/websocket.log        # WebSocket日志
```

### 监控脚本
```bash
# 礼品卡日志监控
./monitor_gift_card_logs.sh

# WebSocket日志监控  
./monitor_websocket_logs.sh

# 队列监控
./scripts/queue-monitor.sh
```

### 故障排除
1. **队列停止**: 检查Redis连接，重启Supervisor
2. **WebSocket断开**: 检查端口占用，重启WebSocket服务
3. **数据库连接**: 检查数据库配置和连接池
4. **内存不足**: 调整PHP内存限制，优化查询

## 安全配置

### API安全
- **认证**: Laravel Sanctum Token认证
- **限流**: 接口访问频率限制
- **CORS**: 跨域请求配置
- **加密**: 敏感数据加密存储

### 数据安全
- **密码加密**: 账号密码自动加密
- **数据脱敏**: 敏感信息脱敏处理
- **访问控制**: 基于角色的权限控制
- **审计日志**: 详细的操作审计日志

## 开发指南

### 代码结构
```
app/
├── Console/Commands/        # 控制台命令
├── Http/Controllers/        # HTTP控制器
│   ├── Api/                # API控制器
│   └── Test/               # 测试控制器
├── Services/               # 业务服务层
│   ├── Gift/               # 礼品卡服务
│   └── Wechat/             # 微信服务
├── Models/                 # 数据模型
├── Jobs/                   # 队列任务
├── Events/                 # 事件
├── Listeners/              # 事件监听器
└── Utils/                  # 工具类
```

### 开发规范
- **PSR-4**: 自动加载规范
- **RESTful**: API设计规范
- **单一职责**: 服务类职责分离
- **依赖注入**: Laravel服务容器
- **异常处理**: 统一异常处理机制

### 测试
```bash
# 运行测试
php artisan test

# 礼品卡兑换测试
curl -X POST /api/test/gift-card/exchange

# Apple账户测试
curl -X GET /api/test/apple-account/batch-query
```

## 版本历史

### v1.0.0 (当前版本)
- ✅ 基础用户认证与权限管理
- ✅ 礼品卡交易系统
- ✅ iTunes账号管理
- ✅ 仓库库存管理
- ✅ 微信机器人集成
- ✅ WebSocket实时通信
- ✅ 监控与日志系统

## 技术支持

### 文档资源
- `REFRESH_LOGIN_ACCOUNTS_README.md` - 账号登录刷新说明
- `GIFT_CARD_SERVICE_REFACTOR_GUIDE.md` - 礼品卡服务重构指南
- `WEBSOCKET_TROUBLESHOOTING.md` - WebSocket故障排除
- `UBUNTU_DEPLOYMENT.md` - Ubuntu部署指南



---

*最后更新时间: 2024年12月* 
