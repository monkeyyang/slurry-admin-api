#!/bin/bash

# Laravel 队列管理脚本
# 用法: ./queue-manager.sh [start|stop|restart|status|monitor]

LARAVEL_PATH="/www/wwwroot/slurry-admin-api"
SUPERVISOR_CONFIG="/etc/supervisor/conf.d/laravel-workers.conf"

case "$1" in
    start)
        echo "启动 Laravel 统一队列工作进程..."
        supervisorctl reread
        supervisorctl update
        supervisorctl start laravel-worker:*
        ;;
    stop)
        echo "停止 Laravel 统一队列工作进程..."
        supervisorctl stop laravel-worker:*
        ;;
    restart)
        echo "重启 Laravel 统一队列工作进程..."
        supervisorctl restart laravel-worker:*
        ;;
    status)
        echo "队列工作进程状态:"
        supervisorctl status | grep laravel
        echo ""
        echo "Redis 队列状态:"
        cd $LARAVEL_PATH
        php artisan tinker --execute="
            use Illuminate\Support\Facades\Redis;
            echo '=== 队列任务统计 ===' . PHP_EOL;
            echo 'gift_card_exchange (礼品卡兑换): ' . Redis::llen('queues:gift_card_exchange') . ' jobs' . PHP_EOL;
            echo 'forecast_crawler (预报爬虫): ' . Redis::llen('queues:forecast_crawler') . ' jobs' . PHP_EOL;
            echo 'bill_processing (账单处理): ' . Redis::llen('queues:bill_processing') . ' jobs' . PHP_EOL;
            echo 'high (高优先级): ' . Redis::llen('queues:high') . ' jobs' . PHP_EOL;
            echo 'default (默认): ' . Redis::llen('queues:default') . ' jobs' . PHP_EOL;
            echo '=== 总计 ===' . PHP_EOL;
            \$total = Redis::llen('queues:gift_card_exchange') + Redis::llen('queues:forecast_crawler') + Redis::llen('queues:bill_processing') + Redis::llen('queues:high') + Redis::llen('queues:default');
            echo '待处理任务总数: ' . \$total . PHP_EOL;
        "
        ;;
    monitor)
        echo "实时监控队列状态 (按 Ctrl+C 退出)..."
        while true; do
            clear
            echo "=== Laravel 队列监控 - $(date) ==="
            supervisorctl status | grep laravel
            echo ""
            cd $LARAVEL_PATH
            php artisan tinker --execute="
                use Illuminate\Support\Facades\Redis;
                echo 'gift_card_exchange: ' . Redis::llen('queues:gift_card_exchange') . ' jobs' . PHP_EOL;
                echo 'forecast_crawler: ' . Redis::llen('queues:forecast_crawler') . ' jobs' . PHP_EOL;
                echo 'bill_processing: ' . Redis::llen('queues:bill_processing') . ' jobs' . PHP_EOL;
                echo 'high: ' . Redis::llen('queues:high') . ' jobs' . PHP_EOL;
                echo 'default: ' . Redis::llen('queues:default') . ' jobs' . PHP_EOL;
            "
            sleep 5
        done
        ;;
    *)
        echo "用法: $0 {start|stop|restart|status|monitor}"
        echo ""
        echo "命令说明:"
        echo "  start   - 启动队列工作进程"
        echo "  stop    - 停止队列工作进程"
        echo "  restart - 重启队列工作进程"
        echo "  status  - 查看队列状态"
        echo "  monitor - 实时监控队列状态"
        exit 1
        ;;
esac
