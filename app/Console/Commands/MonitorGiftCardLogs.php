<?php

namespace App\Console\Commands;

use App\Services\GiftCardLogMonitorService;
use Illuminate\Console\Command;

class MonitorGiftCardLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'giftcard:monitor-logs 
                            {--lines=100 : 显示最近的日志行数}
                            {--search= : 搜索关键词}
                            {--level= : 过滤日志级别 (ERROR|WARNING|INFO|DEBUG)}
                            {--stats : 显示统计信息}
                            {--realtime : 实时监控模式}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '监控礼品卡兑换日志';

    protected GiftCardLogMonitorService $logMonitorService;

    public function __construct(GiftCardLogMonitorService $logMonitorService)
    {
        parent::__construct();
        $this->logMonitorService = $logMonitorService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🎁 礼品卡日志监控工具');
        $this->info('==================');

        // 显示统计信息
        if ($this->option('stats')) {
            $this->showStats();
            return 0;
        }

        // 搜索模式
        if ($this->option('search')) {
            $this->searchLogs();
            return 0;
        }

        // 实时监控模式
        if ($this->option('realtime')) {
            $this->startRealTimeMonitoring();
            return 0;
        }

        // 默认显示最新日志
        $this->showLatestLogs();
        return 0;
    }

    /**
     * 显示统计信息
     */
    protected function showStats(): void
    {
        $this->info('📊 日志统计信息');
        $this->line('');

        try {
            $stats = $this->logMonitorService->getLogStats();

            $this->table(
                ['项目', '数量'],
                [
                    ['总日志数', $stats['total']],
                    ['错误日志', $stats['levels']['ERROR']],
                    ['警告日志', $stats['levels']['WARNING']],
                    ['信息日志', $stats['levels']['INFO']],
                    ['调试日志', $stats['levels']['DEBUG']],
                    ['最后更新', $stats['last_update']],
                ]
            );

            if (!empty($stats['recent_errors'])) {
                $this->line('');
                $this->error('🚨 最近的错误:');
                foreach ($stats['recent_errors'] as $error) {
                    $this->line("  [{$error['timestamp']}] {$error['message']}");
                }
            }

        } catch (\Exception $e) {
            $this->error('获取统计信息失败: ' . $e->getMessage());
        }
    }

    /**
     * 搜索日志
     */
    protected function searchLogs(): void
    {
        $keyword = $this->option('search');
        $level = $this->option('level');

        $this->info("🔍 搜索日志: {$keyword}" . ($level ? " (级别: {$level})" : ''));
        $this->line('');

        try {
            $results = $this->logMonitorService->searchLogs($keyword, $level, 50);

            if (empty($results)) {
                $this->warn('未找到匹配的日志');
                return;
            }

            $this->info("找到 " . count($results) . " 条匹配的日志:");
            $this->line('');

            foreach ($results as $log) {
                $this->displayLogEntry($log);
            }

        } catch (\Exception $e) {
            $this->error('搜索日志失败: ' . $e->getMessage());
        }
    }

    /**
     * 显示最新日志
     */
    protected function showLatestLogs(): void
    {
        $lines = (int)$this->option('lines');
        $level = $this->option('level');

        $this->info("📋 最新 {$lines} 条日志" . ($level ? " (级别: {$level})" : ''));
        $this->line('');

        try {
            $logs = $this->logMonitorService->getLatestLogs($lines);

            if (empty($logs)) {
                $this->warn('暂无日志数据');
                return;
            }

            // 按级别过滤
            if ($level) {
                $logs = array_filter($logs, function ($log) use ($level) {
                    return $log['level'] === strtoupper($level);
                });
            }

            foreach ($logs as $log) {
                $this->displayLogEntry($log);
            }

            $this->line('');
            $this->info('提示: 使用 --realtime 参数启动实时监控模式');

        } catch (\Exception $e) {
            $this->error('获取日志失败: ' . $e->getMessage());
        }
    }

    /**
     * 启动实时监控
     */
    protected function startRealTimeMonitoring(): void
    {
        $this->info('🔄 启动实时日志监控');
        $this->info('按 Ctrl+C 停止监控');
        $this->line('');

        try {
            $this->logMonitorService->startRealTimeMonitoring();
        } catch (\Exception $e) {
            $this->error('实时监控失败: ' . $e->getMessage());
        }
    }

    /**
     * 显示单条日志
     */
    protected function displayLogEntry(array $log): void
    {
        $levelColors = [
            'ERROR' => 'red',
            'WARNING' => 'yellow',
            'INFO' => 'green',
            'DEBUG' => 'cyan',
        ];

        $color = $levelColors[$log['level']] ?? 'white';
        
        $this->line(sprintf(
            '<fg=%s>[%s] %s: %s</>',
            $color,
            $log['timestamp'],
            $log['level'],
            $log['message']
        ));

        // 显示上下文信息
        if (!empty($log['context'])) {
            $contextStr = json_encode($log['context'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $this->line('<fg=gray>  上下文: ' . $contextStr . '</>');
        }
    }
} 