<?php

namespace App\Jobs;

use App\Models\WechatMessageLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class SendWechatMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 3;
    public $timeout = 30;
    public $backoff = [10, 30, 60]; // 重试延迟时间（秒）

    protected $messageLogId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $messageLogId)
    {
        $this->messageLogId = $messageLogId;
        $this->onQueue('wechat-message'); // 指定队列名称
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $messageLog = WechatMessageLog::find($this->messageLogId);

        if (!$messageLog) {
            Log::warning("微信消息记录不存在: {$this->messageLogId}");
            return;
        }

        // 如果已经发送成功，跳过
        if ($messageLog->status === WechatMessageLog::STATUS_SUCCESS) {
            Log::info("微信消息已发送成功，跳过: {$this->messageLogId}");
            return;
        }

        Log::info("开始发送微信消息", [
            'message_id'      => $this->messageLogId,
            'room_id'         => $messageLog->room_id,
            'retry_count'     => $messageLog->retry_count,
            'content_preview' => $messageLog->content_preview
        ]);

        try {
            $result = $this->sendWechatMessage($messageLog);

            if ($result['success']) {
                $messageLog->markAsSuccess($result['response']);
                Log::info("微信消息发送成功", [
                    'message_id' => $this->messageLogId,
                    'room_id'    => $messageLog->room_id
                ]);
            } else {
                throw new \Exception($result['error'] ?? '发送失败');
            }

        } catch (\Exception $e) {
            Log::error("微信消息发送失败", [
                'message_id'  => $this->messageLogId,
                'room_id'     => $messageLog->room_id,
                'error'       => $e->getMessage(),
                'retry_count' => $messageLog->retry_count
            ]);

            // 记录失败信息
            $messageLog->markAsFailed($e->getMessage());

            // 如果还可以重试，抛出异常让队列重试
            if ($messageLog->canRetry()) {
                throw $e;
            }
        }
    }

    /**
     * 发送微信消息
     */
    private function sendWechatMessage(WechatMessageLog $messageLog): array
    {
        try {
            // 构建请求内容
            $content = [
                'data'      => [
                    'to_wxid' => $messageLog->room_id,
                    'content' => $messageLog->content
                ],
                'client_id' => 1,
                'type'      => $this->getWechatMessageType($messageLog->message_type)
            ];

            // 记录发送请求
            Log::channel('wechat')->info('发送微信消息队列', [
                'message_id'      => $this->messageLogId,
                'room_id'         => $messageLog->room_id,
                'message_type'    => $messageLog->message_type,
                'message_length'  => strlen($messageLog->content),
                'content_preview' => $messageLog->content_preview
            ]);

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL            => config('wechat.api_url', 'http://106.52.250.202:6666/'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING       => '',
                CURLOPT_MAXREDIRS      => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => json_encode($content),
                CURLOPT_HTTPHEADER     => [
                    'User-Agent: Apifox/1.0.0 (https://www.apifox.cn)',
                    'Content-Type: application/json',
                ],
            ]);

            $response  = curl_exec($curl);
            $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            // 检查CURL错误
            if ($curlError) {
                return [
                    'success'  => false,
                    'error'    => "CURL错误: {$curlError}",
                    'response' => null
                ];
            }

            // 检查HTTP状态码
            if ($httpCode !== 200) {
                return [
                    'success'  => false,
                    'error'    => "HTTP错误: {$httpCode}",
                    'response' => $response
                ];
            }

            // 解析响应
            $responseData = json_decode($response, true);

            Log::channel('wechat')->info('微信消息发送成功（队列）', [
                'message_id'    => $this->messageLogId,
                'room_id'       => $messageLog->room_id,
                'http_code'     => $httpCode,
                'response_data' => $responseData
            ]);

            return [
                'success'   => true,
                'response'  => $responseData,
                'http_code' => $httpCode
            ];

        } catch (\Exception $e) {
            return [
                'success'  => false,
                'error'    => $e->getMessage(),
                'response' => null
            ];
        }
    }

    /**
     * 获取微信消息类型
     */
    private function getWechatMessageType(string $messageType): string
    {
        return match ($messageType) {
            WechatMessageLog::TYPE_TEXT => 'MT_SEND_TEXTMSG',
            WechatMessageLog::TYPE_IMAGE => 'MT_SEND_IMGMSG',
            WechatMessageLog::TYPE_FILE => 'MT_SEND_FILEMSG',
            default => 'MT_SEND_TEXTMSG',
        };
    }

    /**
     * 处理失败的任务
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("微信消息发送任务最终失败", [
            'message_id' => $this->messageLogId,
            'error'      => $exception->getMessage(),
            'trace'      => $exception->getTraceAsString()
        ]);

        // 更新消息状态为最终失败
        $messageLog = WechatMessageLog::find($this->messageLogId);
        if ($messageLog) {
            $messageLog->markAsFailed("任务最终失败: " . $exception->getMessage());
        }
    }
}
