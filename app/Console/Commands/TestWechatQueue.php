<?php

namespace App\Console\Commands;

use App\Services\WechatMessageService;
use App\Models\WechatMessageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestWechatQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'wechat:test-queue
                          {--room=45958721463@chatroom : 测试群聊ID}
                          {--count=5 : 发送消息数量}
                          {--sync : 使用同步模式}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试微信消息队列功能';

    protected WechatMessageService $wechatMessageService;

    public function __construct(WechatMessageService $wechatMessageService)
    {
        parent::__construct();
        $this->wechatMessageService = $wechatMessageService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $roomId  = $this->option('room');
        $count   = (int)$this->option('count');
        $useSync = $this->option('sync');

        $this->info("开始测试微信消息队列功能");
        $this->info("群聊ID: {$roomId}");
        $this->info("消息数量: {$count}");
        $this->info("发送模式: " . ($useSync ? '同步' : '异步队列'));

        $this->line('');

        // 测试单条消息发送
        $this->testSingleMessage($roomId, $useSync);

        // 测试批量消息发送
        $this->testBatchMessages($roomId, $count, $useSync);

        // 测试不同类型消息
        $this->testDifferentTypes($roomId, $useSync);

        // 显示统计信息
        $this->showStats($roomId);

        $this->info('');
        $this->info('测试完成！');
        $this->info('可以通过以下方式查看结果：');
        $this->info('1. 访问监控面板: https://slurry-api.1105.me/wechat/monitor/');
        $this->info('2. 查看日志: storage/logs/laravel.log');
        $this->info('3. 查看微信日志: storage/logs/wechat.log');
    }

    /**
     * 测试单条消息发送
     */
    private function testSingleMessage(string $roomId, bool $useSync): void
    {
        $this->info('📨 测试单条消息发送...');

        $message = "单条测试消息 - " . now()->format('Y-m-d H:i:s');

        $result = $this->wechatMessageService->sendMessage(
            $roomId,
            $message,
            WechatMessageLog::TYPE_TEXT,
            'test-command',
            !$useSync
        );

        if ($result !== false) {
            $this->info("✅ 单条消息发送成功，消息ID: {$result}");
        } else {
            $this->error("❌ 单条消息发送失败");
        }
    }

    /**
     * 测试批量消息发送
     */
    private function testBatchMessages(string $roomId, int $count, bool $useSync): void
    {
        $this->info("📨 测试批量消息发送 ({$count} 条)...");

        $messages = [];
        for ($i = 1; $i <= $count; $i++) {
            $messages[] = [
                'room_id' => $roomId,
                'content' => "批量测试消息 #{$i} - " . now()->format('Y-m-d H:i:s')
            ];
        }

        $result = $this->wechatMessageService->sendBatchMessages(
            $messages,
            WechatMessageLog::TYPE_TEXT,
            'test-batch-command',
            !$useSync
        );

        $this->info("✅ 批量消息发送完成");
        $this->info("   成功: " . count($result['success']) . " 条");
        $this->info("   失败: " . count($result['failed']) . " 条");

        if (!empty($result['failed'])) {
            $this->warn("失败的消息:");
            foreach ($result['failed'] as $failed) {
                $this->warn("  - " . $failed['error']);
            }
        }
    }

    /**
     * 测试不同类型消息
     */
    private function testDifferentTypes(string $roomId, bool $useSync): void
    {
        $this->info('📨 测试不同类型消息...');

        $messages = [
            [
                'content' => "普通文本消息 - " . now()->format('Y-m-d H:i:s'),
                'type'    => WechatMessageLog::TYPE_TEXT,
                'label'   => '文本消息'
            ],
            [
                'content' => "带表情的消息 😊🎉 - " . now()->format('Y-m-d H:i:s'),
                'type'    => WechatMessageLog::TYPE_TEXT,
                'label'   => '表情消息'
            ],
            [
                'content' => "多行消息测试\n第二行内容\n第三行内容\n时间: " . now()->format('Y-m-d H:i:s'),
                'type'    => WechatMessageLog::TYPE_TEXT,
                'label'   => '多行消息'
            ]
        ];

        foreach ($messages as $msg) {
            $result = $this->wechatMessageService->sendMessage(
                $roomId,
                $msg['content'],
                $msg['type'],
                'test-types-command',
                !$useSync
            );

            if ($result !== false) {
                $this->info("✅ {$msg['label']} 发送成功，消息ID: {$result}");
            } else {
                $this->error("❌ {$msg['label']} 发送失败");
            }
        }
    }

    /**
     * 显示统计信息
     */
    private function showStats(string $roomId): void
    {
        $this->info('');
        $this->info('📊 当前统计信息:');

        // 获取整体统计
        $overallStats = $this->wechatMessageService->getMessageStats();
        $this->info("总消息数: {$overallStats['total']}");
        $this->info("待发送: {$overallStats['pending']}");
        $this->info("已成功: {$overallStats['success']}");
        $this->info("已失败: {$overallStats['failed']}");
        $this->info("成功率: {$overallStats['success_rate']}%");

        // 获取当前群聊统计
        $roomStats = $this->wechatMessageService->getMessageStats($roomId);
        $this->info('');
        $this->info("当前群聊 ({$roomId}) 统计:");
        $this->info("总消息数: {$roomStats['total']}");
        $this->info("待发送: {$roomStats['pending']}");
        $this->info("已成功: {$roomStats['success']}");
        $this->info("已失败: {$roomStats['failed']}");
        $this->info("成功率: {$roomStats['success_rate']}%");

        // 获取今日统计
        $todayStats = $this->wechatMessageService->getMessageStats(
            null,
            now()->startOfDay(),
            now()->endOfDay()
        );
        $this->info('');
        $this->info("今日统计:");
        $this->info("总消息数: {$todayStats['total']}");
        $this->info("待发送: {$todayStats['pending']}");
        $this->info("已成功: {$todayStats['success']}");
        $this->info("已失败: {$todayStats['failed']}");
        $this->info("成功率: {$todayStats['success_rate']}%");
    }
}
