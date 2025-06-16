#!/bin/bash

# 交易监控WebSocket服务器启动脚本

# 设置端口（默认8080）
PORT=${1:-8080}

echo "正在启动交易监控WebSocket服务器..."
echo "端口: $PORT"

# 检查PHP是否安装
if ! command -v php &> /dev/null; then
    echo "错误: PHP未安装或不在PATH中"
    exit 1
fi

# 检查composer依赖
if [ ! -d "vendor" ]; then
    echo "错误: vendor目录不存在，请先运行 composer install"
    exit 1
fi

# 启动WebSocket服务器
php websocket-server.php $PORT 