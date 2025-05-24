<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GiftCardTask extends Model
{
    use HasFactory;
    
    /**
     * 任务类型常量
     */
    public const TYPE_LOGIN = 'login';
    public const TYPE_QUERY = 'query';
    public const TYPE_REDEEM = 'redeem';
    
    /**
     * 任务状态常量
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    
    /**
     * 允许批量赋值的属性
     *
     * @var array
     */
    protected $fillable = [
        'task_id',
        'type',
        'status',
        'request_data',
        'result_data',
        'error_message',
        'completed_at'
    ];
    
    /**
     * 属性类型转换
     *
     * @var array
     */
    protected $casts = [
        'request_data' => 'json',
        'result_data' => 'json',
        'completed_at' => 'datetime',
    ];
    
    /**
     * 与该任务关联的礼品卡兑换记录
     */
    public function exchangeRecords()
    {
        return $this->hasMany(GiftCardExchangeRecord::class, 'task_id', 'task_id');
    }
    
    /**
     * 判断任务是否完成
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
    
    /**
     * 判断任务是否失败
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }
    
    /**
     * 判断任务是否处理中
     *
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }
    
    /**
     * 判断任务是否等待处理
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
    
    /**
     * 更新任务状态为处理中
     *
     * @return $this
     */
    public function markAsProcessing()
    {
        $this->status = self::STATUS_PROCESSING;
        $this->save();
        return $this;
    }
    
    /**
     * 更新任务状态为已完成
     *
     * @param array $resultData 结果数据
     * @return $this
     */
    public function markAsCompleted(array $resultData = [])
    {
        $this->status = self::STATUS_COMPLETED;
        $this->result_data = $resultData;
        $this->completed_at = now();
        $this->save();
        return $this;
    }
    
    /**
     * 更新任务状态为失败
     *
     * @param string $errorMessage 错误信息
     * @return $this
     */
    public function markAsFailed(string $errorMessage)
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->completed_at = now();
        $this->save();
        return $this;
    }
} 