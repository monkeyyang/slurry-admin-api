@echo off
REM 交易监控WebSocket服务器启动脚本 (Windows)

REM 设置端口（默认8080）
set PORT=%1
if "%PORT%"=="" set PORT=8080

echo 正在启动交易监控WebSocket服务器...
echo 端口: %PORT%

REM 检查PHP是否安装
php --version >nul 2>&1
if errorlevel 1 (
    echo 错误: PHP未安装或不在PATH中
    pause
    exit /b 1
)

REM 检查composer依赖
if not exist "vendor" (
    echo 错误: vendor目录不存在，请先运行 composer install
    pause
    exit /b 1
)

REM 启动WebSocket服务器
php websocket-server.php %PORT%
pause 