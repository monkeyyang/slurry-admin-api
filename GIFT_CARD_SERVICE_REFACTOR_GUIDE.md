# 礼品卡服务重构指南

## 概述

为了提高代码的可扩展性和可维护性，对礼品卡相关的服务类进行了重构，采用了属性设置的方式替代传统的参数传递方式。

## 重构的类

1. **BatchGiftCardService** - 批量兑换服务
2. **RedeemGiftCardJob** - 兑换任务队列
3. **GiftCardService** - 礼品卡服务
4. **GiftCardController** - 控制器

## 新的使用方式

### 1. BatchGiftCardService 使用示例

```php
// 旧方式（已废弃）
$batchId = $batchService->startBatchRedemption(
    $codes,
    $roomId,
    $cardType,
    $cardForm,
    $msgid
);

// 新方式（推荐）
$batchId = $batchService
    ->setGiftCardCodes($codes)
    ->setRoomId($roomId)
    ->setCardType($cardType)
    ->setCardForm($cardForm)
    ->setMsgId($msgid)
    ->setWxId($wxid)
    ->setAdditionalParam('priority', 5)
    ->setAdditionalParam('source', 'api')
    ->startBatchRedemption();
```

### 2. RedeemGiftCardJob 使用示例

```php
// 旧方式（已废弃）
$job = new RedeemGiftCardJob($code, $roomId, $cardType, $cardForm, $batchId, $msgid);

// 新方式（推荐）
$job = (new RedeemGiftCardJob())
    ->setGiftCardCode($code)
    ->setRoomId($roomId)
    ->setCardType($cardType)
    ->setCardForm($cardForm)
    ->setBatchId($batchId)
    ->setMsgId($msgid)
    ->setWxId($wxid)
    ->setAdditionalParam('retry_count', 3);
    
dispatch($job);
```

### 3. GiftCardService 使用示例

```php
// 旧方式（已废弃）
$result = $giftCardService->redeem($code, $roomId, $cardType, $cardForm, $batchId, $msgid);

// 新方式（推荐）
$result = $giftCardService
    ->setGiftCardCode($code)
    ->setRoomId($roomId)
    ->setCardType($cardType)
    ->setCardForm($cardForm)
    ->setBatchId($batchId)
    ->setMsgId($msgid)
    ->setWxId($wxid)
    ->setAdditionalParam('callback_url', 'https://example.com/callback')
    ->redeemGiftCard();
```

### 4. 控制器中的使用示例

```php
public function bulkRedeem(Request $request): JsonResponse
{
    // 验证请求...
    
    $batchId = $this->batchService
        ->setGiftCardCodes($validCodes)
        ->setRoomId($validated['room_id'])
        ->setCardType($validated['card_type'])
        ->setCardForm($validated['card_form'])
        ->setMsgId($validated['msgid'] ?? '')
        ->setWxId($validated['wxid'] ?? '')
        ->setAdditionalParam('user_id', auth()->id())
        ->setAdditionalParam('ip_address', $request->ip())
        ->startBatchRedemption();
        
    return response()->json([...]);
}
```

## 错误处理机制

### 业务逻辑错误 vs 系统错误

重构后的 `RedeemGiftCardJob` 保留了原有的错误分类处理机制：

#### 业务逻辑错误（不重试）
```php
protected array $businessErrors = [
    '礼品卡无效',
    '该礼品卡已经被兑换',
    '未找到符合条件的汇率',
    '未找到可用的兑换计划',
    '未找到可用的兑换账号',
    'AlreadyRedeemed',
    'Tap Continue to request re-enablement',
    'Bad card',
    '查卡失败',
];
```

**处理方式：**
- 不记录堆栈跟踪
- 直接标记为失败，不重试
- 发送失败消息给微信群
- 更新批量任务进度

#### 系统错误（会重试）
```php
protected array $systemErrors = [
    '系统错误',
    '网络错误',
    '服务器错误',
    '数据库错误',
];
```

**处理方式：**
- 记录完整堆栈跟踪
- 抛出异常触发队列重试机制
- 达到最大重试次数后进入 `failed()` 方法

### 错误处理流程

