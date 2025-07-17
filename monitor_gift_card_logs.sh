#!/bin/bash

# 礼品卡兑换日志监控脚本 - 升级版
# 用于实时监控礼品卡兑换系统的日志
# 支持多种监控模式和功能

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# 默认配置
LOG_DIR="storage/logs"
TODAY=$(date '+%Y-%m-%d')
GIFT_CARD_LOG="$LOG_DIR/gift_card_exchange-$TODAY.log"

# 显示帮助信息
show_help() {
    echo -e "${GREEN}礼品卡日志监控工具 - 升级版${NC}"
    echo "=================================="
    echo ""
    echo "用法: $0 [选项]"
    echo ""
    echo "选项:"
    echo "  -h, --help          显示帮助信息"
    echo "  -r, --realtime      实时监控模式 (默认)"
    echo "  -g, --grouped       分组显示并发任务"
    echo "  -s, --stats         显示统计信息"
    echo "  -l, --lines N       显示最近N行日志 (默认100)"
    echo "  --search KEYWORD    搜索包含关键词的日志"
    echo "  --level LEVEL       过滤日志级别 (ERROR|WARNING|INFO|DEBUG)"
    echo "  --batch ID          只显示指定批次ID的日志"
    echo "  --card CODE         只显示指定礼品卡码的日志"
    echo "  --api               使用API接口获取日志"
    echo "  --artisan           使用Laravel Artisan命令"
    echo "  --file PATH         指定日志文件路径"
    echo ""
    echo "示例:"
    echo "  $0                           # 默认实时监控"
    echo "  $0 -r                        # 实时监控"
    echo "  $0 -g                        # 分组显示并发任务"
    echo "  $0 -s                        # 显示统计信息"
    echo "  $0 -l 50                     # 显示最近50行日志"
    echo "  $0 --search '礼品卡'          # 搜索包含'礼品卡'的日志"
    echo "  $0 --level ERROR             # 只显示错误日志"
    echo "  $0 --batch 10e19bc4          # 只显示指定批次的日志"
    echo "  $0 --card XV9T2PXQ           # 只显示指定卡号的日志"
    echo "  $0 --api -s                  # 使用API获取统计信息"
    echo "  $0 --artisan --realtime      # 使用Artisan实时监控"
    echo ""
    echo "API配置:"
    echo "  export API_BASE_URL=https://your-domain.com/api  # 设置API地址"
    echo "  $0 --api -s                  # 使用自定义API地址"
}

# 检查环境
check_environment() {
    # 检查是否在Laravel项目根目录
    if [ ! -f "artisan" ]; then
        echo -e "${YELLOW}警告: 当前目录不是Laravel项目根目录${NC}"
        echo -e "${BLUE}尝试使用绝对路径...${NC}"
        LOG_DIR="/www/wwwroot/slurry-admin-api/storage/logs"
        GIFT_CARD_LOG="$LOG_DIR/gift_card_exchange-$TODAY.log"
    fi
}

# 查找日志文件
find_log_file() {
    local log_file="$1"

    # 检查指定的日志文件是否存在
    if [ ! -f "$log_file" ]; then
        echo -e "${YELLOW}指定日志文件不存在，查找最新的礼品卡兑换日志...${NC}"

        # 查找最新的礼品卡兑换日志文件
        local latest_log=$(find "$LOG_DIR" -name "gift_card_exchange-*.log" -type f -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -d' ' -f2-)

        if [ -z "$latest_log" ]; then
            echo -e "${RED}错误: 未找到任何礼品卡兑换日志文件${NC}"
            echo -e "${BLUE}提示: 日志文件应该位于 $LOG_DIR/gift_card_exchange-YYYY-MM-DD.log${NC}"
            echo -e "${BLUE}请先执行一些礼品卡兑换操作来生成日志文件${NC}"
            return 1
        fi

        GIFT_CARD_LOG="$latest_log"
        echo -e "${GREEN}找到最新日志文件: $(basename $GIFT_CARD_LOG)${NC}"
    fi

    return 0
}

