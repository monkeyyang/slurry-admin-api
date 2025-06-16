#!/bin/bash

# WebSocket连接诊断脚本
# 用于排查WebSocket连接问题

PORT=8848
HOST="localhost"

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

echo -e "${CYAN}🔍 WebSocket连接诊断工具${NC}"
echo "================================"
echo ""

# 1. 检查端口是否开放
echo -e "${BLUE}1. 检查端口 $PORT 是否开放...${NC}"
if lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo -e "${GREEN}✅ 端口 $PORT 正在监听${NC}"
    echo "监听进程:"
    lsof -Pi :$PORT -sTCP:LISTEN
else
    echo -e "${RED}❌ 端口 $PORT 未开放${NC}"
    echo -e "${YELLOW}💡 请先启动WebSocket服务器:${NC}"
    echo "   ./websocket-manager.sh start"
fi
echo ""

# 2. 检查WebSocket服务进程
echo -e "${BLUE}2. 检查WebSocket服务进程...${NC}"
if [ -f "storage/websocket.pid" ]; then
    local pid=$(cat "storage/websocket.pid")
    if ps -p $pid > /dev/null 2>&1; then
        echo -e "${GREEN}✅ WebSocket服务进程正在运行 (PID: $pid)${NC}"
        echo "进程信息:"
        ps -p $pid -o pid,ppid,cmd
    else
        echo -e "${RED}❌ WebSocket服务进程不存在${NC}"
        rm -f "storage/websocket.pid"
    fi
else
    echo -e "${YELLOW}⚠️  WebSocket PID文件不存在${NC}"
fi
echo ""

# 3. 检查PHP进程
echo -e "${BLUE}3. 检查PHP WebSocket进程...${NC}"
php_processes=$(ps aux | grep "websocket-server.php" | grep -v grep)
if [ -n "$php_processes" ]; then
    echo -e "${GREEN}✅ 找到PHP WebSocket进程:${NC}"
    echo "$php_processes"
else
    echo -e "${RED}❌ 未找到PHP WebSocket进程${NC}"
fi
echo ""

# 4. 测试TCP连接
echo -e "${BLUE}4. 测试TCP连接到 $HOST:$PORT...${NC}"
if timeout 5 bash -c "</dev/tcp/$HOST/$PORT" 2>/dev/null; then
    echo -e "${GREEN}✅ TCP连接成功${NC}"
else
    echo -e "${RED}❌ TCP连接失败${NC}"
    echo -e "${YELLOW}💡 可能的原因:${NC}"
    echo "   - WebSocket服务器未启动"
    echo "   - 端口被防火墙阻止"
    echo "   - 服务器配置错误"
fi
echo ""

# 5. 检查日志文件
echo -e "${BLUE}5. 检查WebSocket日志...${NC}"
log_file="storage/logs/websocket-$(date +%Y%m%d).log"
if [ -f "$log_file" ]; then
    echo -e "${GREEN}✅ 日志文件存在: $log_file${NC}"
    echo "最近的日志内容:"
    echo -e "${CYAN}----------------------------------------${NC}"
    tail -10 "$log_file"
    echo -e "${CYAN}----------------------------------------${NC}"
else
    echo -e "${YELLOW}⚠️  日志文件不存在: $log_file${NC}"
fi
echo ""

# 6. 检查依赖
echo -e "${BLUE}6. 检查PHP依赖...${NC}"
if [ -d "vendor" ]; then
    echo -e "${GREEN}✅ Composer依赖已安装${NC}"
    
    # 检查关键依赖
    if [ -d "vendor/ratchet" ]; then
        echo -e "${GREEN}✅ Ratchet WebSocket库已安装${NC}"
    else
        echo -e "${RED}❌ Ratchet WebSocket库未安装${NC}"
        echo -e "${YELLOW}💡 请运行: composer install${NC}"
    fi
else
    echo -e "${RED}❌ Composer依赖未安装${NC}"
    echo -e "${YELLOW}💡 请运行: composer install${NC}"
fi
echo ""

# 7. 网络连接测试
echo -e "${BLUE}7. 使用curl测试WebSocket握手...${NC}"
if command -v curl &> /dev/null; then
    response=$(curl -s -I -H "Connection: Upgrade" -H "Upgrade: websocket" -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" -H "Sec-WebSocket-Version: 13" "http://$HOST:$PORT/ws/monitor" 2>&1)
    if echo "$response" | grep -q "101"; then
        echo -e "${GREEN}✅ WebSocket握手成功${NC}"
    else
        echo -e "${RED}❌ WebSocket握手失败${NC}"
        echo "响应内容:"
        echo "$response"
    fi
else
    echo -e "${YELLOW}⚠️  curl未安装，跳过WebSocket握手测试${NC}"
fi
echo ""

# 8. 提供解决建议
echo -e "${CYAN}🛠️  解决建议:${NC}"
echo "================================"
echo ""

if ! lsof -Pi :$PORT -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo -e "${YELLOW}1. 启动WebSocket服务器:${NC}"
    echo "   ./websocket-manager.sh start"
    echo ""
fi

echo -e "${YELLOW}2. 检查前端配置:${NC}"
echo "   确保前端连接地址为: ws://localhost:$PORT/ws/monitor"
echo "   检查token是否正确传递"
echo ""

echo -e "${YELLOW}3. 查看实时日志:${NC}"
echo "   ./websocket-manager.sh logs"
echo ""

echo -e "${YELLOW}4. 重启服务:${NC}"
echo "   ./websocket-manager.sh restart"
echo ""

echo -e "${YELLOW}5. 手动测试连接:${NC}"
echo "   可以使用浏览器开发者工具或WebSocket测试工具"
echo "   连接地址: ws://localhost:$PORT/ws/monitor?token=test"
echo ""

echo -e "${GREEN}诊断完成! 🎉${NC}" 