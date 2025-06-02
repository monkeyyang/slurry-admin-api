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
     * 根据计划配置生成计划项
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

        // 计算基础天数（总金额/面值倍数）
        $baseDays = floor($totalAmount / $multipleBase);
        
        // 计算每天需要完成的基础天数（向下取整）
        $daysPerDay = floor($baseDays / $days);
        
        // 计算每天的基础金额（基础天数 * 面值倍数）
        $baseAmountPerDay = $daysPerDay * $multipleBase;
        
        // 为每一天创建计划项
        $remainingAmount = $totalAmount;
        
        for ($day = 1; $day <= $days; $day++) {
            // 根据间隔小时数计算时间
            $hourOffset = ($day - 1) * $this->interval_hours;
            $itemDate = $startDate->copy()->addHours($hourOffset);

            // 最后一天使用剩余金额
            if ($day == $days) {
                $amount = $remainingAmount;
            } else {
                // 使用计算出的基础金额
                $amount = $baseAmountPerDay;
                
                // 添加浮动范围内的随机浮动金额，确保是5的倍数
                $randomFloat = mt_rand(-100, 100) / 100 * $floatAmount;
                $randomFloat = round($randomFloat / $multipleBase) * $multipleBase; // 确保是5的倍数
                $amount += $randomFloat;

                // 确保不超过剩余金额
                $amount = min($amount, $remainingAmount);
                $amount = max($amount, $multipleBase); // 确保金额至少是5的倍数

                // 确保是5的倍数
                $amount = floor($amount / $multipleBase) * $multipleBase;
            }

            // 更新剩余金额
            $remainingAmount -= $amount;

            // 计算最小和最大金额（确保是5的倍数）
            $minAmount = $day == $days ? $amount : floor(($amount - $floatAmount) / $multipleBase) * $multipleBase;
            $maxAmount = $day == $days ? $amount : ceil(($amount + $floatAmount) / $multipleBase) * $multipleBase;

            // 创建计划项
            ChargePlanItem::create([
                'plan_id' => $this->id,
                'day' => $day,
                'time' => $itemDate->format('H:i:s'),
                'amount' => $amount,
                'min_amount' => max($multipleBase, $minAmount),
                'max_amount' => $maxAmount,
                'description' => $day == $days ? "最后一天充值剩余金额" : "第{$day}天充值",
                'status' => 'pending',
            ]);
        }
    }
}
