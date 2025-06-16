# 前端WebSocket配置指南

## 🌐 前后端分离架构配置

### 环境信息
- **前端域名**: `https://1105.me`
- **后端域名**: `https://slurry-api.1105.me`
- **WebSocket端口**: `8848`

## 🔧 前端配置

### 1. WebSocket连接地址

```javascript
// 生产环境配置
const WEBSOCKET_CONFIG = {
  // 使用WSS协议（HTTPS环境必须）
  url: 'wss://slurry-api.1105.me/ws/monitor',
  
  // 如果通过Nginx代理，端口可能不需要指定
  // url: 'wss://slurry-api.1105.me/ws/monitor',
  
  // 如果直连WebSocket服务器，需要指定端口
  // url: 'wss://slurry-api.1105.me:8848/ws/monitor',
  
  // 开发环境（如果需要）
  // url: 'ws://localhost:8848/ws/monitor',
}
```

### 2. 完整的WebSocket客户端代码

```javascript
class WebSocketManager {
  constructor() {
    this.ws = null;
    this.reconnectAttempts = 0;
    this.maxReconnectAttempts = 5;
    this.reconnectInterval = 3000;
    this.heartbeatInterval = 30000;
    this.heartbeatTimer = null;
  }

  connect(token = null) {
    try {
      // 构建连接URL
      const wsUrl = token 
        ? `wss://slurry-api.1105.me/ws/monitor?token=${token}`
        : `wss://slurry-api.1105.me/ws/monitor`;

      console.log('连接WebSocket:', wsUrl);
      
      this.ws = new WebSocket(wsUrl);
      
      this.ws.onopen = this.onOpen.bind(this);
      this.ws.onmessage = this.onMessage.bind(this);
      this.ws.onclose = this.onClose.bind(this);
      this.ws.onerror = this.onError.bind(this);
      
    } catch (error) {
      console.error('WebSocket连接失败:', error);
    }
  }

  onOpen(event) {
    console.log('✅ WebSocket连接成功');
    this.reconnectAttempts = 0;
    this.startHeartbeat();
    
    // 发送初始化消息
    this.send({
      type: 'getStatus'
    });
  }

  onMessage(event) {
    try {
      const data = JSON.parse(event.data);
      console.log('📨 收到消息:', data);
      
      switch (data.type) {
        case 'status':
          this.handleStatusUpdate(data.data);
          break;
        case 'log':
          this.handleLogUpdate(data.data);
          break;
        case 'pong':
          console.log('💓 心跳响应');
          break;
        default:
          console.log('未知消息类型:', data.type);
      }
    } catch (error) {
      console.error('解析消息失败:', error);
    }
  }

  onClose(event) {
    console.log('❌ WebSocket连接关闭:', event.code, event.reason);
    this.stopHeartbeat();
    
    // 自动重连
    if (this.reconnectAttempts < this.maxReconnectAttempts) {
      this.reconnectAttempts++;
      console.log(`🔄 尝试重连 (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
      
      setTimeout(() => {
        this.connect();
      }, this.reconnectInterval);
    } else {
      console.error('❌ 重连失败，已达到最大尝试次数');
    }
  }

  onError(event) {
    console.error('❌ WebSocket错误:', event);
  }

  send(data) {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(data));
    } else {
      console.warn('⚠️ WebSocket未连接，无法发送消息');
    }
  }

  startHeartbeat() {
    this.heartbeatTimer = setInterval(() => {
      this.send({ type: 'ping' });
    }, this.heartbeatInterval);
  }

  stopHeartbeat() {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer);
      this.heartbeatTimer = null;
    }
  }

  disconnect() {
    this.stopHeartbeat();
    if (this.ws) {
      this.ws.close();
      this.ws = null;
    }
  }

  handleStatusUpdate(status) {
    // 处理状态更新
    console.log('📊 状态更新:', status);
    // 更新UI...
  }

  handleLogUpdate(log) {
    // 处理日志更新
    console.log('📝 日志更新:', log);
    // 更新日志列表...
  }
}

// 使用示例
const wsManager = new WebSocketManager();

// 连接（可以传入token）
wsManager.connect('your-auth-token');

// 或者不传token（开发环境）
// wsManager.connect();
```

### 3. Vue.js集成示例

```vue
<template>
  <div class="websocket-monitor">
    <div class="connection-status">
      <span :class="connectionStatusClass">
        {{ connectionStatus }}
      </span>
    </div>
    
    <div class="logs">
      <div v-for="log in logs" :key="log.id" class="log-item">
        {{ log.message }}
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: 'WebSocketMonitor',
  data() {
    return {
      ws: null,
      connectionStatus: '未连接',
      logs: [],
      reconnectAttempts: 0,
      maxReconnectAttempts: 5
    }
  },
  
  computed: {
    connectionStatusClass() {
      return {
        'status-connected': this.connectionStatus === '已连接',
        'status-connecting': this.connectionStatus === '连接中',
        'status-disconnected': this.connectionStatus === '未连接'
      }
    }
  },
  
  mounted() {
    this.initWebSocket();
  },
  
  beforeUnmount() {
    this.disconnect();
  },
  
  methods: {
    initWebSocket() {
      this.connectionStatus = '连接中';
      
      const wsUrl = 'wss://slurry-api.1105.me/ws/monitor';
      this.ws = new WebSocket(wsUrl);
      
      this.ws.onopen = this.handleOpen;
      this.ws.onmessage = this.handleMessage;
      this.ws.onclose = this.handleClose;
      this.ws.onerror = this.handleError;
    },
    
    handleOpen() {
      console.log('WebSocket连接成功');
      this.connectionStatus = '已连接';
      this.reconnectAttempts = 0;
    },
    
    handleMessage(event) {
      const data = JSON.parse(event.data);
      
      if (data.type === 'log') {
        this.logs.unshift({
          id: Date.now(),
          message: data.data.message,
          timestamp: new Date()
        });
        
        // 限制日志数量
        if (this.logs.length > 100) {
          this.logs = this.logs.slice(0, 100);
        }
      }
    },
    
    handleClose() {
      console.log('WebSocket连接关闭');
      this.connectionStatus = '未连接';
      
      // 自动重连
      if (this.reconnectAttempts < this.maxReconnectAttempts) {
        this.reconnectAttempts++;
        setTimeout(() => {
          this.initWebSocket();
        }, 3000);
      }
    },
    
    handleError(error) {
      console.error('WebSocket错误:', error);
      this.connectionStatus = '连接错误';
    },
    
    disconnect() {
      if (this.ws) {
        this.ws.close();
        this.ws = null;
      }
    }
  }
}
</script>

<style scoped>
.connection-status {
  padding: 10px;
  margin-bottom: 20px;
}

.status-connected {
  color: #52c41a;
}

.status-connecting {
  color: #1890ff;
}

.status-disconnected {
  color: #ff4d4f;
}

.logs {
  max-height: 400px;
  overflow-y: auto;
}

.log-item {
  padding: 5px;
  border-bottom: 1px solid #f0f0f0;
  font-family: monospace;
  font-size: 12px;
}
</style>
```

## 🔒 安全配置

### 1. Token认证

```javascript
// 获取认证token
const token = localStorage.getItem('auth_token') || 
              sessionStorage.getItem('auth_token') ||
              'development-token'; // 开发环境默认token

// 连接时传递token
const wsUrl = `wss://slurry-api.1105.me/ws/monitor?token=${token}`;
```

### 2. CORS处理

确保后端已正确配置CORS，允许来自 `https://1105.me` 的连接。

## 🚀 部署配置

### 1. 环境变量配置

```javascript
// 根据环境配置WebSocket地址
const getWebSocketUrl = () => {
  const env = process.env.NODE_ENV;
  
  switch (env) {
    case 'production':
      return 'wss://slurry-api.1105.me/ws/monitor';
    case 'development':
      return 'ws://localhost:8848/ws/monitor';
    default:
      return 'wss://slurry-api.1105.me/ws/monitor';
  }
};
```

### 2. 构建配置

确保构建工具正确处理WebSocket连接：

```javascript
// vite.config.js 或 webpack.config.js
export default {
  server: {
    proxy: {
      '/ws': {
        target: 'ws://localhost:8848',
        ws: true,
        changeOrigin: true
      }
    }
  }
}
```

## 🔧 故障排除

### 常见问题

1. **连接被拒绝**: 检查后端WebSocket服务是否启动
2. **CORS错误**: 确保后端允许前端域名
3. **SSL证书问题**: 确保使用WSS协议且证书有效
4. **Token验证失败**: 检查token是否正确传递

### 调试工具

```javascript
// 启用详细日志
const DEBUG = true;

if (DEBUG) {
  console.log('WebSocket URL:', wsUrl);
  console.log('Token:', token);
  console.log('Origin:', window.location.origin);
}
```

## 📞 获取帮助

如果遇到连接问题：

1. 检查浏览器开发者工具的Network标签
2. 查看Console中的错误信息
3. 确认后端WebSocket服务正在运行
4. 验证SSL证书配置
5. 检查防火墙和网络配置 