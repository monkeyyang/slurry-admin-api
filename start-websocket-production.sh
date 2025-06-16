#!/bin/bash

# 生产环境WebSocket服务器启动脚本
# 用于前后端分离架构

PORT=8848
DOMAIN="slurry-api.1105.me"
FRONTEND_DOMAIN="https://1105.me"

echo "🚀 启动生产环境WebSocket服务器..."
echo "📡 端口: $PORT"
echo "🌐 后端域名: $DOMAIN"
echo "🖥️  前端域名: $FRONTEND_DOMAIN"
echo "🔗 WebSocket连接地址: wss://$DOMAIN:$PORT/ws/monitor"

# 检查PHP是否安装
if ! command -v php &> /dev/null; then
    echo "❌ 错误: PHP未安装或不在PATH中"
    exit 1
fi

# 检查composer依赖
if [ ! -d "vendor" ]; then
    echo "❌ 错误: vendor目录不存在，请先运行 composer install"
    exit 1
fi

# 检查SSL证书（如果需要）
SSL_CERT_PATH="/etc/ssl/certs/$DOMAIN.crt"
SSL_KEY_PATH="/etc/ssl/private/$DOMAIN.key"

if [ -f "$SSL_CERT_PATH" ] && [ -f "$SSL_KEY_PATH" ]; then
    echo "✅ 找到SSL证书文件"
    echo "   证书: $SSL_CERT_PATH"
    echo "   私钥: $SSL_KEY_PATH"
    USE_SSL=true
else
    echo "⚠️  未找到SSL证书，使用非加密连接"
    echo "   前端需要连接: ws://$DOMAIN:$PORT/ws/monitor"
    USE_SSL=false
fi

# 检查端口是否被占用
if lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null ; then
    echo "⚠️  警告: 端口 $PORT 已被占用"
    echo "🔍 正在查找占用进程..."
    lsof -Pi :$PORT -sTCP:LISTEN
    echo ""
    read -p "是否要杀死占用进程并继续? (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        echo "🔪 正在杀死占用进程..."
        lsof -Pi :$PORT -sTCP:LISTEN -t | xargs kill -9
        sleep 2
    else
        echo "❌ 启动取消"
        exit 1
    fi
fi

# 创建日志目录
mkdir -p storage/logs

# 设置环境变量
export WEBSOCKET_PORT=$PORT
export WEBSOCKET_DOMAIN=$DOMAIN
export FRONTEND_DOMAIN=$FRONTEND_DOMAIN

# 启动WebSocket服务器
echo "✅ 启动WebSocket服务器..."
echo "📋 日志文件: storage/logs/websocket-production-$(date +%Y%m%d).log"
echo ""
echo "🔧 配置信息:"
echo "   - 端口: $PORT"
echo "   - 域名: $DOMAIN"
echo "   - 前端: $FRONTEND_DOMAIN"
echo "   - SSL: $USE_SSL"
echo ""

if [ "$USE_SSL" = true ]; then
    echo "🔒 使用SSL加密连接"
    echo "   前端连接地址: wss://$DOMAIN:$PORT/ws/monitor"
else
    echo "🔓 使用非加密连接"
    echo "   前端连接地址: ws://$DOMAIN:$PORT/ws/monitor"
fi

echo ""
echo "🎯 前端WebSocket配置示例:"
echo "   const wsUrl = 'wss://$DOMAIN:$PORT/ws/monitor';"
echo "   // 或者如果没有SSL:"
echo "   // const wsUrl = 'ws://$DOMAIN:$PORT/ws/monitor';"
echo ""

# 启动服务
php websocket-server.php $PORT 2>&1 | tee storage/logs/websocket-production-$(date +%Y%m%d).log 