```php
try {
    // 执行兑换逻辑
    $result = $giftCardService->redeemGiftCard();
    
} catch (Throwable $e) {
    // 1. 记录失败信息
    $this->recordFailure($e, $batchService);
    
    // 2. 判断错误类型
    if ($this->isBusinessError($e)) {
        // 业务错误：发送失败消息，更新进度，不重试
        send_msg_to_wechat($this->roomId, "兑换失败\n" . $e->getMessage());
        $batchService->updateProgress($this->batchId, false, $this->giftCardCode, null, $e->getMessage());
        return; // 不抛出异常，避免重试
    }
    
    // 系统错误：记录详细日志，抛出异常触发重试
    throw $e;
}
```

## 新增功能

### 1. 额外参数支持

现在可以轻松添加额外的参数，无需修改方法签名：

```php
$service
    ->setAdditionalParam('key1', 'value1')
    ->setAdditionalParam('key2', 'value2')
    ->setAdditionalParams([
        'key3' => 'value3',
        'key4' => 'value4'
    ]);

// 获取额外参数
$value = $service->getAdditionalParam('key1', 'default_value');
```

### 2. 链式调用

所有设置方法都支持链式调用，代码更加简洁：

```php
$result = $service
    ->setGiftCardCode($code)
    ->setRoomId($roomId)
    ->setCardType($cardType)
    ->setCardForm($cardForm)
    ->setMsgId($msgid)
    ->setWxId($wxid)
    ->redeemGiftCard();
```

### 3. 属性重置

可以重置所有属性以便复用服务实例：

```php
$service->reset();
// 现在可以设置新的参数并执行新的任务
```

### 4. 参数验证

内置参数验证，确保必要参数不为空：

```php
try {
    $result = $service->redeemGiftCard();
} catch (InvalidArgumentException $e) {
    // 处理参数验证错误
    echo $e->getMessage(); // 例如：礼品卡码不能为空
}
```

### 5. 批量任务状态检查

支持检查批量任务是否已取消：

```php
// 在任务执行前检查批量任务状态
$batchProgress = $batchService->getBatchProgress($this->batchId);
if (empty($batchProgress) || $batchProgress['status'] === 'cancelled') {
    // 跳过处理
    return;
}
```

## 兼容性

为了保持向后兼容性，所有旧的方法都保留了，但标记为 `@deprecated`：

- `BatchGiftCardService::startBatchRedemptionLegacy()`
- `RedeemGiftCardJob::createLegacy()`
- `GiftCardService::redeem()`

建议尽快迁移到新的方式，因为旧方法可能在未来版本中被移除。

## 扩展示例

### 添加新参数的场景

假设需要添加一个 `callback_url` 参数：

```php
// 1. 在服务中设置参数
$service->setAdditionalParam('callback_url', 'https://example.com/callback');

// 2. 在处理逻辑中使用参数
$callbackUrl = $this->getAdditionalParam('callback_url');
if ($callbackUrl) {
    // 发送回调通知
    Http::post($callbackUrl, $result);
}
```

这种方式无需修改任何现有的方法签名或接口。

### 批量设置参数

```php
$service->setAdditionalParams([
    'user_id' => auth()->id(),
    'ip_address' => request()->ip(),
    'user_agent' => request()->userAgent(),
    'timestamp' => now()->timestamp,
    'source' => 'web_api'
]);
```

### 自定义错误处理

```php
// 可以扩展业务错误列表
protected array $businessErrors = [
    // 原有错误...
    '自定义业务错误',
    '余额不足',
    '账户被锁定',
];

// 或者在运行时动态添加
if ($this->getAdditionalParam('strict_mode')) {
    $this->businessErrors[] = '严格模式下的特殊错误';
}
```

## 优势

1. **可扩展性** - 新增参数无需修改现有方法签名
2. **可读性** - 链式调用使代码更加清晰
3. **灵活性** - 可以根据需要设置不同的参数组合
4. **维护性** - 减少了方法参数的复杂性
5. **类型安全** - 每个参数都有对应的设置方法，提供更好的IDE支持
6. **错误处理** - 保留原有的业务错误和系统错误分类处理机制

## 注意事项

1. 服务实例在设置参数后会保持状态，如需复用请调用 `reset()` 方法
2. 必要参数的验证在执行主方法时进行，而不是在设置时
3. 额外参数的类型和验证需要在业务逻辑中自行处理
4. 旧方法虽然保留，但建议尽快迁移到新方式
5. 错误处理机制保持不变，业务错误不重试，系统错误会重试

## 总结

通过这次重构，实现了更加灵活和可扩展的礼品卡服务架构。新的属性设置方式不仅提高了代码的可读性，还为未来的功能扩展提供了良好的基础，同时完整保留了原有的错误处理机制。 
