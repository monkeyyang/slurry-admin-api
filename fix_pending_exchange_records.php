<?php

/**
 * 修复卡住的兑换记录脚本
 *
 * 问题分析：
 * 1. 兑换记录创建时状态为 'pending'（检查兑换代码）
 * 2. 正常流程：pending -> success/failed
 * 3. 异常情况：网络超时、API异常、进程意外终止等导致状态卡在pending
 * 4. 这些记录会阻止账号状态转换，因为系统认为还有待处理任务
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Models\ItunesTradeAccountLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

// 初始化Laravel应用
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

class PendingExchangeRecordsFixer
{
    public int   $timeoutMinutes = 10; // 超过10分钟的pending记录认为是异常
    private bool $dryRun         = false;

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * 修复卡住的pending记录
     */
    public function fixPendingRecords(): array
    {
        echo "=== 修复卡住的兑换记录 ===\n";
        echo "超时阈值: {$this->timeoutMinutes} 分钟\n";
        echo "模式: " . ($this->dryRun ? "预览模式（不会实际修改）" : "修复模式") . "\n\n";

        // 查找超时的pending记录
        $timeoutThreshold = Carbon::now()->subMinutes($this->timeoutMinutes);

        $pendingRecords = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_PENDING)
            ->where('created_at', '<', $timeoutThreshold)
            ->with(['account'])
            ->orderBy('created_at', 'asc')
            ->get();

        echo "发现 {$pendingRecords->count()} 条超时的pending记录\n\n";

        if ($pendingRecords->isEmpty()) {
            echo "✓ 没有发现异常的pending记录\n";
            return ['fixed' => 0, 'errors' => 0];
        }

        $fixed = 0;
        $errors = 0;

        foreach ($pendingRecords as $record) {
            try {
                $this->processPendingRecord($record);
                $fixed++;
            } catch (Exception $e) {
                echo "❌ 处理记录 {$record->id} 失败: " . $e->getMessage() . "\n";
                $errors++;
            }
        }

        echo "\n=== 处理完成 ===\n";
        echo "修复记录数: {$fixed}\n";
        echo "错误记录数: {$errors}\n";

        return ['fixed' => $fixed, 'errors' => $errors];
    }

    /**
     * 处理单个pending记录
     */
    private function processPendingRecord(ItunesTradeAccountLog $record): void
    {
        $timeoutMinutes = Carbon::now()->diffInMinutes($record->created_at);
        $accountInfo = $record->account ? $record->account->account : "账号{$record->account_id}";

        echo "处理记录 ID: {$record->id}\n";
        echo "  礼品卡: {$record->code}\n";
        echo "  账号: {$accountInfo}\n";
        echo "  创建时间: {$record->created_at}\n";
        echo "  超时时长: {$timeoutMinutes} 分钟\n";

        // 检查是否有相同礼品卡的其他记录
        $otherRecords = ItunesTradeAccountLog::where('code', $record->code)
            ->where('id', '!=', $record->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($otherRecords->isNotEmpty()) {
            $latestRecord = $otherRecords->first();
            echo "  发现相同礼品卡的其他记录，最新状态: {$latestRecord->status}\n";

            if ($latestRecord->status === ItunesTradeAccountLog::STATUS_SUCCESS) {
                echo "  ⚠️  礼品卡已在其他记录中成功兑换，标记此记录为失败\n";
                $this->updateRecordStatus($record, ItunesTradeAccountLog::STATUS_FAILED, '礼品卡已在其他记录中兑换');
                return;
            }
        }

        // 检查兑换任务是否真的超时
        if ($this->shouldMarkAsFailed($record)) {
            echo "  🔄 标记为失败状态\n";
            $this->updateRecordStatus($record, ItunesTradeAccountLog::STATUS_FAILED, "处理超时（{$timeoutMinutes}分钟）");
        } else {
            echo "  ⏳ 记录可能仍在处理中，跳过\n";
        }

        echo "\n";
    }

    /**
     * 判断是否应该标记为失败
     */
    private function shouldMarkAsFailed(ItunesTradeAccountLog $record): bool
    {
        // 如果没有batch_id，说明不是批量任务，超时后直接标记为失败
        if (empty($record->batch_id)) {
            return true;
        }

        // 如果有batch_id，检查是否有同批次的其他成功记录
        $sameBatchSuccessCount = ItunesTradeAccountLog::where('batch_id', $record->batch_id)
            ->where('status', ItunesTradeAccountLog::STATUS_SUCCESS)
            ->count();

        // 如果同批次有成功记录，说明API是正常的，这个记录可能真的卡住了
        if ($sameBatchSuccessCount > 0) {
            return true;
        }

        // 如果同批次都没有成功记录，可能是整批任务都有问题，需要更谨慎
        $timeDiffHours = Carbon::now()->diffInHours($record->created_at);
        return $timeDiffHours >= 1; // 1小时后强制标记为失败
    }

    /**
     * 更新记录状态
     */
    private function updateRecordStatus(ItunesTradeAccountLog $record, string $status, string $errorMessage = ''): void
    {
        if ($this->dryRun) {
            echo "  [预览] 将更新状态为: {$status}";
            if ($errorMessage) {
                echo "，错误信息: {$errorMessage}";
            }
            echo "\n";
            return;
        }

        $updateData = ['status' => $status];
        if ($status === ItunesTradeAccountLog::STATUS_FAILED && $errorMessage) {
            $updateData['error_message'] = $errorMessage;
        }

        $record->update($updateData);

        // 记录日志
        Log::channel('kernel_process_accounts')->info("修复pending记录", [
            'record_id' => $record->id,
            'code' => $record->code,
            'account_id' => $record->account_id,
            'old_status' => ItunesTradeAccountLog::STATUS_PENDING,
            'new_status' => $status,
            'error_message' => $errorMessage,
            'timeout_minutes' => Carbon::now()->diffInMinutes($record->created_at)
        ]);
    }

    /**
     * 显示当前pending记录统计
     */
    public function showPendingStats(): void
    {
        echo "=== Pending记录统计 ===\n";

        $totalPending = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_PENDING)->count();
        echo "总pending记录数: {$totalPending}\n";

        if ($totalPending == 0) {
            echo "✓ 没有pending记录\n\n";
            return;
        }

        // 按时间分组统计
        $timeRanges = [
            '5分钟内' => 5,
            '10分钟内' => 10,
            '30分钟内' => 30,
            '1小时内' => 60,
            '6小时内' => 360,
            '24小时内' => 1440,
            '24小时以上' => 999999
        ];

        $currentTime = Carbon::now();
        $lastThreshold = 0;

        foreach ($timeRanges as $label => $minutes) {
            $startTime = $currentTime->copy()->subMinutes($minutes);
            $endTime = $lastThreshold > 0 ? $currentTime->copy()->subMinutes($lastThreshold) : $currentTime;

            $count = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_PENDING)
                ->whereBetween('created_at', [$startTime, $endTime])
                ->count();

            if ($count > 0) {
                $timeoutIndicator = $minutes > $this->timeoutMinutes ? " ⚠️" : "";
                echo "{$label}: {$count} 条{$timeoutIndicator}\n";
            }

            $lastThreshold = $minutes;
        }

        echo "\n";
    }

    /**
     * 显示受影响的账号
     */
    public function showAffectedAccounts(): void
    {
        echo "=== 受影响的账号 ===\n";

        $timeoutThreshold = Carbon::now()->subMinutes($this->timeoutMinutes);

        $affectedAccounts = DB::table('itunes_trade_account_logs as logs')
            ->join('itunes_trade_accounts as accounts', 'logs.account_id', '=', 'accounts.id')
            ->where('logs.status', ItunesTradeAccountLog::STATUS_PENDING)
            ->where('logs.created_at', '<', $timeoutThreshold)
            ->select('accounts.id', 'accounts.account', 'accounts.status',
                    DB::raw('COUNT(logs.id) as pending_count'),
                    DB::raw('MIN(logs.created_at) as oldest_pending'))
            ->groupBy('accounts.id', 'accounts.account', 'accounts.status')
            ->orderBy('pending_count', 'desc')
            ->get();

        if ($affectedAccounts->isEmpty()) {
            echo "✓ 没有受影响的账号\n\n";
            return;
        }

        echo "发现 {$affectedAccounts->count()} 个账号受到影响:\n\n";

        foreach ($affectedAccounts as $account) {
            $oldestTime = Carbon::parse($account->oldest_pending);
            $hoursSinceOldest = Carbon::now()->diffInHours($oldestTime);

            echo "账号: {$account->account}\n";
            echo "  当前状态: {$account->status}\n";
            echo "  pending记录数: {$account->pending_count}\n";
            echo "  最早pending时间: {$account->oldest_pending} ({$hoursSinceOldest}小时前)\n\n";
        }
    }
}

