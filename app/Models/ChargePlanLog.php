<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChargePlanLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'item_id',
        'day',
        'time',
        'action',
        'status',
        'details',
    ];

    /**
     * The charge plan that the log belongs to
     */
    public function plan()
    {
        return $this->belongsTo(ChargePlan::class, 'plan_id');
    }

    /**
     * The plan item that the log belongs to
     */
    public function item()
    {
        return $this->belongsTo(ChargePlanItem::class, 'item_id');
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
            'itemId' => $this->item_id ? (string)$this->item_id : null,
            'day' => $this->day,
            'time' => $this->time,
            'action' => $this->action,
            'status' => $this->status,
            'details' => $this->details,
            'createdAt' => $this->created_at->toISOString(),
        ];
    }
} 