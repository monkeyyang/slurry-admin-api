# 代理IP配置和使用指南

## 🎯 概述

系统已配置了10个代理IP地址，用于查码功能。这些代理IP来自IPIDEA服务，支持HTTP代理协议。

## 📋 代理地址列表

当前配置的代理地址：

```
http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336
```

**代理信息**:
- 用户名: `Ys00000011-zone-static-region-us`
- 密码: `112233QQ`
- 主机: `4c38563462f6b480.lqz.na.ipidea.online`
- 端口: `2336`
- 地区: 美国 (US)
- 类型: 静态住宅IP

## 🔧 配置说明

### 1. 配置文件位置
- **主配置**: `config/proxy.php`
- **代理列表**: `proxies.txt`

### 2. 配置内容
```php
'proxy_list' => [
    'http://Ys00000011-zone-static-region-us:112233QQ@4c38563462f6b480.lqz.na.ipidea.online:2336',
    // ... 共10个相同的代理地址
],
```

## 🚀 使用方法

### 1. 测试代理可用性

```bash
# 使用测试脚本
php test_proxy.php

# 使用Artisan命令
php artisan proxy:manage test
```

### 2. 查看代理列表

```bash
php artisan proxy:manage list
```

### 3. 管理代理

```bash
# 添加代理
php artisan proxy:manage add --proxy="http://user:pass@host:port"

# 移除代理
php artisan proxy:manage remove --proxy="http://user:pass@host:port"

# 清空代理列表
php artisan proxy:manage clear
```

## 📊 代理轮询机制

### 工作原理
1. 系统使用轮询方式选择代理
2. 每次请求使用不同的代理
3. 代理索引存储在缓存中
4. 自动循环使用所有可用代理

### 缓存配置
- 缓存键: `proxy_current_index`
- 缓存时间: 1小时
- 自动更新: 每次使用后更新索引

## 🔍 代理测试

### 测试URL
- 默认测试URL: `http://httpbin.org/ip`
- 可配置: `config/proxy.test_url`

### 测试超时
- 默认超时: 10秒
- 可配置: `config/proxy.test_timeout`

### 测试结果
- ✅ 可用: 代理响应正常
- ❌ 不可用: 代理无响应或错误

## 📈 性能优化

### 并发处理
- 多账号同时查码
- 每个账号使用不同代理
- 避免代理过载

### 错误处理
- 代理失败自动切换
- 重试机制
- 详细日志记录

## 🛠️ 故障排除

### 常见问题

1. **代理连接失败**
   - 检查网络连接
   - 验证代理地址格式
   - 确认代理服务状态

2. **代理响应慢**
   - 调整超时时间
   - 检查代理负载
   - 考虑更换代理

3. **代理不可用**
   - 运行代理测试
   - 检查代理服务商状态
   - 更新代理列表

### 日志查看

```bash
# 查看代理相关日志
tail -f storage/logs/laravel.log | grep -i proxy

# 查看查码日志
tail -f storage/logs/laravel.log | grep -i "getVerifyCode"
```

## 🔒 安全考虑

### 代理安全
1. **认证信息**: 用户名密码已配置
2. **HTTPS支持**: 支持HTTPS代理
3. **地区限制**: 美国地区IP，适合查码

### 使用建议
1. **定期测试**: 建议每天测试代理可用性
2. **监控使用**: 关注代理使用频率
3. **备份方案**: 准备备用代理

## 📝 配置示例

### 环境变量配置 (.env)
```env
# 代理配置
PROXY_ENABLED=true
PROXY_TEST_URL=http://httpbin.org/ip
PROXY_TEST_TIMEOUT=10
```

### 自定义代理配置
```php
// config/proxy.php
return [
    'proxy_list' => [
        'http://your-username:your-password@your-proxy-host:port',
    ],
    'test_url' => 'http://httpbin.org/ip',
    'test_timeout' => 10,
    'verify_timeout' => 60,
    'verify_interval' => 5,
    'request_timeout' => 10,
];
```

## 🎯 最佳实践

### 1. 代理管理
- 定期测试代理可用性
- 监控代理使用情况
- 及时更新失效代理

### 2. 查码优化
- 合理设置超时时间
- 避免过于频繁的请求
- 使用并发处理提高效率

### 3. 日志监控
- 关注查码成功率
- 监控代理响应时间
- 及时处理异常情况

## 📞 技术支持

如果遇到代理相关问题：

1. **检查配置**: 确认代理地址格式正确
2. **测试连接**: 使用测试脚本验证
3. **查看日志**: 分析错误信息
4. **联系服务商**: 确认代理服务状态

---

**注意**: 这些代理IP仅供查码功能使用，请遵守相关服务条款和使用规范。 