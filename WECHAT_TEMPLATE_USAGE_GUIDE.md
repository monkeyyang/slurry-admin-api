# 微信模板使用指南

## 配置文件模板

在 `config/wechat.php` 中定义的模板：

```php
'templates' => [
    'gift_card_success'     => "[强]礼品卡兑换成功\n---------------------------------\n账号：{account}\n国家：{country}   当前第{day}天\n礼品卡：{amount} {currency}\n兑换金额：{exchanged_amount}\n账户余款：{balance}\n计划总额：{total_amount}\n群聊绑定：{bind_room}\n时间：{time}",
    'redeem_plan_completed' => "[强]兑换目标达成通知\n---------------------------------\n账号：{account}\n国家：{country} 账户余款：{balance}",
    'redeem_account_ban'    => "❌ 账号禁用，请检测\n---------------------------------\n{account}",
    'verify_code_success'   => "✅ 查码成功\n---------------------\n{account}\n{code}",
    'verify_code_failed'    => "❌ 查码失败\n---------------------\n{account}\n{code}",
],
```

## 使用方法

### 方法1：直接使用配置模板（最基础）

```php
// 获取模板
$template = config('wechat.templates.redeem_plan_completed');

// 替换占位符
$msg = str_replace([
    '{account}',
    '{country}',
    '{balance}'
], [
    $account->account,
    $account->country_code ?? 'Unknown',
    $currentTotalAmount
], $template);

// 发送消息
send_msg_to_wechat('45958721463@chatroom', $msg, 'MT_SEND_TEXTMSG', true, 'your-source');
```

### 方法2：使用 WechatMessageService 模板功能

```php
$wechatMessageService = app(\App\Services\WechatMessageService::class);

$result = $wechatMessageService->sendMessageWithTemplate(
    '45958721463@chatroom',
    'redeem_plan_completed',
    [
        'account' => $account->account,
        'country' => $account->country_code ?? 'Unknown',
        'balance' => $currentTotalAmount
    ],
    'your-source'
);
```

### 方法3：使用辅助函数（推荐）

```php
$result = send_wechat_template(
    '45958721463@chatroom',
    'redeem_plan_completed',
    [
        'account' => $account->account,
        'country' => $account->country_code ?? 'Unknown',
        'balance' => $currentTotalAmount
    ],
    'your-source'
);
```

## 使用示例

### 1. 兑换目标达成通知

```php
// 使用 redeem_plan_completed 模板
$result = send_wechat_template(
    '45958721463@chatroom',
    'redeem_plan_completed',
    [
        'account' => 'user@icloud.com',
        'country' => 'US',
        'balance' => '500.00'
    ],
    'plan-completion'
);

// 生成消息：
// [强]兑换目标达成通知
// ---------------------------------
// 账号：user@icloud.com
// 国家：US 账户余款：500.00
```

### 2. 礼品卡兑换成功通知

```php
// 使用 gift_card_success 模板
$result = send_wechat_template(
    '45958721463@chatroom',
    'gift_card_success',
    [
        'account' => 'user@icloud.com',
        'country' => 'US',
        'day' => '3',
        'amount' => '100',
        'currency' => 'USD',
        'exchanged_amount' => '100.00',
        'balance' => '300.00',
        'total_amount' => '500.00',
        'bind_room' => '是',
        'time' => '2024-01-15 14:30:00'
    ],
    'gift-card-exchange'
);
```

### 3. 查码成功通知

```php
// 使用 verify_code_success 模板
$result = send_wechat_template(
    '20229649389@chatroom',
    'verify_code_success',
    [
        'account' => 'user@icloud.com',
        'code' => '123456'
    ],
    'verify-code'
);
```

### 4. 账号禁用通知

```php
// 使用 redeem_account_ban 模板
$result = send_wechat_template(
    '45958721463@chatroom',
    'redeem_account_ban',
    [
        'account' => 'user@icloud.com'
    ],
    'account-ban'
);
```

## 在不同场景中的使用

### 在 Command 中使用

```php
class YourCommand extends Command
{
    public function handle()
    {
        // 处理逻辑...
        
        // 发送通知
        send_wechat_template(
            '45958721463@chatroom',
            'redeem_plan_completed',
            [
                'account' => $account->account,
                'country' => $account->country_code,
                'balance' => $account->balance
            ],
            'your-command'
        );
    }
}
```

### 在 Job 中使用

```php
class YourJob implements ShouldQueue
{
    public function handle()
    {
        // 处理逻辑...
        
        // 发送成功通知
        send_wechat_template(
            $this->roomId,
            'gift_card_success',
            [
                'account' => $this->account->account,
                'country' => $this->account->country_code,
                // ... 其他变量
            ],
            'your-job'
        );
    }
}
```

### 在 Service 中使用

```php
class YourService
{
    public function processComplete($account)
    {
        // 处理逻辑...
        
        // 发送完成通知
        send_wechat_template(
            config('wechat.default_room'),
            'redeem_plan_completed',
            [
                'account' => $account->account,
                'country' => $account->country_code,
                'balance' => $account->balance
            ],
            'your-service'
        );
    }
}
```

## 注意事项

1. **模板变量检查**: 确保传入的变量与模板中的占位符匹配
2. **空值处理**: 使用 `??` 操作符处理可能为空的值
3. **错误处理**: 检查函数返回值判断发送是否成功
4. **来源标识**: 使用有意义的 `fromSource` 参数便于日志追踪
5. **队列模式**: 默认使用队列异步发送，可根据需要调整

## 扩展模板

如需添加新模板，在 `config/wechat.php` 中添加：

```php
'templates' => [
    // 现有模板...
    'your_new_template' => "您的模板内容\n变量：{variable_name}",
],
```

然后即可使用：

```php
send_wechat_template('room_id', 'your_new_template', ['variable_name' => 'value']);
``` 