#!/bin/bash

# 交易监控WebSocket服务器启动脚本 - 8848端口
# 专门为前端监控页面提供WebSocket服务

PORT=8848

echo "🚀 正在启动交易监控WebSocket服务器..."
echo "📡 端口: $PORT"
echo "🔗 连接地址: ws://localhost:$PORT/ws/monitor"

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

# 启动WebSocket服务器
echo "✅ 启动WebSocket服务器..."
php websocket-server.php $PORT 2>&1 | tee storage/logs/websocket-$(date +%Y%m%d).log 