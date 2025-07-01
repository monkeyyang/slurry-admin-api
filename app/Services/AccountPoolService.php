<?php

namespace App\Services;

use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradeAccountLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

/**
 * 账号池管理服务
 * 
 * 功能：
 * 1. 维护按国家、金额、计划、群聊分组的Redis账号池
 * 2. 按余额从高到低排序，优先消费高余额账号
 * 3. 支持零间隔兑换和并发安全
 * 4. 实时同步账号状态变更
 * 
 * 池子命名规则：
 * - account_pool_ca_500_room1_plan1: 绑定计划和群聊的账号池
 * - account_pool_ca_500_room1: 绑定群聊但无计划的账号池
 * - account_pool_ca_500: 通用账号池
 */
class AccountPoolService
{
    private $redis;
    private const POOL_PREFIX = 'account_pool_';
    private const LOCK_PREFIX = 'account_lock_';
    private const LOCK_TTL = 300; // 5分钟锁定时间
    private const POOL_TTL = 3600; // 1小时池子过期时间

    public function __construct()
    {
        $this->redis = Redis::connection();
    }

    /**
     * 获取可用账号（原子操作）
     * 
     * @param string $country 国家代码
     * @param float $amount 金额
     * @param int|null $planId 计划ID
     * @param int|null $roomId 群聊ID
     * @param float $exchangeRate 汇率
     * @return array|null 账号信息或null
     */
    public function getAvailableAccount(string $country, float $amount, ?int $planId = null, ?int $roomId = null, float $exchangeRate = 1.0): ?array
    {
        // 构建池子查找优先级：优先级从高到低
        $poolKeys = $this->buildPoolKeysByPriority($country, $amount, $planId, $roomId);
        
        foreach ($poolKeys as $poolKey) {
            $accountData = $this->getAccountFromPool($poolKey, $amount, $exchangeRate, $planId);
            if ($accountData) {
                return $accountData;
            }
        }

        // 所有池子都没有可用账号，尝试重建主池子
        Log::info("所有池子无可用账号，尝试重建", [
            'country' => $country,
            'amount' => $amount,
            'plan_id' => $planId,
            'room_id' => $roomId
        ]);

        $this->refreshPool($country, $amount, $planId, $roomId);
        $primaryPoolKey = $this->buildPoolKey($country, $amount, $planId, $roomId);
        
        return $this->getAccountFromPool($primaryPoolKey, $amount, $exchangeRate, $planId);
    }

    /**
     * 从指定池子中获取账号
     */
    private function getAccountFromPool(string $poolKey, float $amount, float $exchangeRate, ?int $planId): ?array
    {
        $lockKey = $this->LOCK_PREFIX . md5($poolKey);
        $lockValue = uniqid();

        // 获取分布式锁
        if (!$this->redis->set($lockKey, $lockValue, 'EX', 10, 'NX')) {
            Log::debug("获取池子锁失败", ['pool_key' => $poolKey]);
            return null;
        }

        try {
            // 从池子中获取余额最高的账号（ZSET按score降序）
            $accountData = $this->redis->zpopmax($poolKey);
            
            if (empty($accountData)) {
                return null;
            }

            $accountId = array_key_first($accountData);
            $balance = $accountData[$accountId];

            // 验证账号是否仍然可用
            $account = ItunesTradeAccount::find($accountId);
            if (!$account || !$this->isAccountAvailable($account, $amount, $exchangeRate, $planId)) {
                Log::warning("账号不可用，从池子中移除", [
                    'account_id' => $accountId,
                    'pool_key' => $poolKey
                ]);
                
                // 递归获取下一个账号
                return $this->getAccountFromPool($poolKey, $amount, $exchangeRate, $planId);
            }

            // 为账号加锁防止重复使用
            $accountLockKey = $this->LOCK_PREFIX . 'account_' . $accountId;
            $this->redis->setex($accountLockKey, self::LOCK_TTL, time());

            Log::info("成功获取账号", [
                'account_id' => $accountId,
                'account_email' => $account->account,
                'balance' => $balance,
                'pool_key' => $poolKey
            ]);

            return [
                'id' => $accountId,
                'account' => $account->account,
                'balance' => $balance,
                'country_code' => $account->country_code,
                'plan_id' => $account->plan_id,
                'current_plan_day' => $account->current_plan_day,
                'lock_key' => $accountLockKey
            ];

        } finally {
            // 释放池子锁
            if ($this->redis->get($lockKey) === $lockValue) {
                $this->redis->del($lockKey);
            }
        }
    }

    /**
     * 构建池子查找优先级
     */
    private function buildPoolKeysByPriority(string $country, float $amount, ?int $planId, ?int $roomId): array
    {
        $poolKeys = [];

        // 优先级1：完全匹配（国家+金额+群聊+计划）
        if ($planId && $roomId) {
            $poolKeys[] = $this->buildPoolKey($country, $amount, $planId, $roomId);
        }

        // 优先级2：匹配国家+金额+群聊（无计划限制）
        if ($roomId) {
            $poolKeys[] = $this->buildPoolKey($country, $amount, null, $roomId);
        }

        // 优先级3：匹配国家+金额+计划（无群聊限制）
        if ($planId) {
            $poolKeys[] = $this->buildPoolKey($country, $amount, $planId, null);
        }

        // 优先级4：通用池（只匹配国家+金额）
        $poolKeys[] = $this->buildPoolKey($country, $amount, null, null);

        return array_unique($poolKeys);
    }

