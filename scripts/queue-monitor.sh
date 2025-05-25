#!/bin/bash

# 队列监控脚本
# 用于监控队列性能并提供调优建议

echo "=== Laravel 队列监控报告 ==="
echo "时间: $(date)"
echo

# 1. 检查队列积压情况
echo "1. 队列积压情况:"
redis-cli -h 127.0.0.1 -p 6379 <<EOF
LLEN queues:gift_card_exchange
LLEN queues:forecast_crawler  
LLEN queues:bill_processing
LLEN queues:card_query
LLEN queues:high
LLEN queues:default
EOF

echo

# 2. 检查进程状态
echo "2. 队列进程状态:"
ps aux | grep "queue:work" | grep -v grep | wc -l
echo "当前运行的队列进程数量: $(ps aux | grep "queue:work" | grep -v grep | wc -l)"

echo

# 3. 内存使用情况
echo "3. 队列进程内存使用:"
ps aux | grep "queue:work" | grep -v grep | awk '{sum+=$6} END {print "总内存使用: " sum/1024 " MB"}'

echo

# 4. CPU使用情况
echo "4. 系统负载:"
uptime

echo

# 5. 失败任务统计
echo "5. 失败任务数量:"
redis-cli -h 127.0.0.1 -p 6379 LLEN queues:failed

echo

# 6. 性能建议
echo "6. 性能建议:"

# 检查队列积压
GIFT_CARD_QUEUE=$(redis-cli -h 127.0.0.1 -p 6379 LLEN queues:gift_card_exchange)
FORECAST_QUEUE=$(redis-cli -h 127.0.0.1 -p 6379 LLEN queues:forecast_crawler)
BILL_QUEUE=$(redis-cli -h 127.0.0.1 -p 6379 LLEN queues:bill_processing)
CARD_QUERY_QUEUE=$(redis-cli -h 127.0.0.1 -p 6379 LLEN queues:card_query)

if [ "$GIFT_CARD_QUEUE" -gt 100 ]; then
    echo "⚠️  礼品卡兑换队列积压严重($GIFT_CARD_QUEUE)，建议增加进程数"
fi

if [ "$FORECAST_QUEUE" -gt 50 ]; then
    echo "⚠️  预报爬虫队列积压($FORECAST_QUEUE)，建议增加进程数"
fi

if [ "$BILL_QUEUE" -gt 50 ]; then
    echo "⚠️  账单处理队列积压($BILL_QUEUE)，建议增加进程数"
fi

if [ "$CARD_QUERY_QUEUE" -gt 30 ]; then
    echo "⚠️  卡密查询队列积压($CARD_QUERY_QUEUE)，建议增加进程数"
fi

# 检查系统负载
LOAD=$(uptime | awk -F'load average:' '{print $2}' | awk '{print $1}' | sed 's/,//')
LOAD_INT=$(echo "$LOAD" | cut -d'.' -f1)

if [ "$LOAD_INT" -gt 6 ]; then
    echo "⚠️  系统负载较高($LOAD)，建议减少队列进程数"
elif [ "$LOAD_INT" -lt 2 ]; then
    echo "✅ 系统负载正常($LOAD)，可以考虑增加队列进程数"
fi

echo
echo "=== 监控完成 ===" 