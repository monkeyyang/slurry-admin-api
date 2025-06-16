#!/bin/bash

# WebSocket服务管理脚本
# 用于管理交易监控WebSocket服务器

PORT=8848
DOMAIN="slurry-api.1105.me"
FRONTEND_DOMAIN="https://1105.me"
PID_FILE="storage/websocket.pid"
LOG_FILE="storage/logs/websocket-$(date +%Y%m%d).log"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 显示帮助信息
show_help() {
    echo -e "${GREEN}WebSocket服务管理工具${NC}"
    echo "========================"
    echo ""
    echo "用法: $0 {start|stop|restart|status|logs}"
    echo ""
    echo "命令:"
    echo "  start    启动WebSocket服务器"
    echo "  stop     停止WebSocket服务器"
    echo "  restart  重启WebSocket服务器"
    echo "  status   查看服务状态"
    echo "  logs     查看实时日志"
    echo ""
    echo "示例:"
    echo "  $0 start     # 启动服务"
    echo "  $0 status    # 查看状态"
    echo "  $0 logs      # 查看日志"
}

# 检查服务状态
check_status() {
    if [ -f "$PID_FILE" ]; then
        local pid=$(cat "$PID_FILE")
        if ps -p $pid > /dev/null 2>&1; then
            echo -e "${GREEN}✅ WebSocket服务正在运行${NC}"
            echo -e "   PID: $pid"
            echo -e "   端口: $PORT"
            echo -e "   后端域名: $DOMAIN"
            echo -e "   前端域名: $FRONTEND_DOMAIN"
            echo -e "   连接地址: wss://$DOMAIN:$PORT/ws/monitor"
            return 0
        else
            echo -e "${RED}❌ WebSocket服务未运行 (PID文件存在但进程不存在)${NC}"
            rm -f "$PID_FILE"
            return 1
        fi
    else
        echo -e "${RED}❌ WebSocket服务未运行${NC}"
        return 1
    fi
}

# 启动服务
start_service() {
    echo -e "${BLUE}🚀 启动WebSocket服务器...${NC}"
    
    # 检查是否已经运行
    if check_status > /dev/null 2>&1; then
        echo -e "${YELLOW}⚠️  WebSocket服务已经在运行${NC}"
        return 1
    fi
    
    # 检查端口是否被占用
    if lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null 2>&1; then
        echo -e "${RED}❌ 端口 $PORT 已被占用${NC}"
        echo "占用进程:"
        lsof -Pi :$PORT -sTCP:LISTEN
        return 1
    fi
    
    # 创建日志目录
    mkdir -p storage/logs
    
    # 启动服务
    echo -e "${GREEN}✅ 正在启动服务...${NC}"
    nohup php websocket-server.php $PORT > "$LOG_FILE" 2>&1 &
    local pid=$!
    
    # 保存PID
    echo $pid > "$PID_FILE"
    
    # 等待服务启动
    sleep 2
    
    # 验证启动状态
    if ps -p $pid > /dev/null 2>&1; then
        echo -e "${GREEN}🎉 WebSocket服务启动成功!${NC}"
        echo -e "   PID: $pid"
        echo -e "   端口: $PORT"
        echo -e "   日志: $LOG_FILE"
        echo -e "   后端域名: $DOMAIN"
        echo -e "   前端域名: $FRONTEND_DOMAIN"
        echo -e "   连接地址: wss://$DOMAIN:$PORT/ws/monitor"
        echo ""
        echo -e "${BLUE}🎯 前端配置示例:${NC}"
        echo -e "   const wsUrl = 'wss://$DOMAIN:$PORT/ws/monitor';"
    else
        echo -e "${RED}❌ WebSocket服务启动失败${NC}"
        rm -f "$PID_FILE"
        return 1
    fi
}

# 停止服务
stop_service() {
    echo -e "${BLUE}🛑 停止WebSocket服务器...${NC}"
    
    if [ -f "$PID_FILE" ]; then
        local pid=$(cat "$PID_FILE")
        if ps -p $pid > /dev/null 2>&1; then
            echo -e "${YELLOW}正在停止进程 $pid...${NC}"
            kill $pid
            
            # 等待进程结束
            local count=0
            while ps -p $pid > /dev/null 2>&1 && [ $count -lt 10 ]; do
                sleep 1
                count=$((count + 1))
            done
            
            # 如果进程仍在运行，强制杀死
            if ps -p $pid > /dev/null 2>&1; then
                echo -e "${RED}强制杀死进程...${NC}"
                kill -9 $pid
            fi
            
            echo -e "${GREEN}✅ WebSocket服务已停止${NC}"
        else
            echo -e "${YELLOW}⚠️  进程不存在${NC}"
        fi
        rm -f "$PID_FILE"
    else
        echo -e "${YELLOW}⚠️  WebSocket服务未运行${NC}"
    fi
}

# 重启服务
restart_service() {
    echo -e "${BLUE}🔄 重启WebSocket服务器...${NC}"
    stop_service
    sleep 2
    start_service
}

# 查看日志
show_logs() {
    if [ -f "$LOG_FILE" ]; then
        echo -e "${GREEN}📋 WebSocket服务日志 (按Ctrl+C退出):${NC}"
        echo -e "${BLUE}日志文件: $LOG_FILE${NC}"
        echo ""
        tail -f "$LOG_FILE"
    else
        echo -e "${RED}❌ 日志文件不存在: $LOG_FILE${NC}"
        return 1
    fi
}

# 主逻辑
case "${1:-}" in
    start)
        start_service
        ;;
    stop)
        stop_service
        ;;
    restart)
        restart_service
        ;;
    status)
        check_status
        ;;
    logs)
        show_logs
        ;;
    *)
        show_help
        exit 1
        ;;
esac 