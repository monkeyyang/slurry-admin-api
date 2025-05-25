<?php

namespace App\Jobs;

use App\Services\GiftCardExchangeService;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessGiftCardExchangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $message;
    protected $requestId;

    /**
     * 队列连接
     *
     * @var string
     */
    public $connection;

    /**
     * 队列名称
     *
     * @var string
     */
    public $queue;

    /**
     * 任务最大尝试次数
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * 任务超时时间（秒）
     *
     * @var int
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param string $message 兑换消息
     * @param string|null $requestId 请求ID，用于追踪
     */
    public function __construct(string $message, string $requestId = null)
    {
        $this->message = $message;
        $this->requestId = $requestId ?: uniqid('exchange_', true);

        // 设置队列连接和队列名称
        $this->connection = config('gift_card.queue.connection');
        $this->queue = config('gift_card.queue.queue_name');
    }

    /**
     * Execute the job.
     *
     * @param GiftCardExchangeService $giftCardExchangeService
     * @return void
     * @throws Exception
     */
    public function handle(GiftCardExchangeService $giftCardExchangeService): void
    {
        try {
            Log::info("开始处理礼品卡兑换队列任务", [
                'request_id' => $this->requestId,
                'message' => $this->message,
                'attempt' => $this->attempts()
            ]);

            // 处理兑换消息
            $result = $giftCardExchangeService->processExchangeMessage($this->message);

            if ($result['success']) {
                Log::info("礼品卡兑换队列任务处理成功", [
                    'request_id' => $this->requestId,
                    'result' => $result['data']
                ]);
            } else {
                Log::error("礼品卡兑换队列任务处理失败", [
                    'request_id' => $this->requestId,
                    'error' => $result['message']
                ]);

                // 如果是业务逻辑错误（如卡无效、没有合适账户等），不重试
                if ($this->shouldNotRetry($result['message'])) {
                    $this->fail(new Exception($result['message']));
                    return;
                }

                throw new Exception($result['message']);
            }
        } catch (Exception $e) {
            Log::error("礼品卡兑换队列任务执行异常", [
                'request_id' => $this->requestId,
                'message' => $this->message,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts()
            ]);

            throw $e;
        }
    }

    /**
     * 判断是否不应该重试
     *
     * @param string $errorMessage
     * @return bool
     */
    protected function shouldNotRetry(string $errorMessage): bool
    {
        $noRetryErrors = [
            '消息格式无效',
            '礼品卡无效',
            '没有找到合适的可执行计划',
            '所有账号已达额度上限'
        ];

        foreach ($noRetryErrors as $error) {
            if (str_contains($errorMessage, $error)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception): void
    {
        Log::error("礼品卡兑换队列任务最终失败", [
            'request_id' => $this->requestId,
            'message' => $this->message,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}
