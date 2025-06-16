<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class GiftCardLogMonitorService
{
    protected string $logPath;
    protected array $logLevels = ['DEBUG', 'INFO', 'WARNING', 'ERROR'];
    protected array $logColors = [
        'DEBUG' => 'debug',
        'INFO' => 'success', 
        'WARNING' => 'warning',
        'ERROR' => 'error'
    ];

    public function __construct()
    {
        $this->logPath = storage_path('logs');
    }

    /**
     * 获取今天的礼品卡日志文件路径
     */
    protected function getTodayLogFile(): string
    {
        $today = Carbon::now()->format('Y-m-d');
        return $this->logPath . "/gift_card_exchange-{$today}.log";
    }

    /**
     * 获取最新的日志条目
     */
    public function getLatestLogs(int $lines = 100): array
    {
        $logFile = $this->getTodayLogFile();
        
        if (!File::exists($logFile)) {
            return [];
        }

        // 读取文件最后N行
        $content = $this->tailFile($logFile, $lines);
        return $this->parseLogContent($content);
    }

    /**
     * 实时监控日志变化
     */
    public function startRealTimeMonitoring(): void
    {
        $logFile = $this->getTodayLogFile();
        $lastSize = File::exists($logFile) ? File::size($logFile) : 0;
        $lastPosition = $lastSize;

        echo "开始监控礼品卡日志: {$logFile}\n";
        echo "按 Ctrl+C 停止监控\n\n";

        while (true) {
            if (!File::exists($logFile)) {
                sleep(1);
                continue;
            }

            $currentSize = File::size($logFile);
            
            if ($currentSize > $lastPosition) {
                // 文件有新内容
                $newContent = $this->readFileFromPosition($logFile, $lastPosition);
                $newLogs = $this->parseLogContent($newContent);
                
                foreach ($newLogs as $log) {
                    $this->displayLogEntry($log);
                    $this->broadcastLogToWebSocket($log);
                }
                
                $lastPosition = $currentSize;
            }
            
            sleep(1); // 每秒检查一次
        }
    }

    /**
     * 从指定位置读取文件内容
     */
    protected function readFileFromPosition(string $filePath, int $position): string
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return '';
        }

        fseek($handle, $position);
        $content = fread($handle, filesize($filePath) - $position);
        fclose($handle);

        return $content ?: '';
    }

    /**
     * 读取文件最后N行
     */
    protected function tailFile(string $filePath, int $lines): string
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            return '';
        }

        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];

        while ($linecounter > 0) {
            $t = " ";
            while ($t != "\n") {
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }
            $linecounter--;
            if ($beginning) {
                rewind($handle);
            }
            $text[$lines - $linecounter - 1] = fgets($handle);
            if ($beginning) break;
        }
        fclose($handle);

        return implode('', array_reverse($text));
    }

    /**
     * 解析日志内容
     */
    protected function parseLogContent(string $content): array
    {
        if (empty($content)) {
            return [];
        }

        $lines = explode("\n", trim($content));
        $logs = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $logEntry = $this->parseLogLine($line);
            if ($logEntry) {
                $logs[] = $logEntry;
            }
        }

        return $logs;
    }

    /**
     * 解析单行日志
     */
    protected function parseLogLine(string $line): ?array
    {
        // Laravel日志格式: [2024-12-16 21:48:17] local.ERROR: 消息内容 {"context":"data"}
        $pattern = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] \w+\.(\w+): (.+?)(\s+\{.*\})?$/';
        
        if (preg_match($pattern, $line, $matches)) {
            $timestamp = $matches[1];
            $level = strtoupper($matches[2]);
            $message = trim($matches[3]);
            $context = isset($matches[4]) ? trim($matches[4]) : '';

            // 解析上下文JSON
            $contextData = [];
            if (!empty($context)) {
                $contextJson = json_decode($context, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $contextData = $contextJson;
                }
            }

            return [
                'id' => uniqid(),
                'timestamp' => $timestamp,
                'level' => $level,
                'message' => $message,
                'context' => $contextData,
                'raw_line' => $line,
                'color' => $this->logColors[$level] ?? 'default'
            ];
        }

        // 如果不匹配标准格式，作为普通消息处理
        return [
            'id' => uniqid(),
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'INFO',
            'message' => $line,
            'context' => [],
            'raw_line' => $line,
            'color' => 'default'
        ];
    }

    /**
     * 显示日志条目（控制台输出）
     */
    protected function displayLogEntry(array $log): void
    {
        $colors = [
            'ERROR' => "\033[31m",    // 红色
            'WARNING' => "\033[33m",  // 黄色
            'INFO' => "\033[32m",     // 绿色
            'DEBUG' => "\033[36m",    // 青色
            'reset' => "\033[0m"      // 重置
        ];

        $color = $colors[$log['level']] ?? $colors['reset'];
        $reset = $colors['reset'];

        echo sprintf(
            "%s[%s] %s%s%s: %s%s\n",
            $color,
            $log['timestamp'],
            $log['level'],
            $reset,
            $color,
            $log['message'],
            $reset
        );

        // 如果有上下文数据，也显示出来
        if (!empty($log['context'])) {
            echo "  上下文: " . json_encode($log['context'], JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    /**
     * 广播日志到WebSocket
     */
    protected function broadcastLogToWebSocket(array $log): void
    {
        try {
            $message = json_encode([
                'type' => 'gift_card_log',
                'data' => [
                    'id' => $log['id'],
                    'timestamp' => $log['timestamp'],
                    'level' => $log['level'],
                    'message' => $log['message'],
                    'context' => $log['context'],
                    'color' => $log['color']
                ]
            ]);

            // 推送到Redis列表供WebSocket服务器使用
            Redis::lpush('websocket-messages', $message);
            Redis::ltrim('websocket-messages', 0, 999);
        } catch (\Exception $e) {
            // 静默处理Redis错误，不影响日志监控
        }
    }

    /**
     * 获取日志统计信息
     */
    public function getLogStats(): array
    {
        $logs = $this->getLatestLogs(1000); // 分析最近1000条日志
        
        $stats = [
            'total' => count($logs),
            'levels' => [
                'ERROR' => 0,
                'WARNING' => 0,
                'INFO' => 0,
                'DEBUG' => 0
            ],
            'recent_errors' => [],
            'last_update' => date('Y-m-d H:i:s')
        ];

        foreach ($logs as $log) {
            $level = $log['level'];
            if (isset($stats['levels'][$level])) {
                $stats['levels'][$level]++;
            }

            // 收集最近的错误
            if ($level === 'ERROR' && count($stats['recent_errors']) < 5) {
                $stats['recent_errors'][] = [
                    'timestamp' => $log['timestamp'],
                    'message' => $log['message']
                ];
            }
        }

        return $stats;
    }

    /**
     * 搜索日志
     */
    public function searchLogs(string $keyword, string $level = null, int $limit = 100): array
    {
        $logs = $this->getLatestLogs(1000);
        $results = [];

        foreach ($logs as $log) {
            // 级别过滤
            if ($level && $log['level'] !== strtoupper($level)) {
                continue;
            }

            // 关键词搜索
            if (stripos($log['message'], $keyword) !== false || 
                stripos(json_encode($log['context']), $keyword) !== false) {
                $results[] = $log;
                
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return $results;
    }
} 