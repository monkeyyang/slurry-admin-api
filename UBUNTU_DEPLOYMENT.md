# Ubuntu系统WebSocket监控服务部署指南

## 系统要求
- Ubuntu 18.04+ 或相关Linux发行版
- PHP 8.1+
- Composer
- Supervisor

## 1. 安装依赖

### 更新系统包
```bash
sudo apt update
sudo apt upgrade -y
```

### 安装PHP 8.1+
```bash
sudo apt install software-properties-common
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.1 php8.1-cli php8.1-fpm php8.1-mysql php8.1-xml php8.1-curl php8.1-mbstring php8.1-zip php8.1-gd php8.1-bcmath
```

### 安装Composer
```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 安装Supervisor
```bash
sudo apt install supervisor
```

## 2. 项目部署

### 克隆项目并安装依赖
```bash
cd /var/www
sudo git clone your-project-repo slurry-admin-api
sudo chown -R www-data:www-data /var/www/slurry-admin-api
cd /var/www/slurry-admin-api
sudo -u www-data composer install --no-dev --optimize-autoloader
```

### 配置环境变量
```bash
sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
```

编辑`.env`文件，配置数据库和WebSocket设置：
```env
# WebSocket监控配置
WEBSOCKET_LOG_LEVEL=info
ACCOUNT_MONITOR_WEBSOCKET_URL=ws://your-websocket-server/
ACCOUNT_MONITOR_CLIENT_ID=your-client-id
ACCOUNT_MONITOR_PING_INTERVAL=30
ACCOUNT_MONITOR_RECONNECT_DELAY=5
```

### 运行数据库迁移
```bash
sudo -u www-data php artisan migrate --force
```

## 3. 配置Supervisor

### 复制配置文件
```bash
sudo cp websocket-monitor.conf /etc/supervisor/conf.d/
```

### 编辑配置文件
```bash
sudo nano /etc/supervisor/conf.d/websocket-monitor.conf
```

修改路径为实际项目路径：
```ini
[program:websocket-monitor]
command=php /var/www/slurry-admin-api/artisan account:monitor
directory=/var/www/slurry-admin-api
user=www-data
autostart=true
autorestart=true
redirect_stderr=true
stdout_logfile=/var/log/websocket-monitor.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
environment=LARAVEL_ENV="production"
```

### 启动Supervisor服务
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start websocket-monitor
```

## 4. 日志监控

### 使用内置脚本监控日志
```bash
# 赋予执行权限
chmod +x monitor_websocket_logs.sh

# 实时监控日志
./monitor_websocket_logs.sh -f

# 查看最后100行日志
./monitor_websocket_logs.sh -n 100

# 查看帮助
./monitor_websocket_logs.sh -h
```

### 手动查看日志
```bash
# 查看WebSocket监控日志
tail -f storage/logs/websocket_monitor.log

# 查看Supervisor日志
tail -f /var/log/websocket-monitor.log
```

## 5. 服务管理

### 查看服务状态
```bash
sudo supervisorctl status websocket-monitor
```

### 重启服务
```bash
sudo supervisorctl restart websocket-monitor
```

### 停止服务
```bash
sudo supervisorctl stop websocket-monitor
```

### 查看服务日志
```bash
sudo supervisorctl tail websocket-monitor
```

## 6. 故障排除

### 检查PHP扩展
```bash
php -m | grep -E "(sockets|curl|json|mbstring)"
```

### 检查网络连接
```bash
# 测试WebSocket连接
telnet your-websocket-server 80
```

### 检查权限
```bash
# 确保日志目录可写
sudo chown -R www-data:www-data storage/logs
sudo chmod -R 755 storage/logs
```

### 常见问题

1. **连接超时**：检查防火墙和网络配置
2. **权限错误**：确保www-data用户有正确的文件权限
3. **内存不足**：增加PHP内存限制或服务器内存
4. **日志文件过大**：配置日志轮转或清理旧日志

## 7. 性能优化

### 配置opcache
在`/etc/php/8.1/cli/php.ini`中添加：
```ini
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=4000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

### 系统监控
```bash
# 监控进程
ps aux | grep "account:monitor"

# 监控内存使用
free -h

# 监控连接数
netstat -an | grep :80 | wc -l
``` 