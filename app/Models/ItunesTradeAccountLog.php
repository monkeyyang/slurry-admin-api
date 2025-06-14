<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ItunesTradeAccountLog extends Model
{
    use HasFactory;

    protected $table = 'itunes_trade_account_logs';

    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_PENDING = 'pending';

    protected $fillable = [
        'account_id',
        'plan_id',
        'day',
        'amount',
        'status',
        'exchange_time',
        'error_message',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'plan_id' => 'integer',
        'day' => 'integer',
        'amount' => 'decimal:2',
        'exchange_time' => 'datetime',
    ];

    /**
     * 关联账号
     */
    public function account()
    {
        return $this->belongsTo(ItunesTradeAccount::class, 'account_id');
    }

    /**
     * 关联计划
     */
    public function plan()
    {
        return $this->belongsTo(ItunesTradePlan::class, 'plan_id');
    }

    /**
     * 获取状态文本
     */
    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            self::STATUS_SUCCESS => '成功',
            self::STATUS_FAILED => '失败',
            self::STATUS_PENDING => '处理中',
            default => '未知',
        };
    }

    /**
     * 作用域：按账号筛选
     */
    public function scopeByAccount($query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * 作用域：按计划筛选
     */
    public function scopeByPlan($query, int $planId)
    {
        return $query->where('plan_id', $planId);
    }

    /**
     * 作用域：按状态筛选
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 作用域：成功状态
     */
    public function scopeSuccess($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    /**
     * 作用域：失败状态
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * 作用域：处理中状态
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
} 