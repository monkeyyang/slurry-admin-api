#!/bin/bash

# ============================================================================
# Laravel Slurry Admin API - Supervisor 管理脚本
# 功能: 安装、配置、管理 Supervisor 服务
# 作者: AI Assistant
# 日期: 2024-12-16
# ============================================================================

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 配置变量
PROJECT_ROOT="/www/wwwroot/slurry-admin-api"
SUPERVISOR_CONFIG_DIR="/etc/supervisor/conf.d"
SUPERVISOR_CONFIG_FILE="slurry-admin-api.conf"
LOG_DIR="$PROJECT_ROOT/storage/logs/supervisor"

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

# 检查是否为root用户
check_root() {
    if [[ $EUID -ne 0 ]]; then
        log_error "此脚本需要root权限运行"
        echo "请使用: sudo $0 $@"
        exit 1
    fi
}

# 检查系统环境
check_system() {
    log_info "检查系统环境..."
    
    # 检查操作系统
    if [[ ! -f /etc/os-release ]]; then
        log_error "无法确定操作系统类型"
        exit 1
    fi
    
    source /etc/os-release
    log_info "操作系统: $NAME $VERSION"
    
    # 检查PHP
    if ! command -v php &> /dev/null; then
        log_error "PHP 未安装或不在PATH中"
        exit 1
    fi
    
    PHP_VERSION=$(php -v | head -n 1 | cut -d " " -f 2)
    log_info "PHP 版本: $PHP_VERSION"
    
    # 检查项目目录
    if [[ ! -d "$PROJECT_ROOT" ]]; then
        log_error "项目目录不存在: $PROJECT_ROOT"
        exit 1
    fi
    
    log_success "系统环境检查完成"
}

# 安装Supervisor
install_supervisor() {
    log_info "安装 Supervisor..."
    
    # 检查是否已安装
    if command -v supervisord &> /dev/null; then
        log_warning "Supervisor 已安装"
        return 0
    fi
    
    # 根据系统类型安装
    if command -v apt-get &> /dev/null; then
        # Ubuntu/Debian
        apt-get update
        apt-get install -y supervisor
    elif command -v yum &> /dev/null; then
        # CentOS/RHEL
        yum install -y supervisor
    elif command -v dnf &> /dev/null; then
        # Fedora
        dnf install -y supervisor
    else
        log_error "不支持的操作系统，请手动安装 Supervisor"
        exit 1
    fi
    
    # 启用并启动服务
    systemctl enable supervisor
    systemctl start supervisor
    
    log_success "Supervisor 安装完成"
}

# 创建日志目录
create_log_directory() {
    log_info "创建日志目录..."
    
    if [[ ! -d "$LOG_DIR" ]]; then
        mkdir -p "$LOG_DIR"
        chown www:www "$LOG_DIR"
        chmod 755 "$LOG_DIR"
        log_success "日志目录创建完成: $LOG_DIR"
    else
        log_warning "日志目录已存在: $LOG_DIR"
    fi
}

# 部署配置文件
deploy_config() {
    log_info "部署 Supervisor 配置文件..."
    
    # 检查配置文件是否存在
    if [[ ! -f "$PROJECT_ROOT/supervisor-complete.conf" ]]; then
        log_error "配置文件不存在: $PROJECT_ROOT/supervisor-complete.conf"
        exit 1
    fi
    
    # 复制配置文件
    cp "$PROJECT_ROOT/supervisor-complete.conf" "$SUPERVISOR_CONFIG_DIR/$SUPERVISOR_CONFIG_FILE"
    
    # 设置权限
    chmod 644 "$SUPERVISOR_CONFIG_DIR/$SUPERVISOR_CONFIG_FILE"
    
    log_success "配置文件部署完成"
}

# 验证配置
validate_config() {
    log_info "验证 Supervisor 配置..."
    
    # 重新读取配置
    supervisorctl reread
    
    if [[ $? -eq 0 ]]; then
        log_success "配置验证通过"
    else
        log_error "配置验证失败"
        exit 1
    fi
}