# 使用Artisan命令
use_artisan() {
    local cmd="php artisan giftcard:monitor-logs"

    if [ "$REALTIME" = true ]; then
        cmd="$cmd --realtime"
    fi

    if [ "$STATS" = true ]; then
        cmd="$cmd --stats"
    fi

    if [ -n "$LINES" ]; then
        cmd="$cmd --lines=$LINES"
    fi

    if [ -n "$SEARCH" ]; then
        cmd="$cmd --search=\"$SEARCH\""
    fi

    if [ -n "$LEVEL" ]; then
        cmd="$cmd --level=$LEVEL"
    fi

    echo -e "${BLUE}使用Artisan命令: $cmd${NC}"
    echo ""

    eval $cmd
}

# 使用API接口
use_api() {
    # 智能检测API基础URL
    local base_url=""

    # 1. 优先使用环境变量
    if [ -n "$API_BASE_URL" ]; then
        base_url="$API_BASE_URL"
    # 2. 检查是否有.env文件并尝试读取APP_URL
    elif [ -f ".env" ]; then
        local app_url=$(grep "^APP_URL=" .env 2>/dev/null | cut -d'=' -f2 | tr -d '"' | tr -d "'")
        if [ -n "$app_url" ]; then
            base_url="$app_url/api"
        else
            base_url="https://slurry-api.1105.me/api"
        fi
    # 3. 默认值 - 使用实际的后端地址
    else
        base_url="https://slurry-api.1105.me/api"
    fi

    local endpoint=""
    local params=""

    if [ "$STATS" = true ]; then
        endpoint="/giftcard/logs/stats"
    elif [ -n "$SEARCH" ]; then
        endpoint="/giftcard/logs/search"
        params="?keyword=$SEARCH"
        if [ -n "$LEVEL" ]; then
            params="$params&level=$LEVEL"
        fi
    else
        endpoint="/giftcard/logs/latest"
        if [ -n "$LINES" ]; then
            params="?lines=$LINES"
        fi
    fi

    local url="$base_url$endpoint$params"
    echo -e "${BLUE}API请求: $url${NC}"
    echo ""

    if command -v curl &> /dev/null; then
        curl -s "$url" | python3 -m json.tool 2>/dev/null || curl -s "$url"
    else
        echo -e "${RED}错误: curl未安装${NC}"
        return 1
    fi
}

# 显示日志统计信息
show_stats() {
    if [ ! -f "$GIFT_CARD_LOG" ]; then
        echo -e "${RED}日志文件不存在${NC}"
        return 1
    fi

    echo -e "${GREEN}=== 日志统计信息 ===${NC}"
    echo -e "${BLUE}日志文件: $GIFT_CARD_LOG${NC}"
    echo ""

    local total_lines=$(wc -l < "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    local error_count=$(grep -c "ERROR" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    local warning_count=$(grep -c "WARNING" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    local info_count=$(grep -c "INFO" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    local debug_count=$(grep -c "DEBUG" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")

    echo -e "总日志行数: ${CYAN}$total_lines${NC}"
    echo -e "错误日志: ${RED}$error_count${NC}"
    echo -e "警告日志: ${YELLOW}$warning_count${NC}"
    echo -e "信息日志: ${GREEN}$info_count${NC}"
    echo -e "调试日志: ${BLUE}$debug_count${NC}"
    echo ""

    # 显示最近的错误
    echo -e "${RED}最近的错误 (最多5条):${NC}"
    grep "ERROR" "$GIFT_CARD_LOG" 2>/dev/null | tail -5 | while read line; do
        echo -e "${RED}  $line${NC}"
    done
}

# 显示最近的日志
show_recent_logs() {
    local lines=${LINES:-100}

    if [ ! -f "$GIFT_CARD_LOG" ]; then
        echo -e "${RED}日志文件不存在${NC}"
        return 1
    fi

    echo -e "${GREEN}=== 最近 $lines 条日志 ===${NC}"
    echo -e "${BLUE}日志文件: $GIFT_CARD_LOG${NC}"
    echo ""

    tail -n "$lines" "$GIFT_CARD_LOG" | while read line; do
        format_log_line "$line"
    done
}