    /**
     * 释放账号锁
     */
    public function releaseAccountLock(string $lockKey): void
    {
        $this->redis->del($lockKey);
    }

    /**
     * 构建池子key
     */
    private function buildPoolKey(string $country, float $amount, ?int $planId = null, ?int $roomId = null): string
    {
        $key = self::POOL_PREFIX . strtolower($country) . '_' . intval($amount);
        
        if ($roomId) {
            $key .= '_room' . $roomId;
        }
        
        if ($planId) {
            $key .= '_plan' . $planId;
        }
        
        return $key;
    }

    /**
     * 刷新指定池子
     */
    public function refreshPool(string $country, float $amount, ?int $planId = null, ?int $roomId = null): void
    {
        $poolKey = $this->buildPoolKey($country, $amount, $planId, $roomId);
        
        Log::info("开始刷新账号池", [
            'pool_key' => $poolKey,
            'country' => $country,
            'amount' => $amount,
            'plan_id' => $planId,
            'room_id' => $roomId
        ]);

        // 清空现有池子
        $this->redis->del($poolKey);

        // 构建查询条件
        $query = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->where('amount', '>', 0)
            ->where('country_code', strtoupper($country));

        // 计划过滤
        if ($planId) {
            $query->where('plan_id', $planId);
        } else {
            $query->whereNull('plan_id');
        }

        // 如果指定了群聊，添加相关过滤（这里需要根据实际业务逻辑调整）
        if ($roomId) {
            // 假设有wechat_room_id字段或关联表
            // $query->where('wechat_room_id', $roomId);
        }

        $accounts = $query->get();

        $addedCount = 0;
        foreach ($accounts as $account) {
            if ($this->isAccountAvailable($account, $amount, 1.0, $planId)) {
                // 使用余额作为score，Redis ZSET会按score排序
                $this->redis->zadd($poolKey, $account->amount, $account->id);
                $addedCount++;
            }
        }

        // 设置池子过期时间
        $this->redis->expire($poolKey, self::POOL_TTL);

        Log::info("账号池刷新完成", [
            'pool_key' => $poolKey,
            'total_accounts' => $accounts->count(),
            'added_count' => $addedCount
        ]);
    }

    /**
     * 检查账号是否可用
     */
    private function isAccountAvailable(ItunesTradeAccount $account, float $requiredAmount, float $exchangeRate, ?int $planId = null): bool
    {
        // 1. 基础状态检查
        if ($account->status !== ItunesTradeAccount::STATUS_PROCESSING ||
            $account->login_status !== ItunesTradeAccount::STATUS_LOGIN_ACTIVE ||
            $account->amount <= 0) {
            return false;
        }

        // 2. 账号是否被锁定
        $accountLockKey = $this->LOCK_PREFIX . 'account_' . $account->id;
        if ($this->redis->exists($accountLockKey)) {
            return false;
        }

        // 3. 余额检查（考虑汇率）
        $requiredBalance = $requiredAmount / $exchangeRate;
        if ($account->amount < $requiredBalance) {
            return false;
        }

        // 4. 计划相关检查
        if ($planId && $account->plan_id) {
            if (!$this->checkPlanAvailability($account)) {
                return false;
            }
        }

        // 5. 兑换间隔检查（支持零间隔）
        if (!$this->checkExchangeInterval($account)) {
            return false;
        }

        return true;
    }

    /**
     * 检查计划可用性
     */
    private function checkPlanAvailability(ItunesTradeAccount $account): bool
    {
        if (!$account->plan) {
            return false;
        }

        $currentDay = $account->current_plan_day ?? 1;

        // 检查当日计划是否已完成
        $dailyAmount = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('day', $currentDay)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->sum('amount');

        $dailyAmounts = $account->plan->daily_amounts ?? [];
        $dailyLimit = $dailyAmounts[$currentDay - 1] ?? 0;

        // 如果当日已完成，不可用
        if ($dailyAmount >= $dailyLimit) {
            return false;
        }

        // 检查总目标是否已达成
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        $currentTotalAmount = $lastSuccessLog ? $lastSuccessLog->after_amount : 0;
        
        return $currentTotalAmount < $account->plan->total_amount;
    }

