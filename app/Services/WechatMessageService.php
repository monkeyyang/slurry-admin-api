<?php

namespace App\Services;

use App\Models\WechatMessageLog;
use App\Jobs\SendWechatMessageJob;
use Illuminate\Support\Facades\Log;

class WechatMessageService
{
    /**
     * 发送微信消息（支持队列和同步模式）
     *
     * @param string $roomId 群聊ID
     * @param string $content 消息内容
     * @param string $messageType 消息类型
     * @param string|null $fromSource 来源标识
     * @param bool $useQueue 是否使用队列（默认根据配置）
     * @param int $maxRetry 最大重试次数
     * @return bool|int 成功返回true或消息ID，失败返回false
     */
    public function sendMessage(
        string  $roomId,
        string  $content,
        string  $messageType = WechatMessageLog::TYPE_TEXT,
        ?string $fromSource = null,
        ?bool   $useQueue = null,
        int     $maxRetry = 3
    ): bool|int
    {
        // 如果未指定是否使用队列，则根据配置决定
        if ($useQueue === null) {
            $useQueue = config('wechat.queue.enabled', true);
        }

        // 创建消息记录
        $messageLog = WechatMessageLog::create([
            'room_id'      => $roomId,
            'message_type' => $messageType,
            'content'      => $content,
            'from_source'  => $fromSource,
            'status'       => WechatMessageLog::STATUS_PENDING,
            'max_retry'    => $maxRetry,
        ]);

        if (!$messageLog) {
            Log::error('创建微信消息记录失败', [
                'room_id'         => $roomId,
                'content_preview' => mb_substr($content, 0, 100)
            ]);
            return false;
        }

        if ($useQueue) {
            // 使用队列异步发送
            SendWechatMessageJob::dispatch($messageLog->id);
            Log::info('微信消息已加入队列', [
                'message_id'  => $messageLog->id,
                'room_id'     => $roomId,
                'from_source' => $fromSource
            ]);
            return $messageLog->id;
        } else {
            // 同步发送
            $result = $this->sendMessageSync($messageLog);
            return $result;
        }
    }

