#!/bin/bash

# 增强版礼品卡兑换日志监控脚本
# 支持并发任务分组显示和更好的实时监控

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color

# 默认配置
LOG_DIR="storage/logs"
TODAY=$(date '+%Y-%m-%d')
GIFT_CARD_LOG="$LOG_DIR/gift_card_exchange-$TODAY.log"

# 全局变量
declare -A BATCH_TASKS  # 存储批次任务信息
declare -A JOB_TASKS    # 存储单个任务信息
BUFFER_SIZE=50          # 缓冲区大小
SHOW_ALL_LOGS=false     # 是否显示所有日志

# 显示帮助信息
show_help() {
    echo -e "${GREEN}增强版礼品卡日志监控工具${NC}"
    echo "=================================="
    echo ""
    echo "用法: $0 [选项]"
    echo ""
    echo "选项:"
    echo "  -h, --help          显示帮助信息"
    echo "  -r, --realtime      实时监控模式 (默认)"
    echo "  -g, --grouped       分组显示并发任务"
    echo "  -a, --all           显示所有日志（包括非关键日志）"
    echo "  -b, --batch ID      只监控指定批次ID的任务"
    echo "  -c, --card CODE     只监控指定礼品卡码的任务"
    echo "  -s, --stats         显示统计信息"
    echo "  -l, --lines N       显示最近N行日志 (默认100)"
    echo "  --buffer N          设置缓冲区大小 (默认50)"
    echo "  --file PATH         指定日志文件路径"
    echo ""
    echo "示例:"
    echo "  $0                           # 默认实时监控"
    echo "  $0 -g                        # 分组显示并发任务"
    echo "  $0 -b batch_123              # 只监控批次123的任务"
    echo "  $0 -c CARD123456             # 只监控指定卡号的任务"
    echo "  $0 -a                        # 显示所有日志"
    echo "  $0 --buffer 100              # 设置更大的缓冲区"
}

# 检查环境
check_environment() {
    if [ ! -f "artisan" ]; then
        echo -e "${YELLOW}警告: 当前目录不是Laravel项目根目录${NC}"
        LOG_DIR="/www/wwwroot/slurry-admin-api/storage/logs"
        GIFT_CARD_LOG="$LOG_DIR/gift_card_exchange-$TODAY.log"
    fi
}

# 查找日志文件
find_log_file() {
    local log_file="$1"
    
    if [ ! -f "$log_file" ]; then
        echo -e "${YELLOW}指定日志文件不存在，查找最新的礼品卡兑换日志...${NC}"
        
        local latest_log=$(find "$LOG_DIR" -name "gift_card_exchange-*.log" -type f -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -d' ' -f2-)
        
        if [ -z "$latest_log" ]; then
            echo -e "${RED}错误: 未找到任何礼品卡兑换日志文件${NC}"
            return 1
        fi
        
        GIFT_CARD_LOG="$latest_log"
        echo -e "${GREEN}找到最新日志文件: $(basename $GIFT_CARD_LOG)${NC}"
    fi
    
    return 0
}