// 主程序
function main(): void
{
    $options = getopt("", ["dry-run", "help", "stats", "fix", "timeout:"]);

    if (isset($options['help'])) {
        showHelp();
        return;
    }

    $dryRun = isset($options['dry-run']);
    $timeoutMinutes = isset($options['timeout']) ? (int)$options['timeout'] : 10;

    $fixer = new PendingExchangeRecordsFixer($dryRun);
    $fixer->timeoutMinutes = $timeoutMinutes;

    if (isset($options['stats'])) {
        $fixer->showPendingStats();
        $fixer->showAffectedAccounts();
        return;
    }

    if (isset($options['fix'])) {
        $result = $fixer->fixPendingRecords();

        if (!$dryRun && $result['fixed'] > 0) {
            echo "\n建议运行账号状态处理命令:\n";
            echo "php artisan itunes:process-accounts\n";
        }
        return;
    }

    // 默认显示统计信息
    $fixer->showPendingStats();
    $fixer->showAffectedAccounts();

    echo "使用说明:\n";
    echo "  --stats     显示详细统计\n";
    echo "  --fix       修复异常记录\n";
    echo "  --dry-run   预览模式（与--fix配合使用）\n";
    echo "  --timeout=N 设置超时阈值（分钟，默认10）\n";
    echo "  --help      显示帮助\n";
}

