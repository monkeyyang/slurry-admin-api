<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\CardQueryService;

class QueryGiftCards extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cards:query
                            {--batch=100 : 每批处理的记录数}
                            {--date= : 查询日期限制，格式：YYYY-MM-DD HH:MM:SS}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '手动触发卡密查询';


    /**
     * 卡密查询服务
     *
     * @var CardQueryService
     */
    protected CardQueryService $cardQueryService;

    /**
     * 构造函数
     */
    public function __construct(CardQueryService $cardQueryService)
    {
        parent::__construct();
        $this->cardQueryService = $cardQueryService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info("暂停执行...");

        exit;
        $batchSize = $this->option('batch');
        $cutoffDate = $this->option('date') ?: '2025-05-14 01:28:00';
        // 使用 Laravel 内置的 verbosity
        $isVerbose = $this->getOutput()->isVerbose();

        $this->info("开始查询卡密，批处理大小: {$batchSize}，时间限制: {$cutoffDate}...");

        // 调试信息
        if ($this->getOutput()->isDebug()) {
            $this->comment('DEBUG: 准备调用 CardQueryService::batchQueryCards');
        }

        $result = $this->cardQueryService->batchQueryCards((int)$batchSize, $cutoffDate);

        if ($result['code'] === 0) {
            $this->info('卡密查询成功: ' . $result['message']);

            if (!empty($result['data'])) {
                // 打印查询参数
                if (isset($result['data']['request_params'])) {
                    $this->info("请求参数: " . json_encode($result['data']['request_params']));
                }

                // 显示任务ID
                if (!empty($result['data']['task_id'])) {
                    $this->info("任务ID: " . $result['data']['task_id']);
                }

                // 显示统计数据
                if (isset($result['stats'])) {
                    $this->info("========== 查询统计 ==========");
                    $this->info("总处理记录数: {$result['stats']['total']}");
                    $this->info("有效卡密数量: {$result['stats']['valid']}");
                    $this->info("无效卡密数量: {$result['stats']['invalid']}");
                }

                // 显示卡密详细信息
                if (isset($result['cards']) && !empty($result['cards'])) {
                    $this->info("========== 卡密详细信息 ==========");
                    $formattedCards = [];
                    $content = "❌请检查以下卡密是否被赎回：\n";
                    $count = 1;
                    $invalidCards = [];

                    foreach ($result['cards'] as $index => $card) {
                        $formattedCards[] = [
                            '序号' => $index + 1,
                            '卡号' => $card['card_code'],
                            '余额' => $card['balance'],
                            '状态' => $card['validation'],
                            '有效性' => $card['is_valid'] ? '有效' : '无效',
                        ];

                        if (!$card['is_valid']) {
                            $invalidCards[] = $card;
                            $content .= $card['card_code']."[".$card['balance']."]\n";
                            $count++;
                        }
                    }

                    if (!empty($invalidCards)) {
                        $this->sendWechatMsg($content);
                    } else {
                        $this->info("没有发现无效卡密，无需发送微信通知");
                    }

                    $this->table(
                        ['序号', '卡号', '余额', '状态', '有效性'],
                        $formattedCards
                    );
                }
            } else {
                $this->warn("没有查询到任何卡密数据");
            }

            // 如果使用了verbose选项，则输出完整的API响应
            if ($isVerbose && !empty($result['data'])) {
                $this->info("========== 完整API响应 ==========");
                $this->line(json_encode($result['data'], JSON_PRETTY_PRINT));
            }
        } else {
            $this->error('卡密查询失败: ' . $result['message']);
            if (isset($result['error'])) {
                $this->error('错误详情: ' . $result['error']);
            }
        }

        return $result['code'] === 0 ? 0 : 1;
    }

    /**
     * 发送文本消息到群聊
     *
     * @param $content
     * @return void
     */
    private function sendWechatMsg($content): void
    {
        if (!is_array($content)) {
            // 拼接内容
            $content = [
                'data'      => [
                    'to_wxid' => '50414550188@chatroom',
                    'content' => $content
                ],
                'client_id' => 1,
                'type'      => 'MT_SEND_TEXTMSG'
            ];
        }
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => 'http://106.52.250.202:6666/',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING       => '',
            CURLOPT_MAXREDIRS      => 10,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => json_encode($content),
            CURLOPT_HTTPHEADER     => [
                'User-Agent: Apifox/1.0.0 (https://www.apifox.cn)',
                'Content-Type: application/json',
            ],
        ]);
        curl_exec($curl);
        curl_close($curl);

    }
}
