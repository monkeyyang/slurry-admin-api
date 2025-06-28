<?php

namespace App\Console\Commands;

use App\Models\ItunesTradeAccountLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

class CleanupPendingRecords extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cleanup:pending-records 
                            {--timeout=10 : 超时阈值（分钟）}
                            {--dry-run : 预览模式，不实际修改数据}
                            {--batch-size=100 : 批量处理大小}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理超时的pending兑换记录，防止记录卡住';

    private int $timeoutMinutes;
    private bool $dryRun;
    private int $batchSize;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->timeoutMinutes = (int)$this->option('timeout');
        $this->dryRun = $this->option('dry-run');
        $this->batchSize = (int)$this->option('batch-size');

        $this->getLogger()->info("开始清理pending记录", [
            'timeout_minutes' => $this->timeoutMinutes,
            'dry_run' => $this->dryRun,
            'batch_size' => $this->batchSize
        ]);

        $this->info("=== 清理超时的Pending兑换记录 ===");
        $this->info("超时阈值: {$this->timeoutMinutes} 分钟");
        $this->info("模式: " . ($this->dryRun ? "预览模式" : "清理模式"));

        try {
            $result = $this->cleanupPendingRecords();
            
            $this->info("\n=== 清理完成 ===");
            $this->info("处理记录数: {$result['processed']}");
            $this->info("清理记录数: {$result['cleaned']}");
            $this->info("跳过记录数: {$result['skipped']}");
            $this->info("错误记录数: {$result['errors']}");

            if ($result['cleaned'] > 0) {
                $this->warn("\n建议运行账号状态处理命令:");
                $this->warn("php artisan itunes:process-accounts");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("清理过程发生错误: " . $e->getMessage());
            $this->getLogger()->error("清理pending记录失败", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * 清理超时的pending记录
     */
    private function cleanupPendingRecords(): array
    {
        $timeoutThreshold = Carbon::now()->subMinutes($this->timeoutMinutes);
        $processed = 0;
        $cleaned = 0;
        $skipped = 0;
        $errors = 0;

        // 分批处理，避免内存问题
        do {
            $pendingRecords = ItunesTradeAccountLog::where('status', ItunesTradeAccountLog::STATUS_PENDING)
                ->where('created_at', '<', $timeoutThreshold)
                ->with(['account'])
                ->orderBy('created_at', 'asc')
                ->limit($this->batchSize)
                ->get();

            if ($pendingRecords->isEmpty()) {
                break;
            }

            foreach ($pendingRecords as $record) {
                $processed++;
                try {
                    $action = $this->processRecord($record);
                    if ($action === 'cleaned') {
                        $cleaned++;
                    } elseif ($action === 'skipped') {
                        $skipped++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    $this->getLogger()->error("处理记录失败", [
                        'record_id' => $record->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // 避免无限循环
            if ($pendingRecords->count() < $this->batchSize) {
                break;
            }

        } while (true);

        return [
            'processed' => $processed,
            'cleaned' => $cleaned,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }

    /**
     * 处理单个记录
     */
    private function processRecord(ItunesTradeAccountLog $record): string
    {
        $timeoutMinutes = Carbon::now()->diffInMinutes($record->created_at);
        $accountInfo = $record->account ? $record->account->account : "账号{$record->account_id}";

        $this->line("处理记录 ID: {$record->id}");
        $this->line("  礼品卡: {$record->code}");
        $this->line("  账号: {$accountInfo}");
        $this->line("  创建时间: {$record->created_at}");
        $this->line("  超时时长: {$timeoutMinutes} 分钟");

        // 检查是否有相同礼品卡的其他记录
        $otherRecords = ItunesTradeAccountLog::where('code', $record->code)
            ->where('id', '!=', $record->id)
            ->orderBy('created_at', 'desc')
            ->get();

        if ($otherRecords->isNotEmpty()) {
            $latestRecord = $otherRecords->first();
            $this->line("  发现相同礼品卡的其他记录，最新状态: {$latestRecord->status}");

            if ($latestRecord->status === ItunesTradeAccountLog::STATUS_SUCCESS) {
                $this->line("  ⚠️  礼品卡已在其他记录中成功兑换，标记此记录为失败");
                $this->updateRecordStatus($record, '礼品卡已在其他记录中兑换');
                return 'cleaned';
            }
        }

        // 检查是否应该清理
        if ($this->shouldCleanRecord($record)) {
            $this->line("  🔄 标记为失败状态");
            $this->updateRecordStatus($record, "处理超时（{$timeoutMinutes}分钟）");
            return 'cleaned';
        } else {
            $this->line("  ⏳ 记录可能仍在处理中，跳过");
            return 'skipped';
        }
    }

    /**
     * 判断是否应该清理记录
     */
    private function shouldCleanRecord(ItunesTradeAccountLog $record): bool
    {
        // 如果没有batch_id，说明不是批量任务，超时后直接清理
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
        return $timeDiffHours >= 1; // 1小时后强制清理
    }

    /**
     * 更新记录状态
     */
    private function updateRecordStatus(ItunesTradeAccountLog $record, string $errorMessage): void
    {
        if ($this->dryRun) {
            $this->line("  [预览] 将更新状态为: failed，错误信息: {$errorMessage}");
            return;
        }

        $record->update([
            'status' => ItunesTradeAccountLog::STATUS_FAILED,
            'error_message' => $errorMessage
        ]);

        // 记录日志
        $this->getLogger()->info("清理pending记录", [
            'record_id' => $record->id,
            'code' => $record->code,
            'account_id' => $record->account_id,
            'old_status' => ItunesTradeAccountLog::STATUS_PENDING,
            'new_status' => ItunesTradeAccountLog::STATUS_FAILED,
            'error_message' => $errorMessage,
            'timeout_minutes' => Carbon::now()->diffInMinutes($record->created_at),
            'cleanup_method' => 'auto_cleanup_command'
        ]);
    }

    /**
     * 获取专用日志实例
     */
    private function getLogger(): LoggerInterface
    {
        return Log::channel('kernel_process_accounts');
    }
} 