function showHelp(): void
{
    echo "修复卡住的兑换记录脚本\n\n";
    echo "用法: php fix_pending_exchange_records.php [选项]\n\n";
    echo "选项:\n";
    echo "  --stats          显示pending记录统计信息\n";
    echo "  --fix            修复超时的pending记录\n";
    echo "  --dry-run        预览模式，不实际修改数据（与--fix配合使用）\n";
    echo "  --timeout=N      设置超时阈值（分钟），默认10分钟\n";
    echo "  --help           显示此帮助信息\n\n";
    echo "示例:\n";
    echo "  php fix_pending_exchange_records.php --stats\n";
    echo "  php fix_pending_exchange_records.php --fix --dry-run\n";
    echo "  php fix_pending_exchange_records.php --fix --timeout=30\n";
    echo "  php fix_pending_exchange_records.php --fix\n\n";
    echo "问题说明:\n";
    echo "  兑换记录在创建时状态为'pending'（检查兑换代码），\n";
    echo "  正常情况下会很快变为'success'或'failed'。\n";
    echo "  如果由于网络超时、API异常等原因卡在pending状态，\n";
    echo "  会阻止账号状态转换，此脚本用于修复这类问题。\n";
}

// 异常处理
try {
    main();
} catch (Exception $e) {
    echo "脚本执行失败: " . $e->getMessage() . "\n";
    echo "堆栈跟踪:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
