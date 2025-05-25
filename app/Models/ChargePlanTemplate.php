<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChargePlanTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'country',
        'total_amount',
        'days',
        'multiple_base',
        'float_amount',
        'interval_hours',
        'items',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'multiple_base' => 'decimal:2',
        'float_amount' => 'decimal:2',
        'items' => 'array',
    ];

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
            'country' => $this->country,
            'totalAmount' => $this->total_amount,
            'days' => $this->days,
            'multipleBase' => $this->multiple_base,
            'floatAmount' => $this->float_amount,
            'intervalHours' => $this->interval_hours,
            'items' => $this->items,
            'createdAt' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updatedAt' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Create a template from an existing plan
     *
     * @param string $name
     * @param ChargePlan $plan
     * @return self
     */
    public static function createFromPlan(string $name, ChargePlan $plan)
    {
        $items = $plan->items->map(function ($item) {
            return [
                'day' => $item->day,
                'time' => $item->time,
                'amount' => $item->amount,
                'minAmount' => $item->min_amount,
                'maxAmount' => $item->max_amount,
                'description' => $item->description,
            ];
        })->toArray();

        return self::create([
            'name' => $name,
            'country' => $plan->country,
            'total_amount' => $plan->total_amount,
            'days' => $plan->days,
            'multiple_base' => $plan->multiple_base,
            'float_amount' => $plan->float_amount,
            'interval_hours' => $plan->interval_hours,
            'items' => $items,
        ]);
    }
} 