    /**
     * 同步发送微信消息
     *
     * @param WechatMessageLog $messageLog
     * @return bool
     */
    public function sendMessageSync(WechatMessageLog $messageLog): bool
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
            Log::channel('wechat')->info('发送微信消息（同步）', [
                'message_id'      => $messageLog->id,
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
                $errorMessage = "CURL错误: {$curlError}";
                $messageLog->markAsFailed($errorMessage);
                Log::channel('wechat')->error('微信消息发送失败（同步）', [
                    'message_id' => $messageLog->id,
                    'room_id'    => $messageLog->room_id,
                    'error'      => $errorMessage
                ]);
                return false;
            }

            // 检查HTTP状态码
            if ($httpCode !== 200) {
                $errorMessage = "HTTP错误: {$httpCode}";
                $messageLog->markAsFailed($errorMessage, $response);
                Log::channel('wechat')->error('微信消息发送失败（同步）', [
                    'message_id' => $messageLog->id,
                    'room_id'    => $messageLog->room_id,
                    'http_code'  => $httpCode,
                    'response'   => $response
                ]);
                return false;
            }

            // 解析响应
            $responseData = json_decode($response, true);
            $messageLog->markAsSuccess($responseData);

            Log::channel('wechat')->info('微信消息发送成功（同步）', [
                'message_id'    => $messageLog->id,
                'room_id'       => $messageLog->room_id,
                'http_code'     => $httpCode,
                'response_data' => $responseData
            ]);

            return true;

        } catch (\Exception $e) {
            $messageLog->markAsFailed($e->getMessage());
            Log::channel('wechat')->error('微信消息发送异常（同步）', [
                'message_id' => $messageLog->id,
                'room_id'    => $messageLog->room_id,
                'error'      => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * 批量发送微信消息
     *
     * @param array $messages 消息数组 [['room_id' => '', 'content' => ''], ...]
     * @param string $messageType 消息类型
     * @param string|null $fromSource 来源标识
     * @param bool $useQueue 是否使用队列
     * @return array 发送结果 ['success' => [], 'failed' => []]
     */
    public function sendBatchMessages(
        array   $messages,
        string  $messageType = WechatMessageLog::TYPE_TEXT,
        ?string $fromSource = null,
        bool    $useQueue = true
    ): array
    {
        $results = ['success' => [], 'failed' => []];

        foreach ($messages as $message) {
            $roomId  = $message['room_id'] ?? '';
            $content = $message['content'] ?? '';

            if (empty($roomId) || empty($content)) {
                $results['failed'][] = [
                    'room_id' => $roomId,
                    'content' => $content,
                    'error'   => '房间ID或内容为空'
                ];
                continue;
            }

            $result = $this->sendMessage($roomId, $content, $messageType, $fromSource, $useQueue);

            if ($result !== false) {
                $results['success'][] = [
                    'room_id'    => $roomId,
                    'content'    => $content,
                    'message_id' => $result
                ];
            } else {
                $results['failed'][] = [
                    'room_id' => $roomId,
                    'content' => $content,
                    'error'   => '发送失败'
                ];
            }
        }

        return $results;
    }

    /**
     * 重试失败的消息
     *
     * @param int $messageId 消息ID
     * @return bool
     */
    public function retryMessage(int $messageId): bool
    {
        $messageLog = WechatMessageLog::find($messageId);

        if (!$messageLog) {
            Log::warning("消息记录不存在: {$messageId}");
            return false;
        }

        if (!$messageLog->canRetry()) {
            Log::warning("消息不能重试: {$messageId}", [
                'status'      => $messageLog->status,
                'retry_count' => $messageLog->retry_count,
                'max_retry'   => $messageLog->max_retry
            ]);
            return false;
        }

        // 重置状态并加入队列
        $messageLog->update(['status' => WechatMessageLog::STATUS_PENDING]);
        SendWechatMessageJob::dispatch($messageLog->id);

        Log::info("消息重试已加入队列: {$messageId}");
        return true;
    }

    /**
     * 使用模板发送微信消息
     *
     * @param string $roomId 群聊ID
     * @param string $templateName 模板名称
     * @param array $variables 模板变量
     * @param string|null $fromSource 来源标识
     * @param bool $useQueue 是否使用队列
     * @return bool|int 成功返回true或消息ID，失败返回false
     */
    public function sendMessageWithTemplate(
        string  $roomId,
        string  $templateName,
        array   $variables = [],
        ?string $fromSource = null,
        bool    $useQueue = true
    ): bool|int
    {
        // 获取模板
        $template = config("wechat.templates.{$templateName}");
        
        if (!$template) {
            Log::error("微信模板不存在: {$templateName}");
            return false;
        }

        // 替换占位符
        $content = $this->replacePlaceholders($template, $variables);

        // 发送消息
        return $this->sendMessage(
            $roomId,
            $content,
            WechatMessageLog::TYPE_TEXT,
            $fromSource,
            $useQueue
        );
    }

    /**
     * 替换模板中的占位符
     *
     * @param string $template 模板内容
     * @param array $variables 变量数组
     * @return string 替换后的内容
     */
    private function replacePlaceholders(string $template, array $variables): string
    {
        $placeholders = [];
        $values = [];
        
        foreach ($variables as $key => $value) {
            $placeholders[] = '{' . $key . '}';
            $values[] = $value ?? '';
        }
        
        return str_replace($placeholders, $values, $template);
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
     * 获取消息统计
     *
     * @param string|null $roomId 房间ID
     * @param string|null $startDate 开始日期
     * @param string|null $endDate 结束日期
     * @return array
     */
    public function getMessageStats(?string $roomId = null, ?string $startDate = null, ?string $endDate = null): array
    {
        $query = WechatMessageLog::query();

        if ($roomId) {
            $query->where('room_id', $roomId);
        }

        if ($startDate && $endDate) {
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        $total   = $query->count();
        $pending = $query->where('status', WechatMessageLog::STATUS_PENDING)->count();
        $success = $query->where('status', WechatMessageLog::STATUS_SUCCESS)->count();
        $failed  = $query->where('status', WechatMessageLog::STATUS_FAILED)->count();

        return [
            'total'        => $total,
            'pending'      => $pending,
            'success'      => $success,
            'failed'       => $failed,
            'success_rate' => $total > 0 ? round(($success / $total) * 100, 2) : 0
        ];
    }
}