# 更新配置
update_config() {
    log_info "更新 Supervisor 配置..."
    
    supervisorctl update
    
    if [[ $? -eq 0 ]]; then
        log_success "配置更新完成"
    else
        log_error "配置更新失败"
        exit 1
    fi
}

# 启动所有服务
start_services() {
    log_info "启动所有服务..."
    
    supervisorctl start all
    
    if [[ $? -eq 0 ]]; then
        log_success "所有服务启动完成"
    else
        log_warning "部分服务启动失败，请检查日志"
    fi
}

# 显示状态
show_status() {
    log_info "显示服务状态..."
    echo
    supervisorctl status
    echo
}

# 显示日志
show_logs() {
    local service=$1
    
    if [[ -z "$service" ]]; then
        log_info "可用的服务列表:"
        supervisorctl status | awk '{print $1}'
        echo
        echo "使用方法: $0 logs <service_name>"
        return
    fi
    
    log_info "显示 $service 的日志..."
    supervisorctl tail -f "$service"
}

# 重启服务
restart_service() {
    local service=$1
    
    if [[ -z "$service" ]]; then
        log_info "重启所有服务..."
        supervisorctl restart all
    else
        log_info "重启服务: $service"
        supervisorctl restart "$service"
    fi
    
    log_success "服务重启完成"
}

# 停止服务
stop_service() {
    local service=$1
    
    if [[ -z "$service" ]]; then
        log_info "停止所有服务..."
        supervisorctl stop all
    else
        log_info "停止服务: $service"
        supervisorctl stop "$service"
    fi
    
    log_success "服务停止完成"
}

# 完整安装
full_install() {
    log_info "开始完整安装 Supervisor..."
    
    check_system
    install_supervisor
    create_log_directory
    deploy_config
    validate_config
    update_config
    start_services
    show_status
    
    log_success "Supervisor 完整安装完成！"
    echo
    echo "管理命令:"
    echo "  查看状态: $0 status"
    echo "  查看日志: $0 logs <service_name>"
    echo "  重启服务: $0 restart [service_name]"
    echo "  停止服务: $0 stop [service_name]"
    echo "  更新配置: $0 update"
}

# 更新配置和重启
update_and_restart() {
    log_info "更新配置并重启服务..."
    
    deploy_config
    validate_config
    update_config
    restart_service
    show_status
    
    log_success "配置更新和服务重启完成！"
}

# 显示帮助信息
show_help() {
    echo "Laravel Slurry Admin API - Supervisor 管理脚本"
    echo
    echo "用法: $0 <command> [options]"
    echo
    echo "命令:"
    echo "  install     - 完整安装 Supervisor (需要root权限)"
    echo "  status      - 显示所有服务状态"
    echo "  logs        - 显示服务日志"
    echo "  restart     - 重启服务"
    echo "  stop        - 停止服务"
    echo "  start       - 启动服务"
    echo "  update      - 更新配置"
    echo "  reload      - 更新配置并重启服务"
    echo "  help        - 显示此帮助信息"
    echo
    echo "示例:"
    echo "  $0 install                    # 完整安装"
    echo "  $0 status                     # 查看状态"
    echo "  $0 logs laravel-gift-card-worker  # 查看礼品卡队列日志"
    echo "  $0 restart websocket-trade-monitor # 重启WebSocket服务"
    echo "  $0 stop                       # 停止所有服务"
    echo "  $0 reload                     # 重新加载配置"
    echo
}

# 主函数
main() {
    case "$1" in
        "install")
            check_root
            full_install
            ;;
        "status")
            show_status
            ;;
        "logs")
            show_logs "$2"
            ;;
        "restart")
            restart_service "$2"
            ;;
        "stop")
            stop_service "$2"
            ;;
        "start")
            supervisorctl start "${2:-all}"
            ;;
        "update")
            check_root
            deploy_config
            validate_config
            update_config
            ;;
        "reload")
            check_root
            update_and_restart
            ;;
        "help"|"--help"|"-h")
            show_help
            ;;
        "")
            show_help
            ;;
        *)
            log_error "未知命令: $1"
            show_help
            exit 1
            ;;
    esac
}

# 执行主函数
main "$@" 