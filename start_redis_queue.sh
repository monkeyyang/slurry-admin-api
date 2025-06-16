#!/bin/bash

# Redis队列工作进程启动脚本
# 用于启动礼品卡兑换队列处理

echo "=== 启动Redis队列工作进程 ==="

# 检查Redis连接
echo "检查Redis连接..."
php artisan tinker --execute="Redis::ping();"

if [ $? -ne 0 ]; then
    echo "❌ Redis连接失败，请检查Redis服务是否启动"
    exit 1
fi

echo "✅ Redis连接正常"

# 启动队列工作进程
echo "启动队列工作进程..."
echo "队列名称: gift-card"
echo "连接: redis"
echo "超时: 120秒"
echo "内存限制: 512MB"
echo ""

# 启动队列工作进程
php artisan queue:work redis \
    --queue=gift-card,default \
    --timeout=120 \
    --memory=512 \
    --tries=3 \
    --delay=30 \
    --verbose

echo "队列工作进程已停止" 