    /**
     * 检查兑换间隔
     */
    private function checkExchangeInterval(ItunesTradeAccount $account): bool
    {
        // 如果没有计划或间隔为0，直接可用
        if (!$account->plan || ($account->plan->exchange_interval ?? 0) == 0) {
            return true;
        }

        // 查找最后一次成功兑换时间
        $lastSuccessLog = ItunesTradeAccountLog::where('account_id', $account->id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->orderBy('exchange_time', 'desc')
            ->first();

        if (!$lastSuccessLog) {
            return true;
        }

        $lastExchangeTime = Carbon::parse($lastSuccessLog->exchange_time);
        $intervalMinutes = $lastExchangeTime->diffInMinutes(now());
        $requiredInterval = $account->plan->exchange_interval ?? 5;

        return $intervalMinutes >= $requiredInterval;
    }

    /**
     * 批量刷新所有相关池子
     */
    public function refreshAllPools(): void
    {
        Log::info("开始批量刷新所有账号池");

        // 获取所有活跃的国家和金额组合
        $combinations = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', ItunesTradeAccount::STATUS_LOGIN_ACTIVE)
            ->where('amount', '>', 0)
            ->select('country_code', 'plan_id')
            ->distinct()
            ->get();

        // 常见金额池子
        $amounts = [50, 100, 200, 500, 1000];

        foreach ($combinations as $combination) {
            foreach ($amounts as $amount) {
                // 刷新有计划的池子
                if ($combination->plan_id) {
                    $this->refreshPool($combination->country_code, $amount, $combination->plan_id);
                }
                
                // 刷新无计划的池子
                $this->refreshPool($combination->country_code, $amount);
            }
        }

        Log::info("所有账号池刷新完成");
    }

    /**
     * 从所有相关池子中移除账号
     */
    public function removeAccountFromPools(int $accountId): void
    {
        // 获取所有可能的池子keys
        $pattern = self::POOL_PREFIX . '*';
        $keys = $this->redis->keys($pattern);

        $removedCount = 0;
        foreach ($keys as $poolKey) {
            if ($this->redis->zrem($poolKey, $accountId)) {
                $removedCount++;
            }
        }

        Log::info("从账号池中移除账号", [
            'account_id' => $accountId,
            'removed_from_pools' => $removedCount
        ]);
    }

    /**
     * 更新账号在池子中的余额
     */
    public function updateAccountBalance(int $accountId, float $newBalance): void
    {
        // 获取所有包含该账号的池子
        $pattern = self::POOL_PREFIX . '*';
        $keys = $this->redis->keys($pattern);

        $updatedCount = 0;
        foreach ($keys as $poolKey) {
            if ($this->redis->zscore($poolKey, $accountId) !== false) {
                $this->redis->zadd($poolKey, $newBalance, $accountId);
                $updatedCount++;
            }
        }

        Log::info("更新账号池中的余额", [
            'account_id' => $accountId,
            'new_balance' => $newBalance,
            'updated_pools' => $updatedCount
        ]);
    }

    /**
     * 获取池子统计信息
     */
    public function getPoolStats(): array
    {
        $pattern = self::POOL_PREFIX . '*';
        $keys = $this->redis->keys($pattern);

        $stats = [];
        foreach ($keys as $poolKey) {
            $count = $this->redis->zcard($poolKey);
            $topAccount = $count > 0 ? $this->redis->zrevrange($poolKey, 0, 0, 'WITHSCORES') : [];
            $stats[$poolKey] = [
                'count' => $count,
                'top_balance' => !empty($topAccount) ? array_values($topAccount)[0] : 0
            ];
        }

        return $stats;
    }

    /**
     * 清理过期和无效的池子
     */
    public function cleanupPools(): void
    {
        $pattern = self::POOL_PREFIX . '*';
        $keys = $this->redis->keys($pattern);

        $cleanedPools = 0;
        foreach ($keys as $poolKey) {
            $count = $this->redis->zcard($poolKey);
            if ($count == 0) {
                $this->redis->del($poolKey);
                $cleanedPools++;
            }
        }

        Log::info("清理账号池完成", ['cleaned_pools' => $cleanedPools]);
    }

    /**
     * 维护账号池（主要方法）
     */
    public function maintainPools(array $options = []): array
    {
        $startTime = microtime(true);
        $result = [
            'processed_accounts' => 0,
            'created_pools' => 0,
            'updated_pools' => 0,
            'cleaned_pools' => 0,
            'added_accounts' => 0,
            'removed_accounts' => 0,
            'errors' => [],
            'warnings' => []
        ];

        try {
            // 强制重建所有池子
            if ($options['force'] ?? false) {
                $this->clearAllPools();
                $result['cleaned_pools'] = 1;
            }

            // 刷新所有相关池子
            $this->refreshAllPools();
            
            // 清理空池子
            $this->cleanupPools();

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            Log::info('账号池维护完成', [
                'result' => $result,
                'execution_time_ms' => $executionTime
            ]);

        } catch (\Exception $e) {
            $result['errors'][] = "维护过程异常: " . $e->getMessage();
            Log::error('账号池维护异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $result;
    }

    /**
     * 清空所有池子
     */
    private function clearAllPools(): void
    {
        $pattern = self::POOL_PREFIX . '*';
        $keys = $this->redis->keys($pattern);
        
        foreach ($keys as $key) {
            $this->redis->del($key);
        }
        
        Log::info("已清空所有账号池", ['count' => count($keys)]);
    }
} 