# 提取日志中的关键信息
extract_log_info() {
    local line="$1"
    local timestamp=""
    local level=""
    local message=""
    local batch_id=""
    local job_id=""
    local card_code=""
    
    # 提取时间戳
    if [[ $line =~ \[([0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}:[0-9]{2})\] ]]; then
        timestamp="${BASH_REMATCH[1]}"
    fi
    
    # 提取日志级别
    if [[ $line =~ local\.(ERROR|WARNING|INFO|DEBUG): ]]; then
        level="${BASH_REMATCH[1]}"
    fi
    
    # 提取消息内容
    message=$(echo "$line" | sed 's/.*local\.[A-Z]*: //')
    
    # 提取batch_id
    if [[ $message =~ \"batch_id\":\"([^\"]+)\" ]]; then
        batch_id="${BASH_REMATCH[1]}"
    fi
    
    # 提取job_id
    if [[ $message =~ \"job_id\":\"?([^\",:]+)\"? ]]; then
        job_id="${BASH_REMATCH[1]}"
    fi
    
    # 提取card_code
    if [[ $message =~ \"card_code\":\"([^\"]+)\" ]]; then
        card_code="${BASH_REMATCH[1]}"
    fi
    
    echo "$timestamp|$level|$batch_id|$job_id|$card_code|$message"
}

# 格式化并显示日志行
format_and_display_log() {
    local line="$1"
    local info=$(extract_log_info "$line")
    IFS='|' read -r timestamp level batch_id job_id card_code message <<< "$info"
    
    local display_time=$(date '+%H:%M:%S')
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
    case "$level" in
        "ERROR")
            echo -e "${RED}[$display_time]$prefix $message${NC}"
            ;;
        "WARNING")
            echo -e "${YELLOW}[$display_time]$prefix $message${NC}"
            ;;
        "INFO")
            if [[ $message == *"开始处理礼品卡兑换任务"* ]]; then
                echo -e "${CYAN}[$display_time]$prefix ▶️ 开始任务${NC}"
            elif [[ $message == *"礼品卡兑换任务完成"* ]]; then
                echo -e "${GREEN}[$display_time]$prefix ✅ 任务完成${NC}"
            elif [[ $message == *"批量兑换任务已启动"* ]]; then
                echo -e "${PURPLE}[$display_time]$prefix 🚀 批量任务启动${NC}"
            elif [[ $message == *"检测到业务逻辑错误"* ]]; then
                echo -e "${YELLOW}[$display_time]$prefix ⚠️ 业务错误（不重试）${NC}"
            elif [[ $message == *"系统错误"* ]]; then
                echo -e "${RED}[$display_time]$prefix 🔄 系统错误（将重试）${NC}"
            else
                if [ "$SHOW_ALL_LOGS" = true ]; then
                    echo -e "${BLUE}[$display_time]$prefix $message${NC}"
                fi
            fi
            ;;
        "DEBUG")
            if [ "$SHOW_ALL_LOGS" = true ]; then
                echo -e "${CYAN}[$display_time]$prefix $message${NC}"
            fi
            ;;
        *)
            if [ "$SHOW_ALL_LOGS" = true ]; then
                echo "[$display_time]$prefix $line"
            fi
            ;;
    esac
}

# 分组监控模式
grouped_monitor() {
    echo -e "${GREEN}=== 分组监控模式 ===${NC}"
    echo -e "${BLUE}日志文件: $GIFT_CARD_LOG${NC}"
    echo -e "${YELLOW}按 Ctrl+C 退出监控${NC}"
    echo ""
    
    local line_buffer=()
    local buffer_count=0
    
    tail -f "$GIFT_CARD_LOG" | while read line; do
        # 添加到缓冲区
        line_buffer+=("$line")
        ((buffer_count++))
        
        # 当缓冲区满时，批量处理
        if [ $buffer_count -ge $BUFFER_SIZE ]; then
            # 按批次ID分组处理
            declare -A batch_groups
            
            for buffered_line in "${line_buffer[@]}"; do
                local info=$(extract_log_info "$buffered_line")
                IFS='|' read -r timestamp level batch_id job_id card_code message <<< "$info"
                
                if [ -n "$batch_id" ]; then
                    batch_groups["$batch_id"]+="$buffered_line"$'\n'
                else
                    # 非批次相关的日志直接显示
                    format_and_display_log "$buffered_line"
                fi
            done
            
            # 按批次显示
            for batch_id in "${!batch_groups[@]}"; do
                echo -e "${WHITE}=== 批次: $batch_id ===${NC}"
                while IFS= read -r batch_line; do
                    [ -n "$batch_line" ] && format_and_display_log "$batch_line"
                done <<< "${batch_groups[$batch_id]}"
                echo ""
            done
            
            # 清空缓冲区
            line_buffer=()
            buffer_count=0
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
        IFS='|' read -r timestamp level batch_id job_id card_code message <<< "$info"
        
        local should_display=false
        
        case "$filter_type" in
            "batch")
                [ "$batch_id" = "$filter_value" ] && should_display=true
                ;;
            "card")
                [ "$card_code" = "$filter_value" ] && should_display=true
                ;;
        esac
        
        if [ "$should_display" = true ]; then
            format_and_display_log "$line"
        fi
    done
}

