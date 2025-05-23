<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'country',
        'total_target_amount',
        'current_amount',
        'status',
        'account_count',
        'auto_switch',
        'switch_threshold',
    ];

    protected $casts = [
        'total_target_amount' => 'decimal:2',
        'current_amount' => 'decimal:2',
        'switch_threshold' => 'decimal:2',
        'auto_switch' => 'boolean',
    ];

    /**
     * The plans that belong to the account group
     */
    public function plans()
    {
        return $this->hasMany(ChargePlan::class, 'group_id');
    }

    /**
     * Increment the group's current amount
     *
     * @param float $amount
     * @return void
     */
    public function incrementAmount($amount)
    {
        $this->current_amount = $this->current_amount + $amount;
        $this->save();
        
        // Update status if reached target
        if ($this->total_target_amount && $this->current_amount >= $this->total_target_amount) {
            $this->status = 'completed';
            $this->save();
        }
    }

    /**
     * Update account count based on plans
     *
     * @return void
     */
    public function updateAccountCount()
    {
        $this->account_count = $this->plans->count();
        $this->save();
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
            'name' => $this->name,
            'description' => $this->description,
            'country' => $this->country,
            'totalTargetAmount' => $this->total_target_amount,
            'currentAmount' => $this->current_amount,
            'status' => $this->status,
            'accountCount' => $this->account_count,
            'autoSwitch' => $this->auto_switch,
            'switchThreshold' => $this->switch_threshold,
            'plans' => $this->plans->map(function ($plan) {
                return [
                    'id' => (string)$plan->id,
                    'account' => $plan->account,
                    'totalAmount' => $plan->total_amount,
                    'progress' => $plan->progress,
                    'status' => $plan->status,
                    'priority' => $plan->priority,
                ];
            }),
            'createdAt' => $this->created_at->toISOString(),
            'updatedAt' => $this->updated_at->toISOString(),
        ];
    }
} 