# 搜索日志
search_logs() {
    local keyword="$1"
    local level="$2"

    if [ ! -f "$GIFT_CARD_LOG" ]; then
        echo -e "${RED}日志文件不存在${NC}"
        return 1
    fi

    echo -e "${GREEN}=== 搜索结果: $keyword ===${NC}"
    if [ -n "$level" ]; then
        echo -e "${BLUE}过滤级别: $level${NC}"
    fi
    echo ""

    local grep_cmd="grep -i \"$keyword\" \"$GIFT_CARD_LOG\""
    if [ -n "$level" ]; then
        grep_cmd="$grep_cmd | grep \"$level\""
    fi

    eval $grep_cmd | head -50 | while read line; do
        format_log_line "$line"
    done
}

# 格式化日志行
format_log_line() {
    local line="$1"
    local timestamp=$(date '+%H:%M:%S')

    # 根据日志级别着色
    if [[ $line == *"ERROR"* ]]; then
        echo -e "${RED}[$timestamp] $line${NC}"
    elif [[ $line == *"WARNING"* ]]; then
        echo -e "${YELLOW}[$timestamp] $line${NC}"
    elif [[ $line == *"INFO"* ]]; then
        if [[ $line == *"开始兑换礼品卡"* ]]; then
            echo -e "${CYAN}[$timestamp] $line${NC}"
        elif [[ $line == *"礼品卡兑换成功"* ]]; then
            echo -e "${GREEN}[$timestamp] $line${NC}"
        elif [[ $line == *"批量兑换任务已启动"* ]]; then
            echo -e "${PURPLE}[$timestamp] $line${NC}"
        elif [[ $line == *"批量兑换任务完成"* ]]; then
            echo -e "${GREEN}[$timestamp] $line${NC}"
        else
            echo -e "${BLUE}[$timestamp] $line${NC}"
        fi
    elif [[ $line == *"DEBUG"* ]]; then
        echo -e "${CYAN}[$timestamp] $line${NC}"
    else
        echo "[$timestamp] $line"
    fi
}

