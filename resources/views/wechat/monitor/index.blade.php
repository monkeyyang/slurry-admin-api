<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>微信消息监控面板</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }

        .status-pending {
            background-color: #ffc107;
            color: #212529;
        }

        .status-success {
            background-color: #198754;
            color: white;
        }

        .status-failed {
            background-color: #dc3545;
            color: white;
        }

        .stats-card {
            transition: transform 0.2s;
        }

        .stats-card:hover {
            transform: translateY(-2px);
        }

        .message-content {
            max-width: 300px;
            word-wrap: break-word;
        }

        .auto-refresh {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }

        .loading {
            display: none;
        }

        .loading.active {
            display: inline-block;
        }

        .table-container {
            max-height: 600px;
            overflow-y: auto;
        }

        .filter-form {
            background-color: #f8f9fa;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1><i class="fas fa-comments"></i> 微信消息监控面板</h1>
                <div class="auto-refresh">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="autoRefresh" checked>
                        <label class="form-check-label" for="autoRefresh">
                            自动刷新 (<span id="refreshInterval">5</span>s)
                        </label>
                    </div>
                </div>
            </div>

            <!-- 统计卡片 -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stats-card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5 class="card-title">总消息数</h5>
                                    <h2 id="totalMessages">-</h2>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-envelope fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-warning text-dark">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5 class="card-title">待发送</h5>
                                    <h2 id="pendingMessages">-</h2>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-clock fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5 class="card-title">发送成功</h5>
                                    <h2 id="successMessages">-</h2>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-check-circle fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-danger text-white">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <h5 class="card-title">发送失败</h5>
                                    <h2 id="failedMessages">-</h2>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-times-circle fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 队列状态 -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-tasks"></i> 队列状态</h5>
                            <div class="row">
                                <div class="col-6">
                                    <div class="text-center">
                                        <h4 id="queueSize" class="text-info">-</h4>
                                        <small>待处理任务</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <h4 id="failedJobs" class="text-danger">-</h4>
                                        <small>失败任务</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-chart-line"></i> 成功率</h5>
                            <div class="row">
                                <div class="col-6">
                                    <div class="text-center">
                                        <h4 id="successRate" class="text-success">-</h4>
                                        <small>总体成功率</small>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center">
                                        <h4 id="todaySuccessRate" class="text-info">-</h4>
                                        <small>今日成功率</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 操作按钮 -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-tools"></i> 操作面板</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <button class="btn btn-info btn-sm" onclick="refreshData()">
                                        <i class="fas fa-refresh"></i> 刷新数据
                                    </button>
                                    <button class="btn btn-warning btn-sm" onclick="retryAllFailed()">
                                        <i class="fas fa-redo"></i> 重试失败
                                    </button>
                                </div>
                                <div class="col-md-8">
                                    <div class="input-group">
                                        <select class="form-select" id="testRoomId">
                                            <option value="">选择测试群聊</option>
                                        </select>
                                        <input type="text" class="form-control" id="testMessage"
                                               placeholder="测试消息内容">
                                        <button class="btn btn-primary" onclick="sendTestMessage()">
                                            <i class="fas fa-paper-plane"></i> 发送测试
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 筛选器 -->
            <div class="filter-form">
                <div class="row">
                    <div class="col-md-2">
                        <select class="form-select" id="filterRoomId">
                            <option value="">所有群聊</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="filterStatus">
                            <option value="">所有状态</option>
                            <option value="0">待发送</option>
                            <option value="1">发送成功</option>
                            <option value="2">发送失败</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select class="form-select" id="filterSource">
                            <option value="">所有来源</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" id="filterStartDate">
                    </div>
                    <div class="col-md-2">
                        <input type="date" class="form-control" id="filterEndDate">
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-outline-primary w-100" onclick="applyFilters()">
                            <i class="fas fa-filter"></i> 筛选
                        </button>
                    </div>
                </div>
            </div>

            <!-- 消息列表 -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-list"></i> 消息列表</h5>
                    <div class="float-end">
                            <span class="loading" id="loadingSpinner">
                                <i class="fas fa-spinner fa-spin"></i> 加载中...
                            </span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-container">
                        <table class="table table-hover mb-0">
                            <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>群聊</th>
                                <th>消息内容</th>
                                <th>来源</th>
                                <th>状态</th>
                                <th>重试次数</th>
                                <th>创建时间</th>
                                <th>操作</th>
                            </tr>
                            </thead>
                            <tbody id="messageTableBody">
                            <tr>
                                <td colspan="8" class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 分页 -->
            <div class="d-flex justify-content-center mt-3">
                <nav>
                    <ul class="pagination" id="pagination">
                        <!-- 分页按钮将由JavaScript动态生成 -->
                    </ul>
                </nav>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 全局变量
    let autoRefreshInterval;
    let currentPage = 1;
    let pageSize = 20;
    let config = {};
    let rooms = [];

    // 初始化页面
    document.addEventListener('DOMContentLoaded', function () {
        loadConfig();
        loadRooms();
        loadMessages();
        loadStats();

        // 设置自动刷新
        const autoRefreshCheckbox = document.getElementById('autoRefresh');
        autoRefreshCheckbox.addEventListener('change', function () {
            if (this.checked) {
                startAutoRefresh();
            } else {
                stopAutoRefresh();
            }
        });

        // 默认启动自动刷新
        startAutoRefresh();
    });

    // 加载配置
    async function loadConfig() {
        try {
            const response = await fetch('/api/wechat/monitor/config');
            const result = await response.json();
            if (result.success) {
                config = result.data;
                document.getElementById('refreshInterval').textContent = config.refresh_interval / 1000;
                pageSize = config.page_size;
            }
        } catch (error) {
            console.error('加载配置失败:', error);
        }
    }

    // 加载房间列表
    async function loadRooms() {
        try {
            const response = await fetch('/api/wechat/monitor/rooms');
            const result = await response.json();
            if (result.success) {
                rooms = result.data;
                updateRoomSelects();
            }
        } catch (error) {
            console.error('加载房间列表失败:', error);
        }
    }

    // 更新房间选择器
    function updateRoomSelects() {
        const selects = ['filterRoomId', 'testRoomId'];
        selects.forEach(selectId => {
            const select = document.getElementById(selectId);
            // 清空现有选项（除了第一个）
            select.innerHTML = select.innerHTML.split('</option>')[0] + '</option>';

            rooms.forEach(room => {
                const option = document.createElement('option');
                option.value = room.room_id;
                option.textContent = `${room.name} (${room.room_id})`;
                select.appendChild(option);
            });
        });
    }

    // 加载统计数据
    async function loadStats() {
        try {
            const response = await fetch('/api/wechat/monitor/stats');
            const result = await response.json();
            if (result.success) {
                updateStatsDisplay(result.data);
            }
        } catch (error) {
            console.error('加载统计数据失败:', error);
        }
    }

    // 更新统计显示
    function updateStatsDisplay(data) {
        document.getElementById('totalMessages').textContent = data.overall.total;
        document.getElementById('pendingMessages').textContent = data.overall.pending;
        document.getElementById('successMessages').textContent = data.overall.success;
        document.getElementById('failedMessages').textContent = data.overall.failed;

        document.getElementById('queueSize').textContent = data.queue.queue_size;
        document.getElementById('failedJobs').textContent = data.queue.failed_jobs;

        document.getElementById('successRate').textContent = data.overall.success_rate + '%';
        document.getElementById('todaySuccessRate').textContent = data.today.success_rate + '%';
    }

    // 加载消息列表
    async function loadMessages() {
        showLoading(true);
        try {
            const params = new URLSearchParams({
                page: currentPage,
                page_size: pageSize
            });

            // 添加筛选条件
            const filters = getFilters();
            Object.entries(filters).forEach(([key, value]) => {
                if (value) params.append(key, value);
            });

            const response = await fetch(`/api/wechat/monitor/messages?${params}`);
            const result = await response.json();
            if (result.success) {
                updateMessageTable(result.data.data);
                updatePagination(result.data);
            }
        } catch (error) {
            console.error('加载消息列表失败:', error);
        } finally {
            showLoading(false);
        }
    }

    // 获取筛选条件
    function getFilters() {
        return {
            room_id: document.getElementById('filterRoomId').value,
            status: document.getElementById('filterStatus').value,
            from_source: document.getElementById('filterSource').value,
            start_date: document.getElementById('filterStartDate').value,
            end_date: document.getElementById('filterEndDate').value
        };
    }

    // 更新消息表格
    function updateMessageTable(messages) {
        const tbody = document.getElementById('messageTableBody');
        tbody.innerHTML = '';

        if (messages.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center">暂无数据</td></tr>';
            return;
        }

        messages.forEach(message => {
            const row = document.createElement('tr');
            const statusClass = getStatusClass(message.status);
            const statusText = getStatusText(message.status);

            row.innerHTML = `
                    <td>${message.id}</td>
                    <td>${message.room_name || message.room_id}</td>
                    <td class="message-content">${message.content_preview}</td>
                    <td>${message.from_source || '-'}</td>
                    <td><span class="badge ${statusClass}">${statusText}</span></td>
                    <td>${message.retry_count}/${message.max_retry}</td>
                    <td>${formatDate(message.created_at)}</td>
                    <td>
                        ${message.status === 2 && message.retry_count < message.max_retry ?
                `<button class="btn btn-sm btn-outline-warning" onclick="retryMessage(${message.id})">
                                <i class="fas fa-redo"></i>
                            </button>` : ''}
                    </td>
                `;
            tbody.appendChild(row);
        });
    }

    // 获取状态样式类
    function getStatusClass(status) {
        switch (status) {
            case 0:
                return 'status-pending';
            case 1:
                return 'status-success';
            case 2:
                return 'status-failed';
            default:
                return 'bg-secondary';
        }
    }

    // 获取状态文本
    function getStatusText(status) {
        switch (status) {
            case 0:
                return '待发送';
            case 1:
                return '发送成功';
            case 2:
                return '发送失败';
            default:
                return '未知';
        }
    }

    // 获取房间名称
    function getRoomName(roomId) {
        const room = rooms.find(r => r.room_id === roomId);
        return room ? room.name : roomId;
    }

    // 格式化日期
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString('zh-CN');
    }

    // 更新分页
    function updatePagination(data) {
        const pagination = document.getElementById('pagination');
        pagination.innerHTML = '';

        const totalPages = data.last_page;
        const currentPage = data.current_page;

        // 上一页
        if (currentPage > 1) {
            pagination.innerHTML += `
                    <li class="page-item">
                        <a class="page-link" href="#" onclick="changePage(${currentPage - 1})">上一页</a>
                    </li>`;
        }

        // 页码
        for (let i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
            const activeClass = i === currentPage ? 'active' : '';
            pagination.innerHTML += `
                    <li class="page-item ${activeClass}">
                        <a class="page-link" href="#" onclick="changePage(${i})">${i}</a>
                    </li>`;
        }

        // 下一页
        if (currentPage < totalPages) {
            pagination.innerHTML += `
                    <li class="page-item">
                        <a class="page-link" href="#" onclick="changePage(${currentPage + 1})">下一页</a>
                    </li>`;
        }
    }

    // 切换页面
    function changePage(page) {
        currentPage = page;
        loadMessages();
    }

    // 应用筛选条件
    function applyFilters() {
        currentPage = 1;
        loadMessages();
    }

    // 刷新数据
    function refreshData() {
        loadStats();
        loadMessages();
    }

    // 重试单个消息
    async function retryMessage(messageId) {
        try {
            const response = await fetch('/api/wechat/monitor/retry', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({message_id: messageId})
            });

            const result = await response.json();
            if (result.success) {
                alert('重试成功');
                loadMessages();
            } else {
                alert('重试失败: ' + result.message);
            }
        } catch (error) {
            console.error('重试失败:', error);
            alert('重试失败');
        }
    }

    // 重试所有失败的消息
    async function retryAllFailed() {
        if (!confirm('确定要重试所有失败的消息吗？')) return;

        try {
            // 这里应该先获取所有失败的消息ID，然后批量重试
            // 为了简化，我们显示一个提示
            alert('批量重试功能正在开发中');
        } catch (error) {
            console.error('批量重试失败:', error);
            alert('批量重试失败');
        }
    }

    // 发送测试消息
    async function sendTestMessage() {
        const roomId = document.getElementById('testRoomId').value;
        const content = document.getElementById('testMessage').value;

        if (!roomId) {
            alert('请选择测试群聊');
            return;
        }

        try {
            const response = await fetch('/api/wechat/monitor/test-message', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    room_id: roomId,
                    content: content || undefined,
                    use_queue: true
                })
            });

            const result = await response.json();
            if (result.success) {
                alert('测试消息发送成功');
                document.getElementById('testMessage').value = '';
                loadMessages();
            } else {
                alert('测试消息发送失败: ' + result.message);
            }
        } catch (error) {
            console.error('发送测试消息失败:', error);
            alert('发送测试消息失败');
        }
    }

    // 显示/隐藏加载状态
    function showLoading(show) {
        const spinner = document.getElementById('loadingSpinner');
        if (show) {
            spinner.classList.add('active');
        } else {
            spinner.classList.remove('active');
        }
    }

    // 启动自动刷新
    function startAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }

        const interval = config.refresh_interval || 5000;
        autoRefreshInterval = setInterval(() => {
            loadStats();
            loadMessages();
        }, interval);
    }

    // 停止自动刷新
    function stopAutoRefresh() {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
    }
</script>
</body>
</html>
