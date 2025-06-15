#!/bin/bash

# WebSocket监控服务测试脚本
# 适用于Ubuntu系统

# 设置颜色
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目路径
PROJECT_PATH="/www/wwwroot/slurry-admin-api"
LOG_FILE="$PROJECT_PATH/storage/logs/account-monitor.log"
SUPERVISOR_LOG="/www/wwwroot/slurry-admin-api/storage/logs/account-monitor.log"

echo -e "${GREEN}=== WebSocket监控服务测试工具 ===${NC}"
echo ""

# 1. 检查项目环境
echo -e "${BLUE}1. 检查项目环境${NC}"
echo "-------------------"

# 检查项目目录
if [ -d "$PROJECT_PATH" ]; then
    echo -e "${GREEN}✓ 项目目录存在: $PROJECT_PATH${NC}"
else
    echo -e "${RED}✗ 项目目录不存在: $PROJECT_PATH${NC}"
    exit 1
fi

# 检查artisan文件
if [ -f "$PROJECT_PATH/artisan" ]; then
    echo -e "${GREEN}✓ artisan文件存在${NC}"
else
    echo -e "${RED}✗ artisan文件不存在${NC}"
    exit 1
fi

# 检查PHP版本
PHP_VERSION=$(php -v | head -n1 | cut -d' ' -f2 | cut -d'.' -f1,2)
echo -e "${GREEN}✓ PHP版本: $PHP_VERSION${NC}"

# 检查Composer依赖
if [ -d "$PROJECT_PATH/vendor" ]; then
    echo -e "${GREEN}✓ Composer依赖已安装${NC}"
else
    echo -e "${YELLOW}⚠ Composer依赖未安装，正在安装...${NC}"
    cd "$PROJECT_PATH"
    composer install --no-dev --optimize-autoloader
fi

echo ""

# 2. 检查配置文件
echo -e "${BLUE}2. 检查配置文件${NC}"
echo "-------------------"

# 检查.env文件
if [ -f "$PROJECT_PATH/.env" ]; then
    echo -e "${GREEN}✓ .env文件存在${NC}"

    # 检查关键配置
    if grep -q "ACCOUNT_MONITOR_WEBSOCKET_URL" "$PROJECT_PATH/.env"; then
        WEBSOCKET_URL=$(grep "ACCOUNT_MONITOR_WEBSOCKET_URL" "$PROJECT_PATH/.env" | cut -d'=' -f2)
        echo -e "${GREEN}✓ WebSocket URL配置: $WEBSOCKET_URL${NC}"
    else
        echo -e "${YELLOW}⚠ 未找到ACCOUNT_MONITOR_WEBSOCKET_URL配置${NC}"
    fi
else
    echo -e "${RED}✗ .env文件不存在${NC}"
    exit 1
fi

# 检查日志配置
if grep -q "websocket_monitor" "$PROJECT_PATH/config/logging.php"; then
    echo -e "${GREEN}✓ WebSocket日志通道已配置${NC}"
else
    echo -e "${RED}✗ WebSocket日志通道未配置${NC}"
fi

echo ""

# 3. 测试命令行执行
echo -e "${BLUE}3. 测试命令行执行${NC}"
echo "-------------------"

cd "$PROJECT_PATH"

# 检查命令是否存在
if php artisan list | grep -q "account:monitor"; then
    echo -e "${GREEN}✓ account:monitor命令已注册${NC}"
else
    echo -e "${RED}✗ account:monitor命令未注册${NC}"
    exit 1
fi

# 语法检查
echo -e "${YELLOW}正在进行语法检查...${NC}"
php -l app/Services/Gift/AccountMonitorService.php
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ AccountMonitorService语法正确${NC}"
else
    echo -e "${RED}✗ AccountMonitorService语法错误${NC}"
    exit 1
fi

php -l app/Console/Commands/StartAccountMonitor.php
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ StartAccountMonitor语法正确${NC}"
else
    echo -e "${RED}✗ StartAccountMonitor语法错误${NC}"
    exit 1
fi

echo ""

# 4. 检查Supervisor配置
echo -e "${BLUE}4. 检查Supervisor配置${NC}"
echo "-------------------"

if [ -f "/etc/supervisor/conf.d/account-monitor.conf" ]; then
    echo -e "${GREEN}✓ Supervisor配置文件存在${NC}"

    # 检查配置语法
    sudo supervisorctl reread 2>/dev/null
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Supervisor配置语法正确${NC}"
    else
        echo -e "${RED}✗ Supervisor配置语法错误${NC}"
    fi