# 提取日志中的关键信息
extract_log_info() {
    local line="$1"
    local batch_id=""
    local job_id=""
    local card_code=""

    # 提取batch_id
    if [[ $line =~ \"batch_id\":\"([^\"]+)\" ]]; then
        batch_id="${BASH_REMATCH[1]}"
    fi

    # 提取job_id
    if [[ $line =~ \"job_id\":\"([^\",:]+)\" ]]; then
        job_id="${BASH_REMATCH[1]}"
    fi

    # 提取card_code
    if [[ $line =~ \"card_code\":\"([^\"]+)\" ]]; then
        card_code="${BASH_REMATCH[1]}"
    fi

    echo "$batch_id|$job_id|$card_code"
}

# 增强的格式化日志行
enhanced_format_log_line() {
    local line="$1"
    local info=$(extract_log_info "$line")
    IFS='|' read -r batch_id job_id card_code <<< "$info"

    local timestamp=$(date '+%H:%M:%S')
    local prefix=""

    # 构建前缀信息
    if [ -n "$batch_id" ] && [ -n "$job_id" ]; then
        prefix="[${batch_id:0:8}:${job_id:0:8}]"
    elif [ -n "$batch_id" ]; then
        prefix="[${batch_id:0:8}]"
    elif [ -n "$job_id" ]; then
        prefix="[${job_id:0:8}]"
    fi

    if [ -n "$card_code" ]; then
        prefix="$prefix[${card_code:0:8}]"
    fi

    # 根据日志级别和内容着色
    if [[ $line == *"ERROR"* ]]; then
        echo -e "${RED}[$timestamp]$prefix $line${NC}"
    elif [[ $line == *"WARNING"* ]]; then
        echo -e "${YELLOW}[$timestamp]$prefix $line${NC}"
    elif [[ $line == *"INFO"* ]]; then
        if [[ $line == *"开始处理礼品卡兑换任务"* ]]; then
            echo -e "${CYAN}[$timestamp]$prefix ▶️ 开始队列任务${NC}"
        elif [[ $line == *"礼品卡兑换任务完成"* ]]; then
            echo -e "${GREEN}[$timestamp]$prefix ✅ 队列任务完成${NC}"
        elif [[ $line == *"总额度达成，账号计划完成"* ]]; then
            echo -e "${GREEN}[$timestamp]$prefix ✅ 总额度达成，账号计划完成${NC}"
        elif [[ $line == *"批量兑换任务已启动"* ]]; then
            echo -e "${PURPLE}[$timestamp]$prefix 🚀 批量任务启动${NC}"
        elif [[ $line == *"批量兑换任务完成"* ]]; then
            echo -e "${GREEN}[$timestamp]$prefix 🎉 批量任务完成${NC}"
        elif [[ $line == *"检测到业务逻辑错误"* ]]; then
            echo -e "${YELLOW}[$timestamp]$prefix ⚠️ 业务错误（不重试）${NC}"
        elif [[ $line == *"系统错误"* ]]; then
            echo -e "${RED}[$timestamp]$prefix 🔄 系统错误（将重试）${NC}"
        elif [[ $line == *"开始兑换礼品卡"* ]]; then
            echo -e "${BLUE}[$timestamp]$prefix 🎯 开始兑换${NC}"
        elif [[ $line == *"礼品卡兑换成功"* ]]; then
            echo -e "${GREEN}[$timestamp]$prefix 💰 兑换成功${NC}"
        elif [[ $line == *"礼品卡兑换失败"* ]]; then
            echo -e "${RED}[$timestamp]$prefix ❌ 兑换失败${NC}"
        else
            echo -e "${BLUE}[$timestamp]$prefix $line${NC}"
        fi
    elif [[ $line == *"DEBUG"* ]]; then
        echo -e "${CYAN}[$timestamp]$prefix $line${NC}"
    else
        echo "[$timestamp]$prefix $line"
    fi
}

# 实时监控日志 (原有功能)
realtime_monitor() {
    echo -e "${GREEN}=== 礼品卡兑换日志实时监控 ===${NC}"
    echo -e "${BLUE}日志文件: $GIFT_CARD_LOG${NC}"
    echo -e "${YELLOW}按 Ctrl+C 退出监控${NC}"
    echo -e "${CYAN}提示: 使用增强格式显示，支持并发任务识别${NC}"
    echo ""

    # 实时监控日志，使用增强格式
    tail -f "$GIFT_CARD_LOG" | while read line; do
        enhanced_format_log_line "$line"
    done
}

# 分组监控模式 - 按批次分组显示
grouped_monitor() {
    echo -e "${GREEN}=== 分组监控模式 ===${NC}"
    echo -e "${BLUE}日志文件: $GIFT_CARD_LOG${NC}"
    echo -e "${YELLOW}按 Ctrl+C 退出监控${NC}"
    echo -e "${CYAN}提示: 按批次分组显示并发任务${NC}"
    echo ""

    # 使用关联数组来存储每个批次的日志
    declare -A batch_logs
    local line_count=0

    tail -f "$GIFT_CARD_LOG" | while read line; do
        local info=$(extract_log_info "$line")
        IFS='|' read -r batch_id job_id card_code <<< "$info"

        ((line_count++))

        if [ -n "$batch_id" ]; then
            # 如果是批次相关的日志，添加到对应批次
            batch_logs["$batch_id"]+="$line"$'\n'

            # 如果是批次完成，显示该批次的所有日志
            if [[ $line == *"批量兑换任务完成"* ]] || [[ $line == *"批量兑换任务失败"* ]]; then
                echo -e "${WHITE}=== 批次完成: ${batch_id:0:12} ===${NC}"
                while IFS= read -r batch_line; do
                    [ -n "$batch_line" ] && enhanced_format_log_line "$batch_line"
                done <<< "${batch_logs[$batch_id]}"
                echo ""
                unset batch_logs["$batch_id"]
            fi
        else
            # 非批次相关的日志直接显示
            enhanced_format_log_line "$line"
        fi

        # 每100行显示一次进度
        if [ $((line_count % 100)) -eq 0 ]; then
            echo -e "${CYAN}[已处理 $line_count 行日志]${NC}"
        fi
    done
}

# 过滤监控模式
filtered_monitor() {
    local filter_type="$1"
    local filter_value="$2"

    echo -e "${GREEN}=== 过滤监控模式: $filter_type = $filter_value ===${NC}"
    echo -e "${BLUE}日志文件: $GIFT_CARD_LOG${NC}"
    echo -e "${YELLOW}按 Ctrl+C 退出监控${NC}"
    echo ""

    tail -f "$GIFT_CARD_LOG" | while read line; do
        local info=$(extract_log_info "$line")
        IFS='|' read -r batch_id job_id card_code <<< "$info"

        local should_display=false

        case "$filter_type" in
            "batch")
                [[ "$batch_id" == *"$filter_value"* ]] && should_display=true
                ;;
            "card")
                [[ "$card_code" == *"$filter_value"* ]] && should_display=true
                ;;
        esac

        if [ "$should_display" = true ]; then
            enhanced_format_log_line "$line"
        fi
    done
}

# 解析命令行参数
REALTIME=false
GROUPED=false
STATS=false
LINES=""
SEARCH=""
LEVEL=""
BATCH=""
CARD=""
USE_API=false
USE_ARTISAN=false
CUSTOM_FILE=""

# 如果没有参数，默认为实时监控
if [ $# -eq 0 ]; then
    REALTIME=true
fi

while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_help
            exit 0
            ;;
        -r|--realtime)
            REALTIME=true
            shift
            ;;
        -g|--grouped)
            GROUPED=true
            shift
            ;;
        -s|--stats)
            STATS=true
            shift
            ;;
        -l|--lines)
            LINES="$2"
            shift 2
            ;;
        --search)
            SEARCH="$2"
            shift 2
            ;;
        --level)
            LEVEL="$2"
            shift 2
            ;;
        --batch)
            BATCH="$2"
            shift 2
            ;;
        --card)
            CARD="$2"
            shift 2
            ;;
        --api)
            USE_API=true
            shift
            ;;
        --artisan)
            USE_ARTISAN=true
            shift
            ;;
        --file)
            CUSTOM_FILE="$2"
            shift 2
            ;;
        *)
            echo -e "${RED}未知选项: $1${NC}"
            show_help
            exit 1
            ;;
    esac
