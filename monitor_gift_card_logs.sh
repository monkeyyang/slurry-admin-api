#!/bin/bash

# 礼品卡兑换日志监控脚本
# 用于实时监控礼品卡兑换系统的日志

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# 日志文件路径
LOG_DIR="/www/wwwroot/slurry-admin-api/storage/logs"
TODAY=$(date '+%Y-%m-%d')
GIFT_CARD_LOG="$LOG_DIR/gift_card_exchange-$TODAY.log"

# 检查日志文件是否存在，如果不存在则查找最新的日志文件
if [ ! -f "$GIFT_CARD_LOG" ]; then
    echo -e "${YELLOW}今日日志文件不存在，查找最新的礼品卡兑换日志...${NC}"
    
    # 查找最新的礼品卡兑换日志文件
    LATEST_LOG=$(find "$LOG_DIR" -name "gift_card_exchange-*.log" -type f -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -d' ' -f2-)
    
    if [ -z "$LATEST_LOG" ]; then
        echo -e "${RED}错误: 未找到任何礼品卡兑换日志文件${NC}"
        echo -e "${BLUE}提示: 日志文件应该位于 $LOG_DIR/gift_card_exchange-YYYY-MM-DD.log${NC}"
        echo -e "${BLUE}请先执行一些礼品卡兑换操作来生成日志文件${NC}"
        exit 1
    fi
    
    GIFT_CARD_LOG="$LATEST_LOG"
    echo -e "${GREEN}找到最新日志文件: $(basename $GIFT_CARD_LOG)${NC}"
fi

echo -e "${GREEN}=== 礼品卡兑换日志监控 ===${NC}"
echo -e "${BLUE}日志文件: $GIFT_CARD_LOG${NC}"
echo -e "${YELLOW}按 Ctrl+C 退出监控${NC}"
echo ""

# 实时监控日志
tail -f "$GIFT_CARD_LOG" | while read line; do
    # 获取当前时间戳
    timestamp=$(date '+%H:%M:%S')

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
    else
        echo "[$timestamp] $line"
    fi
done
