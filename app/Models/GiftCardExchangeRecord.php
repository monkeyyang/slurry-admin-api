<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GiftCardExchangeRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'item_id',
        'account',
        'card_number',
        'card_type',
        'country_code',
        'original_balance',
        'original_currency',
        'exchange_rate',
        'converted_amount',
        'target_currency',
        'transaction_id',
        'status',
        'details',
        'exchange_time',
        'task_id',
    ];

    protected $casts = [
        'original_balance' => 'decimal:2',
        'exchange_rate' => 'decimal:2',
        'converted_amount' => 'decimal:2',
        'exchange_time' => 'datetime',
    ];

    /**
     * 关联的充值计划
     */
    public function plan()
    {
        return $this->belongsTo(ChargePlan::class, 'plan_id');
    }

    /**
     * 关联的计划项目
     */
    public function item()
    {
        return $this->belongsTo(ChargePlanItem::class, 'item_id');
    }

    /**
     * 关联的任务
     */
    public function task()
    {
        return $this->belongsTo(GiftCardTask::class, 'task_id', 'task_id');
    }

    /**
     * 转换为API数组
     */
    public function toApiArray()
    {
        return [
            'id' => (string)$this->id,
            'planId' => (string)$this->plan_id,
            'itemId' => (string)$this->item_id,
            'account' => $this->account,
            'cardNumber' => $this->card_number,
            'cardType' => $this->card_type,
            'countryCode' => $this->country_code,
            'originalBalance' => $this->original_balance,
            'originalCurrency' => $this->original_currency,
            'exchangeRate' => $this->exchange_rate,
            'convertedAmount' => $this->converted_amount,
            'targetCurrency' => $this->target_currency,
            'transactionId' => $this->transaction_id,
            'status' => $this->status,
            'details' => $this->details,
            'exchangeTime' => $this->exchange_time ? $this->exchange_time->toISOString() : null,
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
} 