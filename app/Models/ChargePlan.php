<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ChargePlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'account',
        'password',
        'country',
        'total_amount',
        'days',
        'multiple_base',
        'float_amount',
        'interval_hours',
        'start_time',
        'status',
        'current_day',
        'progress',
        'charged_amount',
        'group_id',
        'priority',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'multiple_base' => 'decimal:2',
        'float_amount' => 'decimal:2',
        'progress' => 'decimal:2',
        'charged_amount' => 'decimal:2',
        'start_time' => 'datetime',
    ];

    /**
     * The items that belong to the charge plan
     */
    public function items()
    {
        return $this->hasMany(ChargePlanItem::class, 'plan_id');
    }

    /**
     * The logs that belong to the charge plan
     */
    public function logs()
    {
        return $this->hasMany(ChargePlanLog::class, 'plan_id');
    }

    /**
     * The account group that the plan belongs to
     */
    public function group()
    {
        return $this->belongsTo(AccountGroup::class, 'group_id');
    }

    /**
     * The wechat room binding for this plan
     */
    public function wechatRoomBinding()
    {
        return $this->hasOne(ChargePlanWechatRoomBinding::class, 'plan_id');
    }

    /**
     * Get the bound wechat room info
     */
    public function getBoundWechatRoom()
    {
        $binding = $this->wechatRoomBinding;
        if ($binding) {
            return $binding->getWechatRoomInfo();
        }
        return null;
    }

    /**
     * Get the plan's current progress as a percentage
     *
     * @return float
     */
    public function getProgressAttribute()
    {
        if ($this->charged_amount && $this->total_amount > 0) {
            return ($this->charged_amount / $this->total_amount) * 100;
        }
        return 0;
    }

    /**
     * Convert the model to an array for API responses
     *
     * @return array
     */
    public function toApiArray()
    {
        $wechatRoom = $this->getBoundWechatRoom();
        
        return [
            'id' => (string)$this->id,
            'account' => $this->account,
            'password' => $this->password,
            'country' => $this->country,
            'totalAmount' => $this->total_amount,
            'days' => $this->days,
            'multipleBase' => $this->multiple_base,
            'floatAmount' => $this->float_amount,
            'intervalHours' => $this->interval_hours,
            'startTime' => $this->start_time ? $this->start_time->format('Y-m-d H:i:s') : null,
            'items' => $this->items->map(function ($item) {
                return $item->toApiArray();
            }),
            'status' => $this->status,
            'currentDay' => $this->current_day,
            'progress' => $this->progress,
            'chargedAmount' => $this->charged_amount,
            'groupId' => $this->group_id ? (string)$this->group_id : null,
            'priority' => $this->priority,
            'wechatRoom' => $wechatRoom ? [
                'roomId' => $wechatRoom->room_id,
                'roomName' => $wechatRoom->room_name ?? '未知群组',
            ] : null,
            'createdAt' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updatedAt' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Update the plan status based on its execution
     *
     * @return void
     */
    public function updateStatus()
    {
        $completedItems = $this->items()->where('status', 'completed')->count();
        $totalItems = $this->items()->count();
        
        if ($completedItems === $totalItems) {
            $this->status = 'completed';
            $this->save();
        } elseif ($completedItems > 0) {
            $this->status = 'processing';
            $this->save();
        }
    }

    /**
     * Generate plan items based on the plan configuration
     *
     * @return void
     */
    public function generateItems()
    {
        $startDate = Carbon::parse($this->start_time);
        $totalAmount = $this->total_amount;
        $multipleBase = $this->multiple_base;
        $floatAmount = $this->float_amount;
        $days = $this->days;
        
        // Calculate item amounts based on multiple base and days
        $itemAmounts = [];
        $remainingAmount = $totalAmount;
        
        for ($day = 1; $day <= $days; $day++) {
            // For the last day, use remaining amount
            if ($day == $days) {
                $itemAmounts[$day] = $remainingAmount;
                continue;
            }
            
            // Calculate amount as a multiple of the base amount
            $multiplier = mt_rand(1, 3); // Random multiplier between 1 and 3
            $baseAmount = $multipleBase * $multiplier;
            
            // Add some float amount within range
            $randomFloat = mt_rand(-100, 100) / 100 * $floatAmount;
            $amount = $baseAmount + $randomFloat;
            
            // Ensure we don't exceed total amount
            $amount = min($amount, $remainingAmount * 0.9); // Don't use more than 90% of remaining
            $amount = max($amount, 0.01); // Ensure positive amount
            
            // Round to 2 decimal places
            $amount = round($amount, 2);
            
            $itemAmounts[$day] = $amount;
            $remainingAmount -= $amount;
        }
        
        // Create items for each day
        foreach ($itemAmounts as $day => $amount) {
            // Calculate time based on interval hours
            $hourOffset = ($day - 1) * $this->interval_hours;
            $itemDate = $startDate->copy()->addHours($hourOffset);
            
            // Create plan item
            ChargePlanItem::create([
                'plan_id' => $this->id,
                'day' => $day,
                'time' => $itemDate->format('H:i:s'),
                'amount' => $amount,
                'min_amount' => max(0, $amount - $floatAmount),
                'max_amount' => $amount + $floatAmount,
                'description' => "Day $day charge",
                'status' => 'pending',
            ]);
        }
    }
} 