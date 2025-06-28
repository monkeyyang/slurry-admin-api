## Slurry Admin API

基于Laravel的综合性管理系统后端API，主要用于礼品卡交易、iTunes账号管理、仓库库存管理以及微信机器人集成。

## 技术栈
- **PHP版本**: 8.3
- **Laravel版本**: v10.10
- **数据库**: MySQL
- **队列**: Redis Queue
- **WebSocket**: React/Socket + Ratchet/Pawl

## 项目地址
- **后端API**: https://github.com/dotreen/slurry-admin-api
- **前端界面**: https://github.com/dotreen/slurry-admin-web (基于 vue-pure-admin 5.8.0)
- **数据库文档**: https://github.com/dotreen/slurry-admin-doc

## 核心功能

### iTunes账号管理
- 自动维护50个零余额登录账号
- 智能账号状态转换和生命周期管理
- 自动登录/登出管理
- 计划执行和进度跟踪

### 礼品卡交易系统
- 批量礼品卡兑换处理
- 实时交易状态监控
- 队列任务管理和错误恢复
- 详细的交易日志记录

### 仓库管理系统
- 货品库存管理
- 批量入库处理
- 预报匹配确认
- 实时库存查询

### 微信机器人集成
- 消息处理和命令系统
- 群聊管理和账单记录
- WebSocket实时通信

## 快速开始

### 核心命令
```bash
# iTunes账号处理（每分钟自动运行）
php artisan itunes:process-accounts

# 仅执行登出操作
php artisan itunes:process-accounts --logout-only

# 仅执行登录操作
php artisan itunes:process-accounts --login-only

# 修复任务数据
php artisan itunes:process-accounts --fix-task=TASK_ID

# 刷新失效登录账号
php artisan itunes:refresh-invalid-login

# 清理pending记录
php artisan cleanup:pending-records
```

### 启动服务
```bash
# 启动队列工作进程
php artisan queue:work

# 启动WebSocket服务
./start-websocket.sh
```

## 预览

[查看预览](http://slurry-admin.dotreen.com)
>  账号密码：admin/admin123

## 致谢
[xiaoxian521](https://github.com/xiaoxian521)
[valarchie](https://github.com/valarchie/AgileBoot-Front-End)

## 许可证

[MIT]
