#!/bin/bash

# ============================================================================
# Laravel Queue Monitor Script
# 功能: 监控队列状态和积压情况
# 作者: AI Assistant
# 日期: 2024-12-16
# ============================================================================

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Redis配置
REDIS_HOST="localhost"
REDIS_PORT="6379"
REDIS_DB="0"

# 队列列表
QUEUES=(
    "gift_card_exchange:礼品卡兑换队列"
    "forecast_crawler:预报爬虫队列"
    "bill_processing:账单处理队列"
    "card_query:卡密查询队列"
    "wechat-message:微信消息队列"
    "mail:邮件队列"
    "high:高优先级队列"
    "default:默认队列"
)

# 告警阈值
ALERT_THRESHOLDS=(
    "gift_card_exchange:100"
    "forecast_crawler:50"
    "bill_processing:80"
    "card_query:60"
    "wechat-message:50"
    "mail:20"
    "high:30"
    "default:100"
)

# 日志函数
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# 检查Redis连接
check_redis_connection() {
    if ! redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB ping > /dev/null 2>&1; then
        log_error "无法连接到Redis服务器: $REDIS_HOST:$REDIS_PORT"
        exit 1
    fi
}

# 获取队列长度
get_queue_length() {
    local queue_name=$1
    redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB llen "queues:$queue_name" 2>/dev/null || echo "0"
}

# 获取告警阈值
get_alert_threshold() {
    local queue_name=$1
    for threshold in "${ALERT_THRESHOLDS[@]}"; do
        local queue=$(echo $threshold | cut -d':' -f1)
        local value=$(echo $threshold | cut -d':' -f2)
        if [ "$queue" = "$queue_name" ]; then
            echo $value
            return
        fi
    done
    echo "50" # 默认阈值
}

# 显示队列状态
show_queue_status() {
    echo
    echo "=========================================="
    echo "           队列状态监控"
    echo "=========================================="
    echo "时间: $(date '+%Y-%m-%d %H:%M:%S')"
    echo
    
    local total_jobs=0
    local alert_count=0
    
    printf "%-20s %-15s %-8s %-8s %s\n" "队列名称" "中文名称" "任务数" "阈值" "状态"
    echo "------------------------------------------"
    
    for queue_info in "${QUEUES[@]}"; do
        local queue_name=$(echo $queue_info | cut -d':' -f1)
        local queue_desc=$(echo $queue_info | cut -d':' -f2)
        local job_count=$(get_queue_length $queue_name)
        local threshold=$(get_alert_threshold $queue_name)
        
        total_jobs=$((total_jobs + job_count))
        
        if [ $job_count -gt $threshold ]; then
            printf "%-20s %-15s %-8s %-8s %s\n" "$queue_name" "$queue_desc" "$job_count" "$threshold" "$(echo -e "${RED}告警${NC}")"
            alert_count=$((alert_count + 1))
        elif [ $job_count -gt 0 ]; then
            printf "%-20s %-15s %-8s %-8s %s\n" "$queue_name" "$queue_desc" "$job_count" "$threshold" "$(echo -e "${YELLOW}有任务${NC}")"
        else
            printf "%-20s %-15s %-8s %-8s %s\n" "$queue_name" "$queue_desc" "$job_count" "$threshold" "$(echo -e "${GREEN}正常${NC}")"
        fi
    done
    
    echo "------------------------------------------"
    echo "总任务数: $total_jobs"
    echo "告警队列: $alert_count"
    echo
}

# 显示详细信息
show_detailed_info() {
    echo "=========================================="
    echo "           详细队列信息"
    echo "=========================================="
    
    for queue_info in "${QUEUES[@]}"; do
        local queue_name=$(echo $queue_info | cut -d':' -f1)
        local queue_desc=$(echo $queue_info | cut -d':' -f2)
        local job_count=$(get_queue_length $queue_name)
        
        if [ $job_count -gt 0 ]; then
            echo
            echo "队列: $queue_name ($queue_desc)"
            echo "任务数: $job_count"
            
            # 获取最近的任务
            local recent_jobs=$(redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB lrange "queues:$queue_name" 0 2 2>/dev/null)
            if [ -n "$recent_jobs" ]; then
                echo "最近任务预览:"
                echo "$recent_jobs" | head -3 | while read -r job; do
                    if [ -n "$job" ]; then
                        echo "  - $(echo $job | cut -c1-80)..."
                    fi
                done
            fi
            echo "----------------------------------------"
        fi
    done
}

# 监控模式
monitor_mode() {
    local interval=${1:-5}
    log_info "开始监控模式，刷新间隔: ${interval}秒 (按 Ctrl+C 退出)"
    
    while true; do
        clear
        show_queue_status
        sleep $interval
    done
}

# 生成报告
generate_report() {
    local report_file="queue_report_$(date +%Y%m%d_%H%M%S).txt"
    
    {
        echo "Laravel Queue Status Report"
        echo "Generated at: $(date)"
        echo
        show_queue_status
        show_detailed_info
    } > $report_file
    
    log_success "报告已生成: $report_file"
}

# 清理空队列
cleanup_empty_queues() {
    log_info "检查空队列..."
    
    for queue_info in "${QUEUES[@]}"; do
        local queue_name=$(echo $queue_info | cut -d':' -f1)
        local job_count=$(get_queue_length $queue_name)
        
        if [ $job_count -eq 0 ]; then
            redis-cli -h $REDIS_HOST -p $REDIS_PORT -n $REDIS_DB del "queues:$queue_name" > /dev/null 2>&1
            log_info "清理空队列: $queue_name"
        fi
    done
    
    log_success "空队列清理完成"
}

# 显示帮助
show_help() {
    echo "Laravel Queue Monitor - 队列监控工具"
    echo
    echo "用法: $0 [command] [options]"
    echo
    echo "命令:"
    echo "  status      - 显示队列状态 (默认)"
    echo "  detailed    - 显示详细队列信息"
    echo "  monitor     - 实时监控模式"
    echo "  report      - 生成状态报告"
    echo "  cleanup     - 清理空队列"
    echo "  help        - 显示帮助信息"
    echo
    echo "选项:"
    echo "  --interval, -i [seconds]  - 监控模式刷新间隔 (默认: 5秒)"
    echo
    echo "示例:"
    echo "  $0                    # 显示队列状态"
    echo "  $0 status             # 显示队列状态"
    echo "  $0 monitor            # 实时监控"
    echo "  $0 monitor -i 10      # 10秒间隔监控"
    echo "  $0 detailed           # 详细信息"
    echo "  $0 report             # 生成报告"
    echo "  $0 cleanup            # 清理空队列"
    echo
}

# 主函数
main() {
    # 检查Redis连接
    check_redis_connection
    
    case "${1:-status}" in
        "status")
            show_queue_status
            ;;
        "detailed")
            show_detailed_info
            ;;
        "monitor")
            local interval=5
            if [ "$2" = "-i" ] || [ "$2" = "--interval" ]; then
                interval=${3:-5}
            fi
            monitor_mode $interval
            ;;
        "report")
            generate_report
            ;;
        "cleanup")
            cleanup_empty_queues
            ;;
        "help"|"--help"|"-h")
            show_help
            ;;
        *)
            log_error "未知命令: $1"
            show_help
            exit 1
            ;;
    esac
}

# 捕获Ctrl+C信号
trap 'echo -e "\n正在退出..."; exit 0' INT

# 执行主函数
main "$@" 