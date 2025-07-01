<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ItunesTradeAccount;
use App\Models\ItunesTradePlan;
use App\Models\ItunesTradeRate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class SimpleAccountPoolMaintainer extends Command
{
    protected $signature = 'accounts:maintain-simple-pools 
                            {--dry-run : 只显示统计信息，不执行实际更新}
                            {--clear : 清空所有池重新构建}';

    protected $description = '维护简化版Redis账号池';

    // Redis键前缀
    const POOL_PREFIX = 'accounts_pool';
    const FALLBACK_KEY = 'accounts_fallback:0';
    const COMMON_AMOUNTS = [50, 100, 150, 200,250,300,350,400,450, 500];
    const POOL_TTL = 300; // 5分钟

    private $redis;

    public function __construct()
    {
        parent::__construct();
        $this->redis = Redis::connection();
    }

    public function handle()
    {
        $startTime = microtime(true);
        $isDryRun = $this->option('dry-run');
        $shouldClear = $this->option('clear');

        $this->info('开始维护账号池...');

        try {
            // 显示维护前统计
            $this->showPoolStatistics('维护前');

            if ($shouldClear && !$isDryRun) {
                $this->clearAllPools();
                $this->info('已清空所有账号池');
            }

            // 维护正常账号池
            $result = $this->maintainNormalPools($isDryRun);

            // 维护兜底账号池
            $fallbackResult = $this->maintainFallbackPool($isDryRun);

            // 显示结果
            $this->displayResult($result, $fallbackResult);

            // 显示维护后统计
            if (!$isDryRun) {
                $this->showPoolStatistics('维护后');
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("维护完成，耗时: {$executionTime}ms");

            return 0;

        } catch (\Exception $e) {
            $this->error('维护失败: ' . $e->getMessage());
            Log::error('账号池维护异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 维护正常账号池
     */
    private function maintainNormalPools(bool $isDryRun): array
    {
        $result = [
            'processed_accounts' => 0,
            'created_pools' => 0,
            'added_accounts' => 0
        ];

        // 获取活跃账号
        $accounts = ItunesTradeAccount::with(['plan.rate'])
            ->where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', 'valid')
            ->where('amount', '>', 0)
            ->get();

        $result['processed_accounts'] = $accounts->count();

        foreach ($accounts as $account) {
            $acceptableAmounts = $this->calculateAcceptableAmounts($account);

            foreach ($acceptableAmounts as $amount) {
                $poolKeys = $this->generatePoolKeys($amount, $account);

                foreach ($poolKeys as $poolKey) {
                    if (!$isDryRun) {
                        $this->addAccountToPool($poolKey, $account);
                    }
                    $result['added_accounts']++;
                }
            }
        }

        return $result;
    }

    /**
     * 维护兜底账号池
     */
    private function maintainFallbackPool(bool $isDryRun): array
    {
        $result = [
            'fallback_accounts' => 0
        ];

        $fallbackAccounts = ItunesTradeAccount::where('status', ItunesTradeAccount::STATUS_PROCESSING)
            ->where('login_status', 'valid')
            ->where('amount', 0)
            ->get();

        $result['fallback_accounts'] = $fallbackAccounts->count();

        if (!$isDryRun) {
            // 清空现有兜底池
            $this->redis->del(self::FALLBACK_KEY);

            // 添加兜底账号
            foreach ($fallbackAccounts as $account) {
                $accountData = [
                    'id' => $account->id,
                    'account' => $account->account,
                    'amount' => 0,
                    'plan_id' => $account->plan_id,
                    'room_id' => $account->room_id,
                    'updated_at' => $account->updated_at->timestamp
                ];

                $this->redis->zadd(self::FALLBACK_KEY, time(), json_encode($accountData));
            }

            if ($fallbackAccounts->count() > 0) {
                $this->redis->expire(self::FALLBACK_KEY, self::POOL_TTL);
            }
        }

        return $result;
    }

    /**
     * 计算账号可接受的面额
     */
    private function calculateAcceptableAmounts(ItunesTradeAccount $account): array
    {
        $currentBalance = $account->amount;
        $plan = $account->plan;

        if (!$plan || !$plan->rate) {
            // 无计划信息，使用通用逻辑
            return $this->getGenericAcceptableAmounts($currentBalance);
        }

        $rate = $plan->rate;
        $remainingCapacity = $plan->total_amount - $currentBalance;

        switch ($rate->amount_constraint) {
            case ItunesTradeRate::AMOUNT_CONSTRAINT_MULTIPLE:
                return $this->getMultipleConstraintAmounts($rate, $remainingCapacity);

            case ItunesTradeRate::AMOUNT_CONSTRAINT_FIXED:
                return $this->getFixedConstraintAmounts($rate, $remainingCapacity);

            case ItunesTradeRate::AMOUNT_CONSTRAINT_ALL:
            default:
                return $this->getGenericAcceptableAmounts($remainingCapacity);
        }
    }

    /**
     * 获取倍数约束的可接受面额
     */
    private function getMultipleConstraintAmounts($rate, float $remainingCapacity): array
    {
        $amounts = [];
        $multipleBase = $rate->multiple_base ?? 50;
        $minAmount = $rate->min_amount ?? 150;

        foreach (self::COMMON_AMOUNTS as $amount) {
            if ($amount >= $minAmount && 
                $amount % $multipleBase == 0 && 
                $amount <= $remainingCapacity) {
                $amounts[] = $amount;
            }
        }

        return $amounts;
    }

    /**
     * 获取固定面额约束的可接受面额
     */
    private function getFixedConstraintAmounts($rate, float $remainingCapacity): array
    {
        $fixedAmounts = $rate->fixed_amounts ?? [];
        if (is_string($fixedAmounts)) {
            $fixedAmounts = json_decode($fixedAmounts, true) ?: [];
        }

        $amounts = [];
        foreach ($fixedAmounts as $amount) {
            $amount = (float)$amount;
            if ($amount <= $remainingCapacity) {
                $amounts[] = $amount;
            }
        }

        return $amounts;
    }

    /**
     * 获取通用可接受面额
     */
    private function getGenericAcceptableAmounts(float $remainingCapacity): array
    {
        $amounts = [];
        foreach (self::COMMON_AMOUNTS as $amount) {
            if ($amount <= $remainingCapacity) {
                $amounts[] = $amount;
            }
        }
        return $amounts;
    }

    /**
     * 生成池键
     */
    private function generatePoolKeys(float $amount, ItunesTradeAccount $account): array
    {
        $keys = [];
        $planId = $account->plan_id ?? '*';
        $roomId = $account->room_id ?? '*';

        // 精确匹配池
        if ($planId !== '*' && $roomId !== '*') {
            $keys[] = self::POOL_PREFIX . ":{$amount}:{$planId}:{$roomId}";
        }

        // 计划匹配池
        if ($planId !== '*') {
            $keys[] = self::POOL_PREFIX . ":{$amount}:{$planId}:*";
        }

        // 房间匹配池
        if ($roomId !== '*') {
            $keys[] = self::POOL_PREFIX . ":{$amount}:*:{$roomId}";
        }

        // 通用池
        $keys[] = self::POOL_PREFIX . ":{$amount}:*:*";

        return array_unique($keys);
    }

    /**
     * 将账号添加到池中
     */
    private function addAccountToPool(string $poolKey, ItunesTradeAccount $account): void
    {
        $accountData = [
            'id' => $account->id,
            'account' => $account->account,
            'amount' => $account->amount,
            'plan_id' => $account->plan_id,
            'room_id' => $account->room_id,
            'current_plan_day' => $account->current_plan_day,
            'updated_at' => $account->updated_at->timestamp
        ];

        // 使用账号余额作为分数
        $this->redis->zadd($poolKey, $account->amount, json_encode($accountData));
        $this->redis->expire($poolKey, self::POOL_TTL);

        // 限制池大小
        $this->redis->zremrangebyrank($poolKey, 0, -101);
    }

    /**
     * 清空所有池
     */
    private function clearAllPools(): void
    {
        $keys = $this->redis->keys(self::POOL_PREFIX . ':*');
        if (!empty($keys)) {
            $this->redis->del($keys);
        }
        $this->redis->del(self::FALLBACK_KEY);
    }

    /**
     * 显示池统计信息
     */
    private function showPoolStatistics(string $title): void
    {
        $this->info("\n=== {$title}统计 ===");

        $poolKeys = $this->redis->keys(self::POOL_PREFIX . ':*');
        $totalPools = count($poolKeys);
        $fallbackCount = $this->redis->zcard(self::FALLBACK_KEY);

        $this->table(['指标', '数值'], [
            ['账号池总数', $totalPools],
            ['兜底账号数', $fallbackCount],
        ]);

        // 显示面额分布
        $amountDistribution = [];
        foreach ($poolKeys as $poolKey) {
            $size = $this->redis->zcard($poolKey);
            if ($size > 0 && preg_match('/accounts_pool:(\d+(?:\.\d+)?):/', $poolKey, $matches)) {
                $amount = $matches[1];
                $amountDistribution[$amount] = ($amountDistribution[$amount] ?? 0) + $size;
            }
        }

        if (!empty($amountDistribution)) {
            $this->info("\n面额分布:");
            $amountData = [];
            foreach ($amountDistribution as $amount => $count) {
                $amountData[] = ["面额 {$amount}", $count];
            }
            $this->table(['面额', '账号数'], $amountData);
        }
    }

    /**
     * 显示维护结果
     */
    private function displayResult(array $result, array $fallbackResult): void
    {
        $this->info("\n=== 维护结果 ===");

        $this->table(['操作', '数量'], [
            ['处理的账号', $result['processed_accounts']],
            ['添加到池的账号', $result['added_accounts']],
            ['兜底账号', $fallbackResult['fallback_accounts']]
        ]);
    }
} 