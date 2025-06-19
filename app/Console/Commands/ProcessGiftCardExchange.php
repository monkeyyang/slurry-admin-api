<?php

namespace App\Console\Commands;

use App\Services\GiftCardExchangeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessGiftCardExchange extends Command
{
    /**
     * 命令名称
     *
     * @var string
     */
    protected $signature = 'gift-card:exchange {message}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '处理礼品卡兑换消息';

    /**
     * 礼品卡兑换服务
     *
     * @var GiftCardExchangeService
     */
    protected $exchangeService;

    /**
     * 创建命令实例
     */
    public function __construct(GiftCardExchangeService $exchangeService)
    {
        parent::__construct();
        $this->exchangeService = $exchangeService;
    }

    /**
     * 执行命令
     */
    public function handle()
    {
        $message = $this->argument('message');
        $this->info("处理兑换消息: {$message}");

        try {
            $result = $this->exchangeService->processExchangeMessage($message);

            if ($result['success']) {
                $this->info("兑换成功: " . json_encode($result['data'], JSON_UNESCAPED_UNICODE));
                return 0;
            } else {
                $this->error("兑换失败: " . $result['message']);
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("处理异常: " . $e->getMessage());
            Log::channel('gift_card_exchange')->error("礼品卡兑换命令执行失败: " . $e->getMessage());
            return 1;
        }
    }
}