done

# 主逻辑
echo -e "${GREEN}🎁 礼品卡日志监控工具${NC}"
echo "===================="
echo ""

# 检查环境
check_environment

# 如果指定了自定义文件路径
if [ -n "$CUSTOM_FILE" ]; then
    GIFT_CARD_LOG="$CUSTOM_FILE"
fi

# 使用API接口
if [ "$USE_API" = true ]; then
    use_api
    exit $?
fi

# 使用Artisan命令
if [ "$USE_ARTISAN" = true ]; then
    use_artisan
    exit $?
fi

# 查找日志文件
if ! find_log_file "$GIFT_CARD_LOG"; then
    exit 1
fi

# 根据选项执行相应操作
if [ "$STATS" = true ]; then
    show_stats
elif [ -n "$BATCH" ]; then
    filtered_monitor "batch" "$BATCH"
elif [ -n "$CARD" ]; then
    filtered_monitor "card" "$CARD"
elif [ "$GROUPED" = true ]; then
    grouped_monitor
elif [ -n "$SEARCH" ]; then
    search_logs "$SEARCH" "$LEVEL"
elif [ -n "$LINES" ] && [ "$REALTIME" = false ]; then
    show_recent_logs
else
    # 默认或明确指定的实时监控
    realtime_monitor
fi
