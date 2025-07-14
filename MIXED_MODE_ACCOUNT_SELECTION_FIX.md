# 混充模式账号选择修复

## 问题描述

在混充模式下（`bind_room = false`），账号选择逻辑仍然在考虑房间绑定优先级，导致某些高优先级账号没有被正确选择。

## 问题分析

### 原始逻辑问题

在 `FindAccountService.php` 的 `sortAccountsByPriority` 方法中，无论是否启用房间绑定，都使用相同的排序逻辑：

```sql
CASE
    WHEN a.plan_id = ? AND a.room_id = ? THEN 1
    WHEN a.plan_id = ? THEN 2
    WHEN a.room_id = ? THEN 3
    WHEN a.plan_id IS NULL THEN 4
    ELSE 5
END as binding_priority
```

这导致在混充模式下，绑定到特定房间的账号仍然被优先考虑，而不是按照计划绑定和容量优先级来选择。

### 具体案例

账号 `ferrispatrick369612@gmail.com` 的情况：
- 余额：1700.00
- 计划ID：1
- 房间ID：46321584173
- 容量优先级：3（正好充满计划额度）
- 绑定优先级：1（绑定当前计划且绑定对应群聊）

但由于该账号绑定的房间 `46321584173` 在最近30小时内没有兑换请求，导致该账号没有被选中。

## 修复方案

### 1. 修改排序逻辑

在 `sortAccountsByPriority` 方法中，根据 `bind_room` 设置使用不同的排序逻辑：

#### 绑定模式（`bind_room = true`）
```sql
CASE
    WHEN a.plan_id = ? AND a.room_id = ? THEN 1
    WHEN a.plan_id = ? THEN 2
    WHEN a.room_id = ? THEN 3
    WHEN a.plan_id IS NULL THEN 4
    ELSE 5
END as binding_priority
```

#### 混充模式（`bind_room = false`）
```sql
CASE
    WHEN a.plan_id = ? THEN 1
    WHEN a.plan_id IS NULL THEN 2
    ELSE 3
END as binding_priority
```

### 2. 修改兜底账号查找逻辑

在 `findFallbackAccount` 方法中，同样根据 `bind_room` 设置使用不同的查询逻辑：

#### 绑定模式
```sql
ORDER BY
    CASE
        WHEN a.plan_id = ? AND a.room_id = ? THEN 1
        WHEN a.plan_id = ? THEN 2
        WHEN a.room_id = ? THEN 3
        WHEN a.plan_id IS NULL THEN 4
        ELSE 5
    END
```

#### 混充模式
```sql
ORDER BY
    CASE
        WHEN a.plan_id = ? THEN 1
        WHEN a.plan_id IS NULL THEN 2
        ELSE 3
    END
```

## 修复后的优先级逻辑

### 混充模式下的优先级排序

1. **绑定优先级**（`binding_priority`）
   - 1：绑定当前计划
   - 2：无计划
   - 3：其他

2. **容量优先级**（`capacity_priority`）
   - 3：正好充满计划额度
   - 2：可以预留
   - 1：超出计划额度

3. **余额排序**：按余额降序

4. **ID排序**：按ID升序

## 预期效果

### 修复前
- 账号选择受房间绑定限制
- 高优先级账号可能因为房间不活跃而被忽略
- 混充模式下的账号利用率不高

### 修复后
- 混充模式下不考虑房间绑定
- 优先选择绑定当前计划且容量合适的账号
- 提高账号利用率，减少闲置账号

## 测试验证

### 测试脚本
创建了 `test_mixed_mode_account_selection.php` 脚本来验证修复效果。

### 预期结果
在混充模式下，账号 `ferrispatrick369612@gmail.com` 应该被优先选择，因为：
- 绑定优先级：1（绑定当前计划）
- 容量优先级：3（正好充满计划额度）
- 余额：1700.00（较高）

## 影响范围

### 正面影响
1. **提高账号利用率**：混充模式下可以更充分地利用所有可用账号
2. **减少闲置账号**：避免高优先级账号因房间绑定而被闲置
3. **提升兑换效率**：更快的账号选择，减少等待时间

### 注意事项
1. **绑定模式不受影响**：当 `bind_room = true` 时，仍然按照房间绑定逻辑选择账号
2. **向后兼容**：修复不影响现有的绑定模式功能
3. **监控建议**：建议监控混充模式下的账号使用情况，确保符合预期

## 部署建议

1. **测试环境验证**：先在测试环境验证修复效果
2. **监控指标**：部署后监控账号选择效率和利用率
3. **回滚准备**：保留原始代码，以便需要时快速回滚

## 总结

通过这次修复，混充模式下的账号选择逻辑更加合理，能够更好地利用高优先级账号，提高整体兑换效率。修复保持了向后兼容性，不影响现有的绑定模式功能。 