# 标准实时监控
standard_monitor() {
    echo -e "${GREEN}=== 标准实时监控 ===${NC}"
    echo -e "${BLUE}日志文件: $GIFT_CARD_LOG${NC}"
    echo -e "${YELLOW}按 Ctrl+C 退出监控${NC}"
    echo ""
    
    tail -f "$GIFT_CARD_LOG" | while read line; do
        format_and_display_log "$line"
    done
}

# 显示统计信息
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
    
    # 统计任务相关信息
    local batch_start_count=$(grep -c "批量兑换任务已启动" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    local task_start_count=$(grep -c "开始处理礼品卡兑换任务" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    local task_complete_count=$(grep -c "礼品卡兑换任务完成" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    local business_error_count=$(grep -c "检测到业务逻辑错误" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    local system_error_count=$(grep -c "系统错误" "$GIFT_CARD_LOG" 2>/dev/null || echo "0")
    
    echo -e "📊 基础统计:"
    echo -e "  总日志行数: ${CYAN}$total_lines${NC}"
    echo -e "  错误日志: ${RED}$error_count${NC}"
    echo -e "  警告日志: ${YELLOW}$warning_count${NC}"
    echo -e "  信息日志: ${GREEN}$info_count${NC}"
    echo ""
    
    echo -e "🎯 任务统计:"
    echo -e "  批量任务启动: ${PURPLE}$batch_start_count${NC}"
    echo -e "  单个任务开始: ${CYAN}$task_start_count${NC}"
    echo -e "  任务完成: ${GREEN}$task_complete_count${NC}"
    echo -e "  业务错误: ${YELLOW}$business_error_count${NC}"
    echo -e "  系统错误: ${RED}$system_error_count${NC}"
    echo ""
    
    # 计算成功率
    if [ $task_start_count -gt 0 ]; then
        local success_rate=$(( (task_complete_count * 100) / task_start_count ))
        echo -e "📈 成功率: ${GREEN}$success_rate%${NC} ($task_complete_count/$task_start_count)"
    fi
    
    # 显示最近的错误
    echo ""
    echo -e "${RED}🚨 最近的错误 (最多5条):${NC}"
    grep "ERROR" "$GIFT_CARD_LOG" 2>/dev/null | tail -5 | while read line; do
        local info=$(extract_log_info "$line")
        IFS='|' read -r timestamp level batch_id job_id card_code message <<< "$info"
        echo -e "${RED}  [$timestamp] $message${NC}"
    done
}

# 解析命令行参数
REALTIME=false
GROUPED=false
STATS=false
LINES=""
FILTER_BATCH=""
FILTER_CARD=""
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
        -a|--all)
            SHOW_ALL_LOGS=true
            shift
            ;;
        -b|--batch)
            FILTER_BATCH="$2"
            shift 2
            ;;
        -c|--card)
            FILTER_CARD="$2"
            shift 2
            ;;
        -s|--stats)
            STATS=true
            shift
            ;;
        -l|--lines)
            LINES="$2"
            shift 2
            ;;
        --buffer)
            BUFFER_SIZE="$2"
            shift 2
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
echo -e "${GREEN}🎁 增强版礼品卡日志监控工具${NC}"
echo "============================="
echo ""

# 检查环境
check_environment

# 如果指定了自定义文件路径
if [ -n "$CUSTOM_FILE" ]; then
    GIFT_CARD_LOG="$CUSTOM_FILE"
fi

# 查找日志文件
if ! find_log_file "$GIFT_CARD_LOG"; then
    exit 1
fi

# 根据选项执行相应操作
if [ "$STATS" = true ]; then
    show_stats
elif [ -n "$FILTER_BATCH" ]; then
    filtered_monitor "batch" "$FILTER_BATCH"
elif [ -n "$FILTER_CARD" ]; then
    filtered_monitor "card" "$FILTER_CARD"
elif [ "$GROUPED" = true ]; then
    grouped_monitor
else
    # 标准实时监控
    standard_monitor
fi 