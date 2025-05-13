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
                            {--date= : 查询日期限制，格式：YYYY-MM-DD HH:MM:SS}
                            {--verbose : 输出详细信息}';

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
    protected $cardQueryService;

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
        $batchSize = $this->option('batch');
        $cutoffDate = $this->option('date') ?: '2025-05-13 21:00:00';
        $verbose = $this->option('verbose');
        
        $this->info("开始查询卡密，批处理大小: {$batchSize}，时间限制: {$cutoffDate}...");
        
        $result = $this->cardQueryService->batchQueryCards((int)$batchSize, $cutoffDate);
        
        if ($result['code'] === 0) {
            $this->info('卡密查询成功: ' . $result['message']);
            
            if (!empty($result['data'])) {
                // 打印查询参数
                $this->info("请求参数: " . json_encode($result['request_params'] ?? []));
                
                // 显示查询到的卡密列表
                if (!empty($result['data']['data'])) {
                    $this->info("========== 查询到的卡密列表 ==========");
                    $this->table(
                        ['序号', '卡密编码', '状态', '余额'],
                        $this->formatCardData($result['data']['data'])
                    );
                    
                    // 分类统计
                    $validCount = 0;
                    $invalidCount = 0;
                    
                    foreach ($result['data']['data'] as $card) {
                        if (isset($card['status']) && $card['status'] === 'valid') {
                            $validCount++;
                        } else {
                            $invalidCount++;
                        }
                    }
                    
                    $this->info("有效卡密数量: {$validCount}");
                    $this->info("无效卡密数量: {$invalidCount}");
                    $this->info("总查询数量: " . count($result['data']['data']));
                } else {
                    $this->warn("API返回了结果，但没有卡密数据");
                    $this->line("API响应: " . json_encode($result['data']));
                }
            } else {
                $this->warn("没有查询到任何卡密数据");
            }
            
            // 如果使用了verbose选项，则输出完整的API响应
            if ($verbose && !empty($result['data'])) {
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
     * 格式化卡密数据用于表格显示
     *
     * @param array $cards 卡密数据
     * @return array 格式化后的数据
     */
    private function formatCardData(array $cards): array
    {
        $formattedData = [];
        $index = 1;
        
        foreach ($cards as $card) {
            $formattedData[] = [
                'index' => $index++,
                'pin' => $card['pin'] ?? 'N/A',
                'status' => isset($card['status']) ? ($card['status'] === 'valid' ? '有效' : '无效') : '未知',
                'balance' => $card['balance'] ?? 'N/A',
            ];
        }
        
        return $formattedData;
    }
} 