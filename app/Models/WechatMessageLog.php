<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;

class WechatMessageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'message_type',
        'content',
        'from_source',
        'status',
        'retry_count',
        'max_retry',
        'api_response',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'api_response' => 'json',
        'sent_at'      => 'datetime',
    ];

    // 状态常量
    const STATUS_PENDING = 0;   // 待发送
    const STATUS_SUCCESS = 1;   // 发送成功
    const STATUS_FAILED  = 2;   // 发送失败

    // 消息类型常量
    const TYPE_TEXT  = 'text';
    const TYPE_IMAGE = 'image';
    const TYPE_FILE  = 'file';

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '待发送',
            self::STATUS_SUCCESS => '发送成功',
            self::STATUS_FAILED => '发送失败',
            default => '未知状态',
        };
    }

    /**
     * 获取消息类型文本
     */
    public function getMessageTypeTextAttribute(): string
    {
        return match ($this->message_type) {
            self::TYPE_TEXT => '文本消息',
            self::TYPE_IMAGE => '图片消息',
            self::TYPE_FILE => '文件消息',
            default => '未知类型',
        };
    }

    /**
     * 获取内容预览
     */
    public function getContentPreviewAttribute(): string
    {
        if (strlen($this->content) > 50) {
            return mb_substr($this->content, 0, 50) . '...';
        }
        return $this->content;
    }

    /**
     * 是否可以重试
     */
    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->retry_count < $this->max_retry;
    }

    /**
     * 标记为发送成功
     */
    public function markAsSuccess($apiResponse = null): void
    {
        $this->update([
            'status'       => self::STATUS_SUCCESS,
            'api_response' => $apiResponse,
            'sent_at'      => now(),
        ]);
    }

    /**
     * 标记为发送失败
     */
    public function markAsFailed($errorMessage = null, $apiResponse = null): void
    {
        $this->increment('retry_count');
        $this->update([
            'status'        => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'api_response'  => $apiResponse,
        ]);
    }

    /**
     * 作用域: 待发送的消息
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * 作用域: 发送成功的消息
     */
    public function scopeSuccess(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 作用域: 发送失败的消息
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * 作用域: 可重试的消息
     */
    public function scopeCanRetry(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED)
            ->whereColumn('retry_count', '<', 'max_retry');
    }

    /**
     * 作用域: 按房间ID过滤
     */
    public function scopeByRoomId(Builder $query, string $roomId): Builder
    {
        return $query->where('room_id', $roomId);
    }

    /**
     * 作用域: 按时间范围过滤
     */
    public function scopeByDateRange(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * 获取群聊信息（跨库查询）
     */
    public function getRoomInfo()
    {
        if (empty($this->room_id)) {
            return null;
        }

        return MrRoom::where('room_id', $this->room_id)->first();
    }

    /**
     * 获取群聊名称
     */
    public function getRoomNameAttribute(): string
    {
        if (empty($this->room_id)) {
            return '未知群聊';
        }

        $roomInfo = $this->getRoomInfo();
        return $roomInfo ? $roomInfo->room_name : $this->room_id;
    }

    /**
     * 获取格式化的群聊显示名称
     */
    public function getFormattedRoomNameAttribute(): string
    {
        $roomName = $this->getRoomNameAttribute();

        // 如果群聊名称就是room_id（说明没找到对应的中文名），则尝试简化显示
        if ($roomName === $this->room_id && str_contains($this->room_id, '@chatroom')) {
            // 提取@chatroom前面的部分作为简化显示
            $parts = explode('@', $this->room_id);
            return $parts[0] ?? $this->room_id;
        }

        return $roomName;
    }
}