else
    echo -e "${YELLOW}⚠ Supervisor配置文件不存在${NC}"
    echo "请将account-monitor.conf复制到/etc/supervisor/conf.d/目录"
fi

# 检查Supervisor服务状态
SUPERVISOR_STATUS=$(sudo supervisorctl status account-monitor 2>/dev/null | awk '{print $2}')
if [ "$SUPERVISOR_STATUS" = "RUNNING" ]; then
    echo -e "${GREEN}✓ Supervisor服务正在运行${NC}"
elif [ "$SUPERVISOR_STATUS" = "STOPPED" ]; then
    echo -e "${YELLOW}⚠ Supervisor服务已停止${NC}"
else
    echo -e "${YELLOW}⚠ Supervisor服务状态未知${NC}"
fi

echo ""

# 5. 检查日志文件
echo -e "${BLUE}5. 检查日志文件${NC}"
echo "-------------------"

# 创建日志目录
sudo mkdir -p "$(dirname "$LOG_FILE")"
sudo chown -R www:www "$(dirname "$LOG_FILE")"

if [ -f "$LOG_FILE" ]; then
    LOG_SIZE=$(du -h "$LOG_FILE" | cut -f1)
    LOG_LINES=$(wc -l < "$LOG_FILE")
    echo -e "${GREEN}✓ WebSocket日志文件存在${NC}"
    echo -e "  文件大小: $LOG_SIZE"
    echo -e "  行数: $LOG_LINES"

    # 显示最后几行日志
    echo -e "${YELLOW}最近的日志内容:${NC}"
    tail -n 5 "$LOG_FILE" 2>/dev/null || echo "日志文件为空或无法读取"
else
    echo -e "${YELLOW}⚠ WebSocket日志文件不存在（服务启动后会自动创建）${NC}"
fi

if [ -f "$SUPERVISOR_LOG" ]; then
    echo -e "${GREEN}✓ Supervisor日志文件存在${NC}"
    echo -e "${YELLOW}Supervisor最近日志:${NC}"
    tail -n 3 "$SUPERVISOR_LOG" 2>/dev/null || echo "日志文件为空或无法读取"
else
    echo -e "${YELLOW}⚠ Supervisor日志文件不存在${NC}"
fi

echo ""

# 6. 网络连接测试
echo -e "${BLUE}6. 网络连接测试${NC}"
echo "-------------------"

if [ -n "$WEBSOCKET_URL" ]; then
    # 提取主机和端口
    HOST=$(echo "$WEBSOCKET_URL" | sed 's|ws://||' | sed 's|wss://||' | cut -d'/' -f1 | cut -d':' -f1)
    PORT=$(echo "$WEBSOCKET_URL" | sed 's|ws://||' | sed 's|wss://||' | cut -d'/' -f1 | cut -d':' -f2)

    if [ "$PORT" = "$HOST" ]; then
        PORT=80  # 默认端口
    fi

    echo "测试连接到: $HOST:$PORT"

    # 使用nc测试连接
    if command -v nc >/dev/null 2>&1; then
        if timeout 5 nc -z "$HOST" "$PORT" 2>/dev/null; then
            echo -e "${GREEN}✓ 网络连接正常${NC}"
        else
            echo -e "${RED}✗ 无法连接到WebSocket服务器${NC}"
        fi
    else
        echo -e "${YELLOW}⚠ nc命令不可用，跳过网络测试${NC}"
    fi
else
    echo -e "${YELLOW}⚠ 未配置WebSocket URL，跳过网络测试${NC}"
fi

echo ""

# 7. 提供操作建议
echo -e "${BLUE}7. 操作建议${NC}"
echo "-------------------"

echo -e "${YELLOW}启动服务:${NC}"
echo "sudo supervisorctl start account-monitor"
echo ""

echo -e "${YELLOW}查看服务状态:${NC}"
echo "sudo supervisorctl status account-monitor"
echo ""

echo -e "${YELLOW}实时查看日志:${NC}"
echo "./monitor_websocket_logs.sh -f"
echo ""

echo -e "${YELLOW}手动测试命令:${NC}"
echo "cd $PROJECT_PATH && php artisan account:monitor"
echo ""

echo -e "${YELLOW}重启服务:${NC}"
echo "sudo supervisorctl restart account-monitor"
echo ""

echo -e "${GREEN}=== 测试完成 ===${NC}"
