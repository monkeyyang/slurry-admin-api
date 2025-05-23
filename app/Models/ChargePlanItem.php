<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChargePlanItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'day',
        'time',
        'amount',
        'min_amount',
        'max_amount',
        'description',
        'status',
        'executed_at',
        'result',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'executed_at' => 'datetime',
    ];

    /**
     * The charge plan that the item belongs to
     */
    public function plan()
    {
        return $this->belongsTo(ChargePlan::class, 'plan_id');
    }

    /**
     * The logs for this item
     */
    public function logs()
    {
        return $this->hasMany(ChargePlanLog::class, 'item_id');
    }

    /**
     * Convert the model to an array for API responses
     *
     * @return array
     */
    public function toApiArray()
    {
        return [
            'id' => (string)$this->id,
            'planId' => (string)$this->plan_id,
            'day' => $this->day,
            'time' => $this->time,
            'amount' => $this->amount,
            'minAmount' => $this->min_amount,
            'maxAmount' => $this->max_amount,
            'description' => $this->description,
            'status' => $this->status,
            'executedAt' => $this->executed_at ? $this->executed_at->toISOString() : null,
            'result' => $this->result,
        ];
    }
} 