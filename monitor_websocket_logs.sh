#!/bin/bash

# WebSocket日志监控脚本
# 适用于Ubuntu/Linux系统

# 设置颜色
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 默认配置
LOG_FILE="storage/logs/account-monitor.log"
LINES=50
FOLLOW=false

# 帮助信息
show_help() {
    echo "WebSocket日志监控工具"
    echo ""
    echo "用法: $0 [选项]"
    echo ""
    echo "选项:"
    echo "  -f, --follow         实时跟踪日志"
    echo "  -n, --lines N        显示最后N行（默认50）"
    echo "  -l, --log-file PATH  指定日志文件路径"
    echo "  -h, --help           显示帮助信息"
    echo ""
    echo "示例:"
    echo "  $0 -f                # 实时监控日志"
    echo "  $0 -n 100           # 显示最后100行"
    echo "  $0 -f -n 20         # 实时监控并显示最后20行"
}

# 解析命令行参数
while [[ $# -gt 0 ]]; do
    case $1 in
        -f|--follow)
            FOLLOW=true
            shift
            ;;
        -n|--lines)
            LINES="$2"
            shift 2
            ;;
        -l|--log-file)
            LOG_FILE="$2"
            shift 2
            ;;
        -h|--help)
            show_help
            exit 0
            ;;
        *)
            echo "未知选项: $1"
            show_help
            exit 1
            ;;
    esac
done

# 检查日志文件是否存在
if [ ! -f "$LOG_FILE" ]; then
    echo -e "${RED}错误: 日志文件不存在: $LOG_FILE${NC}"
    echo "请检查文件路径或确保WebSocket监控服务已启动"
    exit 1
fi

# 获取项目根目录
PROJECT_ROOT=$(dirname "$(realpath "$0")")
if [ ! -f "$PROJECT_ROOT/artisan" ]; then
    echo -e "${YELLOW}警告: 当前目录可能不是Laravel项目根目录${NC}"
fi

# 构建完整日志文件路径
if [[ "$LOG_FILE" != /* ]]; then
    LOG_FILE="$PROJECT_ROOT/$LOG_FILE"
fi

echo -e "${GREEN}WebSocket监控日志查看器${NC}"
echo -e "${BLUE}日志文件: $LOG_FILE${NC}"
echo -e "${BLUE}显示行数: $LINES${NC}"
echo -e "${BLUE}实时跟踪: $([ "$FOLLOW" = true ] && echo "是" || echo "否")${NC}"
echo "=================================="

# 定义日志级别颜色
colorize_log() {
    sed -e "s/\[.*ERROR.*\]/$(printf "${RED}")\0$(printf "${NC}")/g" \
        -e "s/\[.*WARNING.*\]/$(printf "${YELLOW}")\0$(printf "${NC}")/g" \
        -e "s/\[.*INFO.*\]/$(printf "${GREEN}")\0$(printf "${NC}")/g" \
        -e "s/\[.*DEBUG.*\]/$(printf "${BLUE}")\0$(printf "${NC}")/g" \
        -e "s/client_id/$(printf "${YELLOW}")client_id$(printf "${NC}")/g" \
        -e "s/WebSocket连接成功/$(printf "${GREEN}")WebSocket连接成功$(printf "${NC}")/g" \
        -e "s/WebSocket连接失败/$(printf "${RED}")WebSocket连接失败$(printf "${NC}")/g" \
        -e "s/WebSocket连接关闭/$(printf "${RED}")WebSocket连接关闭$(printf "${NC}")/g"
}

# 如果是实时跟踪模式
if [ "$FOLLOW" = true ]; then
    echo -e "${GREEN}开始实时监控... (按 Ctrl+C 退出)${NC}"
    echo ""

    # 显示最后几行并实时跟踪
    tail -n "$LINES" -f "$LOG_FILE" | colorize_log
else
    # 只显示最后几行
    tail -n "$LINES" "$LOG_FILE" | colorize_log
fi
